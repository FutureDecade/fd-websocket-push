<?php
/**
 * Share Settings event handler for FD WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Share_Settings_Event_Handler {
    
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
     * 分享设置相关的option名称
     */
    private $share_option_name = 'fd_share_settings';
    
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
        // 监听分享设置option更新
        add_action( "update_option_{$this->share_option_name}", array( $this, 'handle_share_settings_update' ), 10, 3 );
        
        // 监听自定义器保存事件（可能包含分享设置）
        add_action( 'customize_save_after', array( $this, 'handle_customizer_save' ) );
        
        // 监听主题选项保存（如果分享设置存储在主题选项中）
        add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), array( $this, 'handle_theme_mods_update' ), 10, 2 );
    }
    
    /**
     * Handle share settings update events
     *
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @param string $option_name 选项名称
     */
    public function handle_share_settings_update( $old_value, $new_value, $option_name ) {
        // 只有当值真正改变时才处理
        if ( $old_value === $new_value ) {
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Processing share settings update for option: ' . $option_name );
        
        // 分析变更的具体内容
        $changes = $this->analyze_share_settings_changes( $old_value, $new_value );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_share_settings_updated_event( $changes, $old_value, $new_value );
        
        // 失效缓存
        $this->invalidate_share_settings_caches();
    }
    
    /**
     * Handle customizer save events
     */
    public function handle_customizer_save() {
        FD_WebSocket_Push_Helper::log( 'Processing customizer save - checking for share settings changes' );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_share_settings_updated_event( 
            array( 'type' => 'customizer_save' ), 
            null, 
            null 
        );
        
        // 失效缓存
        $this->invalidate_share_settings_caches();
    }
    
    /**
     * Handle theme mods update events
     *
     * @param mixed $old_value 旧的主题模式
     * @param mixed $new_value 新的主题模式
     */
    public function handle_theme_mods_update( $old_value, $new_value ) {
        // 检查是否有分享设置相关的变更
        $share_settings_changed = false;
        
        // 检查是否包含分享相关的设置
        $share_keys = array( 'share_enabled', 'share_platforms', 'share_wechat_appid', 'share_wechat_appsecret' );
        
        foreach ( $share_keys as $key ) {
            $old_val = isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
            $new_val = isset( $new_value[ $key ] ) ? $new_value[ $key ] : null;
            
            if ( $old_val !== $new_val ) {
                $share_settings_changed = true;
                break;
            }
        }
        
        if ( $share_settings_changed ) {
            FD_WebSocket_Push_Helper::log( 'Processing theme mods update with share settings changes' );
            
            // 发送WebSocket事件
            $this->websocket_pusher->send_share_settings_updated_event( 
                array( 'type' => 'theme_mods_update' ), 
                $old_value, 
                $new_value 
            );
            
            // 失效缓存
            $this->invalidate_share_settings_caches();
        }
    }
    
    /**
     * Analyze changes in share settings
     *
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @return array 变更分析结果
     */
    private function analyze_share_settings_changes( $old_value, $new_value ) {
        $changes = array();
        
        // 确保值是数组
        $old_value = is_array( $old_value ) ? $old_value : array();
        $new_value = is_array( $new_value ) ? $new_value : array();
        
        // 检查启用状态变更
        $old_enabled = isset( $old_value['share_enabled'] ) ? $old_value['share_enabled'] : false;
        $new_enabled = isset( $new_value['share_enabled'] ) ? $new_value['share_enabled'] : false;
        
        if ( $old_enabled !== $new_enabled ) {
            $changes['enabled_changed'] = true;
            $changes['is_enabled'] = $new_enabled;
        }
        
        // 检查平台变更
        $old_platforms = isset( $old_value['share_platforms'] ) ? $old_value['share_platforms'] : array();
        $new_platforms = isset( $new_value['share_platforms'] ) ? $new_value['share_platforms'] : array();
        
        if ( $old_platforms !== $new_platforms ) {
            $changes['platforms_changed'] = true;
            $changes['platforms'] = $new_platforms;
        }
        
        // 检查微信配置变更
        $wechat_keys = array( 'share_wechat_appid', 'share_wechat_appsecret', 'share_wechat_desc' );
        foreach ( $wechat_keys as $key ) {
            $old_val = isset( $old_value[ $key ] ) ? $old_value[ $key ] : '';
            $new_val = isset( $new_value[ $key ] ) ? $new_value[ $key ] : '';
            
            if ( $old_val !== $new_val ) {
                $changes['wechat_config_changed'] = true;
                break;
            }
        }
        
        // 检查海报设置变更
        $poster_keys = array( 'share_poster_logo', 'share_poster_default_thumb' );
        foreach ( $poster_keys as $key ) {
            $old_val = isset( $old_value[ $key ] ) ? $old_value[ $key ] : '';
            $new_val = isset( $new_value[ $key ] ) ? $new_value[ $key ] : '';
            
            if ( $old_val !== $new_val ) {
                $changes['poster_config_changed'] = true;
                break;
            }
        }
        
        // 设置变更类型
        if ( ! empty( $changes ) ) {
            $changes['type'] = 'settings_update';
        }
        
        return $changes;
    }
    
    /**
     * Invalidate share settings related caches
     */
    private function invalidate_share_settings_caches() {
        if ( ! FD_WebSocket_Push_Helper::is_revalidation_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'REVALIDATE_SECRET not defined, skipping share settings cache invalidation' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Invalidating share settings caches' );
        
        // 失效Next.js分享设置缓存使用 'share-settings' 标签
        $this->cache_invalidator->revalidate_tag( 'share-settings' );
        
        FD_WebSocket_Push_Helper::log( 'Share settings cache invalidation completed' );
    }
}
