<?php
/**
 * Notification event handler for Lingcoo WebSocket Push plugin
 * Handles real-time WebSocket push notifications for user notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Notification_Event_Handler {
    
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
        // 监听通知创建钩子
        add_action( 'fd_member_notification_created', array( $this, 'handle_notification_created' ), 10, 2 );
    }
    
    /**
     * Handle notification created event
     * When a notification is created, send real-time push to the target user
     *
     * @param int $notification_id The notification ID
     * @param array $notification_data The notification data
     */
    public function handle_notification_created( $notification_id, $notification_data ) {
        FD_WebSocket_Push_Helper::log( 'Processing notification created event for notification ID: ' . $notification_id );
        
        // Check if WebSocket push is enabled
        if ( ! FD_WebSocket_Push_Helper::is_websocket_push_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'FD_WEBSOCKET_PUSH_SECRET not defined, cannot send notification event', 'ERROR' );
            return;
        }
        
        // Validate notification data
        if ( ! isset( $notification_data['user_id'] ) || ! $notification_data['user_id'] ) {
            FD_WebSocket_Push_Helper::log( 'Invalid notification data: missing user_id', 'ERROR' );
            return;
        }
        
        $user_id = $notification_data['user_id'];
        
        // Prepare WebSocket event data
        $event_data = array(
            'notificationId' => $notification_id,
            'title' => $notification_data['title'],
            'content' => $notification_data['content'],
            'type' => $notification_data['type'],
            'status' => $notification_data['status'],
            'createdAt' => $notification_data['created_at']
        );
        
        FD_WebSocket_Push_Helper::log( 'Sending notification created event to user ' . $user_id );
        
        // Send WebSocket event to user's private room
        $result = $this->websocket_pusher->send_event(
            'notification:created',
            'user_' . $user_id,
            $event_data
        );
        
        if ( $result ) {
            FD_WebSocket_Push_Helper::log( 'Notification WebSocket event sent successfully' );
        } else {
            FD_WebSocket_Push_Helper::log( 'Failed to send notification WebSocket event', 'ERROR' );
        }
    }
}
