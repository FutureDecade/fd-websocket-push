<?php
/**
 * Homepage layout event handler for FD WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Homepage_Layout_Event_Handler {
    
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
        // 监听首页布局更新钩子
        add_action( 'fd_homepage_layout_updated', array( $this, 'handle_homepage_layout_update' ), 10, 1 );
    }
    
    /**
     * 处理首页布局更新事件
     *
     * @param array $layout_data 布局数据
     */
    public function handle_homepage_layout_update( $layout_data ) {
        FD_WebSocket_Push_Helper::log( 'Processing homepage layout update' );
        
        // 验证布局数据
        if ( empty( $layout_data ) || ! isset( $layout_data['version'] ) ) {
            FD_WebSocket_Push_Helper::log( 'Invalid homepage layout data', 'ERROR' );
            return;
        }
        
        // 发送WebSocket事件
        $this->websocket_pusher->send_homepage_layout_updated_event( $layout_data );
        
        // 使前端缓存失效
        $this->invalidate_homepage_caches();
    }
    
    /**
     * 使首页相关缓存失效
     */
    private function invalidate_homepage_caches() {
        FD_WebSocket_Push_Helper::log( 'Invalidating homepage layout caches' );
        
        // 使首页布局缓存失效
        $this->cache_invalidator->revalidate_tag( 'homepage-layout' );
        
        // 使首页缓存失效（因为布局变化会影响整个首页）
        $this->cache_invalidator->revalidate_path( '/' );
        
        FD_WebSocket_Push_Helper::log( 'Homepage layout caches invalidated' );
    }
}
