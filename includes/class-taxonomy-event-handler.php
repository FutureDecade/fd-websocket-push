<?php
/**
 * Taxonomy event handler for FD WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Taxonomy_Event_Handler {
    
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
        // Built-in taxonomy hooks
        $this->register_builtin_taxonomy_hooks();
        
        // Term meta hooks
        $this->register_term_meta_hooks();
        
        // Object terms hooks
        add_filter( 'set_object_terms', array( $this, 'handle_set_object_terms' ), 10, 6 );
        
        // ACF hooks
        if ( function_exists( 'acf_add_local_field_group' ) ) {
            add_action( 'acf/save_post', array( $this, 'handle_acf_term_save' ), 20 );
        }
        
        // Additional term edit hook
        add_action( 'edit_term', array( $this, 'handle_term_edit' ), 10, 3 );
    }
    
    /**
     * Register hooks for built-in taxonomies
     */
    private function register_builtin_taxonomy_hooks() {
        // Tag hooks
        add_action( 'created_post_tag', array( $this, 'handle_tag_update' ), 10, 3 );
        add_action( 'edited_post_tag', array( $this, 'handle_tag_update' ), 10, 3 );
        add_action( 'delete_post_tag', array( $this, 'handle_tag_update' ), 10, 3 );
        
        // Category hooks
        add_action( 'created_category', array( $this, 'handle_category_update' ), 10, 3 );
        add_action( 'edited_category', array( $this, 'handle_category_update' ), 10, 3 );
        add_action( 'delete_category', array( $this, 'handle_category_update' ), 10, 3 );
    }
    
    /**
     * Register hooks for term meta
     */
    private function register_term_meta_hooks() {
        add_action( 'updated_term_meta', array( $this, 'handle_tag_meta_update' ), 10, 4 );
        add_action( 'added_term_meta', array( $this, 'handle_tag_meta_update' ), 10, 4 );
        add_action( 'updated_term_meta', array( $this, 'handle_category_meta_update' ), 10, 4 );
        add_action( 'added_term_meta', array( $this, 'handle_category_meta_update' ), 10, 4 );
        add_action( 'updated_term_meta', array( $this, 'handle_taxonomy_meta_update' ), 10, 4 );
        add_action( 'added_term_meta', array( $this, 'handle_taxonomy_meta_update' ), 10, 4 );
    }
    
    /**
     * Register hooks for custom taxonomies (called after init)
     */
    public function register_dynamic_taxonomy_hooks() {
        $taxonomies = FD_WebSocket_Push_Helper::get_public_custom_taxonomies();
        
        foreach ( $taxonomies as $taxonomy ) {
            // Taxonomy term CRUD hooks
            add_action( "created_{$taxonomy}", array( $this, 'handle_taxonomy_update' ), 10, 3 );
            add_action( "edited_{$taxonomy}", array( $this, 'handle_taxonomy_update' ), 10, 3 );
            add_action( "delete_{$taxonomy}", array( $this, 'handle_taxonomy_update' ), 10, 3 );
        }
    }
    
    /**
     * Handle tag update events
     *
     * @param int $term_id
     * @param int $tt_id
     * @param string $taxonomy
     */
    public function handle_tag_update( $term_id, $tt_id, $taxonomy ) {
        // Only handle post_tag taxonomy
        if ( $taxonomy !== 'post_tag' ) {
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Processing tag update for term ' . $term_id );
        
        // Send WebSocket event
        $this->websocket_pusher->send_taxonomy_updated_event( 'tag:updated', $term_id, $taxonomy );
        
        // Invalidate caches
        $term = get_term( $term_id, $taxonomy );
        if ( $term && ! is_wp_error( $term ) ) {
            $this->cache_invalidator->revalidate_term_caches( $term );
        }
    }
    
    /**
     * Handle category update events
     *
     * @param int $term_id
     * @param int $tt_id
     * @param string $taxonomy
     */
    public function handle_category_update( $term_id, $tt_id, $taxonomy ) {
        // Only handle category taxonomy
        if ( $taxonomy !== 'category' ) {
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Processing category update for term ' . $term_id );
        
        // Send WebSocket event
        $this->websocket_pusher->send_taxonomy_updated_event( 'category:updated', $term_id, $taxonomy );
        
        // Invalidate caches
        $term = get_term( $term_id, $taxonomy );
        if ( $term && ! is_wp_error( $term ) ) {
            $this->cache_invalidator->revalidate_term_caches( $term );
        }
    }
    
    /**
     * Handle custom taxonomy update events
     *
     * @param int $term_id
     * @param int $tt_id
     * @param string $taxonomy
     */
    public function handle_taxonomy_update( $term_id, $tt_id, $taxonomy ) {
        // Exclude built-in taxonomies (they have dedicated handlers)
        if ( in_array( $taxonomy, ['category', 'post_tag'] ) ) {
            return;
        }

        // Check if taxonomy is public
        if ( ! FD_WebSocket_Push_Helper::is_public_taxonomy( $taxonomy ) ) {
            return;
        }

        FD_WebSocket_Push_Helper::log( 'Processing custom taxonomy update for term ' . $term_id . ' in taxonomy ' . $taxonomy );

        // Send WebSocket event
        $this->websocket_pusher->send_taxonomy_updated_event( 'taxonomy:updated', $term_id, $taxonomy );

        // Invalidate caches
        $term = get_term( $term_id, $taxonomy );
        if ( $term && ! is_wp_error( $term ) ) {
            $this->cache_invalidator->revalidate_term_caches( $term );
        }
    }

    /**
     * Handle tag meta update events
     *
     * @param int $meta_id
     * @param int $object_id
     * @param string $meta_key
     * @param mixed $meta_value
     */
    public function handle_tag_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) {
        // Check if this is tag metadata
        $term = get_term( $object_id );
        if ( ! $term || $term->taxonomy !== 'post_tag' ) {
            return;
        }

        // Check if we should ignore this meta key
        if ( FD_WebSocket_Push_Helper::should_ignore_meta_key( $meta_key ) ) {
            return;
        }

        // Check if this is an allowed custom field
        if ( ! FD_WebSocket_Push_Helper::is_allowed_custom_meta_key( $meta_key, 'post_tag' ) ) {
            return;
        }

        FD_WebSocket_Push_Helper::log( 'Processing tag meta update for term ' . $object_id . ', meta key: ' . $meta_key );

        // Trigger tag update notification
        $this->handle_tag_update( $object_id, 0, 'post_tag' );
    }

    /**
     * Handle category meta update events
     *
     * @param int $meta_id
     * @param int $object_id
     * @param string $meta_key
     * @param mixed $meta_value
     */
    public function handle_category_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) {
        // Check if this is category metadata
        $term = get_term( $object_id );
        if ( ! $term || $term->taxonomy !== 'category' ) {
            return;
        }

        // Check if we should ignore this meta key
        if ( FD_WebSocket_Push_Helper::should_ignore_meta_key( $meta_key ) ) {
            return;
        }

        // Check if this is an allowed custom field
        if ( ! FD_WebSocket_Push_Helper::is_allowed_custom_meta_key( $meta_key, 'category' ) ) {
            return;
        }

        FD_WebSocket_Push_Helper::log( 'Processing category meta update for term ' . $object_id . ', meta key: ' . $meta_key );

        // Trigger category update notification
        $this->handle_category_update( $object_id, 0, 'category' );
    }

    /**
     * Handle custom taxonomy meta update events
     *
     * @param int $meta_id
     * @param int $object_id
     * @param string $meta_key
     * @param mixed $meta_value
     */
    public function handle_taxonomy_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) {
        // Get term information
        $term = get_term( $object_id );
        if ( ! $term || is_wp_error( $term ) ) {
            return;
        }

        // Exclude built-in taxonomies (they have dedicated handlers)
        if ( in_array( $term->taxonomy, ['category', 'post_tag'] ) ) {
            return;
        }

        // Check if taxonomy is public
        if ( ! FD_WebSocket_Push_Helper::is_public_taxonomy( $term->taxonomy ) ) {
            return;
        }

        // Check if we should ignore this meta key
        if ( FD_WebSocket_Push_Helper::should_ignore_meta_key( $meta_key ) ) {
            return;
        }

        // Check if this is an allowed custom field
        if ( ! FD_WebSocket_Push_Helper::is_allowed_custom_meta_key( $meta_key, $term->taxonomy ) ) {
            return;
        }

        FD_WebSocket_Push_Helper::log( 'Processing custom taxonomy meta update for term ' . $object_id . ' in taxonomy ' . $term->taxonomy . ', meta key: ' . $meta_key );

        // Trigger taxonomy update notification
        $this->handle_taxonomy_update( $object_id, 0, $term->taxonomy );
    }

    /**
     * Handle set object terms event (when post taxonomy terms are modified)
     *
     * @param int $object_id
     * @param array $terms
     * @param array $tt_ids
     * @param string $taxonomy
     * @param bool $append
     * @param array $old_tt_ids
     * @return array
     */
    public function handle_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
        FD_WebSocket_Push_Helper::log( 'set_object_terms hook fired for post ' . $object_id . ' in taxonomy "' . $taxonomy . '"' );

        // Only handle public taxonomies and post objects
        $post = get_post( $object_id );
        if ( ! $post ) {
            FD_WebSocket_Push_Helper::log( 'set_object_terms: Skipped. Object not found. Object ID: ' . $object_id );
            return $tt_ids;
        }

        // Check if post type is public
        if ( ! FD_WebSocket_Push_Helper::is_public_post_type( $post->post_type ) ) {
            FD_WebSocket_Push_Helper::log( 'set_object_terms: Skipped. Post type "' . $post->post_type . '" is not public. Object ID: ' . $object_id );
            return $tt_ids;
        }

        // Check if taxonomy is public
        if ( ! FD_WebSocket_Push_Helper::is_public_taxonomy( $taxonomy ) ) {
            FD_WebSocket_Push_Helper::log( 'set_object_terms: Skipped. Taxonomy "' . $taxonomy . '" is not public.' );
            return $tt_ids;
        }

        // Calculate added and removed terms
        $added_tt_ids = array_diff( $tt_ids, $old_tt_ids );
        $removed_tt_ids = array_diff( $old_tt_ids, $tt_ids );

        // Handle added terms
        if ( ! empty( $added_tt_ids ) ) {
            FD_WebSocket_Push_Helper::log( 'set_object_terms detected ADDED terms for post ' . $object_id . ': ' . wp_json_encode( $added_tt_ids ) );

            $affected_terms = [];
            foreach ( $added_tt_ids as $tt_id ) {
                $term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );
                if ( ! $term || is_wp_error( $term ) ) continue;

                $affected_terms[] = [
                    'taxonomy' => $taxonomy,
                    'slug'     => $term->slug,
                    'termId'   => $term->term_id,
                ];

                // Invalidate term caches
                $this->cache_invalidator->revalidate_term_caches( $term );
            }

            // Send WebSocket event for added terms
            if ( ! empty( $affected_terms ) ) {
                $this->websocket_pusher->send_list_item_event( 'list:item-added', $object_id, $affected_terms );
            }
        }

        // Handle removed terms
        if ( ! empty( $removed_tt_ids ) ) {
            FD_WebSocket_Push_Helper::log( 'set_object_terms detected REMOVED terms for post ' . $object_id . ': ' . wp_json_encode( $removed_tt_ids ) );

            $affected_terms = [];
            foreach ( $removed_tt_ids as $tt_id ) {
                $term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );
                if ( ! $term || is_wp_error( $term ) ) continue;

                $affected_terms[] = [
                    'taxonomy' => $taxonomy,
                    'slug'     => $term->slug,
                    'termId'   => $term->term_id,
                ];

                // Invalidate term caches
                $this->cache_invalidator->revalidate_term_caches( $term );
            }

            // Send WebSocket event for removed terms
            if ( ! empty( $affected_terms ) ) {
                $this->websocket_pusher->send_list_item_event( 'list:item-removed', $object_id, $affected_terms );
            }
        }

        return $tt_ids; // Must return the same value
    }

    /**
     * Handle ACF term save event
     *
     * @param int $post_id
     */
    public function handle_acf_term_save( $post_id ) {
        // Check if this is a term ACF save
        if ( strpos( $post_id, 'term_' ) === 0 ) {
            $term_id = (int) str_replace( 'term_', '', $post_id );
            $term = get_term( $term_id );

            if ( $term && ! is_wp_error( $term ) ) {
                FD_WebSocket_Push_Helper::log( 'Processing ACF term save for term ' . $term_id . ' in taxonomy ' . $term->taxonomy );

                // Trigger appropriate update notification based on taxonomy
                if ( $term->taxonomy === 'post_tag' ) {
                    $this->handle_tag_update( $term_id, 0, 'post_tag' );
                } elseif ( $term->taxonomy === 'category' ) {
                    $this->handle_category_update( $term_id, 0, 'category' );
                } else {
                    // Custom taxonomy
                    $this->handle_taxonomy_update( $term_id, 0, $term->taxonomy );
                }
            }
        }
    }

    /**
     * Handle general term edit event (catch-all for missed updates)
     *
     * @param int $term_id
     * @param int $tt_id
     * @param string $taxonomy
     */
    public function handle_term_edit( $term_id, $tt_id, $taxonomy ) {
        FD_WebSocket_Push_Helper::log( 'Processing term edit for term ' . $term_id . ' in taxonomy ' . $taxonomy );

        // Route to appropriate handler based on taxonomy
        if ( $taxonomy === 'post_tag' ) {
            $this->handle_tag_update( $term_id, $tt_id, $taxonomy );
        } elseif ( $taxonomy === 'category' ) {
            $this->handle_category_update( $term_id, $tt_id, $taxonomy );
        } else {
            // Check if it's a public custom taxonomy
            if ( FD_WebSocket_Push_Helper::is_public_taxonomy( $taxonomy ) ) {
                $this->handle_taxonomy_update( $term_id, $tt_id, $taxonomy );
            }
        }
    }
}
