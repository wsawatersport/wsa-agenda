<?php
defined( 'ABSPATH' ) || exit;

class WSA_Activator {

    /**
     * All capabilities owned by the wsa_bestuur role.
     * Kept in one place so activate(), register(), and uninstall.php stay in sync.
     */
    public static function wsa_caps(): array {
        return [
            'read'                  => true,   // required for WP admin dashboard
            'manage_wsa_events'     => true,   // legacy cap used by CPT registration
            'edit_wsa_events'       => true,   // create & edit events
            'delete_wsa_events'     => true,   // delete events
            'manage_wsa_categories' => true,   // add/edit/delete categories
            'manage_wsa_vacations'  => true,   // add/edit/delete vacation blocks
            'manage_wsa_ics_feeds'  => true,   // add/edit/delete ICS feed URLs
        ];
    }

    public static function activate(): void {
        $caps = self::wsa_caps();

        // Create or update the wsa_bestuur role
        if ( ! get_role( 'wsa_bestuur' ) ) {
            add_role( 'wsa_bestuur', __( 'WSA Bestuur', 'wsa-agenda' ), $caps );
        } else {
            // Role already exists (e.g. upgrade path) — ensure every cap is present
            $board = get_role( 'wsa_bestuur' );
            foreach ( $caps as $cap => $grant ) {
                if ( $grant && ! $board->has_cap( $cap ) ) {
                    $board->add_cap( $cap );
                }
            }
        }

        // Mirror all WSA caps onto the administrator role
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( $caps as $cap => $grant ) {
                if ( $grant && ! $admin->has_cap( $cap ) ) {
                    $admin->add_cap( $cap );
                }
            }
        }

        // Register CPT + taxonomy so flush_rewrite_rules works
        WSA_CPT::register();
        WSA_Taxonomy::register();

        // DB tables — delegate to the shared helper so every table is always
        // created in one place. Also stamp the version so wsa_maybe_create_tables()
        // on the next plugins_loaded does not immediately re-run dbDelta.
        wsa_create_tables();
        update_option( 'wsa_db_version', WSA_DB_VERSION );

        // Seed categories (only when taxonomy is empty)
        self::seed_categories();

        // Create /agenda page if not present
        self::maybe_create_agenda_page();

        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    private static function seed_categories(): void {
        $existing = get_terms( [
            'taxonomy'   => 'wsa_event_category',
            'hide_empty' => false,
            'fields'     => 'ids',
        ] );
        if ( ! is_wp_error( $existing ) && count( $existing ) > 0 ) {
            return;
        }

        $defaults = [
            [ 'name' => 'Seizoensopening',  'color' => '#1D9E75' ],
            [ 'name' => 'Seizoenssluiting', 'color' => '#378ADD' ],
            [ 'name' => 'Clubhuis verhuur', 'color' => '#EF9F27' ],
            [ 'name' => 'Sportdag',         'color' => '#D85A30' ],
            [ 'name' => 'ALV',              'color' => '#7F77DD' ],
            [ 'name' => 'Open dag',         'color' => '#D4537E' ],
            [ 'name' => 'Zeilcursus',       'color' => '#639922' ],
            [ 'name' => 'Overig',           'color' => '#888780' ],
        ];

        foreach ( $defaults as $cat ) {
            $result = wp_insert_term( $cat['name'], 'wsa_event_category' );
            if ( ! is_wp_error( $result ) ) {
                update_term_meta( $result['term_id'], 'wsa_category_color', $cat['color'] );
            }
        }
    }

    private static function maybe_create_agenda_page(): void {
        $page = get_page_by_path( 'agenda' );
        if ( $page ) {
            return;
        }
        wp_insert_post( [
            'post_title'   => 'Agenda',
            'post_name'    => 'agenda',
            'post_content' => '[wsa_agenda]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );
    }
}
