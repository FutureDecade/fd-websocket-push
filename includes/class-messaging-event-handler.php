<?php
/**
 * Messaging event handler for Lingcoo WebSocket Push plugin
 * Handles real-time WebSocket push notifications for private messages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Messaging_Event_Handler {
    
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
        // 监听私信发送钩子
        add_action( 'fd_member_pm_message_sent', array( $this, 'handle_message_sent' ), 10, 2 );
    }
    
    /**
     * Handle message sent event
     * When a private message is sent, send real-time push to the recipient
     *
     * @param int $message_id The message ID
     * @param array $message_data The message data
     */
    public function handle_message_sent( $message_id, $message_data ) {
        FD_WebSocket_Push_Helper::log( 'Processing message sent event for message ID: ' . $message_id );
        
        // Check if WebSocket push is enabled
        if ( ! FD_WebSocket_Push_Helper::is_websocket_push_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'FD_WEBSOCKET_PUSH_SECRET not defined, cannot send message event', 'ERROR' );
            return;
        }
        
        // Validate message data
        if ( ! isset( $message_data['recipient_id'] ) || ! $message_data['recipient_id'] ) {
            FD_WebSocket_Push_Helper::log( 'Invalid message data: missing recipient_id', 'ERROR' );
            return;
        }
        
        if ( ! isset( $message_data['sender_id'] ) || ! $message_data['sender_id'] ) {
            FD_WebSocket_Push_Helper::log( 'Invalid message data: missing sender_id', 'ERROR' );
            return;
        }
        
        $recipient_id = $message_data['recipient_id'];
        $sender_id = $message_data['sender_id'];
        
        // Get additional message details from database
        $message = $this->get_message_details( $message_id );
        if ( ! $message ) {
            FD_WebSocket_Push_Helper::log( 'Could not retrieve message details for ID: ' . $message_id, 'ERROR' );
            return;
        }
        
        // Get sender information
        $sender = get_userdata( $sender_id );
        if ( ! $sender ) {
            FD_WebSocket_Push_Helper::log( 'Could not retrieve sender information for ID: ' . $sender_id, 'ERROR' );
            return;
        }
        
        // Prepare WebSocket event data for message received
        $message_event_data = array(
            'messageId' => $message_id,
            'senderId' => $sender_id,
            'senderName' => $sender->display_name,
            'content' => $message_data['content'],
            'conversationId' => $message->conversation_id,
            'sentAt' => $message->sent_at
        );
        
        FD_WebSocket_Push_Helper::log( 'Sending message received event to user ' . $recipient_id );
        
        // Send WebSocket event to recipient's private room
        $result = $this->websocket_pusher->send_event(
            'message:received',
            'user_' . $recipient_id,
            $message_event_data
        );
        
        if ( $result ) {
            FD_WebSocket_Push_Helper::log( 'Message WebSocket event sent successfully' );
        } else {
            FD_WebSocket_Push_Helper::log( 'Failed to send message WebSocket event', 'ERROR' );
        }
        
        /**
         * —— 新增逻辑 ——
         * 发送 current-user:updated 事件，提示前端刷新用户全局数据（包括未读消息总数）。
         * 仅推送给收件人，发送人侧由现有逻辑自行维护。
         */
        try {
            $this->websocket_pusher->send_event(
                'current-user:updated',
                'user_' . $recipient_id,
                array(
                    'user_id'   => $recipient_id,
                    'type'      => 'unread-count-updated',
                    'source'    => 'messaging',
                    'timestamp' => current_time( 'mysql' ),
                )
            );
        } catch ( Exception $e ) {
            FD_WebSocket_Push_Helper::log( 'Failed to push current-user:updated event: ' . $e->getMessage(), 'ERROR' );
        }
        
        // Also send conversation updated event to both users
        $this->send_conversation_updated_events( $message->conversation_id, $sender_id, $recipient_id );
    }
    
    /**
     * Get message details from database
     *
     * @param int $message_id
     * @return object|null
     */
    private function get_message_details( $message_id ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'member_pm_messages';
        
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $message_id
        ) );
    }
    
    /**
     * Send conversation updated events to both participants
     *
     * @param int $conversation_id
     * @param int $sender_id
     * @param int $recipient_id
     */
    private function send_conversation_updated_events( $conversation_id, $sender_id, $recipient_id ) {
        // Get conversation details
        $conversation = $this->get_conversation_details( $conversation_id );
        if ( ! $conversation ) {
            return;
        }
        
        // Get unread count for recipient
        $recipient_unread_count = $this->get_conversation_unread_count( $conversation_id, $recipient_id );
        
        // Prepare conversation update data for recipient
        $recipient_event_data = array(
            'conversationId' => $conversation_id,
            'unreadCount' => $recipient_unread_count,
            'updatedAt' => $conversation->updated_at
        );
        
        // Send to recipient
        $this->websocket_pusher->send_event(
            'conversation:updated',
            'user_' . $recipient_id,
            $recipient_event_data
        );
        
        // Prepare conversation update data for sender (unread count should be 0)
        $sender_event_data = array(
            'conversationId' => $conversation_id,
            'unreadCount' => 0,
            'updatedAt' => $conversation->updated_at
        );
        
        // Send to sender
        $this->websocket_pusher->send_event(
            'conversation:updated',
            'user_' . $sender_id,
            $sender_event_data
        );
    }
    
    /**
     * Get conversation details from database
     *
     * @param int $conversation_id
     * @return object|null
     */
    private function get_conversation_details( $conversation_id ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'member_pm_conversations';
        
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $conversation_id
        ) );
    }
    
    /**
     * Get unread message count for a user in a conversation
     *
     * @param int $conversation_id
     * @param int $user_id
     * @return int
     */
    private function get_conversation_unread_count( $conversation_id, $user_id ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'member_pm_messages';
        
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} 
             WHERE conversation_id = %d 
             AND recipient_id = %d 
             AND is_read = 0",
            $conversation_id,
            $user_id
        ) );
        
        return (int) $count;
    }
}
