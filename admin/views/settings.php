<?php defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Geen toegang.' ); }

$smtp_host = get_option( 'wsa_smtp_host', '' );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'WSA Instellingen', 'wsa-agenda' ); ?></h1>

    <?php
    /* ── Notices ────────────────────────────────────────────────────── */
    if ( ! $smtp_host ) : ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e( 'SMTP niet geconfigureerd — e-mails worden verstuurd via de standaard WordPress mailserver.', 'wsa-agenda' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Instellingen opgeslagen.', 'wsa-agenda' ); ?></p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['smtp_test'] ) ) : ?>
        <?php if ( '1' === $_GET['smtp_test'] ) :
            $test_to = sanitize_email( rawurldecode( (string) ( $_GET['smtp_test_to'] ?? '' ) ) ); ?>
            <div class="notice notice-success is-dismissible">
                <p><?php printf( esc_html__( 'Testmail verzonden naar %s.', 'wsa-agenda' ), '<strong>' . esc_html( $test_to ) . '</strong>' ); ?></p>
            </div>
        <?php else :
            $test_err = sanitize_text_field( rawurldecode( (string) ( $_GET['smtp_test_error'] ?? '' ) ) );
            if ( ! $test_err ) { $test_err = __( 'Onbekende fout.', 'wsa-agenda' ); } ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html__( 'Testmail mislukt: ', 'wsa-agenda' ) . esc_html( $test_err ); ?></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    /* ── SSO: Google ─────────────────────────────────────────────── */
    ?>
    <form method="post">
        <?php wp_nonce_field( 'wsa_admin_action' ); ?>
        <input type="hidden" name="wsa_action" value="save_settings">

        <h2><?php esc_html_e( 'Google SSO', 'wsa-agenda' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Callback URL voor Google:', 'wsa-agenda' ); ?>
            <code><?php echo esc_html( get_site_url() . '/wp-json/wsa/v1/oauth/google/callback' ); ?></code>
        </p>
        <table class="form-table">
            <tr>
                <th><label for="wsa_sso_google_client_id"><?php esc_html_e( 'Client ID', 'wsa-agenda' ); ?></label></th>
                <td><input type="text" name="wsa_sso_google_client_id" id="wsa_sso_google_client_id"
                    class="large-text" value="<?php echo esc_attr( get_option( 'wsa_sso_google_client_id', '' ) ); ?>"></td>
            </tr>
            <tr>
                <th><label for="wsa_sso_google_client_secret"><?php esc_html_e( 'Client Secret', 'wsa-agenda' ); ?></label></th>
                <td><input type="password" name="wsa_sso_google_client_secret" id="wsa_sso_google_client_secret"
                    class="large-text" value="<?php echo esc_attr( get_option( 'wsa_sso_google_client_secret', '' ) ); ?>"></td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Apple SSO', 'wsa-agenda' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Callback URL voor Apple:', 'wsa-agenda' ); ?>
            <code><?php echo esc_html( get_site_url() . '/wp-json/wsa/v1/oauth/apple/callback' ); ?></code><br>
            <?php esc_html_e( 'Apple vereist HTTPS en een geregistreerd domain in Apple Developer Console.', 'wsa-agenda' ); ?>
        </p>
        <table class="form-table">
            <tr>
                <th><label for="wsa_sso_apple_client_id"><?php esc_html_e( 'Services ID (Client ID)', 'wsa-agenda' ); ?></label></th>
                <td><input type="text" name="wsa_sso_apple_client_id" id="wsa_sso_apple_client_id"
                    class="large-text" value="<?php echo esc_attr( get_option( 'wsa_sso_apple_client_id', '' ) ); ?>"></td>
            </tr>
            <tr>
                <th><label for="wsa_sso_apple_team_id"><?php esc_html_e( 'Team ID', 'wsa-agenda' ); ?></label></th>
                <td><input type="text" name="wsa_sso_apple_team_id" id="wsa_sso_apple_team_id"
                    class="regular-text" value="<?php echo esc_attr( get_option( 'wsa_sso_apple_team_id', '' ) ); ?>"></td>
            </tr>
            <tr>
                <th><label for="wsa_sso_apple_key_id"><?php esc_html_e( 'Key ID', 'wsa-agenda' ); ?></label></th>
                <td><input type="text" name="wsa_sso_apple_key_id" id="wsa_sso_apple_key_id"
                    class="regular-text" value="<?php echo esc_attr( get_option( 'wsa_sso_apple_key_id', '' ) ); ?>"></td>
            </tr>
            <tr>
                <th><label for="wsa_sso_apple_private_key"><?php esc_html_e( 'Private Key (.p8 inhoud)', 'wsa-agenda' ); ?></label></th>
                <td>
                    <textarea name="wsa_sso_apple_private_key" id="wsa_sso_apple_private_key"
                        rows="8" class="large-text code"><?php echo esc_textarea( get_option( 'wsa_sso_apple_private_key', '' ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Plak de volledige inhoud van het .p8-bestand inclusief BEGIN/END regels.', 'wsa-agenda' ); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button( __( 'Instellingen opslaan', 'wsa-agenda' ) ); ?>
    </form>

    <hr style="margin:32px 0 24px">

    <?php
    /* ── E-mail instellingen ─────────────────────────────────────── */
    ?>
    <h2><?php esc_html_e( 'E-mail instellingen', 'wsa-agenda' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Vul een SMTP-server in voor betrouwbare verzending van ICS-uitnodigingen, bevestigingen en herinneringen. Laat het hostveld leeg om de standaard WordPress-mailserver te gebruiken.', 'wsa-agenda' ); ?>
    </p>

    <form method="post">
        <?php wp_nonce_field( 'wsa_admin_action' ); ?>
        <input type="hidden" name="wsa_action" value="save_smtp">

        <table class="form-table">
            <tr>
                <th><label for="wsa_smtp_host"><?php esc_html_e( 'SMTP host', 'wsa-agenda' ); ?></label></th>
                <td>
                    <input type="text" name="wsa_smtp_host" id="wsa_smtp_host"
                        class="regular-text"
                        value="<?php echo esc_attr( get_option( 'wsa_smtp_host', '' ) ); ?>"
                        placeholder="mail.wsawatersport.nl">
                    <p class="description"><?php esc_html_e( 'Laat leeg om SMTP uit te schakelen.', 'wsa-agenda' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wsa_smtp_port"><?php esc_html_e( 'SMTP port', 'wsa-agenda' ); ?></label></th>
                <td><input type="number" name="wsa_smtp_port" id="wsa_smtp_port"
                    class="small-text"
                    value="<?php echo esc_attr( get_option( 'wsa_smtp_port', '587' ) ); ?>"
                    min="1" max="65535"></td>
            </tr>
            <tr>
                <th><label for="wsa_smtp_encryption"><?php esc_html_e( 'SMTP beveiliging', 'wsa-agenda' ); ?></label></th>
                <td>
                    <select name="wsa_smtp_encryption" id="wsa_smtp_encryption">
                        <?php
                        $enc = get_option( 'wsa_smtp_encryption', 'tls' );
                        foreach ( [ 'tls' => 'TLS (STARTTLS, aanbevolen)', 'ssl' => 'SSL', '' => 'Geen' ] as $val => $label ) :
                            printf(
                                '<option value="%s"%s>%s</option>',
                                esc_attr( $val ),
                                selected( $enc, $val, false ),
                                esc_html( $label )
                            );
                        endforeach;
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="wsa_smtp_username"><?php esc_html_e( 'SMTP gebruikersnaam', 'wsa-agenda' ); ?></label></th>
                <td><input type="text" name="wsa_smtp_username" id="wsa_smtp_username"
                    class="regular-text"
                    value="<?php echo esc_attr( get_option( 'wsa_smtp_username', '' ) ); ?>"
                    placeholder="agenda@wsawatersport.nl"
                    autocomplete="off"></td>
            </tr>
            <tr>
                <th><label for="wsa_smtp_password"><?php esc_html_e( 'SMTP wachtwoord', 'wsa-agenda' ); ?></label></th>
                <td>
                    <input type="password" name="wsa_smtp_password" id="wsa_smtp_password"
                        class="regular-text" value="" autocomplete="new-password"
                        placeholder="<?php echo get_option( 'wsa_smtp_password' ) ? esc_attr__( '(ongewijzigd)', 'wsa-agenda' ) : ''; ?>">
                    <p class="description"><?php esc_html_e( 'Laat leeg om het bestaande wachtwoord te bewaren. Wachtwoord wordt versleuteld opgeslagen.', 'wsa-agenda' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wsa_smtp_from_name"><?php esc_html_e( 'Afzendernaam', 'wsa-agenda' ); ?></label></th>
                <td><input type="text" name="wsa_smtp_from_name" id="wsa_smtp_from_name"
                    class="regular-text"
                    value="<?php echo esc_attr( get_option( 'wsa_smtp_from_name', 'WSA Watersport' ) ); ?>"
                    placeholder="WSA Watersport"></td>
            </tr>
            <tr>
                <th><label for="wsa_smtp_from_email"><?php esc_html_e( 'Afzenderadres', 'wsa-agenda' ); ?></label></th>
                <td><input type="email" name="wsa_smtp_from_email" id="wsa_smtp_from_email"
                    class="regular-text"
                    value="<?php echo esc_attr( get_option( 'wsa_smtp_from_email', 'agenda@wsawatersport.nl' ) ); ?>"
                    placeholder="agenda@wsawatersport.nl"></td>
            </tr>
        </table>

        <?php submit_button( __( 'E-mailinstellingen opslaan', 'wsa-agenda' ), 'primary', 'submit', false ); ?>
        &nbsp;
    </form>

    <?php /* Test-mail is a separate POST so the result survives the save redirect */ ?>
    <form method="post" style="display:inline">
        <?php wp_nonce_field( 'wsa_admin_action' ); ?>
        <input type="hidden" name="wsa_action" value="send_test_mail">
        <?php
        $current_user = wp_get_current_user();
        submit_button(
            sprintf( __( 'Verstuur testmail naar %s', 'wsa-agenda' ), $current_user->user_email ),
            'secondary',
            'submit',
            false
        );
        ?>
    </form>
    <?php if ( ! $smtp_host ) : ?>
        <p class="description" style="margin-top:6px">
            <?php esc_html_e( 'Tip: sla de SMTP-instellingen op voor je de testmail verstuurt.', 'wsa-agenda' ); ?>
        </p>
    <?php endif; ?>

</div>
