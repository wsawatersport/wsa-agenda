<?php
defined( 'ABSPATH' ) || exit;

/**
 * Minimal custom OAuth2 handler for Google SSO and Apple SSO.
 * Endpoints are registered as WordPress REST routes.
 *
 * Configuration is stored in wp_options:
 *   wsa_sso_google_client_id, wsa_sso_google_client_secret
 *   wsa_sso_apple_client_id, wsa_sso_apple_team_id,
 *   wsa_sso_apple_key_id,    wsa_sso_apple_private_key
 */
class WSA_SSO {

    private const STATE_OPTION_TTL = 600; // 10 min

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        add_action( 'login_form',    [ __CLASS__, 'render_sso_buttons' ] );
    }

    public static function register_routes(): void {
        $ns = 'wsa/v1';

        // Canonical OAuth routes (used by the front-end login buttons).
        register_rest_route( $ns, '/oauth/google/initiate', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'google_redirect' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $ns, '/oauth/google/callback', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'google_callback' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $ns, '/oauth/apple/initiate', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'apple_redirect' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $ns, '/oauth/apple/callback', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'apple_callback' ],
            'permission_callback' => '__return_true',
        ] );

        // Legacy aliases kept for backwards-compatibility with previously registered
        // Google / Apple OAuth app redirect URIs.
        register_rest_route( $ns, '/sso/google', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'google_redirect' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $ns, '/sso/google/callback', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'google_callback' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $ns, '/sso/apple', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'apple_redirect' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $ns, '/sso/apple/callback', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'apple_callback' ],
            'permission_callback' => '__return_true',
        ] );

        // Lightweight endpoint so JS can confirm auth state after popup login.
        register_rest_route( $ns, '/me', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'me' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Returns the current user's auth state — used by the JS login-popup flow
     * to confirm isBoard after the OAuth callback completes.
     */
    public static function me(): WP_REST_Response {
        $user     = wp_get_current_user();
        $is_board = $user->ID && (
            in_array( 'wsa_bestuur', (array) $user->roles, true )
            || user_can( $user->ID, 'manage_options' )
        );
        return new WP_REST_Response( [
            'loggedIn' => (bool) $user->ID,
            'isBoard'  => $is_board,
        ] );
    }

    /* ── Google ─────────────────────────────────────────────────────────── */

    public static function google_redirect( WP_REST_Request $req ): void {
        $client_id = get_option( 'wsa_sso_google_client_id', '' );
        if ( ! $client_id ) {
            wp_die(
                esc_html__( 'OAuth niet geconfigureerd — stel de inloggegevens in via Instellingen › WSA Agenda', 'wsa-agenda' ),
                esc_html__( 'Inloggen niet beschikbaar', 'wsa-agenda' ),
                [ 'response' => 400 ]
            );
        }
        $redirect_uri = self::callback_url( 'google' );
        $state        = self::generate_state( (bool) $req->get_param( 'popup' ), (int) $req->get_param( 'event_id' ) );

        $url = add_query_arg( [
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
        ], 'https://accounts.google.com/o/oauth2/v2/auth' );

        wp_redirect( $url );
        exit;
    }

    public static function google_callback( WP_REST_Request $req ): void {
        $state      = sanitize_text_field( $req->get_param( 'state' ) ?? '' );
        $state_data = self::verify_state( $state );
        if ( $state_data === false ) {
            wp_die( esc_html__( 'Ongeldige OAuth-state. Probeer opnieuw.', 'wsa-agenda' ), 403 );
        }

        $code = sanitize_text_field( $req->get_param( 'code' ) ?? '' );
        if ( ! $code ) {
            wp_die( esc_html__( 'Geen autorisatiecode ontvangen.', 'wsa-agenda' ), 400 );
        }

        // Exchange code for token
        $token_resp = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => get_option( 'wsa_sso_google_client_id', '' ),
                'client_secret' => get_option( 'wsa_sso_google_client_secret', '' ),
                'redirect_uri'  => self::callback_url( 'google' ),
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( is_wp_error( $token_resp ) ) {
            wp_die( esc_html__( 'Token-uitwisseling mislukt.', 'wsa-agenda' ), 500 );
        }

        $token_data   = json_decode( wp_remote_retrieve_body( $token_resp ), true );
        $access_token = $token_data['access_token'] ?? '';

        if ( ! $access_token ) {
            wp_die( esc_html__( 'Geen access token ontvangen.', 'wsa-agenda' ), 400 );
        }

        // Get user info
        $user_resp = wp_remote_get( 'https://www.googleapis.com/oauth2/v3/userinfo', [
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        ] );

        if ( is_wp_error( $user_resp ) ) {
            wp_die( esc_html__( 'Gebruikersinfo ophalen mislukt.', 'wsa-agenda' ), 500 );
        }

        $user_data = json_decode( wp_remote_retrieve_body( $user_resp ), true );
        $email     = sanitize_email( $user_data['email'] ?? '' );
        $name      = sanitize_text_field( $user_data['name'] ?? '' );

        if ( ! $email ) {
            wp_die( esc_html__( 'Geen e-mailadres ontvangen van Google.', 'wsa-agenda' ), 400 );
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( '[WSA OAuth] google_callback state_data: ' . wp_json_encode( $state_data ) );
        self::login_or_create( $email, $name, $state_data['popup'] ?? false, (int) ( $state_data['event_id'] ?? 0 ) );
    }

    /* ── Apple ──────────────────────────────────────────────────────────── */

    public static function apple_redirect( WP_REST_Request $req ): void {
        $client_id = get_option( 'wsa_sso_apple_client_id', '' );
        if ( ! $client_id ) {
            wp_die(
                esc_html__( 'OAuth niet geconfigureerd — stel de inloggegevens in via Instellingen › WSA Agenda', 'wsa-agenda' ),
                esc_html__( 'Inloggen niet beschikbaar', 'wsa-agenda' ),
                [ 'response' => 400 ]
            );
        }
        $redirect_uri = self::callback_url( 'apple' );
        $state        = self::generate_state( (bool) $req->get_param( 'popup' ), (int) $req->get_param( 'event_id' ) );

        $url = add_query_arg( [
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'scope'         => 'name email',
            'state'         => $state,
        ], 'https://appleid.apple.com/auth/authorize' );

        wp_redirect( $url );
        exit;
    }

    public static function apple_callback( WP_REST_Request $req ): void {
        $state      = sanitize_text_field( $req->get_param( 'state' ) ?? '' );
        $state_data = self::verify_state( $state );
        if ( $state_data === false ) {
            wp_die( esc_html__( 'Ongeldige OAuth-state.', 'wsa-agenda' ), 403 );
        }

        $code = sanitize_text_field( $req->get_param( 'code' ) ?? '' );
        if ( ! $code ) {
            wp_die( esc_html__( 'Geen autorisatiecode ontvangen.', 'wsa-agenda' ), 400 );
        }

        // Build Apple client_secret JWT
        $client_secret = self::build_apple_client_secret();
        if ( ! $client_secret ) {
            wp_die( esc_html__( 'Apple SSO niet correct geconfigureerd (private key ontbreekt).', 'wsa-agenda' ), 500 );
        }

        $token_resp = wp_remote_post( 'https://appleid.apple.com/auth/token', [
            'body' => [
                'client_id'     => get_option( 'wsa_sso_apple_client_id', '' ),
                'client_secret' => $client_secret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => self::callback_url( 'apple' ),
            ],
        ] );

        if ( is_wp_error( $token_resp ) ) {
            wp_die( esc_html__( 'Apple token-uitwisseling mislukt.', 'wsa-agenda' ), 500 );
        }

        $token_data = json_decode( wp_remote_retrieve_body( $token_resp ), true );
        $id_token   = $token_data['id_token'] ?? '';

        if ( ! $id_token ) {
            wp_die( esc_html__( 'Geen id_token ontvangen van Apple.', 'wsa-agenda' ), 400 );
        }

        // Decode payload (no signature verification for v1 — trust Apple's HTTPS)
        $parts   = explode( '.', $id_token );
        $payload = json_decode( base64_decode( str_pad( strtr( $parts[1] ?? '', '-_', '+/' ), strlen( $parts[1] ?? '' ) % 4, '=', STR_PAD_RIGHT ) ), true );
        $email   = sanitize_email( $payload['email'] ?? '' );

        // Apple may pass name in the first POST only
        $name_raw = $req->get_param( 'user' );
        $name     = '';
        if ( $name_raw ) {
            $name_data = is_string( $name_raw ) ? json_decode( $name_raw, true ) : $name_raw;
            $first     = $name_data['name']['firstName'] ?? '';
            $last      = $name_data['name']['lastName']  ?? '';
            $name      = sanitize_text_field( trim( "$first $last" ) );
        }

        if ( ! $email ) {
            wp_die( esc_html__( 'Geen e-mailadres ontvangen van Apple.', 'wsa-agenda' ), 400 );
        }

        self::login_or_create( $email, $name, $state_data['popup'] ?? false, (int) ( $state_data['event_id'] ?? 0 ) );
    }

    /* ── Shared login flow ──────────────────────────────────────────────── */

    private static function login_or_create( string $email, string $display_name, bool $popup = false, int $event_id = 0 ): void {
        $user = get_user_by( 'email', $email );

        if ( ! $user ) {
            $user_id = wp_create_user(
                sanitize_user( str_replace( '@', '_', $email ), true ),
                wp_generate_password(),
                $email
            );
            if ( is_wp_error( $user_id ) ) {
                wp_die( esc_html__( 'Account aanmaken mislukt.', 'wsa-agenda' ), 500 );
            }
            $user = get_user_by( 'id', $user_id );
            if ( $display_name ) {
                wp_update_user( [ 'ID' => $user_id, 'display_name' => $display_name ] );
            }
            // Assign wsa_bestuur role — admin must promote manually; SSO alone doesn't grant board
            // Change this to add_role('wsa_bestuur') if auto-granting is desired.
            $user->set_role( 'subscriber' );
        }

        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, false );
        do_action( 'wp_login', $user->user_login, $user );

        // Popup mode: return a self-closing page that notifies the opener via postMessage.
        if ( $popup ) {
            $is_board = in_array( 'wsa_bestuur', (array) $user->roles, true )
                        || user_can( $user->ID, 'manage_options' );
            self::output_popup_success( $is_board );
        }

        $agenda = get_page_by_path( 'agenda' );
        $base   = $agenda ? get_permalink( $agenda ) : get_site_url();
        $dest   = $base;
        if ( $event_id > 0 ) {
            $post = get_post( $event_id );
            if ( $post && $post->post_type === 'wsa_event' && $post->post_status === 'publish' ) {
                $dest = add_query_arg( 'wsa_edit_event', $event_id, $base );
            }
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( '[WSA OAuth] redirect → ' . $dest );
        wp_safe_redirect( $dest );
        exit;
    }

    /**
     * Outputs a minimal HTML page that postMessages wsaLoginSuccess to the opener,
     * then closes the popup window.  Used when the OAuth flow was started with ?popup=1.
     */
    private static function output_popup_success( bool $is_board ): void {
        $origin      = esc_js( get_site_url() );
        $is_board_js = $is_board ? 'true' : 'false';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        header( 'Content-Type: text/html; charset=utf-8' );
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="nl">
        <head><meta charset="utf-8"><title>Inloggen geslaagd</title></head>
        <body>
        <p>Inloggen geslaagd. Dit venster sluit automatisch.</p>
        <script>
        try {
          if (window.opener) {
            window.opener.postMessage(
              { wsaLoginSuccess: true, isBoard: {$is_board_js} },
              '{$origin}'
            );
          }
        } catch (e) {}
        window.close();
        </script>
        </body>
        </html>
        HTML;
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /* ── Apple JWT client_secret ────────────────────────────────────────── */

    private static function build_apple_client_secret(): string {
        $private_key = get_option( 'wsa_sso_apple_private_key', '' );
        $team_id     = get_option( 'wsa_sso_apple_team_id', '' );
        $key_id      = get_option( 'wsa_sso_apple_key_id', '' );
        $client_id   = get_option( 'wsa_sso_apple_client_id', '' );

        if ( ! $private_key || ! $team_id || ! $key_id || ! $client_id ) {
            return '';
        }

        $now = time();
        $header = self::base64url_encode( (string) json_encode( [ 'alg' => 'ES256', 'kid' => $key_id ] ) );
        $claims = self::base64url_encode( (string) json_encode( [
            'iss' => $team_id,
            'iat' => $now,
            'exp' => $now + 86400,
            'aud' => 'https://appleid.apple.com',
            'sub' => $client_id,
        ] ) );

        $signing_input = "$header.$claims";
        $key           = openssl_pkey_get_private( $private_key );
        if ( ! $key ) {
            return '';
        }

        openssl_sign( $signing_input, $raw_sig, $key, OPENSSL_ALGO_SHA256 );

        // Convert DER signature to raw R||S for ES256
        $sig = self::der_to_raw( $raw_sig );

        return "$signing_input." . self::base64url_encode( $sig );
    }

    private static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private static function der_to_raw( string $der ): string {
        // DER-encoded ECDSA signature → raw R||S (each 32 bytes for P-256)
        $offset = 4;
        $r_len  = ord( $der[ $offset ] );
        $offset++;
        if ( $r_len > 32 ) { // leading zero byte
            $r_len--;
            $offset++;
        }
        $r      = substr( $der, $offset, $r_len );
        $offset += $r_len + 2;
        $s_len  = ord( $der[ $offset ] );
        $offset++;
        if ( $s_len > 32 ) {
            $s_len--;
            $offset++;
        }
        $s = substr( $der, $offset, $s_len );
        return str_pad( $r, 32, "\0", STR_PAD_LEFT ) . str_pad( $s, 32, "\0", STR_PAD_LEFT );
    }

    /* ── State helpers ──────────────────────────────────────────────────── */

    private static function generate_state( bool $popup = false, int $event_id = 0 ): string {
        $state = bin2hex( random_bytes( 16 ) );
        set_transient( "wsa_oauth_state_{$state}", [ 'popup' => $popup, 'event_id' => $event_id ], self::STATE_OPTION_TTL );
        return $state;
    }

    /**
     * Verifies the OAuth state token.
     * Returns the stored data array on success, or false if the state is invalid/expired.
     */
    private static function verify_state( string $state ): array|false {
        $key  = "wsa_oauth_state_{$state}";
        $data = get_transient( $key );
        if ( $data !== false ) {
            delete_transient( $key );
            // Back-compat: old installs may have stored a bare `1` instead of an array.
            return is_array( $data ) ? $data : [];
        }
        return false;
    }

    private static function callback_url( string $provider ): string {
        return esc_url_raw( get_site_url() ) . "/wp-json/wsa/v1/oauth/{$provider}/callback";
    }

    /* ── Login page buttons ─────────────────────────────────────────────── */

    public static function render_sso_buttons(): void {
        $google_id = get_option( 'wsa_sso_google_client_id', '' );
        $apple_id  = get_option( 'wsa_sso_apple_client_id', '' );
        if ( ! $google_id && ! $apple_id ) {
            return;
        }
        echo '<div class="wsa-sso-buttons">';
        if ( $google_id ) {
            printf(
                '<a href="%s" class="wsa-sso-btn wsa-sso-google">%s</a>',
                esc_url( get_site_url() . '/wp-json/wsa/v1/sso/google' ),
                esc_html__( 'Inloggen met Google', 'wsa-agenda' )
            );
        }
        if ( $apple_id ) {
            printf(
                '<a href="%s" class="wsa-sso-btn wsa-sso-apple">%s</a>',
                esc_url( get_site_url() . '/wp-json/wsa/v1/sso/apple' ),
                esc_html__( 'Inloggen met Apple', 'wsa-agenda' )
            );
        }
        echo '</div>';
    }
}
