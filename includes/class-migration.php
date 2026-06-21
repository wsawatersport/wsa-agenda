<?php
defined( 'ABSPATH' ) || exit;

class WSA_Migration {

    public static function init(): void {
        add_action( 'wp_ajax_wsa_export',        [ __CLASS__, 'ajax_export' ] );
        add_action( 'wp_ajax_wsa_import_dryrun', [ __CLASS__, 'ajax_import_dryrun' ] );
        add_action( 'wp_ajax_wsa_import_apply',  [ __CLASS__, 'ajax_import_apply' ] );
    }

    /* ── Export ─────────────────────────────────────────────────────────── */

    public static function ajax_export(): void {
        check_ajax_referer( 'wsa_migration' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Geen toegang.', 403 );
        }

        $export = self::build_export();

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="wsa-agenda-export-' . date( 'Y-m-d' ) . '.json"' );
        echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        exit;
    }

    public static function build_export(): array {
        // Events
        $posts  = get_posts( [
            'post_type'      => 'wsa_event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ] );
        $events = [];
        foreach ( $posts as $post ) {
            $terms    = get_the_terms( $post->ID, 'wsa_event_category' );
            $cat_slug = ( is_array( $terms ) && $terms ) ? $terms[0]->slug : '';

            // Expand attachment URLs
            $raw_att  = get_post_meta( $post->ID, 'wsa_event_attachments', true );
            $att_ids  = $raw_att ? json_decode( $raw_att, true ) : [];
            $att_urls = [];
            if ( is_array( $att_ids ) ) {
                foreach ( $att_ids as $id ) {
                    $url = wp_get_attachment_url( $id );
                    if ( $url ) {
                        $att_urls[] = $url;
                    }
                }
            }

            $events[] = [
                'uuid'        => get_post_meta( $post->ID, 'wsa_event_uuid', true ),
                'title'       => $post->post_title,
                'description' => $post->post_content,
                'start'       => get_post_meta( $post->ID, 'wsa_event_start', true ),
                'end'         => get_post_meta( $post->ID, 'wsa_event_end', true ),
                'category'    => $cat_slug,
                'rsvp_enabled'=> (bool) get_post_meta( $post->ID, 'wsa_rsvp_enabled', true ),
                'rsvp_limit'  => (int)  get_post_meta( $post->ID, 'wsa_rsvp_limit', true ),
                'attachments' => $att_urls,
            ];
        }

        // Categories
        $terms = get_terms( [ 'taxonomy' => 'wsa_event_category', 'hide_empty' => false ] );
        $cats  = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $cats[] = [
                    'name'        => $t->name,
                    'slug'        => $t->slug,
                    'color'       => get_term_meta( $t->term_id, 'wsa_category_color', true ) ?: '#888780',
                    'description' => $t->description,
                ];
            }
        }

        return [
            'schema_version' => WSA_SCHEMA_VERSION,
            'plugin_version' => WSA_AGENDA_VERSION,
            'exported_at'    => gmdate( 'c' ),
            'site_url'       => get_site_url(),
            'events'         => $events,
            'categories'     => $cats,
            'vacations'      => WSA_DB::get_vacations(),
            'ics_feeds'      => WSA_DB::get_ics_feeds(),
        ];
    }

    /* ── Dry-run ────────────────────────────────────────────────────────── */

    public static function ajax_import_dryrun(): void {
        check_ajax_referer( 'wsa_migration' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Geen toegang.', 403 );
        }

        $data = self::parse_import_body();
        if ( is_wp_error( $data ) ) {
            wp_send_json_error( $data->get_error_message() );
        }

        wp_send_json_success( self::dry_run_summary( $data ) );
    }

    /* ── Apply import ───────────────────────────────────────────────────── */

    public static function ajax_import_apply(): void {
        check_ajax_referer( 'wsa_migration' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Geen toegang.', 403 );
        }

        $data = self::parse_import_body();
        if ( is_wp_error( $data ) ) {
            wp_send_json_error( $data->get_error_message() );
        }

        $mode   = sanitize_key( $_POST['mode'] ?? 'merge' ); // 'merge' or 'replace'
        $report = self::apply_import( $data, $mode );
        wp_send_json_success( $report );
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    private static function parse_import_body(): array|WP_Error {
        if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
            // Try raw POST body
            $raw = file_get_contents( 'php://input' );
        } else {
            $raw = file_get_contents( $_FILES['import_file']['tmp_name'] );
        }

        if ( ! $raw ) {
            return new WP_Error( 'empty', 'Geen bestand ontvangen.' );
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_json', 'Ongeldig JSON-bestand.' );
        }
        if ( ( $data['schema_version'] ?? 0 ) !== WSA_SCHEMA_VERSION ) {
            return new WP_Error( 'schema_mismatch', sprintf( 'Verwacht schema_version %d.', WSA_SCHEMA_VERSION ) );
        }
        return $data;
    }

    private static function dry_run_summary( array $data ): array {
        $now      = time();
        $one_year = $now - YEAR_IN_SECONDS;
        $warnings = [];

        foreach ( $data['events'] ?? [] as $ev ) {
            if ( empty( $ev['category'] ) ) {
                $warnings[] = sprintf( '"%s" heeft geen categorie.', $ev['title'] ?? '?' );
            }
            $start_ts = $ev['start'] ? strtotime( $ev['start'] ) : 0;
            if ( $start_ts && $start_ts < $one_year ) {
                $warnings[] = sprintf( '"%s" is ouder dan 1 jaar (mogelijk testdata).', $ev['title'] ?? '?' );
            }
        }

        return [
            'events'     => count( $data['events']     ?? [] ),
            'categories' => count( $data['categories'] ?? [] ),
            'vacations'  => count( $data['vacations']  ?? [] ),
            'ics_feeds'  => count( $data['ics_feeds']  ?? [] ),
            'warnings'   => $warnings,
        ];
    }

    private static function apply_import( array $data, string $mode ): array {
        $report = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'warnings' => [] ];

        if ( 'replace' === $mode ) {
            // Delete all existing events
            $existing = get_posts( [ 'post_type' => 'wsa_event', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] );
            foreach ( $existing as $id ) {
                wp_delete_post( $id, true );
            }
        }

        // Upsert categories first
        foreach ( $data['categories'] ?? [] as $cat ) {
            $existing_term = get_term_by( 'slug', $cat['slug'], 'wsa_event_category' );
            if ( $existing_term ) {
                wp_update_term( $existing_term->term_id, 'wsa_event_category', [
                    'name'        => $cat['name'],
                    'description' => $cat['description'] ?? '',
                ] );
                update_term_meta( $existing_term->term_id, 'wsa_category_color', $cat['color'] ?? '#888780' );
            } else {
                $result = wp_insert_term( $cat['name'], 'wsa_event_category', [ 'description' => $cat['description'] ?? '' ] );
                if ( ! is_wp_error( $result ) ) {
                    update_term_meta( $result['term_id'], 'wsa_category_color', $cat['color'] ?? '#888780' );
                }
            }
        }

        // Events
        foreach ( $data['events'] ?? [] as $ev ) {
            $uuid      = sanitize_text_field( $ev['uuid'] ?? '' );
            $post_id   = null;

            if ( $uuid ) {
                $found = get_posts( [
                    'post_type'  => 'wsa_event',
                    'post_status'=> 'any',
                    'meta_key'   => 'wsa_event_uuid',
                    'meta_value' => $uuid,
                    'fields'     => 'ids',
                ] );
                $post_id = $found[0] ?? null;
            }

            $post_data = [
                'post_type'    => 'wsa_event',
                'post_status'  => 'publish',
                'post_title'   => sanitize_text_field( $ev['title'] ?? '' ),
                'post_content' => wp_kses_post( $ev['description'] ?? '' ),
            ];

            if ( $post_id ) {
                $post_data['ID'] = $post_id;
                wp_update_post( $post_data );
                $report['updated']++;
            } else {
                $post_id = wp_insert_post( $post_data );
                if ( is_wp_error( $post_id ) ) {
                    $report['skipped']++;
                    continue;
                }
                if ( $uuid ) {
                    update_post_meta( $post_id, 'wsa_event_uuid', $uuid );
                }
                $report['created']++;
            }

            update_post_meta( $post_id, 'wsa_event_start', sanitize_text_field( $ev['start'] ?? '' ) );
            update_post_meta( $post_id, 'wsa_event_end',   sanitize_text_field( $ev['end']   ?? '' ) );
            update_post_meta( $post_id, 'wsa_rsvp_enabled', $ev['rsvp_enabled'] ? '1' : '0' );
            update_post_meta( $post_id, 'wsa_rsvp_limit',   (int) ( $ev['rsvp_limit'] ?? 0 ) );

            if ( ! empty( $ev['category'] ) ) {
                wp_set_object_terms( $post_id, sanitize_text_field( $ev['category'] ), 'wsa_event_category' );
            }

            // Re-download attachments
            if ( ! empty( $ev['attachments'] ) && is_array( $ev['attachments'] ) ) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                $att_ids = [];
                foreach ( $ev['attachments'] as $url ) {
                    $url     = esc_url_raw( $url );
                    $att_id  = media_sideload_image( $url, $post_id, '', 'id' );
                    if ( is_wp_error( $att_id ) ) {
                        $report['warnings'][] = sprintf( 'Bijlage overgeslagen: %s', $url );
                    } else {
                        $att_ids[] = $att_id;
                    }
                }
                if ( $att_ids ) {
                    update_post_meta( $post_id, 'wsa_event_attachments', wp_json_encode( $att_ids ) );
                }
            }
        }

        // Vacations — upsert by name + date range
        foreach ( $data['vacations'] ?? [] as $vac ) {
            global $wpdb;
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wsa_vacations WHERE name = %s AND start_date = %s AND end_date = %s",
                $vac['name'], $vac['start_date'], $vac['end_date']
            ) );
            if ( ! $existing ) {
                WSA_DB::upsert_vacation( $vac );
            }
        }

        // ICS feeds — upsert by URL
        foreach ( $data['ics_feeds'] ?? [] as $feed ) {
            global $wpdb;
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wsa_ics_feeds WHERE url = %s",
                $feed['url']
            ) );
            if ( ! $existing ) {
                WSA_DB::upsert_ics_feed( $feed );
            }
        }

        return $report;
    }

    /* ── Pre-migration checklist ────────────────────────────────────────── */

    public static function get_checklist(): array {
        $items = [];

        // 1. All events have a category
        $no_cat = get_posts( [
            'post_type'   => 'wsa_event',
            'post_status' => 'publish',
            'tax_query'   => [ [
                'taxonomy' => 'wsa_event_category',
                'operator' => 'NOT EXISTS',
            ] ],
            'numberposts' => -1,
            'fields'      => 'ids',
        ] );
        $items[] = [
            'label'  => __( 'Alle evenementen hebben een categorie', 'wsa-agenda' ),
            'ok'     => empty( $no_cat ),
            'detail' => empty( $no_cat ) ? '' : sprintf( '%d evenement(en) zonder categorie.', count( $no_cat ) ),
        ];

        // 2. No orphaned attachments
        $posts    = get_posts( [ 'post_type' => 'wsa_event', 'post_status' => 'publish', 'numberposts' => -1 ] );
        $orphaned = 0;
        foreach ( $posts as $p ) {
            $raw = get_post_meta( $p->ID, 'wsa_event_attachments', true );
            $ids = $raw ? json_decode( $raw, true ) : [];
            if ( is_array( $ids ) ) {
                foreach ( $ids as $id ) {
                    if ( ! get_post( (int) $id ) ) {
                        $orphaned++;
                    }
                }
            }
        }
        $items[] = [
            'label'  => __( 'Geen ontbrekende bijlagen', 'wsa-agenda' ),
            'ok'     => $orphaned === 0,
            'detail' => $orphaned ? sprintf( '%d ontbrekende bijlage(n).', $orphaned ) : '',
        ];

        // 3. ICS feeds reachable
        $feeds        = WSA_DB::get_ics_feeds();
        $unreachable  = 0;
        foreach ( $feeds as $feed ) {
            $resp = wp_remote_head( esc_url_raw( $feed['url'] ), [ 'timeout' => 5 ] );
            if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) >= 400 ) {
                $unreachable++;
            }
        }
        $items[] = [
            'label'  => __( 'ICS feeds bereikbaar', 'wsa-agenda' ),
            'ok'     => $unreachable === 0,
            'detail' => $unreachable ? sprintf( '%d feed(s) niet bereikbaar.', $unreachable ) : '',
        ];

        // 4. OAuth credentials not placeholder
        $placeholder = [ 'YOUR_CLIENT_ID', 'YOUR_CLIENT_SECRET', 'YOUR_TEAM_ID', '' ];
        $oauth_ok    = ! in_array( get_option( 'wsa_sso_google_client_id', '' ), $placeholder, true );
        $items[] = [
            'label'  => __( 'OAuth-instellingen ingevuld (niet standaard)', 'wsa-agenda' ),
            'ok'     => $oauth_ok,
            'detail' => $oauth_ok ? '' : __( 'Google of Apple client ID is leeg of bevat een plaatshouder.', 'wsa-agenda' ),
        ];

        // 5. Old events warning
        $old = get_posts( [
            'post_type'   => 'wsa_event',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query'  => [ [
                'key'     => 'wsa_event_start',
                'value'   => date( 'Y-m-d', strtotime( '-1 year' ) ),
                'compare' => '<',
                'type'    => 'DATE',
            ] ],
            'fields'      => 'ids',
        ] );
        $items[] = [
            'label'   => __( 'Geen evenementen ouder dan 1 jaar', 'wsa-agenda' ),
            'ok'      => empty( $old ),
            'warning' => true, // warn only, not block
            'detail'  => empty( $old ) ? '' : sprintf( '%d evenement(en) ouder dan 1 jaar (mogelijk testdata).', count( $old ) ),
        ];

        return $items;
    }
}
