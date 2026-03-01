<?php
/*
Plugin Name: Commenter RSS Linker
Description: 1.0 1.读取最新10位评论者（排除博主）；2.抓取其网站RSS前2篇；3.关联其在本站评论的文章链接；4.极致紧凑样式；5.自动定时刷新+评论后即时刷新；6.异步抓取。
Version: 1.0
Author: <a href="fxpai.com" target="_blank">fxpai.com</a> | <a href="https://fxpai.com/article/wordpress-commenter-rss-linker" target="_blank">查看详情</a>
*/

if (!defined('ABSPATH')) exit;

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
    wp_remote_post(admin_url('admin-ajax.php'), array(
        'body'      => array('action' => 'crf_v23_do_update'),
        'timeout'   => 0.1,
        'blocking'  => false,
        'sslverify' => false,
    ));
}

add_action('wp_ajax_crf_v23_do_update', 'crf_v23_core_logic');
add_action('wp_ajax_nopriv_crf_v23_do_update', 'crf_v23_core_logic');

// --- 2. 核心抓取逻辑 ---
function crf_v23_core_logic() {
    global $wpdb;
    update_option('crf_v23_status', 'running');

    $admin_ids = get_users(array('role' => 'Administrator', 'fields' => 'ID'));
    $exclude = !empty($admin_ids) ? implode(',', array_map('intval', $admin_ids)) : '0';

    $results = $wpdb->get_results("
        SELECT c.comment_author, c.comment_author_url, c.comment_post_ID
        FROM $wpdb->comments c
        INNER JOIN (
            SELECT MAX(comment_ID) as max_id FROM $wpdb->comments
            WHERE comment_approved = '1' AND comment_author_url != '' AND comment_type = 'comment' AND user_id NOT IN ($exclude)
            GROUP BY comment_author_url
        ) as latest ON c.comment_ID = latest.max_id
        ORDER BY c.comment_date DESC LIMIT 10
    ");

    $final_data = [];
    if ($results) {
        require_once(ABSPATH . WPINC . '/feed.php');
        foreach ($results as $commenter) {
            $site_url = esc_url($commenter->comment_author_url);
            
            add_filter('http_request_timeout', function(){ return 2; });
            add_filter('wp_feed_cache_transient_lifetime', function(){ return 0; }); 
            
            $rss = fetch_feed(trailingslashit($site_url) . 'feed/');
            if (is_wp_error($rss)) $rss = fetch_feed($site_url);
            
            remove_all_filters('http_request_timeout');
            remove_all_filters('wp_feed_cache_transient_lifetime');

            $posts = [];
            $remote_site_name = '';

            if (!is_wp_error($rss)) {
                $remote_site_name = $rss->get_title();
                foreach ($rss->get_items(0, 2) as $item) {
                    $posts[] = [
                        'title'   => $item->get_title(),
                        'link'    => $item->get_permalink(),
                        'excerpt' => wp_trim_words(strip_tags($item->get_description()), 23, ' »')
                    ];
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
                'posts'      => $posts
            ];
        }
    }
    
    update_option('crf_v23_cache', $final_data);
    update_option('crf_v23_last_updated', current_time('mysql'));
    update_option('crf_v23_status', 'idle');
    die();
}

// --- 3. 渲染展示 (样式已更新) ---
function crf_v23_render() {
    $data = get_option('crf_v23_cache');
    if (!$data) return '<p style="font-size:14px; color:#999;text-align:center;">动态加载中...</p>';

    $html = '<div class="crf-v23-container" style="line-height: 1.4; ">';
    foreach ($data as $item) {
        $html .= '<div class="crf-author-block" style="margin: 5px 10px 10px 10px; padding: 4px 0 6px 12px; border-bottom: 1px solid #999;">';
        
        // 作者 @ 网站名 (14px)
        $display_name = !empty($item['site_name']) ? $item['author'] . ' @ ' . $item['site_name'] : $item['author'];
        $html .= '<div style="margin-bottom: 4px; font-size: 14px;">';
        $html .= '<strong><a href="'.$item['site_url'].'" target="_blank" style="text-decoration:none; ">👤 '.$display_name.'</a></strong>';
        $html .= '</div>';

        // 评于本站 (14px)
        if (!empty($item['local_post']['url'])) {
            $html .= '<div style="font-size: 14px; margin-bottom: 6px;">来自文章：<a href="'.$item['local_post']['url'].'" style="text-decoration:none;">《'.$item['local_post']['title'].'》</a></div>';
        }

        // RSS 文章列表 (14px)
        if (!empty($item['posts'])) {
            foreach ($item['posts'] as $post) {
                $html .= '<div style="margin-top: 6px; line-height: 1.3;">';
                // 重点修改：黑色字体
                $html .= '<a href="'.$post['link'].'" target="_blank" style=" text-decoration: none; font-weight: 500; font-size: 14px;">🔗 '.$post['title'].'</a>';
                $html .= '<div style="font-size: 14px;  margin-top: 2px;">'.$post['excerpt'].'</div>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}
add_shortcode('commenter_rss', 'crf_v23_render');

// --- 4. 小工具 ---
class CRF_V23_Widget extends WP_Widget {
    public function __construct() { parent::__construct('crf_v23_widget', '评论者最新动态(v2.3)'); }
    public function widget($args, $inst) {
        echo $args['before_widget'] . (!empty($inst['title']) ? $args['before_title'].$inst['title'].$args['after_title'] : '') . crf_v23_render() . $args['after_widget'];
    }
    public function form($inst) { 
        $t = !empty($inst['title']) ? $inst['title'] : '读者动态';
        echo '<p><label>标题：</label><input class="widefat" name="'.$this->get_field_name('title').'" type="text" value="'.esc_attr($t).'"></p>';
    }
}
add_action('widgets_init', function(){ register_widget('CRF_V23_Widget'); });

// --- 5. 后台管理页面 ---
add_action('admin_menu', function() {
    add_options_page('评论者RSS设置', '评论者RSS设置', 'manage_options', 'crf-v23-settings', 'crf_v23_page');
});

function crf_v23_page() {
    if (isset($_POST['trigger_refresh'])) { 
        crf_v23_start_async_update(); 
        echo '<div class="updated"><p>✅ 异步刷新已启动！请稍后刷新查看预览。</p></div>'; 
    }
    $status = get_option('crf_v23_status');
    $last = get_option('crf_v23_last_updated', '暂无记录');
    ?>
    <div class="wrap">
        <h1>评论者 RSS 设置 (v1.0)</h1>
        <div class="card" style="max-width: 600px; padding: 15px; background: #fff; border: 1px solid #ccd0d4;">
            <p><strong>上次更新：</strong> <code><?php echo $last; ?></code></p>
            <form method="post">
                <input type="submit" name="trigger_refresh" class="button button-primary" value="立即发起异步抓取" <?php disabled($status, 'running'); ?>>
            </form>
            <p><strong>支持短代码显示（[commenter_rss]）以及widget小组件显示。</p>
            <p><strong>其他问题可至<a href="https://fxpai.com" target="_blank">非学·派' | fxpai.com</a>反馈</p>
        </div>
        <h3>效果预览：</h3>
        <div style="background:#fff; padding:15px; border:1px solid #ccc; max-width:400px;"><?php echo crf_v23_render(); ?></div>
    </div>
    <?php

}
