<?php
/**
 * Post event handler for FD WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Post_Event_Handler {
    
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
        // Post lifecycle events
        add_action( 'save_post', array( $this, 'handle_post_update' ), 10, 3 );
        add_action( 'wp_insert_post', array( $this, 'handle_post_insert' ), 10, 3 );
        add_action( 'before_delete_post', array( $this, 'handle_post_delete' ), 10, 2 );
        add_action( 'transition_post_status', array( $this, 'handle_post_status_transition' ), 10, 3 );
        // Detect author changes after a post is updated (receives both before/after objects)
        add_action( 'post_updated', array( $this, 'handle_author_change' ), 10, 3 );
    }
    
    /**
     * Handle post update event
     *
     * @param int $post_id
     * @param WP_Post $post
     * @param bool $update
     */
    public function handle_post_update( $post_id, $post, $update ) {
        // Only trigger on actual updates, not new posts
        if ( ! $update ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            FD_WebSocket_Push_Helper::log( 'Skipping post update handler for autosave/revision: ' . $post_id );
            return;
        }

        if ( ! $post instanceof WP_Post ) {
            $post = get_post( $post_id );
        }

        if ( ! $post ) {
            FD_WebSocket_Push_Helper::log( 'Skipping post update handler because post was not found: ' . $post_id, 'ERROR' );
            return;
        }

        if ( ! FD_WebSocket_Push_Helper::is_public_post_type( $post->post_type ) ) {
            FD_WebSocket_Push_Helper::log( 'Skipping post update handler because post type "' . $post->post_type . '" is not public' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Processing post update for post ' . $post_id . ' (type: ' . $post->post_type . ', status: ' . $post->post_status . ')' );

        // Invalidate caches before notifying clients so router.refresh reads fresh list data.
        $this->cache_invalidator->invalidate_post_caches( $post_id, $post );
        $this->cache_invalidator->invalidate_list_caches_on_post_update( $post_id, $post );
        
        // Send WebSocket events
        // post:updated 事件现在包含了所有必要的信息（公开信息 + 受保护内容）
        // 对于付费墙文章：公开信息推送给所有用户，完整信息推送给有权限用户
        // 对于公开文章：完整信息推送给所有用户
        $this->websocket_pusher->send_post_updated_event( $post_id, $post );
        
        // Send list update event for published posts to ensure CPT and other lists are updated
        if ( $post->post_status === 'publish' ) {
            $this->websocket_pusher->send_post_updated_for_lists_event( $post_id, $post );
        }
    }
    
    /**
     * Handle post insert event (new posts)
     *
     * @param int $post_id
     * @param WP_Post $post
     * @param bool $update
     */
    public function handle_post_insert( $post_id, $post, $update ) {
        // Only handle new posts (not updates)
        if ( $update ) {
            FD_WebSocket_Push_Helper::log( 'Skipping post insert handler because this is an update, not a new post' );
            return;
        }
        
        // Only handle published posts
        if ( $post->post_status !== 'publish' ) {
            FD_WebSocket_Push_Helper::log( 'Skipping post insert handler because post status is not publish: ' . $post->post_status );
            return;
        }
        
        // Check if post type is public
        if ( ! FD_WebSocket_Push_Helper::is_public_post_type( $post->post_type ) ) {
            FD_WebSocket_Push_Helper::log( 'Skipping post insert handler because post type "' . $post->post_type . '" is not public' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Processing post insert for post ' . $post_id . ' (type: ' . $post->post_type . ', title: ' . $post->post_title . ')' );

        // Invalidate caches
        $this->cache_invalidator->invalidate_caches_on_post_insert( $post_id, $post );

        // Send WebSocket event
        $this->websocket_pusher->send_post_inserted_event( $post_id, $post );
    }
    
    /**
     * Handle post delete event
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public function handle_post_delete( $post_id, $post ) {
        // Only handle published posts (since only they appear in lists)
        if ( $post->post_status !== 'publish' ) {
            FD_WebSocket_Push_Helper::log( 'Skipping post delete handler because post status is not publish: ' . $post->post_status );
            return;
        }
        
        // Check if post type is public
        if ( ! FD_WebSocket_Push_Helper::is_public_post_type( $post->post_type ) ) {
            FD_WebSocket_Push_Helper::log( 'Skipping post delete handler because post type "' . $post->post_type . '" is not public' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Processing post delete for post ' . $post_id . ' (type: ' . $post->post_type . ', title: ' . $post->post_title . ')' );

        // Invalidate caches
        $this->cache_invalidator->invalidate_caches_on_post_delete( $post_id, $post );

        // Send WebSocket event
        $this->websocket_pusher->send_post_deleted_event( $post_id, $post );
    }
    
    /**
     * Handle post status transition event
     *
     * @param string $new_status
     * @param string $old_status
     * @param WP_Post $post
     */
    public function handle_post_status_transition( $new_status, $old_status, $post ) {
        FD_WebSocket_Push_Helper::log( 'Post status transition for post ' . $post->ID . ' (old: ' . $old_status . ' -> new: ' . $new_status . ', type: ' . $post->post_type . ')' );
        
        // Check if post type is public
        if ( ! FD_WebSocket_Push_Helper::is_public_post_type( $post->post_type ) ) {
            FD_WebSocket_Push_Helper::log( 'Skipping post status transition handler because post type "' . $post->post_type . '" is not public' );
            return;
        }
        
        // Determine event type and relevance
        $event_type = '';
        $is_relevant = false;
        
        if ( $old_status !== 'publish' && $new_status === 'publish' ) {
            // Post became published - equivalent to insertion
            $is_relevant = true;
            $event_type = 'post:status-published';
            FD_WebSocket_Push_Helper::log( 'Post status transition: article published (' . $old_status . ' => ' . $new_status . ')' );
        } elseif ( $old_status === 'publish' && $new_status !== 'publish' ) {
            // Post became unpublished - equivalent to deletion
            $is_relevant = true;
            $event_type = 'post:status-unpublished';
            FD_WebSocket_Push_Helper::log( 'Post status transition: article unpublished (' . $old_status . ' => ' . $new_status . ')' );
        } else {
            FD_WebSocket_Push_Helper::log( 'Post status transition not relevant for list updates: ' . $old_status . ' => ' . $new_status );
            return;
        }
        
        if ( ! $is_relevant ) {
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Processing post status transition for post ' . $post->ID . ' (type: ' . $post->post_type . ', event: ' . $event_type . ')' );

        // Invalidate caches
        $this->cache_invalidator->invalidate_caches_on_post_status_change( $post->ID, $post, $old_status, $new_status );

        // Send WebSocket event
        $this->websocket_pusher->send_post_status_transition_event( $event_type, $post, $old_status, $new_status );
    }

    /**
     * Handle author change on post update
     * This runs after the post has been updated and provides both the before/after objects.
     * If the author ID changed, we need to (a) refresh the OLD author's list page so the post is removed
     * and (b) clear the OLD author's cache tag so Next.js will fetch fresh data.
     *
     * @param int     $post_id      Post ID.
     * @param WP_Post $post_after   Post object after update (new data).
     * @param WP_Post $post_before  Post object before update (old data).
     */
    public function handle_author_change( $post_id, $post_after, $post_before ) {
        // Only proceed if author actually changed
        if ( (int) $post_after->post_author === (int) $post_before->post_author ) {
            return;
        }

        // Only handle published posts (作者列表页只显示已发布文章)
        if ( $post_before->post_status !== 'publish' ) {
            return;
        }

        // Ensure post type is public
        if ( ! FD_WebSocket_Push_Helper::is_public_post_type( $post_after->post_type ) ) {
            return;
        }

        // Get old author slug
        $old_author_id   = $post_before->post_author;
        $old_author_user = get_user_by( 'ID', $old_author_id );
        if ( ! $old_author_user ) {
            return;
        }

        // 获取新作者对象
        $new_author_id   = $post_after->post_author;
        $new_author_user = get_user_by( 'ID', $new_author_id );
        if ( ! $new_author_user ) {
            return;
        }

        FD_WebSocket_Push_Helper::log( 'Author change detected for post ' . $post_id . '. Old author: ' . $old_author_user->user_nicename . ' -> New author: ' . $new_author_user->user_nicename );

        $this->cache_invalidator->revalidate_tag( 'author:' . $old_author_user->user_nicename );
        $this->cache_invalidator->revalidate_tag( 'author:' . $new_author_user->user_nicename );

        // --- 1) 旧作者列表：发送 list:item-removed ---
        $affected_removed = [
            [
                'taxonomy' => 'author',
                'slug'     => $old_author_user->user_nicename,
                'termId'   => $old_author_id,
            ],
        ];
        $this->websocket_pusher->send_list_item_event( 'list:item-removed', $post_id, $affected_removed );

        // --- 2) 新作者列表：发送 list:item-added ---
        $affected_added = [
            [
                'taxonomy' => 'author',
                'slug'     => $new_author_user->user_nicename,
                'termId'   => $new_author_id,
            ],
        ];
        $this->websocket_pusher->send_list_item_event( 'list:item-added', $post_id, $affected_added );
    }
}
