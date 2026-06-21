<?php
defined( 'ABSPATH' ) || exit;

/**
 * Merges public/background calendar blocks from three sources:
 *  1. Dutch public holidays (Nager.at API, cached 7 days)
 *  2. School vacations (wp_wsa_vacations DB table)
 *  3. ICS/iCal feeds (wp_wsa_ics_feeds DB, cached 24 h per feed)
 */
class WSA_Public_Blocks {

    private const HOLIDAY_TTL = 7 * DAY_IN_SECONDS;
    private const ICS_TTL     = DAY_IN_SECONDS;

    /**
     * Get all public blocks for a date range.
     *
     * @return array<array{type:string, label:string, start_date:string, end_date:string}>
     */
    public static function get( string $start, string $end ): array {
        return array_merge(
            self::get_holidays( $start, $end ),
            self::get_vacations( $start, $end ),
            self::get_ics_blocks( $start, $end )
        );
    }

    /* ── Holidays ───────────────────────────────────────────────────────── */

    private static function get_holidays( string $start, string $end ): array {
        $start_year = (int) substr( $start, 0, 4 );
        $end_year   = (int) substr( $end, 0, 4 );
        $holidays   = [];

        for ( $y = $start_year; $y <= $end_year; $y++ ) {
            foreach ( self::fetch_holidays_for_year( $y ) as $h ) {
                if ( $h['date'] >= substr( $start, 0, 10 ) && $h['date'] <= substr( $end, 0, 10 ) ) {
                    $holidays[] = [
                        'type'       => 'holiday',
                        'label'      => $h['label'],
                        'start_date' => $h['date'],
                        'end_date'   => $h['date'],
                    ];
                }
            }
        }
        return $holidays;
    }

    private static function fetch_holidays_for_year( int $year ): array {
        $key    = "wsa_holidays_{$year}";
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get(
            "https://date.nager.at/api/v3/PublicHolidays/{$year}/NL",
            [ 'timeout' => 10 ]
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Do NOT cache a failure so the next page load retries.
            return [];
        }

        $data     = json_decode( wp_remote_retrieve_body( $response ), true );
        $holidays = [];
        if ( is_array( $data ) ) {
            foreach ( $data as $item ) {
                $date = sanitize_text_field( $item['date'] ?? '' );
                if ( ! $date ) {
                    continue;
                }
                $holidays[] = [
                    'date'  => $date,
                    'label' => sanitize_text_field( $item['localName'] ?? 'Feestdag' ),
                ];
            }
        }

        // Only persist a non-empty result; empty could mean API returned nothing
        // useful this year, but we still cache it to avoid hammering the API.
        if ( ! empty( $holidays ) ) {
            set_transient( $key, $holidays, self::HOLIDAY_TTL );
        }
        return $holidays;
    }

    /* ── Vacations ──────────────────────────────────────────────────────── */

    private static function get_vacations( string $start, string $end ): array {
        $rows   = WSA_DB::get_vacations( substr( $start, 0, 10 ), substr( $end, 0, 10 ) );
        $blocks = [];
        foreach ( $rows as $r ) {
            $blocks[] = [
                'type'       => 'vacation',
                'label'      => $r['name'],
                'start_date' => $r['start_date'],
                'end_date'   => $r['end_date'],
                'regions'    => $r['regions'],
            ];
        }
        return $blocks;
    }

    /* ── ICS Feeds ──────────────────────────────────────────────────────── */

    private static function get_ics_blocks( string $start, string $end ): array {
        $feeds  = WSA_DB::get_ics_feeds();
        $blocks = [];
        $s10    = substr( $start, 0, 10 );
        $e10    = substr( $end, 0, 10 );

        foreach ( $feeds as $feed ) {
            $id     = (int) $feed['id'];
            $name   = $feed['name'];
            $events = self::fetch_ics_events( $id, $feed['url'] );

            foreach ( $events as $ev ) {
                $ev_start = substr( $ev['start'], 0, 10 );
                $ev_end   = $ev['end'] ? substr( $ev['end'], 0, 10 ) : $ev_start;

                // ICS DTEND for all-day events is exclusive; subtract one day
                if ( strlen( $ev['start'] ) === 10 && $ev['end'] && strlen( $ev['end'] ) === 10 ) {
                    $ev_end = date( 'Y-m-d', strtotime( $ev_end ) - DAY_IN_SECONDS );
                }

                if ( $ev_end < $s10 || $ev_start > $e10 ) {
                    continue;
                }

                $blocks[] = [
                    'type'       => 'ics',
                    'label'      => $name . ': ' . ( $ev['summary'] ?? '' ),
                    'start_date' => $ev_start,
                    'end_date'   => $ev_end,
                ];
            }
        }
        return $blocks;
    }

    private static function fetch_ics_events( int $id, string $url ): array {
        $key    = "wsa_ics_feed_{$id}";
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get( esc_url_raw( $url ), [ 'timeout' => 15 ] );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return [];
        }

        $events = WSA_ICS_Parser::parse( wp_remote_retrieve_body( $response ) );
        set_transient( $key, $events, self::ICS_TTL );
        return $events;
    }
}
