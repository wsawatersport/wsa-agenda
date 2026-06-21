<?php
defined( 'ABSPATH' ) || exit;

class WSA_REST_API {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        $ns = 'wsa/v1';

        // Events
        register_rest_route( $ns, '/events', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'get_events' ],    'permission_callback' => '__return_true' ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_event' ],  'permission_callback' => [ __CLASS__, 'board_permission' ] ],
        ] );
        register_rest_route( $ns, '/events/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ __CLASS__, 'get_event' ],    'permission_callback' => '__return_true' ],
            [ 'methods' => 'PATCH',  'callback' => [ __CLASS__, 'update_event' ], 'permission_callback' => [ __CLASS__, 'board_permission' ] ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_event' ], 'permission_callback' => [ __CLASS__, 'board_permission' ] ],
        ] );

        // Public blocks
        register_rest_route( $ns, '/public-blocks', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_public_blocks' ],
            'permission_callback' => '__return_true',
        ] );

        // RSVP
        register_rest_route( $ns, '/events/(?P<id>\d+)/rsvp', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'post_rsvp' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $ns, '/events/(?P<id>\d+)/rsvp', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_rsvp_list' ],
            'permission_callback' => [ __CLASS__, 'board_permission' ],
        ] );
        register_rest_route( $ns, '/events/(?P<id>\d+)/rsvp/csv', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'download_rsvp_csv' ],
            'permission_callback' => [ __CLASS__, 'board_permission' ],
        ] );

        // Categories (read)
        register_rest_route( $ns, '/categories', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_categories' ],
            'permission_callback' => '__return_true',
        ] );

        // ICS invite — send an .ics file to any email (no login required)
        register_rest_route( $ns, '/events/(?P<id>\d+)/ics', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'post_ics_invite' ],
            'permission_callback' => '__return_true',
        ] );

        // Reminder subscriptions — public POST, board-only GET/DELETE/CSV
        register_rest_route( $ns, '/events/(?P<id>\d+)/subscribe', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'post_subscribe' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $ns, '/events/(?P<id>\d+)/subscribers', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_subscribers' ],
            'permission_callback' => [ __CLASS__, 'board_permission' ],
        ] );
        register_rest_route( $ns, '/events/(?P<id>\d+)/subscribers/csv', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'download_subscribers_csv' ],
            'permission_callback' => [ __CLASS__, 'board_permission' ],
        ] );
        register_rest_route( $ns, '/events/(?P<id>\d+)/subscribers/(?P<sub_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ __CLASS__, 'delete_subscriber' ],
            'permission_callback' => [ __CLASS__, 'board_permission' ],
        ] );

        // Unsubscribe via token — outputs HTML page (no auth required)
        register_rest_route( $ns, '/unsubscribe', [
            'methods'             => 'GET',
            'callback'            => [ 'WSA_Subscriptions', 'handle_unsubscribe' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /* ── Permission ─────────────────────────────────────────────────────── */

    public static function board_permission(): bool {
        return WSA_CPT::current_user_can_manage();
    }

    /* ── Events GET ─────────────────────────────────────────────────────── */

    public static function get_events( WP_REST_Request $req ): WP_REST_Response {
        try {
            $start    = sanitize_text_field( $req->get_param( 'start' ) ?: '' );
            $end      = sanitize_text_field( $req->get_param( 'end' ) ?: '' );
            $cat_slug = sanitize_text_field( $req->get_param( 'category' ) ?: '' );

            $args = [
                'post_type'      => 'wsa_event',
                'post_status'    => 'publish',
                'posts_per_page' => 500,
                'meta_query'     => [],
                'tax_query'      => [],
            ];

            if ( $start ) {
                $args['meta_query'][] = [
                    'key'     => 'wsa_event_end',
                    'value'   => $start,
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ];
            }
            if ( $end ) {
                $args['meta_query'][] = [
                    'key'     => 'wsa_event_start',
                    'value'   => $end,
                    'compare' => '<=',
                    'type'    => 'DATETIME',
                ];
            }
            if ( $cat_slug ) {
                $args['tax_query'][] = [
                    'taxonomy' => 'wsa_event_category',
                    'field'    => 'slug',
                    'terms'    => $cat_slug,
                ];
            }

            $query  = new WP_Query( $args );
            $events = [];
            foreach ( $query->posts as $post ) {
                $events[] = self::format_event( $post );
            }

            return rest_ensure_response( $events );
        } catch ( Exception $e ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    public static function get_event( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $post = get_post( (int) $req['id'] );
        if ( ! $post || 'wsa_event' !== $post->post_type ) {
            return new WP_Error( 'not_found', __( 'Evenement niet gevonden.', 'wsa-agenda' ), [ 'status' => 404 ] );
        }
        return rest_ensure_response( self::format_event( $post, true ) );
    }

    /* ── Events POST / PATCH / DELETE ───────────────────────────────────── */

    public static function create_event( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $body = $req->get_json_params();
        $id   = wp_insert_post( [
            'post_type'    => 'wsa_event',
            'post_status'  => 'publish',
            'post_title'   => sanitize_text_field( $body['title'] ?? '' ),
            'post_content' => wp_kses_post( $body['description'] ?? '' ),
        ], true );

        if ( is_wp_error( $id ) ) {
            return $id;
        }

        self::save_event_meta( $id, $body );
        return rest_ensure_response( self::format_event( get_post( $id ), true ) );
    }

    public static function update_event( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $id   = (int) $req['id'];
        $post = get_post( $id );
        if ( ! $post || 'wsa_event' !== $post->post_type ) {
            return new WP_Error( 'not_found', __( 'Evenement niet gevonden.', 'wsa-agenda' ), [ 'status' => 404 ] );
        }

        $body   = $req->get_json_params();
        $update = [ 'ID' => $id ];
        if ( isset( $body['title'] ) ) {
            $update['post_title'] = sanitize_text_field( $body['title'] );
        }
        if ( isset( $body['description'] ) ) {
            $update['post_content'] = wp_kses_post( $body['description'] );
        }
        wp_update_post( $update );
        self::save_event_meta( $id, $body );

        return rest_ensure_response( self::format_event( get_post( $id ), true ) );
    }

    public static function delete_event( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $id   = (int) $req['id'];
        $post = get_post( $id );
        if ( ! $post || 'wsa_event' !== $post->post_type ) {
            return new WP_Error( 'not_found', __( 'Evenement niet gevonden.', 'wsa-agenda' ), [ 'status' => 404 ] );
        }
        wp_delete_post( $id, true );
        return rest_ensure_response( [ 'deleted' => true ] );
    }

    /* ── Meta helpers ───────────────────────────────────────────────────── */

    private static function save_event_meta( int $post_id, array $body ): void {
        $meta_map = [
            'start'        => 'wsa_event_start',
            'end'          => 'wsa_event_end',
            'rsvp_enabled' => 'wsa_rsvp_enabled',
            'rsvp_limit'   => 'wsa_rsvp_limit',
        ];
        foreach ( $meta_map as $key => $meta_key ) {
            if ( array_key_exists( $key, $body ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $body[ $key ] ) );
            }
        }

        // All-day flag
        if ( array_key_exists( 'allday', $body ) ) {
            update_post_meta( $post_id, 'wsa_event_allday', $body['allday'] ? '1' : '0' );
        }

        // Category
        if ( ! empty( $body['category_slug'] ) ) {
            wp_set_object_terms( $post_id, sanitize_text_field( $body['category_slug'] ), 'wsa_event_category' );
        }

        // Attachments — validate each ID is a real attachment of allowed type
        if ( isset( $body['attachments'] ) && is_array( $body['attachments'] ) ) {
            $allowed_types = [ 'application/pdf', 'image/jpeg', 'image/png', 'image/webp' ];
            $clean_ids     = [];
            foreach ( $body['attachments'] as $att_id ) {
                $att_id = (int) $att_id;
                if ( ! $att_id ) {
                    continue;
                }
                $mime = get_post_mime_type( $att_id );
                if ( in_array( $mime, $allowed_types, true ) ) {
                    $clean_ids[] = $att_id;
                }
            }
            update_post_meta( $post_id, 'wsa_event_attachments', wp_json_encode( $clean_ids ) );
        }
    }

    /* ── Format event ───────────────────────────────────────────────────── */

    private static function format_event( WP_Post $post, bool $full = false ): array {
        $terms    = get_the_terms( $post->ID, 'wsa_event_category' );
        $term     = ( is_array( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;
        $cat      = $term ? [
            'slug'  => $term->slug,
            'name'  => $term->name,
            'color' => get_term_meta( $term->term_id, 'wsa_category_color', true ) ?: '#888780',
        ] : null;

        // Short plain-text excerpt for list view (always included).
        $raw_content = wp_strip_all_tags( $post->post_content );
        $excerpt     = mb_strlen( $raw_content ) > 100
            ? mb_substr( $raw_content, 0, 100 ) . '…'
            : $raw_content;

        $event = [
            'id'           => $post->ID,
            'uuid'         => get_post_meta( $post->ID, 'wsa_event_uuid', true ),
            'title'        => $post->post_title,
            'start'        => get_post_meta( $post->ID, 'wsa_event_start', true ),
            'end'          => get_post_meta( $post->ID, 'wsa_event_end', true ),
            'category'     => $cat,
            'excerpt'      => $excerpt,
            'allday'       => (bool) get_post_meta( $post->ID, 'wsa_event_allday', true ),
            'rsvp_enabled' => (bool) get_post_meta( $post->ID, 'wsa_rsvp_enabled', true ),
            'rsvp_limit'   => (int) get_post_meta( $post->ID, 'wsa_rsvp_limit', true ),
            'rsvp_count'   => WSA_DB::count_rsvp( $post->ID ),
        ];

        if ( $full ) {
            $event['description'] = $post->post_content;
            $event['attachments'] = self::expand_attachments( $post->ID );
        }

        return $event;
    }

    private static function expand_attachments( int $post_id ): array {
        $raw = get_post_meta( $post_id, 'wsa_event_attachments', true );
        $ids = $raw ? json_decode( $raw, true ) : [];
        if ( ! is_array( $ids ) ) {
            return [];
        }

        $out           = [];
        $allowed_types = [ 'application/pdf', 'image/jpeg', 'image/png', 'image/webp' ];

        foreach ( $ids as $id ) {
            $id   = (int) $id;
            $mime = get_post_mime_type( $id );
            if ( ! in_array( $mime, $allowed_types, true ) ) {
                continue;
            }
            $url  = wp_get_attachment_url( $id );
            $meta = wp_get_attachment_metadata( $id );
            $item = [
                'id'            => $id,
                'url'           => $url,
                'filename'      => basename( get_attached_file( $id ) ),
                'filesize'      => (int) filesize( get_attached_file( $id ) ),
                'mime_type'     => $mime,
                'thumbnail_url' => null,
            ];
            if ( str_starts_with( $mime, 'image/' ) ) {
                $thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
                if ( $thumb ) {
                    $item['thumbnail_url'] = $thumb[0];
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    /* ── Public blocks ──────────────────────────────────────────────────── */

    public static function get_public_blocks( WP_REST_Request $req ): WP_REST_Response {
        try {
            $start = sanitize_text_field( $req->get_param( 'start' ) ?: date( 'Y-m-d' ) );
            $end   = sanitize_text_field( $req->get_param( 'end' )   ?: date( 'Y-m-d', strtotime( '+1 year' ) ) );
            return rest_ensure_response( WSA_Public_Blocks::get( $start, $end ) );
        } catch ( Exception $e ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    /* ── RSVP ───────────────────────────────────────────────────────────── */

    public static function post_rsvp( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $body   = $req->get_json_params();
        $result = WSA_RSVP::register(
            (int) $req['id'],
            (string) ( $body['name']  ?? '' ),
            (string) ( $body['email'] ?? '' )
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public static function get_rsvp_list( WP_REST_Request $req ): WP_REST_Response {
        try {
            $rows = WSA_DB::get_rsvp_for_event( (int) $req['id'] );
            return rest_ensure_response( $rows );
        } catch ( Exception $e ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    public static function download_rsvp_csv( WP_REST_Request $req ): void {
        if ( ! WSA_CPT::current_user_can_manage() ) {
            wp_die( 'Geen toegang.', 403 );
        }
        $event_id = (int) $req['id'];
        $post     = get_post( $event_id );
        $filename = 'rsvp-' . sanitize_title( $post ? $post->post_title : 'evenement' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo WSA_RSVP::build_csv( $event_id );
        exit;
    }

    /* ── Categories ─────────────────────────────────────────────────────── */

    public static function get_categories(): WP_REST_Response {
        try {
            return rest_ensure_response( WSA_Taxonomy::get_all() );
        } catch ( Exception $e ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    /* ── ICS invite ──────────────────────────────────────────────────────── */

    public static function post_ics_invite( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $body   = $req->get_json_params();
        $email  = sanitize_email( (string) ( $body['email'] ?? '' ) );
        $result = WSA_Subscriptions::send_ics_invite( (int) $req['id'], $email );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( [ 'sent' => true ] );
    }

    /* ── Subscriptions ───────────────────────────────────────────────────── */

    public static function post_subscribe( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $body   = $req->get_json_params();
        $result = WSA_Subscriptions::subscribe(
            (int) $req['id'],
            (string) ( $body['name']  ?? '' ),
            (string) ( $body['email'] ?? '' )
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public static function get_subscribers( WP_REST_Request $req ): WP_REST_Response {
        try {
            $rows = WSA_Subscriptions::get_subscriptions_for_event( (int) $req['id'] );
            return rest_ensure_response( $rows );
        } catch ( Exception $e ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    public static function download_subscribers_csv( WP_REST_Request $req ): void {
        if ( ! WSA_CPT::current_user_can_manage() ) {
            wp_die( 'Geen toegang.', 403 );
        }
        $event_id = (int) $req['id'];
        $post     = get_post( $event_id );
        $filename = 'aanmeldingen-' . sanitize_title( $post ? $post->post_title : 'evenement' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo WSA_Subscriptions::build_csv( $event_id );
        exit;
    }

    public static function delete_subscriber( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        global $wpdb;
        $event_id = (int) $req['id'];
        $sub_id   = (int) $req['sub_id'];

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wsa_subscriptions WHERE id = %d AND event_id = %d",
                $sub_id,
                $event_id
            )
        );
        if ( ! $exists ) {
            return new WP_Error( 'not_found', __( 'Aanmelding niet gevonden.', 'wsa-agenda' ), [ 'status' => 404 ] );
        }

        WSA_Subscriptions::delete_by_id( $sub_id );
        return rest_ensure_response( [ 'deleted' => true ] );
    }
}
