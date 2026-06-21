<?php
defined( 'ABSPATH' ) || exit;

/**
 * Minimal ICS/iCal parser.
 * Supports VEVENT with DTSTART, DTEND, SUMMARY.
 * Ignores VALARM, VTIMEZONE.
 */
class WSA_ICS_Parser {

    /**
     * Parse raw ICS string → array of events.
     *
     * @return array<array{summary:string, start:string, end:string}>
     */
    public static function parse( string $ics ): array {
        $events   = [];
        $in_event = false;
        $current  = [];

        // Unfold continuation lines (RFC 5545 §3.1)
        $ics   = preg_replace( "/\r\n[ \t]/", '', $ics );
        $ics   = preg_replace( "/\n[ \t]/", '', $ics );
        $lines = preg_split( "/\r\n|\r|\n/", $ics );

        foreach ( $lines as $raw ) {
            $line = rtrim( $raw );
            if ( 'BEGIN:VEVENT' === $line ) {
                $in_event = true;
                $current  = [];
                continue;
            }
            if ( 'END:VEVENT' === $line ) {
                $in_event = false;
                if ( ! empty( $current['start'] ) ) {
                    $events[] = $current;
                }
                continue;
            }
            if ( ! $in_event ) {
                continue;
            }

            // Split property name (ignoring parameters) from value
            if ( ! str_contains( $line, ':' ) ) {
                continue;
            }
            [ $prop_raw, $value ] = explode( ':', $line, 2 );
            $prop = strtoupper( strtok( $prop_raw, ';' ) );

            switch ( $prop ) {
                case 'SUMMARY':
                    $current['summary'] = self::unescape( $value );
                    break;
                case 'DTSTART':
                    $current['start'] = self::parse_dt( $value, $prop_raw );
                    break;
                case 'DTEND':
                    $current['end'] = self::parse_dt( $value, $prop_raw );
                    break;
            }
        }

        return $events;
    }

    private static function unescape( string $s ): string {
        return str_replace( [ '\\,', '\\;', '\\n', '\\N', '\\\\' ], [ ',', ';', "\n", "\n", '\\' ], $s );
    }

    /**
     * Convert iCal datetime string to Y-m-d (or Y-m-d\TH:i:s for timed events).
     */
    private static function parse_dt( string $value, string $prop_raw ): string {
        $value = trim( $value );

        // DATE-only: VALUE=DATE
        if ( str_contains( strtoupper( $prop_raw ), 'VALUE=DATE' ) || strlen( $value ) === 8 ) {
            if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
                return "{$m[1]}-{$m[2]}-{$m[3]}";
            }
        }

        // DATETIME (with optional trailing Z or timezone)
        if ( preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})/', $value, $m ) ) {
            return "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}";
        }

        return $value;
    }
}
