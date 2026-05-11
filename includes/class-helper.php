<?php
/**
 * Helper utility class for FD WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Helper {
    
    /**
     * Check if WebSocket push secret is defined
     *
     * @return bool
     */
    public static function is_websocket_push_enabled() {
        return defined( 'FD_WEBSOCKET_PUSH_SECRET' ) && ! empty( FD_WEBSOCKET_PUSH_SECRET );
    }
    
    /**
     * Check if revalidation secret is defined
     *
     * @return bool
     */
    public static function is_revalidation_enabled() {
        return defined( 'REVALIDATE_SECRET' ) && ! empty( REVALIDATE_SECRET );
    }
    
    /**
     * Get WebSocket push secret
     *
     * @return string|null
     */
    public static function get_websocket_push_secret() {
        return self::is_websocket_push_enabled() ? FD_WEBSOCKET_PUSH_SECRET : null;
    }
    
    /**
     * Get revalidation secret
     *
     * @return string|null
     */
    public static function get_revalidation_secret() {
        return self::is_revalidation_enabled() ? REVALIDATE_SECRET : null;
    }
    
    /**
     * Check if post type is public
     *
     * @param string $post_type
     * @return bool
     */
    public static function is_public_post_type( $post_type ) {
        $post_type_obj = get_post_type_object( $post_type );
        return $post_type_obj && $post_type_obj->public;
    }
    
    /**
     * Check if taxonomy is public
     *
     * @param string $taxonomy
     * @return bool
     */
    public static function is_public_taxonomy( $taxonomy ) {
        $taxonomy_obj = get_taxonomy( $taxonomy );
        return $taxonomy_obj && $taxonomy_obj->public;
    }
    
    /**
     * Get post access level for WebSocket targeting
     *
     * @param int $post_id
     * @param WP_Post $post
     * @return string
     */
    public static function get_post_access_level( $post_id, $post ) {
        // Default to authenticated users for safety
        $target_room = 'authenticated_users';

        if ( $post->post_status === 'publish' ) {
            // Check access requirements for published posts
            $required_level_id = get_post_meta( $post_id, '_fd_required_member_level', true );
            $unlock_price = get_post_meta( $post_id, '_fd_unlock_price', true );

            if ( ! empty( $required_level_id ) && $required_level_id > 0 ) {
                // Requires specific member level - this is protected content
                $target_room = 'protected_content';
            } elseif ( ! empty( $unlock_price ) && $unlock_price > 0 ) {
                // Requires individual purchase - this is protected content
                $target_room = 'protected_content';
            } else {
                // Completely public post
                $target_room = 'public';
            }
        }

        return $target_room;
    }

    /**
     * Get all users who have permission to view protected post content
     *
     * @param int $post_id
     * @param WP_Post $post
     * @return array Array of user IDs who can view the protected content
     */
    public static function get_post_authorized_users( $post_id, $post ) {
        $authorized_users = array();

        $required_level_id = get_post_meta( $post_id, '_fd_required_member_level', true );
        $unlock_price = get_post_meta( $post_id, '_fd_unlock_price', true );

        // 1. Users with sufficient member level
        if ( ! empty( $required_level_id ) && $required_level_id > 0 ) {
            $level_users = self::get_users_with_member_level( $required_level_id );
            $authorized_users = array_merge( $authorized_users, $level_users );
        }

        // 2. Users who have individually unlocked this post
        if ( function_exists( 'fd_member_get_post_unlocked_users' ) ) {
            $unlocked_users = fd_member_get_post_unlocked_users( $post_id );
            $authorized_users = array_merge( $authorized_users, $unlocked_users );
        }

        // 3. Post author and administrators
        $authorized_users[] = $post->post_author;
        $admin_users = get_users( array( 'role' => 'administrator' ) );
        foreach ( $admin_users as $admin ) {
            $authorized_users[] = $admin->ID;
        }

        // Remove duplicates and return
        return array_unique( array_filter( $authorized_users ) );
    }

    /**
     * Get users who have a specific member level or higher
     *
     * @param int $required_level_id
     * @return array Array of user IDs
     */
    public static function get_users_with_member_level( $required_level_id ) {
        if ( ! function_exists( 'fd_member_get_member_level' ) ) {
            return array();
        }

        $required_level = fd_member_get_member_level( $required_level_id );
        if ( ! $required_level ) {
            return array();
        }

        $required_priority = isset( $required_level['priority'] ) ? intval( $required_level['priority'] ) : 0;
        $authorized_users = array();

        // Get all users with member levels
        $users_with_levels = get_users( array(
            'meta_key' => 'fd_member_level',
            'meta_compare' => 'EXISTS'
        ) );

        foreach ( $users_with_levels as $user ) {
            if ( function_exists( 'fd_member_get_user_member_level' ) ) {
                $user_level = fd_member_get_user_member_level( $user->ID );
                if ( $user_level && isset( $user_level['priority'] ) ) {
                    $user_priority = intval( $user_level['priority'] );
                    if ( $user_priority >= $required_priority ) {
                        $authorized_users[] = $user->ID;
                    }
                }
            }
        }

        return $authorized_users;
    }

    /**
     * Check if meta key should be ignored for notifications
     *
     * @param string $meta_key
     * @return bool
     */
    public static function should_ignore_meta_key( $meta_key ) {
        // WordPress internal meta keys to exclude
        $excluded_meta_keys = [
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
            '_wp_old_date',
            'session_tokens',
            '_wp_attachment_metadata',
            '_wp_attached_file',
            '_thumbnail_id'
        ];
        
        // Check if it's in the excluded list
        if ( in_array( $meta_key, $excluded_meta_keys ) ) {
            return true;
        }
        
        // Check for transient keys
        if ( strpos( $meta_key, '_transient' ) === 0 ) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if meta key is allowed for custom field notifications
     *
     * @param string $meta_key
     * @param string $taxonomy
     * @return bool
     */
    public static function is_allowed_custom_meta_key( $meta_key, $taxonomy = '' ) {
        $allowed_meta_patterns = [
            '_banner',           // Banner fields
            '_description',      // Description fields
            '_custom_field',     // Other custom fields
            'category_banner',   // Category banner
            'post_tag_banner',   // Tag banner
            'tag_banner',        // Legacy tag banner
            'archive_layout_mode', // 允许布局模式字段
            'banner_layout_style', // 允许Banner样式字段
            'displayed_reactions', // 新增：允许互动数据展示字段
        ];
        
        // Add taxonomy-specific banner pattern
        if ( ! empty( $taxonomy ) ) {
            $allowed_meta_patterns[] = $taxonomy . '_banner';
        }
        
        // Check if meta key matches any allowed pattern
        foreach ( $allowed_meta_patterns as $pattern ) {
            if ( strpos( $meta_key, $pattern ) !== false || $meta_key === $pattern ) {
                return true;
            }
        }
        
        // Exclude internal fields that start with underscore (unless explicitly allowed)
        if ( strpos( $meta_key, '_' ) === 0 ) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Log runtime diagnostics only when explicitly enabled.
     *
     * @param string $message
     * @param string $level
     */
    public static function log( $message, $level = 'DEBUG' ) {
        if ( ! defined( 'FD_WEBSOCKET_PUSH_DEBUG' ) || true !== FD_WEBSOCKET_PUSH_DEBUG ) {
            return;
        }

        error_log( '[FD WebSocket Push ' . $level . '] ' . $message );
    }
    
    /**
     * Get all public custom taxonomies
     *
     * @return array
     */
    public static function get_public_custom_taxonomies() {
        $taxonomies = [];
        
        // Get WordPress native custom taxonomies
        $wp_taxonomies = get_taxonomies([
            'public' => true,
            '_builtin' => false // Exclude built-in taxonomies
        ], 'names');
        
        $taxonomies = array_merge( $taxonomies, $wp_taxonomies );
        
        // If ACF plugin exists, get ACF registered taxonomies
        if ( function_exists( 'acf_get_taxonomies' ) ) {
            $acf_taxonomies = acf_get_taxonomies();
            foreach ( $acf_taxonomies as $acf_taxonomy ) {
                if ( isset( $acf_taxonomy['taxonomy'] ) && $acf_taxonomy['public'] ) {
                    $taxonomies[] = $acf_taxonomy['taxonomy'];
                }
            }
        }
        
        // Add known ACF taxonomies for compatibility
        $additional_taxonomies = ['company', 'region', 'industry'];
        foreach ( $additional_taxonomies as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                $taxonomies[] = $tax;
            }
        }
        
        // Remove duplicates
        return array_unique( $taxonomies );
    }
}
