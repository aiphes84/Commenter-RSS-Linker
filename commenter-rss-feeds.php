<?php
/*
Plugin Name: Commenter RSS Linker
Description: 读取最新评论者，抓取其网站RSS文章，支持数量控制、黑白名单、评论区内联显示。
Version: 2.1

Changelog:
= 2.1 (2026-03-08) =
* 新增：摘要字数控制选项，中文字符计1字，英文字母每2个计1字，后台可配置（默认50字）
* 新增：RSS 地址自动发现，优先解析首页 HTML <link> 标签，回退尝试 /feed/ /feed /rss /rss.xml /atom.xml /index.xml /feed.xml，兼容 Hugo/Hexo/Ghost/Typecho 等非 WordPress 站点
* 新增：古腾堡 Block（crf/commenter-rss），支持侧边栏设置评论者数量和每人文章数，前台服务端渲染

= 2.0 (2026-03-08) =
* 新增：后台控制评论者显示数量，短代码 limit 参数可覆盖
* 新增：后台控制每人 RSS 文章链接数量，短代码 rss_limit 参数可覆盖
* 新增：黑白名单机制，支持黑名单模式（排除域名）和白名单模式（仅允许域名）
* 新增：评论区内联显示，在每条评论下方附加该评论者近期文章链接（后台开关控制）

Author: fxpai.com
*/

if (!defined('ABSPATH')) exit;

// --- 默认选项 ---
function crf_get_opt($key, $default = null) {
    $defaults = [
        'commenter_limit'   => 10,
        'rss_post_limit'    => 2,
        'excerpt_length'    => 50,
        'whitelist_mode'    => 0,
        'whitelist'         => '',
        'blacklist'         => '',
        'inline_comments'   => 0,
    ];
    $val = get_option('crf_v23_' . $key, null);
    if ($val === null) return isset($defaults[$key]) ? $defaults[$key] : $default;
    return $val;
}

// --- 1. 触发机制 ---
register_activation_hook(__FILE__, 'crf_v23_activation');
function crf_v23_activation() {
    if (!wp_next_scheduled('crf_v23_refresh_event')) {
        wp_schedule_event(time(), 'twicedaily', 'crf_v23_refresh_event');
    }
}

register_deactivation_hook(__FILE__, 'crf_v23_deactivation');
function crf_v23_deactivation() {
    wp_clear_scheduled_hook('crf_v23_refresh_event');
}

add_action('crf_v23_refresh_event', 'crf_v23_start_async_update');
add_action('comment_post', 'crf_v23_start_async_update', 20);
add_action('wp_set_comment_status', 'crf_v23_start_async_update', 20);

function crf_v23_start_async_update() {
    wp_remote_post(admin_url('admin-ajax.php'), [
        'body'      => ['action' => 'crf_v23_do_update'],
        'timeout'   => 0.1,
        'blocking'  => false,
        'sslverify' => false,
    ]);
}

add_action('wp_ajax_crf_v23_do_update', 'crf_v23_core_logic');
add_action('wp_ajax_nopriv_crf_v23_do_update', 'crf_v23_core_logic');

// --- 2a. 摘要截取（中文字符计1个字） ---
function crf_v23_trim_excerpt($text) {
    $limit = max(10, intval(crf_get_opt('excerpt_length', 50)));
    $text  = preg_replace('/\s+/', ' ', trim($text));
    $count = 0;
    $out   = '';
    $len   = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($text, $i, 1, 'UTF-8');
        // 中文/日文/韩文字符计1字，ASCII单词计0.5（两个字母≈1字）
        $count += (preg_match('/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u', $char)) ? 1 : 0.5;
        if ($count > $limit) { $out .= '…'; break; }
        $out .= $char;
    }
    return $out;
}

// --- 2b. RSS 地址自动发现 ---

// 缓存首页 HTML，同一请求内只抓一次
function crf_v23_get_homepage_html($site_url) {
    static $cache = [];
    if (array_key_exists($site_url, $cache)) return $cache[$site_url];
    $resp = wp_remote_get($site_url, ['timeout' => 4, 'redirection' => 3]);
    $cache[$site_url] = is_wp_error($resp) ? '' : wp_remote_retrieve_body($resp);
    return $cache[$site_url];
}

function crf_v23_fetch_feed($site_url) {
    // 第一步：直接尝试传入的 URL——可能本身就是 feed 地址
    $direct = fetch_feed($site_url);
    if (!is_wp_error($direct) && $direct->get_item_quantity() > 0) {
        return $direct;
    }

    // 第二步：URL 路径含 feed/rss/atom/xml 关键词，说明已是 feed 链接但内容为空或解析失败，不再继续
    $path = strtolower(parse_url($site_url, PHP_URL_PATH) ?? '');
    if (preg_match('#(feed|rss|atom|\.xml)#', $path)) {
        return new WP_Error('no_feed', 'No valid feed found for ' . $site_url);
    }

    // 第三步：普通网站首页——先从 HTML <link> 标签自动发现（复用缓存）
    $candidates   = [];
    $body         = crf_v23_get_homepage_html($site_url);
    $content_type = '';
    if (!empty($body)) {
        // Content-Type 直接就是 feed
        $resp = wp_remote_get($site_url, ['timeout' => 1, 'redirection' => 0]);
        if (!is_wp_error($resp)) {
            $content_type = wp_remote_retrieve_header($resp, 'content-type');
        }
        if (preg_match('#application/(rss|atom)\+xml#i', $content_type) && !is_wp_error($direct)) {
            return $direct;
        }

        // 从 <link type="application/rss+xml"> 提取
        if (preg_match_all('/<link[^>]+type=["\']application\/(rss|atom)\+xml["\'][^>]*>/i', $body, $matches)) {
            foreach ($matches[0] as $tag) {
                if (preg_match('/href=["\']([^"\']+)["\']/', $tag, $m)) {
                    $href = $m[1];
                    if (strpos($href, 'http') !== 0) {
                        $parsed = parse_url($site_url);
                        $href   = $parsed['scheme'] . '://' . $parsed['host'] . '/' . ltrim($href, '/');
                    }
                    $candidates[] = $href;
                }
            }
        }
    }

    // 第四步：常见 feed 路径兜底
    $base = trailingslashit($site_url);
    $candidates = array_merge($candidates, [
        $base . 'feed/',
        $base . 'feed',
        $base . 'rss',
        $base . 'rss.xml',
        $base . 'atom.xml',
        $base . 'index.xml',
        $base . 'feed.xml',
    ]);

    foreach (array_unique($candidates) as $url) {
        $feed = fetch_feed($url);
        if (!is_wp_error($feed) && $feed->get_item_quantity() > 0) {
            return $feed;
        }
    }
    return new WP_Error('no_feed', 'No valid feed found for ' . $site_url);
}

// --- 2c. 首页 HTML 解析兜底 ---
function crf_v23_resolve_url($href, $base_url) {
    if (strpos($href, 'http') === 0) return $href;
    $p = parse_url($base_url);
    $origin = $p['scheme'] . '://' . $p['host'];
    if (strpos($href, '//') === 0) return $p['scheme'] . ':' . $href;
    if (strpos($href, '/') === 0) return $origin . $href;
    return rtrim($base_url, '/') . '/' . $href;
}

function crf_v23_scrape_homepage($site_url, $limit) {
    $html = crf_v23_get_homepage_html($site_url);
    if (empty($html)) return ['site_name' => '', 'posts' => []];

    $site_name = '';
    $posts     = [];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // 提取站点名称（<title> 尾部）
    $title_nodes = $dom->getElementsByTagName('title');
    if ($title_nodes->length > 0) {
        $raw = trim($title_nodes->item(0)->textContent);
        // "文章标题 | 站点名" 取竖线后面
        $site_name = preg_match('/[|\-–—]\s*(.+)$/', $raw, $m) ? trim($m[1]) : $raw;
    }

    // 策略1：<article> 内找标题链接
    $articles = $xpath->query('//article');
    if ($articles->length > 0) {
        foreach ($articles as $art) {
            if (count($posts) >= $limit) break;
            $links = $xpath->query('.//h1/a | .//h2/a | .//h3/a | .//h4/a', $art);
            if ($links->length === 0) {
                $links = $xpath->query('.//a[string-length(normalize-space(.)) > 8]', $art);
            }
            if ($links->length === 0) continue;
            $a     = $links->item(0);
            $title = trim($a->textContent);
            $href  = $a->getAttribute('href');
            if (empty($title) || empty($href) || $href === '#') continue;
            $href  = crf_v23_resolve_url($href, $site_url);
            $paras = $xpath->query('.//p[string-length(normalize-space(.)) > 10]', $art);
            $excerpt = $paras->length > 0 ? crf_v23_trim_excerpt(trim($paras->item(0)->textContent)) : '';
            $posts[] = ['title' => esc_html($title), 'link' => esc_url($href), 'excerpt' => $excerpt];
        }
    }

    // 策略2：全局 <h2/h3> 内链接（无 <article> 的简单博客/静态站）
    if (empty($posts)) {
        $skip = '#/(about|contact|home|tag|category|page|author|archive|search)/?(\?.*)?$#i';
        $links = $xpath->query('//h2/a | //h3/a | //h1/a');
        foreach ($links as $a) {
            if (count($posts) >= $limit) break;
            $title = trim($a->textContent);
            $href  = $a->getAttribute('href');
            if (empty($title) || strlen($title) < 4 || empty($href) || $href === '#') continue;
            $href = crf_v23_resolve_url($href, $site_url);
            if (preg_match($skip, $href)) continue;
            $posts[] = ['title' => esc_html($title), 'link' => esc_url($href), 'excerpt' => ''];
        }
    }

    return ['site_name' => esc_html($site_name), 'posts' => $posts];
}

// --- 2. 核心抓取逻辑 ---
function crf_v23_core_logic() {
    global $wpdb;
    update_option('crf_v23_status', 'running');

    $limit         = max(1, intval(crf_get_opt('commenter_limit', 10)));
    $rss_limit     = max(1, intval(crf_get_opt('rss_post_limit', 2)));
    $whitelist_mode = intval(crf_get_opt('whitelist_mode', 0));
    $whitelist     = array_filter(array_map('trim', explode("\n", crf_get_opt('whitelist', ''))));
    $blacklist     = array_filter(array_map('trim', explode("\n", crf_get_opt('blacklist', ''))));

    $admin_ids = get_users(['role' => 'Administrator', 'fields' => 'ID']);
    $exclude   = !empty($admin_ids) ? implode(',', array_map('intval', $admin_ids)) : '0';

    $results = $wpdb->get_results($wpdb->prepare("
        SELECT c.comment_author, c.comment_author_url, c.comment_post_ID
        FROM $wpdb->comments c
        INNER JOIN (
            SELECT MAX(comment_ID) as max_id FROM $wpdb->comments
            WHERE comment_approved = '1' AND comment_author_url != '' AND comment_type = 'comment' AND user_id NOT IN ($exclude)
            GROUP BY comment_author_url
        ) as latest ON c.comment_ID = latest.max_id
        ORDER BY c.comment_date DESC LIMIT %d
    ", $limit * 3));

    $final_data = [];
    if ($results) {
        require_once(ABSPATH . WPINC . '/feed.php');
        foreach ($results as $commenter) {
            if (count($final_data) >= $limit) break;

            $site_url = esc_url($commenter->comment_author_url);
            $domain   = strtolower(parse_url($site_url, PHP_URL_HOST));

            // 黑白名单过滤
            if ($whitelist_mode && !empty($whitelist)) {
                $allowed = false;
                foreach ($whitelist as $w) {
                    if ($domain && strpos($domain, strtolower(trim($w))) !== false) { $allowed = true; break; }
                }
                if (!$allowed) continue;
            } else {
                foreach ($blacklist as $b) {
                    if ($domain && strpos($domain, strtolower(trim($b))) !== false) continue 2;
                }
            }

            add_filter('http_request_timeout', function(){ return 3; });
            add_filter('wp_feed_cache_transient_lifetime', function(){ return 0; });

            $rss = crf_v23_fetch_feed($site_url);

            remove_all_filters('http_request_timeout');
            remove_all_filters('wp_feed_cache_transient_lifetime');

            $posts = [];
            $remote_site_name = '';

            if (!is_wp_error($rss)) {
                $remote_site_name = $rss->get_title();
                foreach ($rss->get_items(0, $rss_limit) as $item) {
                    $posts[] = [
                        'title'   => $item->get_title(),
                        'link'    => $item->get_permalink(),
                        'excerpt' => crf_v23_trim_excerpt(strip_tags($item->get_description()))
                    ];
                }
            }

            // 第五步：RSS 全部失败则解析首页 HTML 兜底
            if (empty($posts)) {
                $scraped = crf_v23_scrape_homepage($site_url, $rss_limit);
                $posts   = $scraped['posts'];
                if (empty($remote_site_name) && !empty($scraped['site_name'])) {
                    $remote_site_name = $scraped['site_name'];
                }
            }

            $final_data[] = [
                'author'     => $commenter->comment_author,
                'site_name'  => !empty($remote_site_name) ? esc_html($remote_site_name) : '',
                'site_url'   => $site_url,
                'local_post' => [
                    'title' => get_the_title($commenter->comment_post_ID),
                    'url'   => get_permalink($commenter->comment_post_ID)
                ],
                'posts' => $posts
            ];
        }
    }

    update_option('crf_v23_cache', $final_data);
    update_option('crf_v23_last_updated', current_time('mysql'));
    update_option('crf_v23_status', 'idle');
    die();
}

// --- 3. 渲染展示 ---
function crf_v23_render($atts = []) {
    $atts  = shortcode_atts(['limit' => 0, 'rss_limit' => 0], $atts);
    $data  = get_option('crf_v23_cache');
    if (!$data) return '<p style="font-size:14px;color:#999;text-align:center;">动态加载中...</p>';

    $limit     = intval($atts['limit']) > 0 ? intval($atts['limit']) : intval(crf_get_opt('commenter_limit', 10));
    $rss_limit = intval($atts['rss_limit']) > 0 ? intval($atts['rss_limit']) : intval(crf_get_opt('rss_post_limit', 2));
    $data      = array_slice($data, 0, $limit);

    $html = '<div class="crf-v23-container" style="line-height:1.4;">';
    foreach ($data as $item) {
        $html .= '<div class="crf-author-block" style="margin:5px 10px 10px 10px;padding:4px 0 6px 12px;border-bottom:1px solid #999;">';

        $display_name = !empty($item['site_name']) ? $item['author'] . ' @ ' . $item['site_name'] : $item['author'];
        $html .= '<div style="margin-bottom:4px;font-size:14px;">';
        $html .= '<strong><a href="' . esc_url($item['site_url']) . '" target="_blank" style="text-decoration:none;">👤 ' . esc_html($display_name) . '</a></strong>';
        $html .= '</div>';

        if (!empty($item['local_post']['url'])) {
            $html .= '<div style="font-size:14px;margin-bottom:6px;">来自文章：<a href="' . esc_url($item['local_post']['url']) . '" style="text-decoration:none;">《' . esc_html($item['local_post']['title']) . '》</a></div>';
        }

        $posts = array_slice($item['posts'], 0, $rss_limit);
        foreach ($posts as $post) {
            $html .= '<div style="margin-top:6px;line-height:1.3;">';
            $html .= '<a href="' . esc_url($post['link']) . '" target="_blank" style="text-decoration:none;font-weight:500;font-size:14px;">🔗 ' . esc_html($post['title']) . '</a>';
            $html .= '<div style="font-size:14px;margin-top:2px;">' . esc_html($post['excerpt']) . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}
add_shortcode('commenter_rss', 'crf_v23_render');

// --- 4. 评论区内联显示 ---
add_filter('comment_text', 'crf_v23_inline_comment', 20, 2);
function crf_v23_inline_comment($text, $comment) {
    if (!intval(crf_get_opt('inline_comments', 0))) return $text;
    if (empty($comment->comment_author_url)) return $text;

    $rss_limit = max(1, intval(crf_get_opt('rss_post_limit', 2)));
    $cache     = get_option('crf_v23_cache', []);
    $site_url  = esc_url($comment->comment_author_url);
    $posts     = [];

    foreach ($cache as $item) {
        if (rtrim($item['site_url'], '/') === rtrim($site_url, '/')) {
            $posts = array_slice($item['posts'], 0, $rss_limit);
            break;
        }
    }

    if (empty($posts)) return $text;

    $inline = '<div class="crf-inline" style="margin-top:8px;padding:6px 10px;background:#f9f9f9;border-left:3px solid #ccc;font-size:13px;">';
    $inline .= '<span style="color:#888;">TA 的近期文章：</span>';
    foreach ($posts as $post) {
        $inline .= '<div style="margin-top:4px;"><a href="' . esc_url($post['link']) . '" target="_blank" style="text-decoration:none;">🔗 ' . esc_html($post['title']) . '</a></div>';
    }
    $inline .= '</div>';

    return $text . $inline;
}

// --- 5. 小工具（兼容经典 + 古腾堡） ---
class CRF_V23_Widget extends WP_Widget {
    public function __construct() { parent::__construct('crf_v23_widget', '评论者最新动态(v2.0)'); }
    public function widget($args, $inst) {
        echo $args['before_widget'];
        if (!empty($inst['title'])) echo $args['before_title'] . $inst['title'] . $args['after_title'];
        echo crf_v23_render();
        echo $args['after_widget'];
    }
    public function form($inst) {
        $t = !empty($inst['title']) ? $inst['title'] : '读者动态';
        echo '<p><label>标题：</label><input class="widefat" name="' . $this->get_field_name('title') . '" type="text" value="' . esc_attr($t) . '"></p>';
    }
}
add_action('widgets_init', function(){
    register_widget('CRF_V23_Widget');
});

// --- 5b. 古腾堡 Block ---
add_action('init', 'crf_v23_register_block');
function crf_v23_register_block() {
    if (!function_exists('register_block_type')) return;
    register_block_type('crf/commenter-rss', [
        'attributes' => [
            'limit'     => ['type' => 'number', 'default' => 0],
            'rss_limit' => ['type' => 'number', 'default' => 0],
        ],
        'render_callback' => 'crf_v23_block_render',
        'editor_script'   => 'crf-v23-block-editor',
    ]);
    wp_register_script(
        'crf-v23-block-editor',
        '',
        ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components'],
        null
    );
    wp_add_inline_script('crf-v23-block-editor', crf_v23_block_js());
}

function crf_v23_block_render($atts) {
    return crf_v23_render($atts);
}

function crf_v23_block_js() {
    $default_limit     = intval(crf_get_opt('commenter_limit', 10));
    $default_rss_limit = intval(crf_get_opt('rss_post_limit', 2));
    return <<<JS
(function(blocks, element, blockEditor, components) {
    var el = element.createElement;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var RangeControl = components.RangeControl;
    blocks.registerBlockType('crf/commenter-rss', {
        title: '评论者最新动态',
        icon: 'rss',
        category: 'widgets',
        attributes: {
            limit:     { type: 'number', default: {$default_limit} },
            rss_limit: { type: 'number', default: {$default_rss_limit} }
        },
        edit: function(props) {
            var attrs = props.attributes;
            return [
                el(InspectorControls, { key: 'inspector' },
                    el(PanelBody, { title: '显示设置', initialOpen: true },
                        el(RangeControl, {
                            label: '评论者数量',
                            value: attrs.limit,
                            min: 1, max: 50,
                            onChange: function(v){ props.setAttributes({ limit: v }); }
                        }),
                        el(RangeControl, {
                            label: '每人RSS文章数',
                            value: attrs.rss_limit,
                            min: 1, max: 10,
                            onChange: function(v){ props.setAttributes({ rss_limit: v }); }
                        })
                    )
                ),
                el('div', { key: 'preview', style: { padding: '12px', background: '#f0f0f0', borderRadius: '4px' } },
                    el('span', { style: { color: '#555' } }, '📡 评论者最新动态（前台渲染）'),
                    el('div', { style: { fontSize: '12px', color: '#999', marginTop: '4px' } },
                        '评论者数量: ' + attrs.limit + '，每人文章数: ' + attrs.rss_limit
                    )
                )
            ];
        },
        save: function() { return null; }
    });
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components));
JS;
}

// --- 6. 后台管理页面 ---
add_action('admin_menu', function() {
    add_options_page('评论者RSS设置', '评论者RSS设置', 'manage_options', 'crf-v23-settings', 'crf_v23_page');
});

function crf_v23_page() {
    if (!current_user_can('manage_options')) return;

    // 保存设置
    if (isset($_POST['crf_save_settings']) && check_admin_referer('crf_v23_nonce')) {
        update_option('crf_v23_commenter_limit',  max(1, intval($_POST['commenter_limit'])));
        update_option('crf_v23_rss_post_limit',   max(1, intval($_POST['rss_post_limit'])));
        update_option('crf_v23_excerpt_length',   max(10, intval($_POST['excerpt_length'])));
        update_option('crf_v23_whitelist_mode',   intval($_POST['whitelist_mode']));
        update_option('crf_v23_whitelist',        sanitize_textarea_field($_POST['whitelist']));
        update_option('crf_v23_blacklist',        sanitize_textarea_field($_POST['blacklist']));
        update_option('crf_v23_inline_comments',  isset($_POST['inline_comments']) ? 1 : 0);
        echo '<div class="updated"><p>✅ 设置已保存。</p></div>';
    }

    if (isset($_POST['trigger_refresh']) && check_admin_referer('crf_v23_nonce')) {
        crf_v23_start_async_update();
        echo '<div class="updated"><p>✅ 异步刷新已启动，请稍后刷新页面查看预览。</p></div>';
    }

    $status         = get_option('crf_v23_status', 'idle');
    $last           = get_option('crf_v23_last_updated', '暂无记录');
    $commenter_limit = intval(crf_get_opt('commenter_limit', 10));
    $rss_post_limit  = intval(crf_get_opt('rss_post_limit', 2));
    $excerpt_length  = intval(crf_get_opt('excerpt_length', 50));
    $whitelist_mode  = intval(crf_get_opt('whitelist_mode', 0));
    $whitelist       = esc_textarea(crf_get_opt('whitelist', ''));
    $blacklist       = esc_textarea(crf_get_opt('blacklist', ''));
    $inline          = intval(crf_get_opt('inline_comments', 0));
    ?>
    <div class="wrap">
        <h1>评论者 RSS 设置 (v2.0)</h1>
        <form method="post">
            <?php wp_nonce_field('crf_v23_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>显示评论者数量</th>
                    <td><input type="number" name="commenter_limit" value="<?php echo $commenter_limit; ?>" min="1" max="50" style="width:80px;">
                    <p class="description">短代码可用 limit 参数覆盖，如 [commenter_rss limit="5"]</p></td>
                </tr>
                <tr>
                    <th>每人显示RSS文章数</th>
                    <td><input type="number" name="rss_post_limit" value="<?php echo $rss_post_limit; ?>" min="1" max="10" style="width:80px;">
                    <p class="description">短代码可用 rss_limit 参数覆盖，如 [commenter_rss rss_limit="3"]</p></td>
                </tr>
                <tr>
                    <th>摘要显示字数</th>
                    <td><input type="number" name="excerpt_length" value="<?php echo $excerpt_length; ?>" min="10" max="300" style="width:80px;"> 字
                    <p class="description">中文字符计1字，英文字母每2个计1字，默认50字。</p></td>
                </tr>
                <tr>
                    <th>名单模式</th>
                    <td>
                        <select name="whitelist_mode">
                            <option value="0" <?php selected($whitelist_mode, 0); ?>>黑名单模式（排除以下域名）</option>
                            <option value="1" <?php selected($whitelist_mode, 1); ?>>白名单模式（仅显示以下域名）</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>白名单域名</th>
                    <td><textarea name="whitelist" rows="4" style="width:300px;"><?php echo $whitelist; ?></textarea>
                    <p class="description">每行一个域名，如 example.com</p></td>
                </tr>
                <tr>
                    <th>黑名单域名</th>
                    <td><textarea name="blacklist" rows="4" style="width:300px;"><?php echo $blacklist; ?></textarea>
                    <p class="description">每行一个域名，如 spam.com</p></td>
                </tr>
                <tr>
                    <th>评论区内联显示</th>
                    <td><label><input type="checkbox" name="inline_comments" value="1" <?php checked($inline, 1); ?>> 在每条评论下方显示该评论者的近期文章链接</label></td>
                </tr>
            </table>
            <p>
                <input type="submit" name="crf_save_settings" class="button button-primary" value="保存设置">
                &nbsp;
                <input type="submit" name="trigger_refresh" class="button" value="立即发起异步抓取" <?php disabled($status, 'running'); ?>>
            </p>
        </form>
        <p><strong>上次更新：</strong> <code><?php echo esc_html($last); ?></code> &nbsp; 状态：<code><?php echo esc_html($status); ?></code></p>
        <p>短代码：<code>[commenter_rss]</code> &nbsp;|&nbsp; <code>[commenter_rss limit="5" rss_limit="3"]</code></p>
        <h3>效果预览：</h3>
        <div style="background:#fff;padding:15px;border:1px solid #ccc;max-width:420px;"><?php echo crf_v23_render(); ?></div>
    </div>
    <?php
}
