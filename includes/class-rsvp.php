<?php
defined( 'ABSPATH' ) || exit;

class WSA_RSVP {

    /**
     * Register a sign-up. Returns [ 'ok' => true ] or WP_Error.
     */
    public static function register( int $event_id, string $name, string $email ): array|WP_Error {
        // Validate event
        $post = get_post( $event_id );
        if ( ! $post || 'wsa_event' !== $post->post_type ) {
            return new WP_Error( 'not_found', __( 'Evenement niet gevonden.', 'wsa-agenda' ), [ 'status' => 404 ] );
        }

        $rsvp_enabled = (bool) get_post_meta( $event_id, 'wsa_rsvp_enabled', true );
        if ( ! $rsvp_enabled ) {
            return new WP_Error( 'rsvp_disabled', __( 'Aanmelden is niet mogelijk voor dit evenement.', 'wsa-agenda' ), [ 'status' => 400 ] );
        }

        // Check capacity
        $limit = (int) get_post_meta( $event_id, 'wsa_rsvp_limit', true );
        if ( $limit > 0 ) {
            $count = WSA_DB::count_rsvp( $event_id );
            if ( $count >= $limit ) {
                return new WP_Error( 'rsvp_full', __( 'Dit evenement is vol.', 'wsa-agenda' ), [ 'status' => 409 ] );
            }
        }

        $name  = sanitize_text_field( $name );
        $email = sanitize_email( $email );

        if ( empty( $name ) ) {
            return new WP_Error( 'invalid_name', __( 'Naam is verplicht.', 'wsa-agenda' ), [ 'status' => 400 ] );
        }
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', __( 'Ongeldig e-mailadres.', 'wsa-agenda' ), [ 'status' => 400 ] );
        }

        $id = WSA_DB::insert_rsvp( $event_id, $name, $email );
        if ( false === $id ) {
            return new WP_Error( 'db_error', __( 'Aanmelden mislukt. Probeer opnieuw.', 'wsa-agenda' ), [ 'status' => 500 ] );
        }

        return [
            'id'    => $id,
            'count' => WSA_DB::count_rsvp( $event_id ),
            'limit' => $limit,
        ];
    }

    /**
     * Build CSV content for an event's RSVP list.
     */
    public static function build_csv( int $event_id ): string {
        $rows = WSA_DB::get_rsvp_for_event( $event_id );
        $out  = "ID,Naam,E-mail,Datum\n";
        foreach ( $rows as $r ) {
            $out .= implode( ',', [
                $r['id'],
                '"' . str_replace( '"', '""', $r['name'] ) . '"',
                '"' . str_replace( '"', '""', $r['email'] ) . '"',
                '"' . $r['created_at'] . '"',
            ] ) . "\n";
        }
        return $out;
    }
}
