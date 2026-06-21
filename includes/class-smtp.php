<?php
defined( 'ABSPATH' ) || exit;

/**
 * WSA_SMTP — configures PHPMailer with saved SMTP credentials.
 *
 * Options stored:
 *   wsa_smtp_host         — SMTP hostname
 *   wsa_smtp_port         — port (default 587)
 *   wsa_smtp_encryption   — 'tls' | 'ssl' | '' (none)
 *   wsa_smtp_username     — SMTP username
 *   wsa_smtp_password     — AES-256-CBC encrypted via encrypt_password()
 *   wsa_smtp_from_email   — From address
 *   wsa_smtp_from_name    — From display name
 */
class WSA_SMTP {

    public static function init(): void {
        // Only hook PHPMailer if an SMTP host is configured.
        if ( get_option( 'wsa_smtp_host', '' ) ) {
            add_action( 'phpmailer_init', [ __CLASS__, 'configure' ] );
        }
    }

    /**
     * Called by the phpmailer_init action.
     * $mailer is PHPMailer\PHPMailer\PHPMailer (WP 5.5+).
     *
     * @param PHPMailer\PHPMailer\PHPMailer $mailer
     */
    public static function configure( $mailer ): void {
        $host = (string) get_option( 'wsa_smtp_host', '' );
        $port = (int)    get_option( 'wsa_smtp_port', 587 );
        $enc  = (string) get_option( 'wsa_smtp_encryption', 'tls' );
        $user = (string) get_option( 'wsa_smtp_username', '' );
        $pass = self::decrypt_password( (string) get_option( 'wsa_smtp_password', '' ) );

        if ( ! $host ) {
            return;
        }

        $mailer->isSMTP();
        $mailer->Host     = $host;
        $mailer->Port     = $port;
        $mailer->SMTPAuth = ( $user !== '' );
        $mailer->Username = $user;
        $mailer->Password = $pass;

        // Map stored encryption string → PHPMailer constants.
        if ( $enc === 'ssl' ) {
            $mailer->SMTPSecure = 'ssl';
        } elseif ( $enc === 'tls' ) {
            $mailer->SMTPSecure = 'tls';
        } else {
            $mailer->SMTPSecure  = '';
            $mailer->SMTPAutoTLS = false;
        }
    }

    /* ── Password encryption ────────────────────────────────────────── */

    /**
     * Encrypt the password with AES-256-CBC, keyed by the WordPress AUTH_KEY.
     * Falls back to base64 obfuscation when OpenSSL is unavailable.
     */
    public static function encrypt_password( string $plain ): string {
        if ( $plain === '' ) {
            return '';
        }

        if ( function_exists( 'openssl_encrypt' ) ) {
            $key    = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
            $iv_len = (int) openssl_cipher_iv_length( 'AES-256-CBC' );
            $iv     = openssl_random_pseudo_bytes( $iv_len );
            $enc    = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
            if ( $enc !== false ) {
                return 'aes:' . base64_encode( $iv . $enc );
            }
        }

        // Fallback: simple base64 obfuscation.
        return 'b64:' . base64_encode( $plain );
    }

    public static function decrypt_password( string $stored ): string {
        if ( str_starts_with( $stored, 'aes:' ) && function_exists( 'openssl_decrypt' ) ) {
            $key    = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
            $data   = (string) base64_decode( substr( $stored, 4 ) );
            $iv_len = (int) openssl_cipher_iv_length( 'AES-256-CBC' );
            $iv     = substr( $data, 0, $iv_len );
            $enc    = substr( $data, $iv_len );
            $dec    = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
            return $dec !== false ? $dec : '';
        }
        if ( str_starts_with( $stored, 'b64:' ) ) {
            return (string) base64_decode( substr( $stored, 4 ) );
        }
        return $stored; // Plain-text backward-compat
    }

    /* ── Test mail ──────────────────────────────────────────────────── */

    /**
     * Send a test email to $to using the currently saved SMTP settings.
     *
     * @param  string           $to  Recipient email address.
     * @return true|WP_Error
     */
    public static function send_test_mail( string $to ): true|WP_Error {
        $from_email = (string) get_option( 'wsa_smtp_from_email', get_option( 'admin_email', '' ) );
        $from_name  = (string) get_option( 'wsa_smtp_from_name',  get_bloginfo( 'name' ) );

        // Capture any PHPMailer error via the wp_mail_failed action.
        $captured_error = null;
        $error_handler  = static function ( WP_Error $err ) use ( &$captured_error ): void {
            $captured_error = $err;
        };
        add_action( 'wp_mail_failed', $error_handler );

        add_filter( 'wp_mail_from',      fn() => $from_email, 20 );
        add_filter( 'wp_mail_from_name', fn() => $from_name,  20 );

        $subject = __( 'WSA Agenda – testmail', 'wsa-agenda' );
        $message = sprintf(
            /* translators: 1: From name, 2: From email, 3: To email */
            __( "Dit is een testmail van de WSA Agenda plugin.\n\nAfzender : %1\$s <%2\$s>\nOntvanger: %3\$s\n\nAls je dit bericht ontvangt, is de e-mailconfiguratie correct.", 'wsa-agenda' ),
            $from_name,
            $from_email,
            $to
        );

        $sent = wp_mail( $to, $subject, $message, [ 'Content-Type: text/plain; charset=UTF-8' ] );

        remove_action( 'wp_mail_failed', $error_handler );
        remove_all_filters( 'wp_mail_from',      20 );
        remove_all_filters( 'wp_mail_from_name', 20 );

        if ( $sent ) {
            return true;
        }

        return $captured_error instanceof WP_Error
            ? $captured_error
            : new WP_Error( 'mail_failed', __( 'E-mail kon niet worden verstuurd.', 'wsa-agenda' ) );
    }
}
