<?php
defined( 'ABSPATH' ) || exit;

class WSA_CPT {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
        add_action( 'save_post_wsa_event', [ __CLASS__, 'save_meta' ], 10, 2 );
        add_action( 'wp_insert_post', [ __CLASS__, 'ensure_uuid' ], 10, 2 );
    }

    public static function register(): void {
        register_post_type( 'wsa_event', [
            'labels'              => [
                'name'               => __( 'Evenementen', 'wsa-agenda' ),
                'singular_name'      => __( 'Evenement', 'wsa-agenda' ),
                'add_new_item'       => __( 'Nieuw evenement', 'wsa-agenda' ),
                'edit_item'          => __( 'Evenement bewerken', 'wsa-agenda' ),
                'view_item'          => __( 'Evenement bekijken', 'wsa-agenda' ),
                'search_items'       => __( 'Zoek evenementen', 'wsa-agenda' ),
                'not_found'          => __( 'Geen evenementen gevonden', 'wsa-agenda' ),
            ],
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => false,   // managed via our admin screen
            'show_in_rest'        => false,   // REST handled by our custom endpoints
            'capability_type'     => 'post',
            'capabilities'        => [
                'edit_posts'         => 'manage_wsa_events',
                'edit_others_posts'  => 'manage_wsa_events',
                'publish_posts'      => 'manage_wsa_events',
                'read_private_posts' => 'manage_wsa_events',
                'delete_posts'       => 'manage_wsa_events',
            ],
            'map_meta_cap'        => false,
            'supports'            => [ 'title', 'editor', 'custom-fields' ],
            'has_archive'         => false,
            'rewrite'             => false,
        ] );

        // Ensure all WSA caps are present on both roles (also handles upgrade path
        // for sites where the plugin was already active before new caps were added).
        if ( class_exists( 'WSA_Activator' ) ) {
            foreach ( [ 'wsa_bestuur', 'administrator' ] as $role_name ) {
                $role = get_role( $role_name );
                if ( ! $role ) {
                    continue;
                }
                foreach ( WSA_Activator::wsa_caps() as $cap => $grant ) {
                    if ( $grant && ! $role->has_cap( $cap ) ) {
                        $role->add_cap( $cap );
                    }
                }
            }
        }
    }

    /**
     * Save custom meta from REST/admin.
     */
    public static function save_meta( int $post_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        // Meta is set directly via update_post_meta in REST handler.
    }

    /**
     * Assign a stable UUID on creation.
     */
    public static function ensure_uuid( int $post_id, WP_Post $post ): void {
        if ( 'wsa_event' !== $post->post_type ) {
            return;
        }
        if ( get_post_meta( $post_id, 'wsa_event_uuid', true ) ) {
            return;
        }
        update_post_meta( $post_id, 'wsa_event_uuid', wp_generate_uuid4() );
    }

    /**
     * Check if current user may manage events.
     */
    public static function current_user_can_manage(): bool {
        $user = wp_get_current_user();
        return in_array( 'wsa_bestuur', (array) $user->roles, true )
               || current_user_can( 'manage_options' );
    }
}
