<?php
/**
 * Cache invalidation handler for FD WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Cache_Invalidator {
    
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
     * Send a revalidation request to Next.js by tag
     *
     * @param string $tag The tag to revalidate
     */
    public function revalidate_tag( $tag ) {
        if ( empty( $tag ) ) {
            return;
        }
        
        $revalidate_secret = FD_WebSocket_Push_Helper::get_revalidation_secret();
        if ( empty( $revalidate_secret ) ) {
            FD_WebSocket_Push_Helper::log( 'REVALIDATE_SECRET is not defined. Cannot revalidate tag: ' . $tag, 'ERROR' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Revalidate tag queued: ' . $tag );
        
        wp_remote_post( 'http://frontend:3000/api/revalidate', [
            'method'   => 'POST',
            'headers'  => [
                'x-revalidate-secret' => $revalidate_secret,
            ],
            'body'     => $tag,
            'blocking' => false,
        ] );
    }
    
    /**
     * Send a revalidation request to Next.js by path
     *
     * @param string $path The path to revalidate
     */
    public function revalidate_path( $path ) {
        if ( empty( $path ) ) {
            return;
        }
        
        $revalidate_secret = FD_WebSocket_Push_Helper::get_revalidation_secret();
        if ( empty( $revalidate_secret ) ) {
            FD_WebSocket_Push_Helper::log( 'REVALIDATE_SECRET is not defined. Cannot revalidate path: ' . $path, 'ERROR' );
            return;
        }
        
        FD_WebSocket_Push_Helper::log( 'Revalidate path queued: ' . $path );
        
        wp_remote_post( 'http://frontend:3000/api/revalidate-path', [
            'method'   => 'POST',
            'headers'  => [
                'x-revalidate-secret' => $revalidate_secret,
            ],
            'body'     => $path,
            'blocking' => false,
        ] );
    }

    /**
     * Revalidate list tags shared by archive pages and Page Composer modules.
     *
     * @param string $post_type The affected post type.
     */
    private function revalidate_global_list_tags( $post_type ) {
        if ( empty( $post_type ) ) {
            return;
        }

        $tags = [
            'post-type:' . $post_type,
        ];

        switch ( $post_type ) {
            case 'post':
                $tags[] = 'homepage-posts';
                $tags[] = 'page-composer:posts';
                $tags[] = 'page-composer-editorial-modules';
                break;
            case 'event':
                $tags[] = 'page-composer:events';
                break;
            case 'app':
                $tags[] = 'page-composer:apps';
                break;
            case 'product':
                $tags[] = 'page-composer:products';
                break;
        }

        foreach ( array_unique( $tags ) as $tag ) {
            $this->revalidate_tag( $tag );
        }
    }
    
    /**
     * Revalidate all necessary tags for a given term
     *
     * @param WP_Term $term The term object
     */
    public function revalidate_term_caches( $term ) {
        if ( ! $term || is_wp_error( $term ) ) {
            return;
        }
        
        $this->revalidate_term_slug( $term->taxonomy, $term->slug );
    }

    /**
     * Revalidate all necessary tags for a taxonomy term slug.
     *
     * @param string $taxonomy The taxonomy name.
     * @param string $slug The term slug.
     */
    public function revalidate_term_slug( $taxonomy, $slug ) {
        if ( empty( $taxonomy ) || empty( $slug ) ) {
            return;
        }

        switch ( $taxonomy ) {
            case 'category':
                $this->revalidate_tag( 'category-index-page' );
                $this->revalidate_tag( 'category:' . $slug );
                $this->revalidate_path( '/category/' . $slug );
                break;
                
            case 'post_tag':
                $this->revalidate_tag( 'tag-index-page' );
                $this->revalidate_tag( 'tag:' . $slug );
                $this->revalidate_path( '/tag/' . $slug );
                break;
                
            default:
                // Custom taxonomy
                $this->revalidate_tag( 'taxonomy:' . $taxonomy );
                $this->revalidate_tag( 'taxonomy-term:' . $taxonomy . ':' . $slug );
                $this->revalidate_path( '/taxonomy/' . $taxonomy );
                $this->revalidate_path( '/taxonomy/' . $taxonomy . '/' . $slug );
                break;
        }

        $taxonomy_object = get_taxonomy( $taxonomy );
        if ( $taxonomy_object && ! empty( $taxonomy_object->object_type ) ) {
            foreach ( $taxonomy_object->object_type as $post_type ) {
                $this->revalidate_global_list_tags( $post_type );
            }
        }
    }
    
    /**
     * Invalidate post-related caches
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public function invalidate_post_caches( $post_id, $post ) {
        // Invalidate cache for the post detail page
        $short_uuid = get_post_meta( $post_id, 'short_uuid', true );
        if ( ! empty( $short_uuid ) ) {
            $this->revalidate_tag( 'post:' . $short_uuid );
        }

        // Invalidate cache for the author archive page
        $author_id = $post->post_author;
        if ( $author_id ) {
            $author = get_user_by( 'ID', $author_id );
            if ( $author ) {
                $this->revalidate_tag( 'author:' . $author->user_nicename );
            }
        }

        // Invalidate taxonomy term caches (categories, tags, custom taxonomies)
        $taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
        foreach ( $taxonomies as $taxonomy ) {
            if ( ! $taxonomy->public ) {
                continue;
            }
            
            $terms = wp_get_post_terms( $post_id, $taxonomy->name );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                foreach ( $terms as $term ) {
                    $this->revalidate_tag( 'taxonomy-term:' . $taxonomy->name . ':' . $term->slug );
                }
            }
        }
    }
    
    /**
     * Invalidate list caches when post is updated
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public function invalidate_list_caches_on_post_update( $post_id, $post ) {
        FD_WebSocket_Push_Helper::log( 'Starting cache invalidation for post ' . $post_id . ' (type: ' . $post->post_type . ')' );
        
        if ( ! FD_WebSocket_Push_Helper::is_revalidation_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'REVALIDATE_SECRET not defined, skipping cache invalidation for post ' . $post_id );
            return;
        }
        
        // 1. Invalidate archive and Page Composer list caches.
        $this->revalidate_global_list_tags( $post->post_type );
        
        // 3. Invalidate related category page caches
        $post_categories = wp_get_post_categories( $post_id );
        foreach ( $post_categories as $cat_id ) {
            $category = get_category( $cat_id );
            if ( $category && ! is_wp_error( $category ) ) {
                $this->revalidate_tag( 'category:' . $category->slug );
            }
        }
        
        // 4. Invalidate related tag page caches
        $post_tags = wp_get_post_tags( $post_id );
        foreach ( $post_tags as $tag ) {
            if ( $tag && ! is_wp_error( $tag ) ) {
                $this->revalidate_tag( 'tag:' . $tag->slug );
            }
        }
        
        // 5. Invalidate related custom taxonomy page caches
        $custom_taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
        foreach ( $custom_taxonomies as $taxonomy ) {
            if ( ! $taxonomy->public ) continue;
            
            $terms = wp_get_post_terms( $post_id, $taxonomy->name );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                foreach ( $terms as $term ) {
                    $this->revalidate_tag( 'taxonomy-term:' . $taxonomy->name . ':' . $term->slug );
                }
            }
        }
        
        FD_WebSocket_Push_Helper::log( 'Invalidated list caches for post ' . $post_id . ' (' . $post->post_type . ')' );
    }
    
    /**
     * Invalidate caches when post is inserted
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public function invalidate_caches_on_post_insert( $post_id, $post ) {
        // Invalidate list caches
        $this->invalidate_list_caches_on_post_update( $post_id, $post );

        // For regular posts, invalidate related taxonomy page caches
        if ( $post->post_type === 'post' ) {
            $this->invalidate_post_taxonomy_caches( $post_id, $post, 'post insert' );
        }
    }
    
    /**
     * Invalidate caches when post is deleted
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public function invalidate_caches_on_post_delete( $post_id, $post ) {
        // Invalidate list caches
        $this->invalidate_list_caches_on_post_update( $post_id, $post );

        // For regular posts, invalidate related taxonomy page caches
        if ( $post->post_type === 'post' ) {
            $this->invalidate_post_taxonomy_caches( $post_id, $post, 'post delete' );
        }
    }
    
    /**
     * Invalidate caches when post status changes
     *
     * @param int $post_id
     * @param WP_Post $post
     * @param string $old_status
     * @param string $new_status
     */
    public function invalidate_caches_on_post_status_change( $post_id, $post, $old_status, $new_status ) {
        // Invalidate list caches
        $this->invalidate_list_caches_on_post_update( $post_id, $post );

        // For regular posts, invalidate related taxonomy page caches
        if ( $post->post_type === 'post' ) {
            $this->invalidate_post_taxonomy_caches( $post_id, $post, 'post status change' );
        }
    }

    /**
     * Invalidate post taxonomy caches
     *
     * @param int $post_id
     * @param WP_Post $post
     * @param string $reason
     */
    public function invalidate_post_taxonomy_caches( $post_id, $post, $reason ) {
        if ( ! FD_WebSocket_Push_Helper::is_revalidation_enabled() ) {
            return;
        }

        FD_WebSocket_Push_Helper::log( 'Invalidating taxonomy caches for post ' . $post_id . ' (reason: ' . $reason . ')' );

        // Get all taxonomies for the post
        $taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

        // Track processed taxonomies to avoid duplicate index page invalidation
        $processed_taxonomies = [];

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! $taxonomy->public ) {
                continue;
            }

            $terms = get_the_terms( $post_id, $taxonomy->name );
            if ( ! $terms || is_wp_error( $terms ) ) {
                continue;
            }

            // Invalidate taxonomy index page (once per taxonomy)
            if ( ! in_array( $taxonomy->name, $processed_taxonomies ) ) {
                $index_cache_tag = '';
                if ( $taxonomy->name === 'category' ) {
                    $index_cache_tag = 'category-index-page';
                } elseif ( $taxonomy->name === 'post_tag' ) {
                    $index_cache_tag = 'tag-index-page';
                } else {
                    $index_cache_tag = 'taxonomy:' . $taxonomy->name;
                }

                FD_WebSocket_Push_Helper::log( 'Invalidating index cache tag: ' . $index_cache_tag . ' (reason: ' . $reason . ')' );
                $this->revalidate_tag( $index_cache_tag );

                $processed_taxonomies[] = $taxonomy->name;
            }

            foreach ( $terms as $term ) {
                // Invalidate taxonomy term page cache
                $cache_tag = '';
                if ( $taxonomy->name === 'category' ) {
                    $cache_tag = 'category:' . $term->slug;
                } elseif ( $taxonomy->name === 'post_tag' ) {
                    $cache_tag = 'tag:' . $term->slug;
                } else {
                    $cache_tag = 'taxonomy-term:' . $taxonomy->name . ':' . $term->slug;
                }

                FD_WebSocket_Push_Helper::log( 'Invalidating term cache tag: ' . $cache_tag . ' (reason: ' . $reason . ')' );
                $this->revalidate_tag( $cache_tag );
            }
        }

        // Send WebSocket events to notify taxonomy pages about post count changes
        $this->notify_taxonomy_updates_for_post( $post_id, $post, $reason );
    }

    /**
     * Send WebSocket events to notify taxonomy pages about post count changes
     *
     * @param int $post_id
     * @param WP_Post $post
     * @param string $reason
     */
    private function notify_taxonomy_updates_for_post( $post_id, $post, $reason ) {
        if ( ! FD_WebSocket_Push_Helper::is_websocket_push_enabled() ) {
            return;
        }

        FD_WebSocket_Push_Helper::log( 'Notifying taxonomy updates for post ' . $post_id . ' (reason: ' . $reason . ')' );

        // Get WebSocket pusher instance
        $websocket_pusher = FD_WebSocket_Push_WebSocket_Pusher::get_instance();

        // Get all taxonomies for the post
        $taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

        // Track processed taxonomies to avoid duplicate notifications
        $processed_taxonomies = [];

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! $taxonomy->public ) {
                continue;
            }

            $terms = get_the_terms( $post_id, $taxonomy->name );
            if ( ! $terms || is_wp_error( $terms ) ) {
                continue;
            }

            // Send taxonomy update notification (once per taxonomy)
            if ( ! in_array( $taxonomy->name, $processed_taxonomies ) ) {
                $event_type = '';
                if ( $taxonomy->name === 'category' ) {
                    $event_type = 'category:updated';
                } elseif ( $taxonomy->name === 'post_tag' ) {
                    $event_type = 'tag:updated';
                } else {
                    $event_type = 'taxonomy:updated';
                }

                // Send event for the first term in this taxonomy (to trigger index page update)
                $first_term = reset( $terms );
                $websocket_pusher->send_taxonomy_updated_event( $event_type, $first_term->term_id, $taxonomy->name );

                $processed_taxonomies[] = $taxonomy->name;
            }
        }
    }

    /**
     * Invalidate homepage cache
     *
     * @param int $post_id
     * @param string $reason
     */
    public function invalidate_homepage_cache( $post_id, $reason ) {
        if ( ! FD_WebSocket_Push_Helper::is_revalidation_enabled() ) {
            return;
        }

        FD_WebSocket_Push_Helper::log( 'Invalidating homepage cache for post ' . $post_id . ' (reason: ' . $reason . ')' );

        $post_type = get_post_type( $post_id );
        if ( $post_type ) {
            $this->revalidate_global_list_tags( $post_type );
            return;
        }

        $this->revalidate_tag( 'homepage-posts' );
    }
}
