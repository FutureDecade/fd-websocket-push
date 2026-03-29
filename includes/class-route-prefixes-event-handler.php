<?php
/**
 * Route Prefixes event handler for FD WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Route_Prefixes_Event_Handler {
    
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
     * 路由前缀相关的option名称
     */
    private $route_prefix_option_names = [
        'fd_post_prefix', // 文章页面URL前缀
        'fd_category_prefix', // 分类页面URL前缀
        'fd_tag_prefix', // 标签页面URL前缀
        'fd_category_index_route', // 分类索引页路径
        'fd_tag_index_route', // 标签索引页路径
        'fd_custom_type_prefix', // 自定义类型前缀
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
        // 监听路由前缀相关的option更新
        foreach ( $this->route_prefix_option_names as $option_name ) {
            add_action( "update_option_{$option_name}", array( $this, 'handle_route_prefix_setting_update' ), 10, 3 );
            FD_WebSocket_Push_Helper::log( 'Registered hook for option: ' . $option_name );
        }
        
        // 监听自定义器保存事件（可能包含路由前缀设置）
        add_action( 'customize_save_after', array( $this, 'handle_customizer_save' ) );

        // 监听主题选项保存（如果路由前缀设置存储在主题选项中）
        add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), array( $this, 'handle_theme_mods_update' ), 10, 2 );

        // 监听WordPress固定链接设置更新（可能影响路由前缀）
        add_action( 'update_option_permalink_structure', array( $this, 'handle_permalink_structure_update' ), 10, 3 );
        add_action( 'update_option_category_base', array( $this, 'handle_category_base_update' ), 10, 3 );
        add_action( 'update_option_tag_base', array( $this, 'handle_tag_base_update' ), 10, 3 );

        // 添加备用监听机制：监听所有选项更新
        add_action( 'updated_option', array( $this, 'handle_any_option_update' ), 10, 3 );

        // 监听设置页面保存事件
        add_action( 'admin_post_update', array( $this, 'handle_admin_post_update' ) );
        add_action( 'admin_notices', array( $this, 'check_route_prefix_changes' ) );
    }
    
    /**
     * Handle route prefix setting update events
     *
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @param string $option_name 选项名称
     */
    public function handle_route_prefix_setting_update( $old_value, $new_value, $option_name ) {
        // 对于分类前缀和标签前缀，需要特殊处理空值比较
        // 因为GraphQL中会将空字符串转换为null，而WordPress选项可能存储为空字符串
        $normalized_old_value = $this->normalize_prefix_value( $old_value, $option_name );
        $normalized_new_value = $this->normalize_prefix_value( $new_value, $option_name );

        // 只有当标准化后的值真正改变时才处理
        if ( $normalized_old_value === $normalized_new_value ) {
            FD_WebSocket_Push_Helper::log( 'Route prefix values are the same after normalization, skipping: ' . $option_name . ' (old: ' . var_export($old_value, true) . ', new: ' . var_export($new_value, true) . ')' );
            return;
        }

        FD_WebSocket_Push_Helper::log( 'Processing route prefix setting update for option: ' . $option_name . ' (old: ' . var_export($old_value, true) . ', new: ' . var_export($new_value, true) . ')' );

        // 发送WebSocket事件
        $this->websocket_pusher->send_route_prefixes_updated_event(
            $option_name,
            $old_value,
            $new_value
        );

        // 失效缓存
        $this->invalidate_route_prefixes_caches();
    }

    /**
     * Normalize prefix values for comparison
     *
     * @param mixed $value 原始值
     * @param string $option_name 选项名称
     * @return mixed 标准化后的值
     */
    private function normalize_prefix_value( $value, $option_name ) {
        // 对于允许为空的前缀选项，将空字符串和null统一处理
        $nullable_options = array( 'fd_category_prefix', 'fd_tag_prefix', 'fd_custom_type_prefix' );

        if ( in_array( $option_name, $nullable_options ) ) {
            // 将空字符串、null、false都标准化为null
            if ( empty( $value ) && $value !== '0' ) {
                return null;
            }
        }

        return $value;
    }
    
    /**
     * Handle customizer save events
     */
    public function handle_customizer_save() {
        FD_WebSocket_Push_Helper::log( 'Processing customizer save - checking for route prefix settings changes' );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_route_prefixes_updated_event( 
            'customizer', 
            null, 
            null 
        );
        
        // 失效缓存
        $this->invalidate_route_prefixes_caches();
    }
    
    /**
     * Handle theme mods update events
     *
     * @param mixed $old_value 旧的主题模式
     * @param mixed $new_value 新的主题模式
     */
    public function handle_theme_mods_update( $old_value, $new_value ) {
        // 检查是否有路由前缀相关的变更
        $route_prefix_changed = false;
        
        // 检查是否包含路由前缀相关的设置
        $route_prefix_keys = array( 'post_prefix', 'category_prefix', 'tag_prefix', 'custom_type_prefix' );
        
        foreach ( $route_prefix_keys as $key ) {
            $old_val = isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
            $new_val = isset( $new_value[ $key ] ) ? $new_value[ $key ] : null;
            
            if ( $old_val !== $new_val ) {
                $route_prefix_changed = true;
                break;
            }
        }
        
        if ( $route_prefix_changed ) {
            FD_WebSocket_Push_Helper::log( 'Processing theme mods update with route prefix settings changes' );
            
            // 发送WebSocket事件
            $this->websocket_pusher->send_route_prefixes_updated_event( 
                'theme_mods', 
                $old_value, 
                $new_value 
            );
            
            // 失效缓存
            $this->invalidate_route_prefixes_caches();
        }
    }
    
    /**
     * Handle permalink structure update events
     *
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @param string $option_name 选项名称
     */
    public function handle_permalink_structure_update( $old_value, $new_value, $option_name ) {
        FD_WebSocket_Push_Helper::log( 'Processing permalink structure update for option: ' . $option_name );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_route_prefixes_updated_event( 
            $option_name, 
            $old_value, 
            $new_value 
        );
        
        // 失效缓存
        $this->invalidate_route_prefixes_caches();
    }
    
    /**
     * Handle category base update events
     *
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @param string $option_name 选项名称
     */
    public function handle_category_base_update( $old_value, $new_value, $option_name ) {
        FD_WebSocket_Push_Helper::log( 'Processing category base update for option: ' . $option_name );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_route_prefixes_updated_event( 
            $option_name, 
            $old_value, 
            $new_value 
        );
        
        // 失效缓存
        $this->invalidate_route_prefixes_caches();
    }
    
    /**
     * Handle tag base update events
     *
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @param string $option_name 选项名称
     */
    public function handle_tag_base_update( $old_value, $new_value, $option_name ) {
        FD_WebSocket_Push_Helper::log( 'Processing tag base update for option: ' . $option_name );
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_route_prefixes_updated_event( 
            $option_name, 
            $old_value, 
            $new_value 
        );
        
        // 失效缓存
        $this->invalidate_route_prefixes_caches();
    }
    
    /**
     * Invalidate route prefixes related caches
     */
    private function invalidate_route_prefixes_caches() {
        if ( ! FD_WebSocket_Push_Helper::is_revalidation_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'REVALIDATE_SECRET not defined, skipping route prefixes cache invalidation' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Invalidating route prefixes caches' );
        
        // 失效Next.js路由前缀缓存使用 'route-prefixes' 标签
        $this->cache_invalidator->revalidate_tag( 'route-prefixes' );
        
        FD_WebSocket_Push_Helper::log( 'Route prefixes cache invalidation completed' );
    }
    
    /**
     * Get current route prefixes for debugging
     */
    public function get_current_route_prefixes() {
        $prefixes = array();
        
        foreach ( $this->route_prefix_option_names as $option_name ) {
            $prefixes[ $option_name ] = get_option( $option_name );
        }
        
        return $prefixes;
    }
    
    /**
     * Check if route prefixes are properly configured
     */
    public function is_route_prefixes_configured() {
        // 检查关键的路由前缀设置是否存在
        $key_settings = array(
            'fd_post_prefix',
            'fd_category_index_route',
            'fd_tag_index_route'
        );
        
        foreach ( $key_settings as $setting ) {
            if ( get_option( $setting ) === false ) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Handle any option update (backup mechanism)
     *
     * @param string $option_name 选项名称
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     */
    public function handle_any_option_update( $option_name, $old_value, $new_value ) {
        // 只处理路由前缀相关的选项
        if ( ! in_array( $option_name, $this->route_prefix_option_names ) ) {
            return;
        }

        FD_WebSocket_Push_Helper::log( 'Backup handler triggered for option: ' . $option_name . ' (old: ' . var_export($old_value, true) . ', new: ' . var_export($new_value, true) . ')' );

        // 调用主要的处理方法
        $this->handle_route_prefix_setting_update( $old_value, $new_value, $option_name );
    }

    /**
     * Handle admin post update events
     */
    public function handle_admin_post_update() {
        // 检查是否是路由前缀设置页面的更新
        if ( isset( $_POST['option_page'] ) && $_POST['option_page'] === 'fd_route_settings_group' ) {
            FD_WebSocket_Push_Helper::log( 'Route prefix settings page updated via admin_post_update' );

            // 发送通用的路由前缀更新事件
            $this->websocket_pusher->send_route_prefixes_updated_event(
                'admin_post_update',
                null,
                $_POST
            );

            // 失效缓存
            $this->invalidate_route_prefixes_caches();
        }
    }

    /**
     * Check for route prefix changes during admin notices
     * This is a last resort to catch changes that might have been missed
     */
    public function check_route_prefix_changes() {
        // 只在路由设置页面执行
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'fd-route-settings' ) {
            return;
        }

        // 检查是否有设置更新的标志
        if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) {
            FD_WebSocket_Push_Helper::log( 'Route prefix settings updated detected via admin_notices' );

            // 发送通用的路由前缀更新事件
            $this->websocket_pusher->send_route_prefixes_updated_event(
                'settings_updated',
                null,
                null
            );

            // 失效缓存
            $this->invalidate_route_prefixes_caches();
        }
    }

    /**
     * Get route prefixes summary for logging
     */
    public function get_route_prefixes_summary() {
        return array(
            'fd_post_prefix' => get_option( 'fd_post_prefix', 'post' ),
            'fd_category_prefix' => get_option( 'fd_category_prefix', '' ) ?: null,
            'fd_tag_prefix' => get_option( 'fd_tag_prefix', '' ) ?: null,
            'fd_category_index_route' => get_option( 'fd_category_index_route', 'category-index' ),
            'fd_tag_index_route' => get_option( 'fd_tag_index_route', 'tag-index' ),
            'fd_custom_type_prefix' => get_option( 'fd_custom_type_prefix', null ),
        );
    }
}
