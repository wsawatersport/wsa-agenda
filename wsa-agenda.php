<?php
/**
 * Plugin Name: WSA Agenda
 * Plugin URI:  https://wsawatersport.nl
 * Description: Custom agenda/calendar system for WSA Watersport. Geen externe plugins.
 * Version:     1.3.4
 * Author:      WSA Watersport
 * Text Domain: wsa-agenda
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WSA_AGENDA_VERSION',     '1.3.4' );
define( 'WSA_AGENDA_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WSA_AGENDA_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WSA_AGENDA_PLUGIN_FILE', __FILE__ );
define( 'WSA_SCHEMA_VERSION',     2 );
/**
 * Increment this string whenever any table schema changes or a new table is
 * added. wsa_maybe_create_tables() compares it against the stored option and
 * re-runs dbDelta() whenever they differ — meaning updates are handled without
 * requiring a plugin deactivate/reactivate cycle.
 */
define( 'WSA_DB_VERSION', '3' );

foreach ( [
    'class-activator',
    'class-cpt',
    'class-taxonomy',
    'class-db',
    'class-ics-parser',
    'class-public-blocks',
    'class-rsvp',
    'class-subscriptions',
    'class-smtp',
    'class-rest-api',
    'class-admin',
    'class-sso',
    'class-migration',
] as $file ) {
    require_once WSA_AGENDA_PLUGIN_DIR . "includes/{$file}.php";
}

register_activation_hook( __FILE__,   [ 'WSA_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WSA_Activator', 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
    WSA_CPT::init();
    WSA_Taxonomy::init();
    WSA_REST_API::init();
    WSA_Admin::init();
    WSA_SSO::init();
    WSA_Migration::init();
    WSA_Subscriptions::init();
    WSA_SMTP::init();
} );

/**
 * Create / update all plugin DB tables via dbDelta().
 * Called both from the activation hook and from wsa_maybe_create_tables().
 */
function wsa_create_tables(): void {
    WSA_DB::create_tables();           // wsa_rsvp, wsa_vacations, wsa_ics_feeds
    WSA_Subscriptions::create_table(); // wsa_subscriptions
}

/**
 * On every plugin load, check whether the stored DB version matches
 * WSA_DB_VERSION. If not, re-run dbDelta() so that tables created after
 * initial installation (or missed due to update-vs-activate timing) are
 * always present without requiring a manual deactivate/reactivate cycle.
 */
function wsa_maybe_create_tables(): void {
    $stored = get_option( 'wsa_db_version', '0' );
    if ( $stored !== WSA_DB_VERSION ) {
        wsa_create_tables();
        update_option( 'wsa_db_version', WSA_DB_VERSION );
    }
}
// Priority 5 — runs before the priority-10 class::init() hooks so tables
// exist before any REST routes or admin screens try to query them.
add_action( 'plugins_loaded', 'wsa_maybe_create_tables', 5 );

/**
 * One-time migration: rename the old wsa_board role to wsa_bestuur.
 * Runs on every plugins_loaded but exits immediately once done.
 * - Creates wsa_bestuur with the canonical caps
 * - Re-assigns every user that had wsa_board
 * - Removes the old wsa_board role from the DB
 */
function wsa_maybe_rename_board_role(): void {
    // Nothing to do once the old role is gone.
    if ( ! get_role( 'wsa_board' ) ) {
        return;
    }
    // Ensure the new role exists with all caps.
    if ( ! get_role( 'wsa_bestuur' ) ) {
        add_role( 'wsa_bestuur', __( 'WSA Bestuur', 'wsa-agenda' ), WSA_Activator::wsa_caps() );
    }
    // Re-assign every user that still carries the old role.
    foreach ( get_users( [ 'role' => 'wsa_board' ] ) as $user ) {
        $user->remove_role( 'wsa_board' );
        $user->add_role( 'wsa_bestuur' );
    }
    // Remove the old role from wp_user_roles.
    remove_role( 'wsa_board' );
}
add_action( 'plugins_loaded', 'wsa_maybe_rename_board_role', 5 );

/* ── Shortcode ────────────────────────────────────────────────────────────── */

add_shortcode( 'wsa_agenda', 'wsa_agenda_shortcode' );

function wsa_agenda_shortcode(): string {
    wp_enqueue_style(
        'wsa-calendar',
        WSA_AGENDA_PLUGIN_URL . 'assets/css/calendar.css',
        [],
        WSA_AGENDA_VERSION
    );
    wp_enqueue_script(
        'wsa-calendar',
        WSA_AGENDA_PLUGIN_URL . 'assets/js/calendar.js',
        [],
        WSA_AGENDA_VERSION,
        true
    );

    $user     = wp_get_current_user();
    $is_board = in_array( 'wsa_bestuur', (array) $user->roles, true )
                || current_user_can( 'manage_options' );

    // SSO URLs — used by the JS login-popup flow.
    $google_id     = get_option( 'wsa_sso_google_client_id', '' );
    $apple_id      = get_option( 'wsa_sso_apple_client_id', '' );
    $agenda_page   = get_page_by_path( 'agenda' );
    $login_redirect = $agenda_page ? get_permalink( $agenda_page ) : get_site_url();

    // Pre-fetch categories for the edit-modal dropdown (board members only).
    $terms = get_terms( [ 'taxonomy' => 'wsa_event_category', 'hide_empty' => false ] );
    $cats  = [];
    if ( ! is_wp_error( $terms ) ) {
        foreach ( $terms as $t ) {
            $cats[] = [
                'slug'  => $t->slug,
                'name'  => $t->name,
                'color' => get_term_meta( $t->term_id, 'wsa_category_color', true ) ?: '#888780',
            ];
        }
    }

    // WordPress media picker is needed for the attachment uploader (board only).
    if ( $is_board ) {
        wp_enqueue_media();
    }

    wp_localize_script( 'wsa-calendar', 'wsaAgenda', [
        'apiBase'      => esc_url_raw( get_site_url() ) . '/wp-json/wsa/v1',
        'nonce'        => wp_create_nonce( 'wp_rest' ),
        'isBoard'      => $is_board,
        'isLoggedIn'   => (bool) $user->ID,
        'siteUrl'      => esc_url_raw( get_site_url() ),
        'categories'   => $cats,
        'mediaTitle'   => __( 'Bijlage selecteren', 'wsa-agenda' ),
        'mediaButton'  => __( 'Selecteer', 'wsa-agenda' ),
        // SSO initiate URLs for the in-modal login chooser (full-page redirect, no popup).
        // If credentials are not yet configured the endpoint returns a clear 400 error.
        'ssoGoogleUrl' => esc_url_raw( get_site_url() . '/wp-json/wsa/v1/oauth/google/initiate' ),
        'ssoAppleUrl'  => esc_url_raw( get_site_url() . '/wp-json/wsa/v1/oauth/apple/initiate' ),
        // Fallback for browsers that block popups (redirects back to agenda page).
        'loginUrl'     => esc_url_raw( wp_login_url( (string) $login_redirect ) ),
        'strings'     => [
            'addEvent'       => __( '+ Evenement toevoegen', 'wsa-agenda' ),
            'close'          => __( 'Sluiten', 'wsa-agenda' ),
            'edit'           => __( 'Bewerken', 'wsa-agenda' ),
            'delete'         => __( 'Verwijderen', 'wsa-agenda' ),
            'save'           => __( 'Opslaan', 'wsa-agenda' ),
            'cancel'         => __( 'Annuleren', 'wsa-agenda' ),
            'rsvpLabel'      => __( 'Aanmelden', 'wsa-agenda' ),
            'rsvpDone'       => __( 'Je bent aangemeld', 'wsa-agenda' ),
            'rsvpFull'       => __( 'Vol — geen plaatsen meer beschikbaar', 'wsa-agenda' ),
            'showPublic'     => __( 'Toon publieke agenda', 'wsa-agenda' ),
            'weekView'       => __( 'Week', 'wsa-agenda' ),
            'monthView'      => __( 'Maand', 'wsa-agenda' ),
            'yearView'       => __( 'Jaar', 'wsa-agenda' ),
            'listView'       => __( 'Lijst', 'wsa-agenda' ),
            'today'          => __( 'Vandaag', 'wsa-agenda' ),
            'loading'        => __( 'Laden\u2026', 'wsa-agenda' ),
            'noEvents'       => __( 'Geen evenementen', 'wsa-agenda' ),
            'confirmDelete'  => __( 'Weet je zeker dat je dit evenement wilt verwijderen?', 'wsa-agenda' ),
            'spotsOf'        => __( 'van', 'wsa-agenda' ),
            'spotsOccupied'  => __( 'plaatsen bezet', 'wsa-agenda' ),
            'nameLabel'      => __( 'Naam', 'wsa-agenda' ),
            'emailLabel'     => __( 'E-mailadres', 'wsa-agenda' ),
            'rsvpSubmit'     => __( 'Aanmelden', 'wsa-agenda' ),
            'allCategories'  => __( 'Alle categorieën', 'wsa-agenda' ),
            'attachments'    => __( 'Bijlagen', 'wsa-agenda' ),
            'selectFiles'    => __( 'Bestanden selecteren', 'wsa-agenda' ),
            'rsvpList'       => __( 'Aanmeldingen', 'wsa-agenda' ),
            'downloadCsv'    => __( 'Download CSV', 'wsa-agenda' ),
            'title'          => __( 'Titel', 'wsa-agenda' ),
            'category'       => __( 'Categorie', 'wsa-agenda' ),
            'startDate'      => __( 'Startdatum', 'wsa-agenda' ),
            'endDate'        => __( 'Einddatum', 'wsa-agenda' ),
            'description'    => __( 'Omschrijving', 'wsa-agenda' ),
            'rsvpEnabled'    => __( 'Aanmelden inschakelen', 'wsa-agenda' ),
            'rsvpLimit'      => __( 'Max. deelnemers (0 = onbeperkt)', 'wsa-agenda' ),
            'errorRequired'  => __( 'Dit veld is verplicht.', 'wsa-agenda' ),
            'errorEmail'     => __( 'Vul een geldig e-mailadres in.', 'wsa-agenda' ),
            'saved'          => __( 'Opgeslagen!', 'wsa-agenda' ),
            'deleted'        => __( 'Verwijderd.', 'wsa-agenda' ),
            'errorSave'      => __( 'Opslaan mislukt. Probeer opnieuw.', 'wsa-agenda' ),
            'allDay'         => __( 'Hele dag', 'wsa-agenda' ),
            'addFile'        => __( 'Bestand toevoegen', 'wsa-agenda' ),
            'startTime'      => __( 'Starttijd', 'wsa-agenda' ),
            'endTime'        => __( 'Eindtijd', 'wsa-agenda' ),
            // Subscription UI strings
            'aanmelden'       => __( 'Aanmelden', 'wsa-agenda' ),
            'subPanelPrefix'  => __( 'Aanmelden voor updates —', 'wsa-agenda' ),
            'subIcsDesc'      => __( 'Voeg dit evenement toe aan je agenda app', 'wsa-agenda' ),
            'subRemDesc'      => __( 'Ontvang een herinnering één week en één dag van tevoren', 'wsa-agenda' ),
            'icsSend'         => __( 'Stuur ICS-uitnodiging', 'wsa-agenda' ),
            'icsSent'         => __( 'Uitnodiging verstuurd! Controleer je inbox.', 'wsa-agenda' ),
            'reminderSubmit'  => __( 'Herinner mij', 'wsa-agenda' ),
            'reminderDone'    => __( 'Je bent aangemeld. Je ontvangt een bevestiging per e-mail.', 'wsa-agenda' ),
            'reminderAlready' => __( 'Dit e-mailadres is al aangemeld voor dit evenement.', 'wsa-agenda' ),
            'unsubscribeLabel' => __( 'Afmelden voor herinneringen', 'wsa-agenda' ),
            'errorSend'       => __( 'Versturen mislukt. Probeer het opnieuw.', 'wsa-agenda' ),
            'mon'            => __( 'Ma', 'wsa-agenda' ),
            'tue'            => __( 'Di', 'wsa-agenda' ),
            'wed'            => __( 'Wo', 'wsa-agenda' ),
            'thu'            => __( 'Do', 'wsa-agenda' ),
            'fri'            => __( 'Vr', 'wsa-agenda' ),
            'sat'            => __( 'Za', 'wsa-agenda' ),
            'sun'            => __( 'Zo', 'wsa-agenda' ),
            'months'         => [
                __( 'Januari', 'wsa-agenda' ),   __( 'Februari', 'wsa-agenda' ),
                __( 'Maart', 'wsa-agenda' ),      __( 'April', 'wsa-agenda' ),
                __( 'Mei', 'wsa-agenda' ),        __( 'Juni', 'wsa-agenda' ),
                __( 'Juli', 'wsa-agenda' ),       __( 'Augustus', 'wsa-agenda' ),
                __( 'September', 'wsa-agenda' ),  __( 'Oktober', 'wsa-agenda' ),
                __( 'November', 'wsa-agenda' ),   __( 'December', 'wsa-agenda' ),
            ],
        ],
    ] );

    ob_start();
    ?>
    <div id="wsa-calendar-root" class="wsa-calendar-root" translate="no">
        <div id="wsa-calendar-app"></div>
        <div id="wsa-modal-root"></div>
    </div>
    <?php
    return (string) ob_get_clean();
}
