<?php
/**
 * Menu event handler for FD WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Menu_Event_Handler {
    
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
        // Menu CRUD hooks
        add_action( 'wp_update_nav_menu', array( $this, 'handle_menu_update' ), 10, 2 );
        add_action( 'wp_create_nav_menu', array( $this, 'handle_menu_create' ), 10, 2 );
        add_action( 'wp_delete_nav_menu', array( $this, 'handle_menu_delete' ), 10, 1 );
        
        // Menu item hooks
        add_action( 'wp_update_nav_menu_item', array( $this, 'handle_menu_item_update' ), 10, 3 );
        
        // Menu location assignment hooks
        add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), array( $this, 'handle_menu_location_update' ), 10, 2 );
        
        // Alternative hook for menu location updates
        add_action( 'customize_save_after', array( $this, 'handle_customizer_menu_save' ) );
    }
    
    /**
     * Handle menu update events
     *
     * @param int $menu_id Menu ID
     * @param array $menu_data Menu data
     */
    public function handle_menu_update( $menu_id, $menu_data = array() ) {
        FD_WebSocket_Push_Helper::log( 'Processing menu update for menu ' . $menu_id );
        
        // Get menu object
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu || is_wp_error( $menu ) ) {
            FD_WebSocket_Push_Helper::log( 'Menu not found or error: ' . $menu_id, 'ERROR' );
            return;
        }
        
        // Send WebSocket event
        $this->websocket_pusher->send_menu_updated_event( $menu_id, 'updated', $menu->name );
        
        // Invalidate caches
        $this->invalidate_menu_caches( $menu );
    }
    
    /**
     * Handle menu create events
     *
     * @param int $menu_id Menu ID
     * @param array $menu_data Menu data
     */
    public function handle_menu_create( $menu_id, $menu_data = array() ) {
        FD_WebSocket_Push_Helper::log( 'Processing menu create for menu ' . $menu_id );
        
        // Get menu object
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu || is_wp_error( $menu ) ) {
            FD_WebSocket_Push_Helper::log( 'Menu not found or error: ' . $menu_id, 'ERROR' );
            return;
        }
        
        // Send WebSocket event
        $this->websocket_pusher->send_menu_updated_event( $menu_id, 'created', $menu->name );
        
        // Invalidate caches
        $this->invalidate_menu_caches( $menu );
    }
    
    /**
     * Handle menu delete events
     *
     * @param int $menu_id Menu ID
     */
    public function handle_menu_delete( $menu_id ) {
        FD_WebSocket_Push_Helper::log( 'Processing menu delete for menu ' . $menu_id );
        
        // Get menu object before deletion
        $menu = wp_get_nav_menu_object( $menu_id );
        $menu_name = $menu && ! is_wp_error( $menu ) ? $menu->name : 'Unknown Menu';
        
        // Send WebSocket event
        $this->websocket_pusher->send_menu_updated_event( $menu_id, 'deleted', $menu_name );
        
        // Invalidate caches
        $this->cache_invalidator->revalidate_tag( 'menus' );
    }
    
    /**
     * Handle menu item update events
     *
     * @param int $menu_id Menu ID
     * @param int $menu_item_db_id Menu item ID
     * @param array $args Menu item arguments
     */
    public function handle_menu_item_update( $menu_id, $menu_item_db_id, $args ) {
        FD_WebSocket_Push_Helper::log( 'Processing menu item update for menu ' . $menu_id . ', item ' . $menu_item_db_id );
        
        // Get menu object
        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu || is_wp_error( $menu ) ) {
            FD_WebSocket_Push_Helper::log( 'Menu not found or error: ' . $menu_id, 'ERROR' );
            return;
        }
        
        // Send WebSocket event
        $this->websocket_pusher->send_menu_updated_event( $menu_id, 'item_updated', $menu->name );
        
        // Invalidate caches
        $this->invalidate_menu_caches( $menu );
    }
    
    /**
     * Handle menu location assignment updates
     *
     * @param mixed $old_value Old theme mods
     * @param mixed $new_value New theme mods
     */
    public function handle_menu_location_update( $old_value, $new_value ) {
        // Check if nav_menu_locations changed
        $old_locations = isset( $old_value['nav_menu_locations'] ) ? $old_value['nav_menu_locations'] : array();
        $new_locations = isset( $new_value['nav_menu_locations'] ) ? $new_value['nav_menu_locations'] : array();
        
        if ( $old_locations !== $new_locations ) {
            FD_WebSocket_Push_Helper::log( 'Processing menu location assignment update' );
            
            // Send WebSocket event for location changes
            $this->websocket_pusher->send_menu_updated_event( 0, 'locations_updated', 'Menu Locations' );
            
            // Invalidate menu caches
            $this->cache_invalidator->revalidate_tag( 'menus' );
        }
    }
    
    /**
     * Handle customizer menu save events
     */
    public function handle_customizer_menu_save() {
        FD_WebSocket_Push_Helper::log( 'Processing customizer menu save' );
        
        // Send WebSocket event
        $this->websocket_pusher->send_menu_updated_event( 0, 'customizer_updated', 'Customizer Menus' );
        
        // Invalidate menu caches
        $this->cache_invalidator->revalidate_tag( 'menus' );
    }
    
    /**
     * Invalidate menu-related caches
     *
     * @param WP_Term $menu Menu object
     */
    private function invalidate_menu_caches( $menu ) {
        if ( ! FD_WebSocket_Push_Helper::is_revalidation_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'REVALIDATE_SECRET not defined, skipping menu cache invalidation' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Invalidating menu caches for menu: ' . $menu->name );
        
        // Invalidate Next.js menu cache using the 'menus' tag
        $this->cache_invalidator->revalidate_tag( 'menus' );
        
        FD_WebSocket_Push_Helper::log( 'Menu cache invalidation completed for menu: ' . $menu->name );
    }
}
