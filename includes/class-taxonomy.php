<?php
defined( 'ABSPATH' ) || exit;

class WSA_Taxonomy {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_taxonomy( 'wsa_event_category', 'wsa_event', [
            'labels'            => [
                'name'          => __( 'Categorieën', 'wsa-agenda' ),
                'singular_name' => __( 'Categorie', 'wsa-agenda' ),
                'add_new_item'  => __( 'Nieuwe categorie', 'wsa-agenda' ),
                'edit_item'     => __( 'Categorie bewerken', 'wsa-agenda' ),
            ],
            'hierarchical'      => false,
            'public'            => false,
            'show_ui'           => false,   // managed by our admin screen
            'show_in_rest'      => false,
            'rewrite'           => false,
        ] );

        register_term_meta( 'wsa_event_category', 'wsa_category_color', [
            'type'              => 'string',
            'single'            => true,
            'sanitize_callback' => [ __CLASS__, 'sanitize_color' ],
        ] );
    }

    /**
     * Validate hex color; fall back to #888780 (Overig).
     */
    public static function sanitize_color( string $color ): string {
        $color = trim( $color );
        if ( preg_match( '/^#[0-9A-Fa-f]{6}$/', $color ) ) {
            return strtoupper( $color );
        }
        return '#888780';
    }

    /**
     * Return the "Overig" term (fallback for category reassignment).
     */
    public static function get_overig_term(): ?WP_Term {
        $term = get_term_by( 'name', 'Overig', 'wsa_event_category' );
        return $term instanceof WP_Term ? $term : null;
    }

    /**
     * Return all categories as plain array for REST / JS.
     */
    public static function get_all(): array {
        $terms = get_terms( [
            'taxonomy'   => 'wsa_event_category',
            'hide_empty' => false,
        ] );
        if ( is_wp_error( $terms ) ) {
            return [];
        }
        $out = [];
        foreach ( $terms as $t ) {
            $out[] = [
                'id'          => (int) $t->term_id,
                'name'        => $t->name,
                'slug'        => $t->slug,
                'color'       => get_term_meta( $t->term_id, 'wsa_category_color', true ) ?: '#888780',
                'description' => $t->description,
                'count'       => (int) $t->count,
            ];
        }
        return $out;
    }
}
