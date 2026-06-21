<?php
defined( 'ABSPATH' ) || exit;

class WSA_Admin {

    public static function init(): void {
        add_action( 'admin_menu',    [ __CLASS__, 'register_menus' ] );
        add_action( 'admin_init',    [ __CLASS__, 'handle_forms' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_boxes' ] );
    }

    public static function register_menus(): void {
        if ( ! WSA_CPT::current_user_can_manage() ) {
            return;
        }

        add_menu_page(
            __( 'WSA Agenda', 'wsa-agenda' ),
            __( 'WSA Agenda', 'wsa-agenda' ),
            'manage_wsa_events',
            'wsa-agenda',
            [ __CLASS__, 'page_events' ],
            'dashicons-calendar-alt',
            30
        );

        add_submenu_page(
            'wsa-agenda',
            __( 'Evenementen', 'wsa-agenda' ),
            __( 'Evenementen', 'wsa-agenda' ),
            'manage_wsa_events',
            'wsa-agenda',
            [ __CLASS__, 'page_events' ]
        );

        add_submenu_page(
            'wsa-agenda',
            __( 'WSA Categorieën', 'wsa-agenda' ),
            __( 'Categorieën', 'wsa-agenda' ),
            'manage_wsa_categories',     // granular cap — board members have this
            'wsa-categories',
            [ __CLASS__, 'page_categories' ]
        );

        add_submenu_page(
            'wsa-agenda',
            __( 'WSA Vakanties', 'wsa-agenda' ),
            __( 'Vakanties', 'wsa-agenda' ),
            'manage_wsa_vacations',      // granular cap — board members have this
            'wsa-vacations',
            [ __CLASS__, 'page_vacations' ]
        );

        add_submenu_page(
            'wsa-agenda',
            __( 'WSA ICS Feeds', 'wsa-agenda' ),
            __( 'ICS Feeds', 'wsa-agenda' ),
            'manage_wsa_ics_feeds',      // granular cap — board members have this
            'wsa-ics-feeds',
            [ __CLASS__, 'page_ics_feeds' ]
        );

        add_submenu_page(
            'wsa-agenda',
            __( 'WSA Instellingen', 'wsa-agenda' ),
            __( 'Instellingen', 'wsa-agenda' ),
            'manage_options',            // SSO credentials — admins only
            'wsa-settings',
            [ __CLASS__, 'page_settings' ]
        );

        // Export/Import under Tools — open to board members
        add_management_page(
            __( 'WSA Agenda Export / Import', 'wsa-agenda' ),
            __( 'WSA Agenda Migratie', 'wsa-agenda' ),
            'manage_wsa_events',         // board members have this
            'wsa-migration',
            [ __CLASS__, 'page_migration' ]
        );
    }

    public static function enqueue_admin_assets( string $hook ): void {
        $pages = [
            'toplevel_page_wsa-agenda',
            'wsa-agenda_page_wsa-categories',
            'wsa-agenda_page_wsa-vacations',
            'wsa-agenda_page_wsa-ics-feeds',
            'wsa-agenda_page_wsa-settings',
            'tools_page_wsa-migration',
        ];
        if ( ! in_array( $hook, $pages, true ) ) {
            return;
        }
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_media();
    }

    /* ── Form handlers ──────────────────────────────────────────────────── */

    public static function handle_forms(): void {
        if ( ! isset( $_POST['wsa_action'] ) ) {
            return;
        }
        if ( ! check_admin_referer( 'wsa_admin_action' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! WSA_CPT::current_user_can_manage() ) {
            wp_die( 'Geen toegang.' );
        }

        $action = sanitize_key( $_POST['wsa_action'] );

        switch ( $action ) {
            case 'save_category':
                self::handle_save_category();
                break;
            case 'delete_category':
                self::handle_delete_category();
                break;
            case 'save_vacation':
                self::handle_save_vacation();
                break;
            case 'delete_vacation':
                self::handle_delete_vacation();
                break;
            case 'save_ics_feed':
                self::handle_save_ics_feed();
                break;
            case 'delete_ics_feed':
                self::handle_delete_ics_feed();
                break;
            case 'save_settings':
                self::handle_save_settings();
                break;
            case 'save_smtp':
                self::handle_save_smtp();
                break;
            case 'send_test_mail':
                self::handle_send_test_mail();
                break;
            case 'delete_subscription':
                self::handle_delete_subscription();
                break;
        }
    }

    private static function redirect_back( string $page, array $extra = [] ): void {
        wp_safe_redirect( add_query_arg( array_merge( [ 'page' => $page ], $extra ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function handle_save_category(): void {
        $term_id = (int) ( $_POST['term_id'] ?? 0 );
        $name    = sanitize_text_field( $_POST['cat_name'] ?? '' );
        $color   = WSA_Taxonomy::sanitize_color( $_POST['cat_color'] ?? '' );
        $desc    = sanitize_textarea_field( $_POST['cat_desc'] ?? '' );

        if ( ! $name ) {
            self::redirect_back( 'wsa-categories', [ 'error' => 'empty_name' ] );
        }

        if ( $term_id ) {
            wp_update_term( $term_id, 'wsa_event_category', [ 'name' => $name, 'description' => $desc ] );
            update_term_meta( $term_id, 'wsa_category_color', $color );
        } else {
            $result = wp_insert_term( $name, 'wsa_event_category', [ 'description' => $desc ] );
            if ( ! is_wp_error( $result ) ) {
                update_term_meta( $result['term_id'], 'wsa_category_color', $color );
            }
        }
        self::redirect_back( 'wsa-categories', [ 'saved' => 1 ] );
    }

    private static function handle_delete_category(): void {
        $term_id    = (int) ( $_POST['term_id'] ?? 0 );
        $reassign   = (bool) ( $_POST['reassign_overig'] ?? false );

        if ( ! $term_id ) {
            self::redirect_back( 'wsa-categories' );
        }

        $count = (int) ( get_term( $term_id, 'wsa_event_category' )->count ?? 0 );
        if ( $count > 0 && ! $reassign ) {
            // Show warning — redirect back with notice
            self::redirect_back( 'wsa-categories', [ 'warn_delete' => $term_id ] );
        }

        if ( $count > 0 && $reassign ) {
            $overig = WSA_Taxonomy::get_overig_term();
            if ( $overig ) {
                $posts = get_posts( [
                    'post_type'   => 'wsa_event',
                    'numberposts' => -1,
                    'tax_query'   => [ [
                        'taxonomy' => 'wsa_event_category',
                        'field'    => 'term_id',
                        'terms'    => $term_id,
                    ] ],
                ] );
                foreach ( $posts as $p ) {
                    wp_set_object_terms( $p->ID, $overig->slug, 'wsa_event_category' );
                }
            }
        }

        wp_delete_term( $term_id, 'wsa_event_category' );
        self::redirect_back( 'wsa-categories', [ 'deleted' => 1 ] );
    }

    private static function handle_save_vacation(): void {
        WSA_DB::upsert_vacation( [
            'id'         => (int) ( $_POST['vac_id'] ?? 0 ),
            'name'       => sanitize_text_field( $_POST['vac_name'] ?? '' ),
            'start_date' => sanitize_text_field( $_POST['vac_start'] ?? '' ),
            'end_date'   => sanitize_text_field( $_POST['vac_end'] ?? '' ),
            'regions'    => implode( ',', array_map( 'sanitize_text_field', (array) ( $_POST['vac_regions'] ?? [ 'Noord', 'Midden', 'Zuid' ] ) ) ),
        ] );
        self::redirect_back( 'wsa-vacations', [ 'saved' => 1 ] );
    }

    private static function handle_delete_vacation(): void {
        WSA_DB::delete_vacation( (int) ( $_POST['vac_id'] ?? 0 ) );
        self::redirect_back( 'wsa-vacations', [ 'deleted' => 1 ] );
    }

    private static function handle_save_ics_feed(): void {
        WSA_DB::upsert_ics_feed( [
            'id'   => (int) ( $_POST['feed_id'] ?? 0 ),
            'name' => sanitize_text_field( $_POST['feed_name'] ?? '' ),
            'url'  => esc_url_raw( $_POST['feed_url'] ?? '' ),
        ] );
        self::redirect_back( 'wsa-ics-feeds', [ 'saved' => 1 ] );
    }

    private static function handle_delete_ics_feed(): void {
        WSA_DB::delete_ics_feed( (int) ( $_POST['feed_id'] ?? 0 ) );
        self::redirect_back( 'wsa-ics-feeds', [ 'deleted' => 1 ] );
    }

    private static function handle_save_settings(): void {
        $opts = [
            'wsa_sso_google_client_id',
            'wsa_sso_google_client_secret',
            'wsa_sso_apple_client_id',
            'wsa_sso_apple_team_id',
            'wsa_sso_apple_key_id',
            'wsa_sso_apple_private_key',
        ];
        foreach ( $opts as $opt ) {
            if ( isset( $_POST[ $opt ] ) ) {
                update_option( $opt, sanitize_textarea_field( $_POST[ $opt ] ) );
            }
        }
        self::redirect_back( 'wsa-settings', [ 'saved' => 1 ] );
    }

    private static function handle_save_smtp(): void {
        $plain_opts = [
            'wsa_smtp_host',
            'wsa_smtp_port',
            'wsa_smtp_encryption',
            'wsa_smtp_username',
            'wsa_smtp_from_email',
            'wsa_smtp_from_name',
        ];
        foreach ( $plain_opts as $opt ) {
            if ( isset( $_POST[ $opt ] ) ) {
                update_option( $opt, sanitize_text_field( $_POST[ $opt ] ) );
            }
        }
        // Password: only update when a new value is provided (non-empty field).
        if ( ! empty( $_POST['wsa_smtp_password'] ) ) {
            update_option(
                'wsa_smtp_password',
                WSA_SMTP::encrypt_password( sanitize_text_field( $_POST['wsa_smtp_password'] ) )
            );
        }
        self::redirect_back( 'wsa-settings', [ 'saved' => 1 ] );
    }

    private static function handle_send_test_mail(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Geen toegang.' );
        }
        $current_user = wp_get_current_user();
        $to           = $current_user->user_email;
        $result       = WSA_SMTP::send_test_mail( $to );

        if ( true === $result ) {
            self::redirect_back( 'wsa-settings', [
                'smtp_test'    => '1',
                'smtp_test_to' => rawurlencode( $to ),
            ] );
        } else {
            $msg = $result instanceof WP_Error ? $result->get_error_message() : __( 'Onbekende fout', 'wsa-agenda' );
            self::redirect_back( 'wsa-settings', [
                'smtp_test'       => '0',
                'smtp_test_error' => rawurlencode( $msg ),
            ] );
        }
    }

    private static function handle_delete_subscription(): void {
        $sub_id = (int) ( $_POST['sub_id'] ?? 0 );
        if ( $sub_id ) {
            WSA_Subscriptions::delete_by_id( $sub_id );
        }
        // Redirect back to the post edit screen for this event.
        $event_id = (int) ( $_POST['event_id'] ?? 0 );
        if ( $event_id ) {
            wp_safe_redirect( get_edit_post_link( $event_id, 'redirect' ) );
        } else {
            wp_safe_redirect( admin_url( 'edit.php?post_type=wsa_event' ) );
        }
        exit;
    }

    /* ── Page renderers ─────────────────────────────────────────────────── */

    public static function page_events(): void {
        require WSA_AGENDA_PLUGIN_DIR . 'admin/views/events.php';
    }
    public static function page_categories(): void {
        require WSA_AGENDA_PLUGIN_DIR . 'admin/views/categories.php';
    }
    public static function page_vacations(): void {
        require WSA_AGENDA_PLUGIN_DIR . 'admin/views/vacations.php';
    }
    public static function page_ics_feeds(): void {
        require WSA_AGENDA_PLUGIN_DIR . 'admin/views/ics-feeds.php';
    }
    public static function page_settings(): void {
        require WSA_AGENDA_PLUGIN_DIR . 'admin/views/settings.php';
    }
    public static function page_migration(): void {
        require WSA_AGENDA_PLUGIN_DIR . 'admin/views/export-import.php';
    }

    /* ── Meta box: subscribers ───────────────────────────────────────── */

    public static function register_meta_boxes(): void {
        if ( ! WSA_CPT::current_user_can_manage() ) {
            return;
        }
        add_meta_box(
            'wsa-subscribers',
            __( 'Aanmeldingen herinneringen', 'wsa-agenda' ),
            [ __CLASS__, 'render_subscribers_meta_box' ],
            'wsa_event',
            'normal',
            'default'
        );
    }

    public static function render_subscribers_meta_box( WP_Post $post ): void {
        $subs  = WSA_Subscriptions::get_subscriptions_for_event( $post->ID );
        $count = count( $subs );
        $csv_url = add_query_arg(
            [
                '_wpnonce' => wp_create_nonce( 'wp_rest' ),
            ],
            get_site_url() . '/wp-json/wsa/v1/events/' . $post->ID . '/subscribers/csv'
        );
        ?>
        <p style="margin:0 0 8px">
            <?php printf( esc_html__( '%d aanmelding(en)', 'wsa-agenda' ), $count ); ?>
            <?php if ( $count > 0 ) : ?>
                &nbsp;·&nbsp;
                <a href="<?php echo esc_url( $csv_url ); ?>" target="_blank"><?php esc_html_e( 'Download CSV', 'wsa-agenda' ); ?></a>
            <?php endif; ?>
        </p>
        <?php if ( $subs ) : ?>
        <table class="widefat striped" style="font-size:12px">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Naam', 'wsa-agenda' ); ?></th>
                    <th><?php esc_html_e( 'E-mailadres', 'wsa-agenda' ); ?></th>
                    <th><?php esc_html_e( 'Aangemeld op', 'wsa-agenda' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $subs as $sub ) : ?>
                <tr>
                    <td><?php echo esc_html( $sub['name'] ); ?></td>
                    <td><?php echo esc_html( $sub['email'] ); ?></td>
                    <td><?php echo esc_html( $sub['created_at'] ); ?></td>
                    <td>
                        <form method="post" style="display:inline" onsubmit="return confirm('<?php esc_attr_e( 'Aanmelding verwijderen?', 'wsa-agenda' ); ?>')">
                            <?php wp_nonce_field( 'wsa_admin_action' ); ?>
                            <input type="hidden" name="wsa_action" value="delete_subscription">
                            <input type="hidden" name="sub_id" value="<?php echo (int) $sub['id']; ?>">
                            <input type="hidden" name="event_id" value="<?php echo $post->ID; ?>">
                            <button type="submit" class="button button-small"><?php esc_html_e( 'Verwijderen', 'wsa-agenda' ); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p style="color:#888;margin:0"><?php esc_html_e( 'Nog geen aanmeldingen.', 'wsa-agenda' ); ?></p>
        <?php endif;
    }
}
