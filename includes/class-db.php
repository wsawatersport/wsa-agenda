<?php
defined( 'ABSPATH' ) || exit;

class WSA_DB {

    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // RSVP registrations
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}wsa_rsvp (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id    BIGINT UNSIGNED NOT NULL,
                name        VARCHAR(200)    NOT NULL,
                email       VARCHAR(200)    NOT NULL,
                created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY event_id (event_id)
            ) {$charset};
        " );

        // School vacation blocks
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}wsa_vacations (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(200)    NOT NULL,
                start_date  DATE            NOT NULL,
                end_date    DATE            NOT NULL,
                regions     VARCHAR(50)     NOT NULL DEFAULT 'Noord,Midden,Zuid',
                PRIMARY KEY (id)
            ) {$charset};
        " );

        // ICS feed registrations
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}wsa_ics_feeds (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(200)    NOT NULL,
                url         TEXT            NOT NULL,
                PRIMARY KEY (id)
            ) {$charset};
        " );
    }

    public static function drop_tables(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsa_rsvp" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsa_vacations" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsa_ics_feeds" );
    }

    /* ── RSVP ───────────────────────────────────────────────────────────── */

    public static function insert_rsvp( int $event_id, string $name, string $email ): int|false {
        global $wpdb;
        $ok = $wpdb->insert(
            "{$wpdb->prefix}wsa_rsvp",
            [
                'event_id'   => $event_id,
                'name'       => $name,
                'email'      => $email,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s' ]
        );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function get_rsvp_for_event( int $event_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, email, created_at FROM {$wpdb->prefix}wsa_rsvp WHERE event_id = %d ORDER BY created_at ASC",
                $event_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function count_rsvp( int $event_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wsa_rsvp WHERE event_id = %d",
                $event_id
            )
        );
    }

    /* ── Vacations ──────────────────────────────────────────────────────── */

    public static function get_vacations( ?string $start = null, ?string $end = null ): array {
        global $wpdb;
        $sql = "SELECT * FROM {$wpdb->prefix}wsa_vacations";
        $args = [];
        if ( $start && $end ) {
            $sql   .= ' WHERE end_date >= %s AND start_date <= %s';
            $args[] = $start;
            $args[] = $end;
        }
        $sql .= ' ORDER BY start_date ASC';
        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );
        return $rows ?: [];
    }

    public static function upsert_vacation( array $data ): int {
        global $wpdb;
        if ( ! empty( $data['id'] ) ) {
            $wpdb->update(
                "{$wpdb->prefix}wsa_vacations",
                [
                    'name'       => $data['name'],
                    'start_date' => $data['start_date'],
                    'end_date'   => $data['end_date'],
                    'regions'    => $data['regions'] ?? 'Noord,Midden,Zuid',
                ],
                [ 'id' => (int) $data['id'] ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return (int) $data['id'];
        }
        $wpdb->insert(
            "{$wpdb->prefix}wsa_vacations",
            [
                'name'       => $data['name'],
                'start_date' => $data['start_date'],
                'end_date'   => $data['end_date'],
                'regions'    => $data['regions'] ?? 'Noord,Midden,Zuid',
            ],
            [ '%s', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public static function delete_vacation( int $id ): void {
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}wsa_vacations", [ 'id' => $id ], [ '%d' ] );
    }

    /* ── ICS Feeds ──────────────────────────────────────────────────────── */

    public static function get_ics_feeds(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wsa_ics_feeds ORDER BY id ASC", ARRAY_A ) ?: [];
    }

    public static function upsert_ics_feed( array $data ): int {
        global $wpdb;
        if ( ! empty( $data['id'] ) ) {
            $wpdb->update(
                "{$wpdb->prefix}wsa_ics_feeds",
                [ 'name' => $data['name'], 'url' => $data['url'] ],
                [ 'id'   => (int) $data['id'] ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
            return (int) $data['id'];
        }
        $wpdb->insert(
            "{$wpdb->prefix}wsa_ics_feeds",
            [ 'name' => $data['name'], 'url' => $data['url'] ],
            [ '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public static function delete_ics_feed( int $id ): void {
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}wsa_ics_feeds", [ 'id' => $id ], [ '%d' ] );
        delete_transient( "wsa_ics_feed_{$id}" );
    }
}
