<?php
defined( 'ABSPATH' ) || exit;

/**
 * WSA_Subscriptions — ICS invite emails + per-event email reminder subscriptions.
 *
 * Responsibilities:
 *  - DB table creation + CRUD for wp_wsa_subscriptions
 *  - Send a one-off ICS invite email  (no subscription stored)
 *  - Store a subscription + schedule WP-cron reminder jobs (7-day, 1-day)
 *  - Fire reminder emails from cron
 *  - Handle GET /wsa/v1/unsubscribe?token=… (outputs HTML page + exits)
 *  - Build CSV for board admin
 */
class WSA_Subscriptions {

    const CRON_7DAY = 'wsa_sub_reminder_7day';
    const CRON_1DAY = 'wsa_sub_reminder_1day';

    public static function init(): void {
        add_action( self::CRON_7DAY, [ __CLASS__, 'fire_reminder_7day' ] );
        add_action( self::CRON_1DAY, [ __CLASS__, 'fire_reminder_1day' ] );
    }

    /* ── Table ──────────────────────────────────────────────────────── */

    public static function create_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "
            CREATE TABLE {$wpdb->prefix}wsa_subscriptions (
                id         BIGINT(20) NOT NULL AUTO_INCREMENT,
                event_id   BIGINT(20) NOT NULL,
                name       VARCHAR(100) NOT NULL,
                email      VARCHAR(200) NOT NULL,
                token      VARCHAR(36)  NOT NULL,
                created_at DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY event_email (event_id, email),
                KEY token (token)
            ) {$charset};
        " );
    }

    /* ── CRUD ───────────────────────────────────────────────────────── */

    public static function get_subscription_by_token( string $token ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wsa_subscriptions WHERE token = %s",
                $token
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_subscriptions_for_event( int $event_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wsa_subscriptions WHERE event_id = %d ORDER BY created_at ASC",
                $event_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function count_for_event( int $event_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wsa_subscriptions WHERE event_id = %d",
                $event_id
            )
        );
    }

    public static function delete_by_id( int $id ): void {
        global $wpdb;
        wp_clear_scheduled_hook( self::CRON_7DAY, [ $id ] );
        wp_clear_scheduled_hook( self::CRON_1DAY, [ $id ] );
        $wpdb->delete( "{$wpdb->prefix}wsa_subscriptions", [ 'id' => $id ], [ '%d' ] );
    }

    public static function delete_all_for_event( int $event_id ): void {
        global $wpdb;
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wsa_subscriptions WHERE event_id = %d",
                $event_id
            )
        );
        foreach ( $ids as $id ) {
            self::delete_by_id( (int) $id );
        }
    }

    /* ── ICS invite (one-off, no subscription stored) ───────────────── */

    /**
     * Generate an ICS calendar file for $event_id and email it to $email.
     *
     * @return true|WP_Error
     */
    public static function send_ics_invite( int $event_id, string $email ): true|WP_Error {
        $post = get_post( $event_id );
        if ( ! $post || 'wsa_event' !== $post->post_type ) {
            return new WP_Error( 'not_found', __( 'Evenement niet gevonden.', 'wsa-agenda' ), [ 'status' => 404 ] );
        }

        $email = sanitize_email( $email );
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', __( 'Ongeldig e-mailadres.', 'wsa-agenda' ), [ 'status' => 400 ] );
        }

        try {
            $ics = self::build_ics( $post );

            // wp_tempnam() is only available in admin context; use PHP's native
            // tempnam() so this works in front-end REST API requests as well.
            $tmp_base = tempnam( sys_get_temp_dir(), 'wsa_ics_' );
            if ( false === $tmp_base ) {
                return new WP_Error( 'temp_file', __( 'Kon tijdelijk bestand niet aanmaken.', 'wsa-agenda' ), [ 'status' => 500 ] );
            }
            // Remove the placeholder file so we can reuse the unique name with
            // the .ics extension (needed for correct MIME detection by mail clients).
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @unlink( $tmp_base );
            $tmp = $tmp_base . '.ics';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $tmp, $ics );

            [ $from_email, $from_name ] = self::from_address();

            $subject = sprintf( __( 'Agenda-uitnodiging: %s', 'wsa-agenda' ), $post->post_title );
            $message = sprintf(
                /* translators: 1: event title, 2: site/club name */
                __( "Beste,\n\nBijgevoegd vind je een agenda-uitnodiging voor:\n%1\$s\n\nOpen het bijgevoegde .ics-bestand om het evenement toe te voegen aan je agenda.\n\nMet vriendelijke groet,\n%2\$s", 'wsa-agenda' ),
                $post->post_title,
                $from_name
            );

            add_filter( 'wp_mail_from',      fn() => $from_email, 20 );
            add_filter( 'wp_mail_from_name', fn() => $from_name,  20 );

            $sent = wp_mail(
                $email,
                $subject,
                $message,
                [ 'Content-Type: text/plain; charset=UTF-8' ],
                [ $tmp ]
            );

            remove_all_filters( 'wp_mail_from',      20 );
            remove_all_filters( 'wp_mail_from_name', 20 );

            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @unlink( $tmp );

            if ( ! $sent ) {
                return new WP_Error( 'mail_failed', __( 'E-mail kon niet worden verstuurd. Controleer de e-mailinstellingen.', 'wsa-agenda' ), [ 'status' => 500 ] );
            }
            return true;

        } catch ( Exception $e ) {
            return new WP_Error( 'ics_error', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    /* ── Subscribe + schedule reminders ────────────────────────────── */

    /**
     * Subscribe a visitor to email reminders for an event.
     *
     * @return array{ subscribed: bool, unsubscribe: string }|WP_Error
     */
    public static function subscribe( int $event_id, string $name, string $email ): array|WP_Error {
        global $wpdb;

        $post = get_post( $event_id );
        if ( ! $post || 'wsa_event' !== $post->post_type ) {
            return new WP_Error( 'not_found', __( 'Evenement niet gevonden.', 'wsa-agenda' ), [ 'status' => 404 ] );
        }

        $name  = sanitize_text_field( $name );
        $email = sanitize_email( $email );

        if ( ! $name ) {
            return new WP_Error( 'invalid_name', __( 'Naam is verplicht.', 'wsa-agenda' ), [ 'status' => 400 ] );
        }
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', __( 'Ongeldig e-mailadres.', 'wsa-agenda' ), [ 'status' => 400 ] );
        }

        // Duplicate check
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}wsa_subscriptions WHERE event_id = %d AND email = %s",
                $event_id,
                $email
            )
        );
        if ( $existing ) {
            return new WP_Error(
                'already_subscribed',
                __( 'Dit e-mailadres is al aangemeld voor dit evenement.', 'wsa-agenda' ),
                [ 'status' => 409 ]
            );
        }

        $token = wp_generate_uuid4();

        $ok = $wpdb->insert(
            "{$wpdb->prefix}wsa_subscriptions",
            [
                'event_id'   => $event_id,
                'name'       => $name,
                'email'      => $email,
                'token'      => $token,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $ok ) {
            return new WP_Error( 'db_error', __( 'Kon aanmelding niet opslaan.', 'wsa-agenda' ), [ 'status' => 500 ] );
        }

        $sub_id = (int) $wpdb->insert_id;

        self::schedule_reminders( $sub_id, $event_id );
        self::send_confirmation( $sub_id, $post, $name, $email, $token );

        return [
            'subscribed'  => true,
            'unsubscribe' => self::unsubscribe_url( $token ),
        ];
    }

    private static function schedule_reminders( int $sub_id, int $event_id ): void {
        $start_raw = get_post_meta( $event_id, 'wsa_event_start', true );
        if ( ! $start_raw ) {
            return;
        }

        $start_ts = strtotime( strlen( $start_raw ) === 10 ? $start_raw . ' 00:00:00' : $start_raw );
        if ( ! $start_ts || $start_ts <= time() ) {
            return; // Already started or past
        }

        $ts_7 = $start_ts - 7 * DAY_IN_SECONDS;
        $ts_1 = $start_ts - DAY_IN_SECONDS;

        if ( $ts_7 > time() ) {
            wp_schedule_single_event( $ts_7, self::CRON_7DAY, [ $sub_id ] );
        }
        if ( $ts_1 > time() ) {
            wp_schedule_single_event( $ts_1, self::CRON_1DAY, [ $sub_id ] );
        }
    }

    /* ── Cron callbacks ─────────────────────────────────────────────── */

    public static function fire_reminder_7day( int $sub_id ): void {
        self::send_reminder( $sub_id, 7 );
    }

    public static function fire_reminder_1day( int $sub_id ): void {
        self::send_reminder( $sub_id, 1 );
    }

    private static function send_reminder( int $sub_id, int $days_before ): void {
        global $wpdb;

        $sub = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wsa_subscriptions WHERE id = %d",
                $sub_id
            ),
            ARRAY_A
        );
        if ( ! $sub ) {
            return;
        }

        $post = get_post( (int) $sub['event_id'] );
        if ( ! $post || 'wsa_event' !== $post->post_type ) {
            return;
        }

        [ $from_email, $from_name ] = self::from_address();

        $when_label = $days_before === 7
            ? __( 'over 7 dagen', 'wsa-agenda' )
            : __( 'morgen', 'wsa-agenda' );

        $start_str = self::format_event_date( $post->ID );
        $unsub_url = self::unsubscribe_url( $sub['token'] );

        $subject = sprintf(
            /* translators: 1: event title, 2: "over 7 dagen" / "morgen" */
            __( 'Herinnering: %1$s vindt %2$s plaats', 'wsa-agenda' ),
            $post->post_title,
            $when_label
        );
        $message = sprintf(
            /* translators: 1: name, 2: event title, 3: when label, 4: date string, 5: unsubscribe URL, 6: club name */
            __( "Beste %1\$s,\n\nDit is een herinnering dat het evenement '%2\$s' %3\$s plaatsvindt.\n\nDatum/tijd: %4\$s\n\nAfmelden voor herinneringen:\n%5\$s\n\nMet vriendelijke groet,\n%6\$s", 'wsa-agenda' ),
            $sub['name'],
            $post->post_title,
            $when_label,
            $start_str ?: '—',
            $unsub_url,
            $from_name
        );

        self::send_mail( $sub['email'], $subject, $message, $from_email, $from_name );
    }

    private static function send_confirmation( int $sub_id, WP_Post $post, string $name, string $email, string $token ): void {
        [ $from_email, $from_name ] = self::from_address();

        $start_str = self::format_event_date( $post->ID );
        $unsub_url = self::unsubscribe_url( $token );

        $subject = sprintf( __( 'Aanmelding bevestigd: %s', 'wsa-agenda' ), $post->post_title );
        $message = sprintf(
            /* translators: 1: name, 2: event title, 3: date string, 4: unsubscribe URL, 5: club name */
            __( "Beste %1\$s,\n\nJe bent aangemeld voor herinneringen over:\n%2\$s\n\nDatum/tijd: %3\$s\n\nJe ontvangt een herinnering 7 dagen en 1 dag voor het evenement.\n\nAfmelden:\n%4\$s\n\nMet vriendelijke groet,\n%5\$s", 'wsa-agenda' ),
            $name,
            $post->post_title,
            $start_str ?: '—',
            $unsub_url,
            $from_name
        );

        self::send_mail( $email, $subject, $message, $from_email, $from_name );
    }

    /* ── Unsubscribe endpoint ───────────────────────────────────────── */

    /**
     * REST callback for GET /wsa/v1/unsubscribe?token=…
     * Outputs a full HTML confirmation page and exits.
     */
    public static function handle_unsubscribe( WP_REST_Request $req ): void {
        $token = sanitize_text_field( (string) ( $req->get_param( 'token' ) ?? '' ) );

        if ( ! $token ) {
            wp_die( esc_html__( 'Ongeldige afmeldlink.', 'wsa-agenda' ), esc_html__( 'Fout', 'wsa-agenda' ), [ 'response' => 400 ] );
        }

        $sub = self::get_subscription_by_token( $token );
        if ( ! $sub ) {
            wp_die( esc_html__( 'Afmeldlink ongeldig of al gebruikt.', 'wsa-agenda' ), esc_html__( 'Fout', 'wsa-agenda' ), [ 'response' => 404 ] );
        }

        $post = get_post( (int) $sub['event_id'] );
        self::delete_by_id( (int) $sub['id'] );
        self::output_unsubscribe_page( $post );
    }

    private static function output_unsubscribe_page( ?WP_Post $post ): void {
        $event_title = $post ? esc_html( $post->post_title ) : esc_html__( 'het evenement', 'wsa-agenda' );
        $site_name   = esc_html( get_bloginfo( 'name' ) );
        $agenda_url  = esc_url( trailingslashit( get_site_url() ) . 'agenda' );

        // Output a minimal styled page then exit — this is a redirect-like response.
        header( 'Content-Type: text/html; charset=utf-8' );
        ?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html__( 'Afgemeld', 'wsa-agenda' ); ?> – <?php echo $site_name; ?></title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;margin:0;padding:40px 20px;display:flex;justify-content:center}
.card{max-width:440px;text-align:center}
h1{font-size:22px;margin:0 0 12px}
p{color:#6b7280;line-height:1.6;margin:0 0 20px}
a{color:#1d4ed8;text-decoration:none}
a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="card">
  <h1>✅ <?php echo esc_html__( 'Je bent afgemeld', 'wsa-agenda' ); ?></h1>
  <p><?php printf( esc_html__( 'Je ontvangt geen herinneringen meer voor %s.', 'wsa-agenda' ), "<strong>{$event_title}</strong>" ); ?></p>
  <p><a href="<?php echo $agenda_url; ?>">← <?php echo esc_html__( 'Terug naar de agenda', 'wsa-agenda' ); ?></a></p>
</div>
</body>
</html>
<?php
        exit;
    }

    /* ── Admin: subscriber list + CSV ───────────────────────────────── */

    /**
     * Build a CSV string of all subscribers for an event.
     */
    public static function build_csv( int $event_id ): string {
        $rows = self::get_subscriptions_for_event( $event_id );
        $out  = "Naam,E-mailadres,Aangemeld op\n";
        foreach ( $rows as $row ) {
            $out .= self::csv_cell( $row['name'] )
                . ',' . self::csv_cell( $row['email'] )
                . ',' . self::csv_cell( $row['created_at'] )
                . "\n";
        }
        return $out;
    }

    private static function csv_cell( string $v ): string {
        $v = str_replace( '"', '""', $v );
        return ( strpbrk( $v, ',"' ) !== false ) ? "\"{$v}\"" : $v;
    }

    /* ── ICS builder ────────────────────────────────────────────────── */

    /**
     * Build a VCALENDAR ICS string for the given post.
     */
    public static function build_ics( WP_Post $post ): string {
        $uid       = get_post_meta( $post->ID, 'wsa_event_uuid', true ) ?: wp_generate_uuid4();
        $allday    = (bool) get_post_meta( $post->ID, 'wsa_event_allday', true );
        $start_raw = (string) get_post_meta( $post->ID, 'wsa_event_start', true );
        $end_raw   = (string) get_post_meta( $post->ID, 'wsa_event_end',   true );

        if ( $allday ) {
            $start_dt = $start_raw ? new DateTime( $start_raw ) : new DateTime();
            $end_dt   = $end_raw   ? new DateTime( $end_raw )   : clone $start_dt;
            // RFC 5545 §3.6.1 — DTEND for all-day is the exclusive next day.
            $end_dt->modify( '+1 day' );
            $dtstart = 'DTSTART;VALUE=DATE:' . $start_dt->format( 'Ymd' );
            $dtend   = 'DTEND;VALUE=DATE:'   . $end_dt->format( 'Ymd' );
        } else {
            $tz      = new DateTimeZone( 'Europe/Amsterdam' );
            $start_dt = $start_raw ? new DateTime( $start_raw, $tz ) : new DateTime( 'now', $tz );
            $end_dt   = $end_raw   ? new DateTime( $end_raw,   $tz ) : clone $start_dt;
            $dtstart  = 'DTSTART;TZID=Europe/Amsterdam:' . $start_dt->format( 'Ymd\THis' );
            $dtend    = 'DTEND;TZID=Europe/Amsterdam:'   . $end_dt->format( 'Ymd\THis' );
        }

        [ $from_email, $from_name ] = self::from_address();

        $summary     = self::ics_escape( $post->post_title );
        $description = self::ics_escape( wp_strip_all_tags( $post->post_content ) );
        $dtstamp     = gmdate( 'Ymd\THis\Z' );

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//WSA Watersport//Agenda//NL',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            "UID:{$uid}@wsawatersport.nl",
            "DTSTAMP:{$dtstamp}",
            $dtstart,
            $dtend,
            "SUMMARY:{$summary}",
        ];

        if ( $description ) {
            $lines[] = "DESCRIPTION:{$description}";
        }

        $lines[] = "ORGANIZER;CN={$from_name}:mailto:{$from_email}";
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode( "\r\n", $lines ) . "\r\n";
    }

    /**
     * Escape a string value for use in ICS properties.
     */
    private static function ics_escape( string $v ): string {
        // Escape special chars per RFC 5545.
        $v = str_replace( [ '\\', ';', ',' ], [ '\\\\', '\;', '\,' ], $v );
        $v = str_replace( [ "\r\n", "\n", "\r" ], '\n', $v );

        // RFC 5545 line folding: max 75 octets per line (using 72 chars for safety).
        if ( strlen( $v ) <= 72 ) {
            return $v;
        }
        $folded = '';
        $len    = 0;
        foreach ( mb_str_split( $v ) as $char ) {
            $bytes = strlen( $char ); // byte length (UTF-8 may be > 1)
            if ( $len + $bytes > 72 ) {
                $folded .= "\r\n ";
                $len     = 1; // the space
            }
            $folded .= $char;
            $len    += $bytes;
        }
        return $folded;
    }

    /* ── Helpers ────────────────────────────────────────────────────── */

    /**
     * Returns [ from_email, from_name ] for outgoing mail.
     */
    public static function from_address(): array {
        return [
            get_option( 'wsa_smtp_from_email', get_option( 'admin_email', '' ) ),
            get_option( 'wsa_smtp_from_name',  get_bloginfo( 'name' ) ),
        ];
    }

    public static function unsubscribe_url( string $token ): string {
        return esc_url_raw(
            add_query_arg( 'token', rawurlencode( $token ), get_site_url() . '/wp-json/wsa/v1/unsubscribe' )
        );
    }

    /**
     * Format the start date/time of an event as a human-readable Dutch string.
     */
    private static function format_event_date( int $event_id ): string {
        $start_raw = get_post_meta( $event_id, 'wsa_event_start', true );
        $allday    = (bool) get_post_meta( $event_id, 'wsa_event_allday', true );
        if ( ! $start_raw ) {
            return '';
        }
        $ts = strtotime( strlen( $start_raw ) === 10 ? $start_raw . ' 00:00:00' : $start_raw );
        if ( ! $ts ) {
            return '';
        }
        return $allday
            ? (string) wp_date( 'd F Y', $ts )
            : (string) wp_date( 'd F Y \o\m H:i', $ts );
    }

    /**
     * Send a plain-text email with custom From headers.
     */
    private static function send_mail( string $to, string $subject, string $message, string $from_email, string $from_name ): bool {
        add_filter( 'wp_mail_from',      fn() => $from_email, 20 );
        add_filter( 'wp_mail_from_name', fn() => $from_name,  20 );

        $sent = wp_mail( $to, $subject, $message, [ 'Content-Type: text/plain; charset=UTF-8' ] );

        remove_all_filters( 'wp_mail_from',      20 );
        remove_all_filters( 'wp_mail_from_name', 20 );

        return $sent;
    }
}
