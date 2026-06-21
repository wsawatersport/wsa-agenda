<?php
/**
 * Uninstall WSA Agenda — removes all plugin data.
 * Called automatically by WordPress when the plugin is deleted via the admin.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/* ── 1. Delete all wsa_event posts + meta ───────────────────────── */
$post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wsa_event'" );
foreach ( $post_ids as $id ) {
    wp_delete_post( (int) $id, true );
}

/* ── 2. Delete all wsa_event_category terms + meta ─────────────── */
$terms = get_terms( [ 'taxonomy' => 'wsa_event_category', 'hide_empty' => false, 'fields' => 'ids' ] );
if ( ! is_wp_error( $terms ) ) {
    foreach ( $terms as $term_id ) {
        wp_delete_term( (int) $term_id, 'wsa_event_category' );
    }
}

/* ── 3. Drop custom DB tables ───────────────────────────────────── */
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsa_rsvp" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsa_vacations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsa_ics_feeds" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsa_subscriptions" );

/* ── 4. Delete all plugin options ───────────────────────────────── */
$options = [
    'wsa_sso_google_client_id',
    'wsa_sso_google_client_secret',
    'wsa_sso_apple_client_id',
    'wsa_sso_apple_team_id',
    'wsa_sso_apple_key_id',
    'wsa_sso_apple_private_key',
    'wsa_smtp_host',
    'wsa_smtp_port',
    'wsa_smtp_encryption',
    'wsa_smtp_username',
    'wsa_smtp_password',
    'wsa_smtp_from_email',
    'wsa_smtp_from_name',
];
foreach ( $options as $opt ) {
    delete_option( $opt );
}

/* ── 5a. Cancel all subscription cron jobs ──────────────────────── */
// We don't have WSA_Subscriptions loaded here, so clear by hook name directly.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wsa_sub_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wsa_sub_%'" );
// Also clear WP cron events for subscription reminders.
$cron = _get_cron_array();
foreach ( [ 'wsa_sub_reminder_7day', 'wsa_sub_reminder_1day' ] as $hook ) {
    if ( isset( $cron ) ) {
        foreach ( (array) $cron as $timestamp => $jobs ) {
            if ( isset( $jobs[ $hook ] ) ) {
                foreach ( $jobs[ $hook ] as $key => $job ) {
                    wp_unschedule_event( $timestamp, $hook, $job['args'] );
                }
            }
        }
    }
}

/* ── 5. Delete all transients ───────────────────────────────────── */
// Holiday transients (wsa_holidays_{year})
for ( $y = 2020; $y <= date( 'Y' ) + 2; $y++ ) {
    delete_transient( "wsa_holidays_{$y}" );
}
// ICS feed transients
$feed_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}wsa_ics_feeds" ) ?: [];
foreach ( $feed_ids as $id ) {
    delete_transient( "wsa_ics_feed_{$id}" );
}
// OAuth state transients (match by option name prefix in wp_options)
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wsa_oauth_state_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wsa_oauth_state_%'" );

/* ── 6. Remove wsa_bestuur role ───────────────────────────────────── */
remove_role( 'wsa_bestuur' );

/* ── 7. Remove all WSA capabilities from administrator role ─────── */
$admin = get_role( 'administrator' );
if ( $admin ) {
    foreach ( [
        'manage_wsa_events',
        'edit_wsa_events',
        'delete_wsa_events',
        'manage_wsa_categories',
        'manage_wsa_vacations',
        'manage_wsa_ics_feeds',
    ] as $cap ) {
        $admin->remove_cap( $cap );
    }
}

/* ── 8. Delete /agenda page created by plugin ───────────────────── */
$page = get_page_by_path( 'agenda' );
if ( $page && strpos( $page->post_content, '[wsa_agenda]' ) !== false ) {
    wp_delete_post( $page->ID, true );
}
