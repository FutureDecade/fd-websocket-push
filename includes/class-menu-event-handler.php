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
     * Batched menu events collected during the current request.
     *
     * @var array<string,array{menu_id:int,menu_name:string,actions:array<string,bool>}>
     */
    private $pending_menu_events = array();

    /**
     * Whether menu cache invalidation is needed at shutdown.
     *
     * @var bool
     */
    private $pending_menu_cache_invalidation = false;
    
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

        // Flush batched menu events once WordPress has finished the save request.
        add_action( 'shutdown', array( $this, 'flush_pending_menu_events' ), 20 );
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
        
        $this->queue_menu_updated_event( $menu_id, 'updated', $menu->name );
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
        
        $this->queue_menu_updated_event( $menu_id, 'created', $menu->name );
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
        
        $this->queue_menu_updated_event( $menu_id, 'deleted', $menu_name );
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
        
        $this->queue_menu_updated_event( $menu_id, 'item_updated', $menu->name );
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
            
            $this->queue_menu_updated_event( 0, 'locations_updated', 'Menu Locations' );
        }
    }
    
    /**
     * Handle customizer menu save events
     */
    public function handle_customizer_menu_save() {
        FD_WebSocket_Push_Helper::log( 'Processing customizer menu save' );
        
        $this->queue_menu_updated_event( 0, 'customizer_updated', 'Customizer Menus' );
    }
    
    /**
     * Invalidate menu-related caches
     *
     * @param WP_Term $menu Menu object
     */
    private function queue_menu_updated_event( $menu_id, $action, $menu_name ) {
        $menu_id = (int) $menu_id;
        $key     = (string) $menu_id;

        if ( ! isset( $this->pending_menu_events[ $key ] ) ) {
            $this->pending_menu_events[ $key ] = array(
                'menu_id'   => $menu_id,
                'menu_name' => $menu_name,
                'actions'   => array(),
            );
        }

        $this->pending_menu_events[ $key ]['menu_name']          = $menu_name;
        $this->pending_menu_events[ $key ]['actions'][ $action ] = true;
        $this->pending_menu_cache_invalidation                   = true;

        FD_WebSocket_Push_Helper::log( 'Queued menu updated event: ' . $action . ' for menu: ' . $menu_name );
    }

    /**
     * Send one event per changed menu and invalidate menu cache once.
     */
    public function flush_pending_menu_events() {
        if ( empty( $this->pending_menu_events ) && ! $this->pending_menu_cache_invalidation ) {
            return;
        }

        if ( ! FD_WebSocket_Push_Helper::is_revalidation_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'REVALIDATE_SECRET not defined, skipping menu cache invalidation' );
        } else {
            FD_WebSocket_Push_Helper::log( 'Invalidating menu caches for batched menu updates' );
            $this->cache_invalidator->revalidate_tag( 'menus' );
        }

        foreach ( $this->pending_menu_events as $event ) {
            $actions = array_keys( $event['actions'] );
            $action  = count( $actions ) === 1 ? $actions[0] : 'batched';

            $this->websocket_pusher->send_menu_updated_event(
                $event['menu_id'],
                $action,
                $event['menu_name']
            );
        }

        FD_WebSocket_Push_Helper::log( 'Flushed ' . count( $this->pending_menu_events ) . ' batched menu update event(s)' );

        $this->pending_menu_events             = array();
        $this->pending_menu_cache_invalidation = false;
    }
}
