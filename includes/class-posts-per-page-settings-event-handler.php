<?php
/**
 * Posts Per Page Settings event handler for Lingcoo WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Posts_Per_Page_Settings_Event_Handler {
    
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
     * 分页设置相关的option名称
     */
    private $posts_per_page_option_names = [
        'posts_per_page', // WordPress默认的每页文章数设置
        'fd_posts_per_page', // 自定义的每页文章数设置
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
        // 监听分页设置相关的option更新
        foreach ( $this->posts_per_page_option_names as $option_name ) {
            add_action( "update_option_{$option_name}", array( $this, 'handle_posts_per_page_setting_update' ), 10, 3 );
        }
        
        // 监听自定义器保存事件（可能包含分页设置）
        add_action( 'customize_save_after', array( $this, 'handle_customizer_save' ) );
        
        // 监听主题选项保存（如果分页设置存储在主题选项中）
        add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), array( $this, 'handle_theme_mods_update' ), 10, 2 );
        
        // 监听WordPress阅读设置更新
        add_action( 'update_option_blog_public', array( $this, 'handle_reading_settings_update' ), 10, 3 );
    }
    
    /**
     * Handle posts per page setting update events
     *
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @param string $option_name 选项名称
     */
    public function handle_posts_per_page_setting_update( $old_value, $new_value, $option_name ) {
        // 只有当值真正改变时才处理
        if ( $old_value === $new_value ) {
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Processing posts per page setting update for option: ' . $option_name );
        
        // 验证新值是否有效
        $new_posts_per_page = intval( $new_value );
        $old_posts_per_page = intval( $old_value );
        
        if ( $new_posts_per_page < 1 ) {
            $new_posts_per_page = 12; // 使用默认值
        }
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_posts_per_page_settings_updated_event( 
            $option_name, 
            $old_posts_per_page, 
            $new_posts_per_page 
        );
        
        // 失效缓存
        $this->invalidate_posts_per_page_settings_caches();
    }
    
    /**
     * Handle customizer save events
     */
    public function handle_customizer_save() {
        FD_WebSocket_Push_Helper::log( 'Processing customizer save - checking for posts per page settings changes' );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_posts_per_page_settings_updated_event( 
            'customizer', 
            null, 
            null 
        );
        
        // 失效缓存
        $this->invalidate_posts_per_page_settings_caches();
    }
    
    /**
     * Handle theme mods update events
     *
     * @param mixed $old_value 旧的主题模式
     * @param mixed $new_value 新的主题模式
     */
    public function handle_theme_mods_update( $old_value, $new_value ) {
        // 检查是否有分页设置相关的变更
        $posts_per_page_changed = false;
        
        // 检查是否包含分页相关的设置
        $posts_per_page_keys = array( 'posts_per_page', 'archive_posts_per_page', 'category_posts_per_page' );
        
        foreach ( $posts_per_page_keys as $key ) {
            $old_val = isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
            $new_val = isset( $new_value[ $key ] ) ? $new_value[ $key ] : null;
            
            if ( $old_val !== $new_val ) {
                $posts_per_page_changed = true;
                break;
            }
        }
        
        if ( $posts_per_page_changed ) {
            FD_WebSocket_Push_Helper::log( 'Processing theme mods update with posts per page settings changes' );
            
            // 发送WebSocket事件
            $this->websocket_pusher->send_posts_per_page_settings_updated_event( 
                'theme_mods', 
                $old_value, 
                $new_value 
            );
            
            // 失效缓存
            $this->invalidate_posts_per_page_settings_caches();
        }
    }
    
    /**
     * Handle reading settings update events
     *
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @param string $option_name 选项名称
     */
    public function handle_reading_settings_update( $old_value, $new_value, $option_name ) {
        FD_WebSocket_Push_Helper::log( 'Processing reading settings update for option: ' . $option_name );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_posts_per_page_settings_updated_event( 
            $option_name, 
            $old_value, 
            $new_value 
        );
        
        // 失效缓存
        $this->invalidate_posts_per_page_settings_caches();
    }
    
    /**
     * Invalidate posts per page settings related caches
     */
    private function invalidate_posts_per_page_settings_caches() {
        if ( ! FD_WebSocket_Push_Helper::is_revalidation_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'REVALIDATE_SECRET not defined, skipping posts per page settings cache invalidation' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Invalidating posts per page settings caches' );
        
        // 失效Next.js分页设置缓存使用 'posts-per-page-settings' 标签
        $this->cache_invalidator->revalidate_tag( 'posts-per-page-settings' );
        
        FD_WebSocket_Push_Helper::log( 'Posts per page settings cache invalidation completed' );
    }
}
