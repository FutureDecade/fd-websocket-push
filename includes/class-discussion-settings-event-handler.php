<?php
/**
 * Discussion Settings event handler for Lingcoo WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Discussion_Settings_Event_Handler {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * WebSocket pusher instance
     */
    private $websocket_pusher;
    
    /**
     * Cache invalidator instance
     */
    private $cache_invalidator;
    
    /**
     * 讨论设置相关的option名称
     */
    private $discussion_option_names = [
        'default_comment_status', // WordPress默认评论状态
        'default_ping_status', // WordPress默认Ping状态
        'comment_moderation', // 评论审核设置
        'moderation_notify', // 审核通知设置
        'comments_notify', // 评论通知设置
        'require_name_email', // 要求姓名和邮箱
        'comment_registration', // 评论注册要求
        'close_comments_for_old_posts', // 关闭旧文章评论
        'close_comments_days_old', // 关闭评论的天数
        'thread_comments', // 嵌套评论
        'thread_comments_depth', // 嵌套评论深度
        'page_comments', // 分页评论
        'comments_per_page', // 每页评论数
        'default_comments_page', // 默认评论页
        'comment_order', // 评论排序
        'comment_whitelist', // 评论白名单
        'comment_max_links', // 评论最大链接数
        'moderation_keys', // 审核关键词
        'blacklist_keys', // 黑名单关键词
    ];
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->websocket_pusher = FD_WebSocket_Push_WebSocket_Pusher::get_instance();
        $this->cache_invalidator = FD_WebSocket_Push_Cache_Invalidator::get_instance();
        
        $this->register_hooks();
    }
    
    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // 监听讨论设置相关的option更新
        foreach ( $this->discussion_option_names as $option_name ) {
            add_action( "update_option_{$option_name}", array( $this, 'handle_discussion_setting_update' ), 10, 3 );
        }
        
        // 监听自定义器保存事件（可能包含讨论设置）
        add_action( 'customize_save_after', array( $this, 'handle_customizer_save' ) );
        
        // 监听主题选项保存（如果讨论设置存储在主题选项中）
        add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), array( $this, 'handle_theme_mods_update' ), 10, 2 );
        
        // 监听WordPress讨论设置页面保存
        add_action( 'update_option_discussion', array( $this, 'handle_discussion_options_update' ), 10, 3 );
    }
    
    /**
     * Handle discussion setting update events
     *
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @param string $option_name 选项名称
     */
    public function handle_discussion_setting_update( $old_value, $new_value, $option_name ) {
        // 只有当值真正改变时才处理
        if ( $old_value === $new_value ) {
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Processing discussion setting update for option: ' . $option_name );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_discussion_settings_updated_event( 
            $option_name, 
            $old_value, 
            $new_value 
        );
        
        // 失效缓存
        $this->invalidate_discussion_settings_caches();
    }
    
    /**
     * Handle customizer save events
     */
    public function handle_customizer_save() {
        FD_WebSocket_Push_Helper::log( 'Processing customizer save - checking for discussion settings changes' );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_discussion_settings_updated_event( 
            'customizer', 
            null, 
            null 
        );
        
        // 失效缓存
        $this->invalidate_discussion_settings_caches();
    }
    
    /**
     * Handle theme mods update events
     *
     * @param mixed $old_value 旧的主题模式
     * @param mixed $new_value 新的主题模式
     */
    public function handle_theme_mods_update( $old_value, $new_value ) {
        // 检查是否有讨论设置相关的变更
        $discussion_changed = false;
        
        // 检查是否包含讨论相关的设置
        $discussion_keys = array( 'comment_form_style', 'comment_display_style', 'discussion_layout' );
        
        foreach ( $discussion_keys as $key ) {
            $old_val = isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
            $new_val = isset( $new_value[ $key ] ) ? $new_value[ $key ] : null;
            
            if ( $old_val !== $new_val ) {
                $discussion_changed = true;
                break;
            }
        }
        
        if ( $discussion_changed ) {
            FD_WebSocket_Push_Helper::log( 'Processing theme mods update with discussion settings changes' );
            
            // 发送WebSocket事件
            $this->websocket_pusher->send_discussion_settings_updated_event( 
                'theme_mods', 
                $old_value, 
                $new_value 
            );
            
            // 失效缓存
            $this->invalidate_discussion_settings_caches();
        }
    }
    
    /**
     * Handle discussion options update events
     *
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @param string $option_name 选项名称
     */
    public function handle_discussion_options_update( $old_value, $new_value, $option_name ) {
        FD_WebSocket_Push_Helper::log( 'Processing discussion options update for option: ' . $option_name );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_discussion_settings_updated_event( 
            $option_name, 
            $old_value, 
            $new_value 
        );
        
        // 失效缓存
        $this->invalidate_discussion_settings_caches();
    }
    
    /**
     * Invalidate discussion settings related caches
     */
    private function invalidate_discussion_settings_caches() {
        if ( ! FD_WebSocket_Push_Helper::is_revalidation_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'REVALIDATE_SECRET not defined, skipping discussion settings cache invalidation' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Invalidating discussion settings caches' );
        
        // 失效Next.js讨论设置缓存使用 'discussion-settings' 标签
        $this->cache_invalidator->revalidate_tag( 'discussion-settings' );
        
        FD_WebSocket_Push_Helper::log( 'Discussion settings cache invalidation completed' );
    }
    
    /**
     * Get current discussion settings for debugging
     */
    public function get_current_discussion_settings() {
        $settings = array();
        
        foreach ( $this->discussion_option_names as $option_name ) {
            $settings[ $option_name ] = get_option( $option_name );
        }
        
        return $settings;
    }
    
    /**
     * Check if discussion settings are properly configured
     */
    public function is_discussion_settings_configured() {
        // 检查关键的讨论设置是否存在
        $key_settings = array(
            'default_comment_status',
            'default_ping_status',
            'comment_moderation'
        );
        
        foreach ( $key_settings as $setting ) {
            if ( get_option( $setting ) === false ) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get discussion settings summary for logging
     */
    public function get_discussion_settings_summary() {
        return array(
            'default_comment_status' => get_option( 'default_comment_status', 'open' ),
            'default_ping_status' => get_option( 'default_ping_status', 'open' ),
            'comment_moderation' => get_option( 'comment_moderation', '0' ) === '1',
            'require_name_email' => get_option( 'require_name_email', '0' ) === '1',
            'comment_registration' => get_option( 'comment_registration', '0' ) === '1',
            'comments_per_page' => intval( get_option( 'comments_per_page', 50 ) ),
            'thread_comments' => get_option( 'thread_comments', '0' ) === '1',
            'thread_comments_depth' => intval( get_option( 'thread_comments_depth', 5 ) ),
        );
    }
}
