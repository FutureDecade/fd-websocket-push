<?php
/**
 * Plugin Name:       FD WebSocket Push
 * Description:       处理在特定 WordPress 事件发生时，向 WebSocket 服务器发送实时推送通知。
 * Version:           1.0.10
 * Author:            AI Assistant & Project Owner
 * Text Domain:       fd-websocket-push
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define plugin constants
define( 'FD_WEBSOCKET_PUSH_VERSION', '1.0.10' );
define( 'FD_WEBSOCKET_PUSH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FD_WEBSOCKET_PUSH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FD_WEBSOCKET_PUSH_PLUGIN_FILE', __FILE__ );
define( 'FD_WEBSOCKET_PUSH_INCLUDES_DIR', FD_WEBSOCKET_PUSH_PLUGIN_DIR . 'includes/' );

/**
 * Main plugin class
 */
class FD_WebSocket_Push {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
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
        $this->init();
    }
    
    /**
     * Initialize the plugin
     */
    private function init() {
        // Load required files
        $this->load_dependencies();
        
        // Initialize components
        $this->init_components();
        
        // Register hooks
        $this->register_hooks();
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-helper.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-event-logger.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-cache-invalidator.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-debug-api.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-websocket-pusher.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-post-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-taxonomy-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-menu-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-vi-settings-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-share-settings-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-posts-per-page-settings-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-discussion-settings-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-route-prefixes-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-member-level-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-general-settings-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-payment-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-notification-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-messaging-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-current-user-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-homepage-layout-event-handler.php';
        require_once FD_WEBSOCKET_PUSH_INCLUDES_DIR . 'class-admin-page.php';
    }
    
    /**
     * Initialize components
     */
    private function init_components() {
        // Initialize all handler classes
        FD_WebSocket_Push_Post_Event_Handler::get_instance();
        FD_WebSocket_Push_Taxonomy_Event_Handler::get_instance();
        FD_WebSocket_Push_Menu_Event_Handler::get_instance();
        FD_WebSocket_Push_VI_Settings_Event_Handler::get_instance();
        FD_WebSocket_Push_Share_Settings_Event_Handler::get_instance();
        FD_WebSocket_Push_Posts_Per_Page_Settings_Event_Handler::get_instance();
        FD_WebSocket_Push_Discussion_Settings_Event_Handler::get_instance();
        FD_WebSocket_Push_Route_Prefixes_Event_Handler::get_instance();

        // 初始化会员等级事件处理器
        $websocket_pusher = FD_WebSocket_Push_WebSocket_Pusher::get_instance();
        $cache_invalidator = FD_WebSocket_Push_Cache_Invalidator::get_instance();
        new FD_Member_Level_Event_Handler($websocket_pusher);
        
        // 初始化常规设置事件处理器
        new FD_WebSocket_Push_General_Settings_Event_Handler($websocket_pusher, $cache_invalidator);
        
        // 初始化当前用户状态事件处理器
        new FD_Current_User_Event_Handler($websocket_pusher);
        
        // 初始化首页布局事件处理器
        FD_WebSocket_Push_Homepage_Layout_Event_Handler::get_instance();

        FD_WebSocket_Push_Payment_Event_Handler::get_instance();
        FD_WebSocket_Push_Notification_Event_Handler::get_instance();
        FD_WebSocket_Push_Messaging_Event_Handler::get_instance();
        new FD_WebSocket_Push_Debug_API();

        // 初始化管理页面
        if (is_admin()) {
            new FD_WebSocket_Push_Admin_Page();
        }
    }
    
    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Plugin activation/deactivation hooks
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
        
        // Initialize after WordPress is fully loaded
        add_action( 'init', array( $this, 'late_init' ), 20 );
    }
    
    /**
     * Late initialization - runs after WordPress init
     */
    public function late_init() {
        // Register dynamic taxonomy hooks
        FD_WebSocket_Push_Taxonomy_Event_Handler::get_instance()->register_dynamic_taxonomy_hooks();
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Reserved for activation logic.
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Reserved for deactivation logic.
    }
}

/**
 * Initialize the plugin
 */
function fd_websocket_push_init() {
    return FD_WebSocket_Push::get_instance();
}

// Start the plugin
fd_websocket_push_init();

/**
 * Backward compatibility functions
 * These functions maintain compatibility with any external code that might call the old function names
 */

/**
 * Backward compatibility wrapper for revalidate tag function
 *
 * @param string $tag The tag to revalidate
 */
function fd_pusher_revalidate_tag( $tag ) {
    $cache_invalidator = FD_WebSocket_Push_Cache_Invalidator::get_instance();
    $cache_invalidator->revalidate_tag( $tag );
}

/**
 * Backward compatibility wrapper for revalidate term caches function
 *
 * @param WP_Term $term The term object
 */
function fd_pusher_revalidate_tag_for_term( $term ) {
    $cache_invalidator = FD_WebSocket_Push_Cache_Invalidator::get_instance();
    $cache_invalidator->revalidate_term_caches( $term );
}
