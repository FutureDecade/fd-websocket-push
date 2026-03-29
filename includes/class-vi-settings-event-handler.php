<?php
/**
 * VI Settings event handler for FD WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_VI_Settings_Event_Handler {
    
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
     * VI设置相关的option名称
     */
    private $vi_option_names = [
        // 基础设置
        'fd_logo_url',
        'fd_logo_dark_url',
        'fd_favicon_url',
        
        // 颜色设置
        'fd_primary_color',
        'fd_secondary_color',
        'fd_background_color',
        'fd_dark_mode_enabled',
        'fd_dark_background_color',
        'fd_amber_color',
        'fd_rose_color',
        'fd_success_color',
        'fd_error_color',
        
        // 排版设置
        'fd_heading_font',
        'fd_body_font',
        'fd_base_font_size',
        'fd_line_height',
        'fd_spacing_unit',
        
        // UI设计令牌
        'fd_radius_small',
        'fd_radius_medium',
        'fd_radius_large',
        'fd_shadow_small',
        'fd_shadow_medium',
        'fd_shadow_large',
        
        // 搜索设置
        'fd_search_engine_type',
        'fd_meilisearch_api_url',
        'fd_meilisearch_api_key',
        'fd_meilisearch_index_name',
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
        // 监听所有VI设置相关的option更新
        foreach ( $this->vi_option_names as $option_name ) {
            add_action( "update_option_{$option_name}", array( $this, 'handle_vi_setting_update' ), 10, 3 );
        }
        
        // 监听自定义器保存事件（可能包含VI设置）
        add_action( 'customize_save_after', array( $this, 'handle_customizer_save' ) );
        
        // 监听主题选项保存（如果VI设置存储在主题选项中）
        add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), array( $this, 'handle_theme_mods_update' ), 10, 2 );
    }
    
    /**
     * Handle VI setting update events
     *
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @param string $option_name 选项名称
     */
    public function handle_vi_setting_update( $old_value, $new_value, $option_name ) {
        // 只有当值真正改变时才处理
        if ( $old_value === $new_value ) {
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Processing VI setting update for option: ' . $option_name );
        
        // 获取设置类型
        $setting_type = $this->get_setting_type( $option_name );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_vi_settings_updated_event( $option_name, $setting_type, $old_value, $new_value );
        
        // 失效缓存
        $this->invalidate_vi_settings_caches();
    }
    
    /**
     * Handle customizer save events
     */
    public function handle_customizer_save() {
        FD_WebSocket_Push_Helper::log( 'Processing customizer save - checking for VI settings changes' );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_vi_settings_updated_event( 'customizer', 'customizer_save', null, null );
        
        // 失效缓存
        $this->invalidate_vi_settings_caches();
    }
    
    /**
     * Handle theme mods update events
     *
     * @param mixed $old_value 旧的主题模式
     * @param mixed $new_value 新的主题模式
     */
    public function handle_theme_mods_update( $old_value, $new_value ) {
        // 检查是否有VI设置相关的变更
        $vi_settings_changed = false;
        
        foreach ( $this->vi_option_names as $option_name ) {
            $old_val = isset( $old_value[ $option_name ] ) ? $old_value[ $option_name ] : null;
            $new_val = isset( $new_value[ $option_name ] ) ? $new_value[ $option_name ] : null;
            
            if ( $old_val !== $new_val ) {
                $vi_settings_changed = true;
                break;
            }
        }
        
        if ( $vi_settings_changed ) {
            FD_WebSocket_Push_Helper::log( 'Processing theme mods update with VI settings changes' );
            
            // 发送WebSocket事件
            $this->websocket_pusher->send_vi_settings_updated_event( 'theme_mods', 'theme_mods_update', $old_value, $new_value );
            
            // 失效缓存
            $this->invalidate_vi_settings_caches();
        }
    }
    
    /**
     * Get setting type based on option name
     *
     * @param string $option_name 选项名称
     * @return string 设置类型
     */
    private function get_setting_type( $option_name ) {
        if ( strpos( $option_name, '_color' ) !== false ) {
            return 'color';
        } elseif ( strpos( $option_name, '_font' ) !== false ) {
            return 'typography';
        } elseif ( strpos( $option_name, '_radius' ) !== false || strpos( $option_name, '_shadow' ) !== false ) {
            return 'ui_token';
        } elseif ( strpos( $option_name, '_logo' ) !== false || strpos( $option_name, '_favicon' ) !== false ) {
            return 'branding';
        } elseif ( strpos( $option_name, 'search' ) !== false || strpos( $option_name, 'meilisearch' ) !== false ) {
            return 'search';
        } else {
            return 'general';
        }
    }
    
    /**
     * Invalidate VI settings related caches
     */
    private function invalidate_vi_settings_caches() {
        if ( ! FD_WebSocket_Push_Helper::is_revalidation_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'REVALIDATE_SECRET not defined, skipping VI settings cache invalidation' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Invalidating VI settings caches' );
        
        // 失效Next.js VI设置缓存使用 'vi-settings' 标签
        $this->cache_invalidator->revalidate_tag( 'vi-settings' );
        
        FD_WebSocket_Push_Helper::log( 'VI settings cache invalidation completed' );
    }
}
