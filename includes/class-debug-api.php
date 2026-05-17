<?php
/**
 * Debug REST API for bridge visualization.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FD_WebSocket_Push_Debug_API {

    /**
     * Event logger instance.
     *
     * @var FD_WebSocket_Push_Event_Logger
     */
    private $event_logger;

    public function __construct() {
        $this->event_logger = new FD_WebSocket_Push_Event_Logger();
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'fd-websocket-push/v1', '/debug/events', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_events' ),
                'permission_callback' => array( $this, 'authorize_request' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'clear_events' ),
                'permission_callback' => array( $this, 'authorize_request' ),
            ),
        ) );
    }

    /**
     * Authorize the internal frontend debug proxy.
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public function authorize_request( $request ) {
        $debug_token = defined( 'FD_DEBUG_TOKEN' ) ? FD_DEBUG_TOKEN : getenv( 'FD_DEBUG_TOKEN' );
        $debug_token = is_string( $debug_token ) ? trim( $debug_token ) : '';
        $request_debug_token = (string) $request->get_header( 'x-fd-debug-token' );

        if ( ! empty( $debug_token ) && hash_equals( $debug_token, $request_debug_token ) ) {
            return true;
        }

        $revalidate_secret = FD_WebSocket_Push_Helper::get_revalidation_secret();
        $request_revalidate_secret = (string) $request->get_header( 'x-revalidate-secret' );

        if ( ! empty( $revalidate_secret ) && hash_equals( $revalidate_secret, $request_revalidate_secret ) ) {
            return true;
        }

        return new WP_Error(
            'fd_websocket_push_debug_unauthorized',
            'Unauthorized',
            array( 'status' => rest_authorization_required_code() )
        );
    }

    /**
     * Return recent WordPress-side websocket event logs as bridge events.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_events( $request ) {
        $limit = min( max( (int) $request->get_param( 'limit' ), 1 ), 300 );
        $events = $this->event_logger->get_events( $limit, 0 );

        return rest_ensure_response( array(
            'service' => 'fd-websocket-push',
            'now'     => gmdate( 'c' ),
            'events'  => array_map( array( $this, 'format_event' ), $events ),
        ) );
    }

    /**
     * Clear WordPress-side websocket event logs.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function clear_events( $request ) {
        $deleted = $this->event_logger->clear_events();

        if ( false === $deleted ) {
            return new WP_Error(
                'fd_websocket_push_debug_clear_failed',
                'Failed to clear debug events',
                array( 'status' => 500 )
            );
        }

        return rest_ensure_response( array(
            'ok'      => true,
            'service' => 'fd-websocket-push',
            'now'     => gmdate( 'c' ),
            'deleted' => (int) $deleted,
        ) );
    }

    /**
     * Format a database row for the frontend bridge console.
     *
     * @param object $event
     * @return array
     */
    private function format_event( $event ) {
        $event_data = json_decode( $event->event_data ?? '', true );
        $response_data = json_decode( $event->response_data ?? '', true );
        $payload = is_array( $event_data ) && isset( $event_data['data'] ) && is_array( $event_data['data'] )
            ? $event_data['data']
            : array();

        return array(
            'id'         => 'wp-' . (string) $event->id,
            'createdAt'  => $this->format_datetime( $event->created_at ),
            'stage'      => $this->get_event_stage( $event->event_type, $event->status ),
            'traceId'    => $event->trace_id,
            'event'      => $event->event_type,
            'status'     => $event->status,
            'durationMs' => $event->duration_ms !== null ? (int) $event->duration_ms : null,
            'message'    => $event->error_message,
            'payload'    => array(
                'eventId'      => (int) $event->id,
                'targetRoom'   => $event->target_room,
                'response'     => $response_data,
                'dataSummary'  => $this->summarize_payload( $payload ),
                'payloadTrace' => isset( $payload['_fdTrace'] ) && is_array( $payload['_fdTrace'] ) ? $payload['_fdTrace'] : null,
            ),
        );
    }

    /**
     * Map WordPress event rows to bridge stages.
     *
     * @param string $event_type
     * @param string $status
     * @return string
     */
    private function get_event_stage( $event_type, $status ) {
        if ( strpos( (string) $event_type, 'cache:revalidate' ) === 0 ) {
            return $status === 'failed' ? 'wordpress:cache-failed' : 'wordpress:cache-revalidated';
        }

        return 'wordpress:websocket-' . ( $status === 'failed' ? 'failed' : 'sent' );
    }

    /**
     * Convert a MySQL datetime to ISO-ish string.
     *
     * @param string $value
     * @return string
     */
    private function format_datetime( $value ) {
        $timestamp = strtotime( (string) $value );
        return $timestamp ? gmdate( 'c', $timestamp ) : gmdate( 'c' );
    }

    /**
     * Summarize payload without large content bodies.
     *
     * @param array $payload
     * @return array
     */
    private function summarize_payload( $payload ) {
        $summary = array();
        foreach ( array( 'postId', 'postType', 'status', 'shortUuid', 'slug', 'termId', 'taxonomy', 'action', 'menuId', 'menuName', 'optionName', 'settingType', 'endpoint', 'body', 'description', 'traceId' ) as $key ) {
            if ( array_key_exists( $key, $payload ) ) {
                $summary[ $key ] = $payload[ $key ];
            }
        }

        if ( isset( $payload['affectedTerms'] ) && is_array( $payload['affectedTerms'] ) ) {
            $summary['affectedTerms'] = count( $payload['affectedTerms'] );
        }

        $summary['keys'] = array_values( array_filter(
            array_keys( $payload ),
            function ( $key ) {
                return $key !== 'content';
            }
        ) );

        return $summary;
    }
}
