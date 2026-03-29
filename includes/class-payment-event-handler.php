<?php
/**
 * Payment event handler for FD WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Payment_Event_Handler {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * WebSocket pusher instance
     */
    private $websocket_pusher;
    
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
        
        $this->register_hooks();
    }
    
    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Payment success hook from fd-payment plugin
        add_action( 'fd_payment_success', array( $this, 'handle_payment_success' ), 10, 1 );
    }
    
    /**
     * Handle payment success event
     * When a user successfully pays to unlock a post, send targeted push notification
     *
     * @param array $payment_data Payment success data from fd-payment plugin
     */
    public function handle_payment_success( $payment_data ) {
        FD_WebSocket_Push_Helper::log( 'Processing payment success event' );
        
        // Check if WebSocket push is enabled
        if ( ! FD_WebSocket_Push_Helper::is_websocket_push_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'FD_WEBSOCKET_PUSH_SECRET not defined, cannot send unlock event', 'ERROR' );
            return;
        }
        
        // Parse metadata to check if this is a post unlock order
        $meta = $this->parse_payment_metadata( $payment_data );
        
        // Ensure metadata is valid and order type is correct
        if ( ! is_array( $meta ) || ! isset( $meta['order_type'] ) || $meta['order_type'] !== 'post_unlock' ) {
            FD_WebSocket_Push_Helper::log( 'Payment is not a post unlock order, skipping' );
            return; // Not a post unlock order
        }
        
        // Extract required information from payment data
        $user_id = isset( $payment_data['user_id'] ) ? $payment_data['user_id'] : null;
        $post_id = isset( $meta['post_id'] ) ? $meta['post_id'] : null;
        
        if ( ! $user_id || ! $post_id ) {
            FD_WebSocket_Push_Helper::log( 'Post unlock order data incomplete, cannot send push notification', 'ERROR' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Sending post unlock notification for user ' . $user_id . ', post ' . $post_id );
        
        // Send WebSocket event to user's private room
        $this->websocket_pusher->send_post_unlocked_event( $user_id, $post_id );
    }
    
    /**
     * Parse payment metadata with flexible handling
     *
     * @param array $payment_data
     * @return array|null
     */
    private function parse_payment_metadata( $payment_data ) {
        if ( empty( $payment_data['metadata'] ) ) {
            return null;
        }
        
        $meta_raw = $payment_data['metadata'];
        
        // Try JSON decode first
        if ( is_string( $meta_raw ) ) {
            $meta = json_decode( $meta_raw, true );
            if ( is_array( $meta ) ) {
                return $meta;
            }
        }
        
        // Try unserialize if JSON decode failed
        if ( is_string( $meta_raw ) ) {
            $meta = maybe_unserialize( $meta_raw );
            if ( is_array( $meta ) ) {
                return $meta;
            }
        }
        
        // If already an array, return as-is
        if ( is_array( $meta_raw ) ) {
            return $meta_raw;
        }
        
        FD_WebSocket_Push_Helper::log( 'Failed to parse payment metadata', 'ERROR' );
        return null;
    }
}
