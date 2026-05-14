<?php
/**
 * WebSocket push handler for FD WebSocket Push plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_WebSocket_Pusher {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * WebSocket server URL
     */
    private $websocket_url = 'http://websocket:8080/push-event';

    /**
     * Event logger instance
     */
    private $event_logger;
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->event_logger = new FD_WebSocket_Push_Event_Logger();
    }

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
     * Create a trace id that can be followed across WP, websocket, and frontend.
     *
     * @param string $event_type
     * @return string
     */
    private function create_trace_id( $event_type ) {
        $event_slug = preg_replace( '/[^a-z0-9]+/i', '-', strtolower( $event_type ) );
        $event_slug = trim( $event_slug, '-' );
        $random = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );

        return 'fd-' . gmdate( 'YmdHis' ) . '-' . $event_slug . '-' . substr( str_replace( '-', '', $random ), 0, 8 );
    }
    
    /**
     * Send event to WebSocket server
     *
     * @param string $event_type
     * @param string $target
     * @param array $data
     * @return bool|WP_Error
     */
    public function send_event( $event_type, $target, $data = [] ) {
        if ( ! FD_WebSocket_Push_Helper::is_websocket_push_enabled() ) {
            FD_WebSocket_Push_Helper::log( 'FD_WEBSOCKET_PUSH_SECRET not defined, cannot send event: ' . $event_type, 'ERROR' );
            return false;
        }

        $started_at = microtime( true );
        $existing_trace = isset( $data['_fdTrace'] ) && is_array( $data['_fdTrace'] ) ? $data['_fdTrace'] : [];
        $trace_id = isset( $existing_trace['traceId'] ) && $existing_trace['traceId']
            ? sanitize_text_field( $existing_trace['traceId'] )
            : $this->create_trace_id( $event_type );

        $data['_fdTrace'] = array_merge( $existing_trace, [
            'traceId' => $trace_id,
            'event' => $event_type,
            'target' => $target,
            'source' => 'wordpress',
            'wordpressCreatedAt' => gmdate( 'c' ),
            'wordpressCreatedAtMs' => (int) round( $started_at * 1000 ),
        ] );

        $event_data = [
            'event'  => $event_type,
            'target' => $target,
            'data'   => $data,
        ];

        // 记录事件到数据库
        $event_id = $this->event_logger->log_event($event_type, $event_data, $target, $trace_id);

        if ( $event_id ) {
            $data['_fdTrace']['eventId'] = (int) $event_id;
            $event_data['data'] = $data;
            $this->event_logger->update_event_data( $event_id, $event_data );
        }

        FD_WebSocket_Push_Helper::log( 'Sending WebSocket event: ' . $event_type . ' to target: ' . $target . ' (Trace ID: ' . $trace_id . ', Event ID: ' . $event_id . ')' );

        do_action( 'fd_websocket_push_before_send', $event_type, $target, $data, $trace_id, $event_id );

        $response = wp_remote_post( $this->websocket_url, [
            'method'   => 'POST',
            'headers'  => [
                'Content-Type'  => 'application/json; charset=utf-8',
                'x-push-secret' => FD_WebSocket_Push_Helper::get_websocket_push_secret(),
            ],
            'body'     => wp_json_encode( $event_data ),
            'blocking' => false,
        ] );

        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            FD_WebSocket_Push_Helper::log( 'WebSocket push failed: ' . $error_message, 'ERROR' );

            // 更新事件状态为失败
            if ($event_id) {
                $duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
                $this->event_logger->update_event_status($event_id, 'failed', null, $error_message, $duration_ms);
            }

            do_action( 'fd_websocket_push_after_send', false, $event_type, $target, $data, $trace_id, $event_id, $error_message );

            return $response;
        }

        // 更新事件状态为成功（非阻塞请求，假设成功）
        if ($event_id) {
            $duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
            $response_data = array(
                'trace_id' => $trace_id,
                'status_code' => wp_remote_retrieve_response_code($response),
                'response_message' => wp_remote_retrieve_response_message($response),
                'sent_at' => current_time('mysql'),
                'duration_ms' => $duration_ms
            );
            $this->event_logger->update_event_status($event_id, 'sent', $response_data, null, $duration_ms);
        }

        do_action( 'fd_websocket_push_after_send', true, $event_type, $target, $data, $trace_id, $event_id, null );

        FD_WebSocket_Push_Helper::log( 'WebSocket push sent successfully (non-blocking, Trace ID: ' . $trace_id . ')' );
        return true;
    }
    
    /**
     * Send post updated event
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public function send_post_updated_event( $post_id, $post ) {
        $target_room = FD_WebSocket_Push_Helper::get_post_access_level( $post_id, $post );

        // 获取分类信息
        $post_categories = wp_get_post_categories( $post_id );
        $categories = array_map( function( $cat_id ) {
            $category = get_category( $cat_id );
            return [
                'id'   => $cat_id,
                'slug' => $category->slug,
                'name' => $category->name,
            ];
        }, $post_categories );

        // 获取标签信息
        $post_tags = wp_get_post_tags( $post_id );
        $tags = array_map( function( $tag ) {
            return [
                'id'   => $tag->term_id,
                'slug' => $tag->slug,
                'name' => $tag->name,
            ];
        }, $post_tags );

        // 获取自定义分类法信息
        $custom_taxonomies_data = [];
        $custom_taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
        foreach ( $custom_taxonomies as $taxonomy ) {
            if ( ! $taxonomy->public ) continue;

            $terms = wp_get_post_terms( $post_id, $taxonomy->name );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $custom_taxonomies_data[$taxonomy->name] = array_map( function( $term ) {
                    return [
                        'id'   => $term->term_id,
                        'slug' => $term->slug,
                        'name' => $term->name,
                    ];
                }, $terms );
            }
        }

        // 获取预览内容信息（用于付费墙文章）
        $preview_content = '';
        $preview_mode = '';
        $preview_value = '';

        // 检查文章是否启用了付费墙（与GraphQL解析器逻辑保持一致）
        $required_level = (int) get_post_meta( $post_id, '_fd_required_member_level', true );
        $unlock_price   = (float) get_post_meta( $post_id, '_fd_unlock_price', true );
        $is_paywalled   = $required_level !== 0 || $unlock_price > 0;

        if ( $is_paywalled && function_exists( 'fd_member_generate_preview' ) ) {
            $preview_content = fd_member_generate_preview( $post_id );
            $preview_mode = get_post_meta( $post_id, '_fd_preview_mode', true ) ?: 'excerpt';
            $preview_value = get_post_meta( $post_id, '_fd_preview_value', true );
        }

        // 准备公开信息（所有用户都可以看到的信息）
        $public_data = [
            'postId'           => $post_id,
            'postType'         => $post->post_type,
            'status'           => $post->post_status,
            'shortUuid'        => get_post_meta( $post_id, '_fd_short_uuid', true ),
            'slug'             => $post->post_name,
            'title'            => $post->post_title,
            'excerpt'          => get_the_excerpt( $post ),
            'date'             => $post->post_date,
            'modified'         => $post->post_modified,
            'featuredImage'    => get_the_post_thumbnail_url( $post_id, 'full' ) ?: '', // 获取特色图片URL
            'categories'       => $categories,
            'tags'             => $tags,
            'customTaxonomies' => $custom_taxonomies_data,
            // 预览内容信息
            'previewContent'   => $preview_content,
            'previewMode'      => $preview_mode,
            'previewValue'     => $preview_value,
        ];

        // 准备完整信息（包含受保护内容）
        $full_data = array_merge( $public_data, [
            'content'   => $post->post_content,
        ]);

        $success = true;

        if ( $target_room === 'protected_content' ) {
            // 1. 公开信息推送给所有用户
            $public_result = $this->send_event( 'post:updated', 'public', $public_data );
            if ( ! $public_result ) {
                $success = false;
            }

            // 2. 完整信息推送给有权限的用户
            $authorized_users = FD_WebSocket_Push_Helper::get_post_authorized_users( $post_id, $post );
            foreach ( $authorized_users as $user_id ) {
                $result = $this->send_event( 'post:updated', 'user_' . $user_id, $full_data );
                if ( ! $result ) {
                    $success = false;
                }
            }

            return $success;
        } else {
            // 对于公开文章：直接推送完整信息到公开房间
            return $this->send_event( 'post:updated', $target_room, $full_data );
        }
    }
    
    /**
     * Send post updated for lists event
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public function send_post_updated_for_lists_event( $post_id, $post ) {
        // Get post categories
        $post_categories = wp_get_post_categories( $post_id );
        $categories = array_map( function( $cat_id ) {
            $category = get_category( $cat_id );
            return [
                'id'   => $cat_id,
                'slug' => $category->slug,
                'name' => $category->name,
            ];
        }, $post_categories );
        
        // Get post tags
        $post_tags = wp_get_post_tags( $post_id );
        $tags = array_map( function( $tag ) {
            return [
                'id'   => $tag->term_id,
                'slug' => $tag->slug,
                'name' => $tag->name,
            ];
        }, $post_tags );
        
        // Get custom taxonomies
        $custom_taxonomies_data = [];
        $custom_taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
        foreach ( $custom_taxonomies as $taxonomy ) {
            if ( ! $taxonomy->public ) continue;
            
            $terms = wp_get_post_terms( $post_id, $taxonomy->name );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $custom_taxonomies_data[$taxonomy->name] = array_map( function( $term ) {
                    return [
                        'id'   => $term->term_id,
                        'slug' => $term->slug,
                        'name' => $term->name,
                    ];
                }, $terms );
            }
        }
        
        // Get author info
        $author_id = $post->post_author;
        $author = get_the_author_meta( 'user_nicename', $author_id );

        $data = [
            'postId'           => $post_id,
            'postType'         => $post->post_type,
            'status'           => $post->post_status,
            'shortUuid'        => get_post_meta( $post_id, '_fd_short_uuid', true ),
            'slug'             => $post->post_name,
            'title'            => $post->post_title,
            'excerpt'          => get_the_excerpt( $post ),
            'date'             => $post->post_date,
            'modified'         => $post->post_modified,
            'authorSlug'       => $author, // Add author slug
            'categories'       => $categories,
            'tags'             => $tags,
            'customTaxonomies' => $custom_taxonomies_data,
        ];
        
        return $this->send_event( 'post:updated-for-lists', 'public', $data );
    }
    
    /**
     * Send post inserted event
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public function send_post_inserted_event( $post_id, $post ) {
        $data = [
            'postId'    => $post_id,
            'postType'  => $post->post_type,
            'status'    => $post->post_status,
            'shortUuid' => get_post_meta( $post_id, '_fd_short_uuid', true ),
            'slug'      => $post->post_name,
            'title'     => $post->post_title,
            'excerpt'   => get_the_excerpt( $post ),
            'date'      => $post->post_date,
            'modified'  => $post->post_modified,
        ];
        
        return $this->send_event( 'post:inserted', 'public', $data );
    }
    
    /**
     * Send post deleted event
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public function send_post_deleted_event( $post_id, $post ) {
        $data = [
            'postId'    => $post_id,
            'postType'  => $post->post_type,
            'status'    => $post->post_status,
            'shortUuid' => get_post_meta( $post_id, '_fd_short_uuid', true ),
            'slug'      => $post->post_name,
            'title'     => $post->post_title,
        ];
        
        return $this->send_event( 'post:deleted', 'public', $data );
    }
    
    /**
     * Send post status transition event
     *
     * @param string $event_type
     * @param WP_Post $post
     * @param string $old_status
     * @param string $new_status
     */
    public function send_post_status_transition_event( $event_type, $post, $old_status, $new_status ) {
        $data = [
            'postId'     => $post->ID,
            'postType'   => $post->post_type,
            'oldStatus'  => $old_status,
            'newStatus'  => $new_status,
            'shortUuid'  => get_post_meta( $post->ID, '_fd_short_uuid', true ),
            'slug'       => $post->post_name,
            'title'      => $post->post_title,
            'excerpt'    => get_the_excerpt( $post ),
            'date'       => $post->post_date,
            'modified'   => $post->post_modified,
        ];
        
        return $this->send_event( $event_type, 'public', $data );
    }
    
    /**
     * Send taxonomy updated event
     *
     * @param string $event_type
     * @param int $term_id
     * @param string $taxonomy
     */
    public function send_taxonomy_updated_event( $event_type, $term_id, $taxonomy, $extra_data = [] ) {
        $term = get_term( $term_id, $taxonomy );
        
        $data = array_merge( [
            'termId'   => $term_id,
            'taxonomy' => $taxonomy,
            'slug'     => $term ? $term->slug : '',
            'name'     => $term ? $term->name : '',
        ], is_array( $extra_data ) ? $extra_data : [] );
        
        return $this->send_event( $event_type, 'public', $data );
    }
    
    /**
     * Send list item added/removed event
     *
     * @param string $event_type ('list:item-added' or 'list:item-removed')
     * @param int $post_id
     * @param array $affected_terms
     */
    public function send_list_item_event( $event_type, $post_id, $affected_terms ) {
        $data = [
            'postId'        => $post_id,
            'affectedTerms' => $affected_terms,
        ];
        
        return $this->send_event( $event_type, 'public', $data );
    }
    
    /**
     * Send post unlocked event
     *
     * @param int $user_id
     * @param int $post_id
     */
    public function send_post_unlocked_event( $user_id, $post_id ) {
        $data = [
            'postId' => (int) $post_id,
        ];

        return $this->send_event( 'post:unlocked', 'user_' . $user_id, $data );
    }

    /**
     * Send menu updated event
     *
     * @param int $menu_id Menu ID
     * @param string $action Action type (updated, created, deleted, item_updated, locations_updated, customizer_updated)
     * @param string $menu_name Menu name
     */
    public function send_menu_updated_event( $menu_id, $action, $menu_name ) {
        $data = [
            'menuId' => (int) $menu_id,
            'action' => $action,
            'menuName' => $menu_name,
            'timestamp' => current_time( 'mysql' ),
        ];

        FD_WebSocket_Push_Helper::log( 'Sending menu updated event: ' . $action . ' for menu: ' . $menu_name );

        return $this->send_event( 'menu:updated', 'public', $data );
    }

    /**
     * Send VI settings updated event
     *
     * @param string $option_name 选项名称
     * @param string $setting_type 设置类型 (color, typography, ui_token, branding, search, general)
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     */
    public function send_vi_settings_updated_event( $option_name, $setting_type, $old_value, $new_value ) {
        $data = [
            'optionName' => $option_name,
            'settingType' => $setting_type,
            'oldValue' => $old_value,
            'newValue' => $new_value,
            'timestamp' => current_time( 'mysql' ),
        ];

        FD_WebSocket_Push_Helper::log( 'Sending VI settings updated event: ' . $setting_type . ' for option: ' . $option_name );

        return $this->send_event( 'vi-settings:updated', 'public', $data );
    }

    /**
     * Send share settings updated event
     *
     * @param array $changes 变更分析结果
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     */
    public function send_share_settings_updated_event( $changes, $old_value, $new_value ) {
        $data = [
            'changes' => $changes,
            'oldValue' => $old_value,
            'newValue' => $new_value,
            'timestamp' => current_time( 'mysql' ),
        ];

        $change_type = isset( $changes['type'] ) ? $changes['type'] : 'unknown';
        FD_WebSocket_Push_Helper::log( 'Sending share settings updated event: ' . $change_type );

        return $this->send_event( 'share-settings:updated', 'public', $data );
    }

    /**
     * Send posts per page settings updated event
     *
     * @param string $option_name 选项名称
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     */
    public function send_posts_per_page_settings_updated_event( $option_name, $old_value, $new_value ) {
        $data = [
            'optionName' => $option_name,
            'oldValue' => $old_value,
            'newValue' => $new_value,
            'timestamp' => current_time( 'mysql' ),
        ];

        FD_WebSocket_Push_Helper::log( 'Sending posts per page settings updated event for option: ' . $option_name );

        return $this->send_event( 'posts-per-page-settings:updated', 'public', $data );
    }

    /**
     * Send discussion settings updated event
     *
     * @param string $option_name 选项名称
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     */
    public function send_discussion_settings_updated_event( $option_name, $old_value, $new_value ) {
        $data = [
            'optionName' => $option_name,
            'oldValue' => $old_value,
            'newValue' => $new_value,
            'timestamp' => current_time( 'mysql' ),
        ];

        FD_WebSocket_Push_Helper::log( 'Sending discussion settings updated event for option: ' . $option_name );

        return $this->send_event( 'discussion-settings:updated', 'public', $data );
    }

    /**
     * Send route prefixes updated event
     *
     * @param string $option_name 选项名称
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     */
    public function send_route_prefixes_updated_event( $option_name, $old_value, $new_value ) {
        $data = [
            'optionName' => $option_name,
            'oldValue' => $old_value,
            'newValue' => $new_value,
            'timestamp' => current_time( 'mysql' ),
        ];

        FD_WebSocket_Push_Helper::log( 'Sending route prefixes updated event for option: ' . $option_name );

        return $this->send_event( 'route-prefixes:updated', 'public', $data );
    }

    /**
     * Send member levels updated event
     */
    public function send_member_levels_updated_event() {
        $data = [
            'timestamp' => current_time( 'mysql' ),
            'message' => 'Member levels configuration updated'
        ];

        FD_WebSocket_Push_Helper::log( 'Sending member levels updated event' );

        return $this->send_event( 'member-levels:updated', 'public', $data );
    }

    /**
     * Send user member level updated event
     *
     * @param int $user_id 用户ID
     * @param int|null $level_id 新的会员等级ID，null表示移除等级
     */
    public function send_user_member_level_updated_event( $user_id, $level_id ) {
        $data = [
            'userId' => $user_id,
            'levelId' => $level_id,
            'timestamp' => current_time( 'mysql' ),
        ];

        // 获取会员等级信息
        if ( $level_id && function_exists( 'fd_member_get_member_level' ) ) {
            $level_info = fd_member_get_member_level( $level_id );
            if ( $level_info ) {
                $data['levelInfo'] = $level_info;
            }
        }

        FD_WebSocket_Push_Helper::log( "Sending user member level updated event for user {$user_id}, level {$level_id}" );

        return $this->send_event( 'user-member-level:updated', "user_{$user_id}", $data );
    }

    /**
     * Send user member expiration updated event
     *
     * @param int $user_id 用户ID
     * @param int|null $expiration_time 过期时间戳
     */
    public function send_user_member_expiration_updated_event( $user_id, $expiration_time ) {
        $data = [
            'userId' => $user_id,
            'expirationTime' => $expiration_time,
            'timestamp' => current_time( 'mysql' ),
        ];

        FD_WebSocket_Push_Helper::log( "Sending user member expiration updated event for user {$user_id}" );

        return $this->send_event( 'user-member-expiration:updated', "user_{$user_id}", $data );
    }

    /**
     * Send member upgrade completed event
     *
     * @param int $user_id 用户ID
     * @param int $level_id 新的会员等级ID
     * @param int $order_id 订单ID
     */
    public function send_member_upgrade_completed_event( $user_id, $level_id, $order_id ) {
        $data = [
            'userId' => $user_id,
            'levelId' => $level_id,
            'orderId' => $order_id,
            'timestamp' => current_time( 'mysql' ),
        ];

        // 获取会员等级信息
        if ( function_exists( 'fd_member_get_member_level' ) ) {
            $level_info = fd_member_get_member_level( $level_id );
            if ( $level_info ) {
                $data['levelInfo'] = $level_info;
            }
        }

        FD_WebSocket_Push_Helper::log( "Sending member upgrade completed event for user {$user_id}, level {$level_id}" );

        return $this->send_event( 'member-upgrade:completed', "user_{$user_id}", $data );
    }

    /**
     * 发送常规设置更新事件
     */
    public function send_general_settings_updated_event( $setting_name, $old_value, $new_value ) {
        // 获取完整的常规设置数据
        $general_settings = array(
            'title' => get_option('blogname', ''),
            'description' => get_option('blogdescription', ''),
            'url' => get_option('home', ''),
            'language' => get_option('WPLANG', 'zh_CN') ?: 'zh_CN',
            'timezone' => get_option('timezone_string', 'Asia/Shanghai') ?: 'Asia/Shanghai',
            'dateFormat' => get_option('date_format', 'Y年n月j日'),
            'timeFormat' => get_option('time_format', 'H:i'),
            'startOfWeek' => (int) get_option('start_of_week', 1)
        );

        $data = array(
            'changedSetting' => array(
                'name' => $setting_name,
                'oldValue' => $old_value,
                'newValue' => $new_value
            ),
            'generalSettings' => $general_settings,
            'timestamp' => current_time('mysql')
        );

        FD_WebSocket_Push_Helper::log( "Sending general settings updated event: {$setting_name}" );

        return $this->send_event( 'general-settings:updated', 'public', $data );
    }

    /**
     * Send homepage layout updated event
     *
     * @param array $layout_data 布局数据
     */
    public function send_homepage_layout_updated_event( $layout_data ) {
        $data = [
            'version' => $layout_data['version'] ?? '1.0',
            'lastUpdated' => $layout_data['last_updated'] ?? current_time('mysql'),
            'rowsCount' => count($layout_data['rows'] ?? []),
            'timestamp' => current_time( 'mysql' ),
        ];

        FD_WebSocket_Push_Helper::log( 'Sending homepage layout updated event' );

        return $this->send_event( 'homepage-layout:updated', 'public', $data );
    }
}
