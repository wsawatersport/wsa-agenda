<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
    <h1><?php esc_html_e( 'WSA Evenementen', 'wsa-agenda' ); ?></h1>
    <p><?php esc_html_e( 'Beheer evenementen via de agenda op de voorkant van de website.', 'wsa-agenda' ); ?></p>

    <?php
    $posts = get_posts( [
        'post_type'      => 'wsa_event',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'orderby'        => 'meta_value',
        'meta_key'       => 'wsa_event_start',
        'order'          => 'DESC',
    ] );
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Titel', 'wsa-agenda' ); ?></th>
                <th><?php esc_html_e( 'Categorie', 'wsa-agenda' ); ?></th>
                <th><?php esc_html_e( 'Start', 'wsa-agenda' ); ?></th>
                <th><?php esc_html_e( 'Einde', 'wsa-agenda' ); ?></th>
                <th><?php esc_html_e( 'Aanmeldingen', 'wsa-agenda' ); ?></th>
                <th><?php esc_html_e( 'UUID', 'wsa-agenda' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $posts as $post ) :
            $terms   = get_the_terms( $post->ID, 'wsa_event_category' );
            $cat     = ( is_array( $terms ) && $terms ) ? $terms[0]->name : '—';
            $start   = get_post_meta( $post->ID, 'wsa_event_start', true );
            $end     = get_post_meta( $post->ID, 'wsa_event_end',   true );
            $count   = WSA_DB::count_rsvp( $post->ID );
            $limit   = (int) get_post_meta( $post->ID, 'wsa_rsvp_limit', true );
            $uuid    = get_post_meta( $post->ID, 'wsa_event_uuid', true );
            $csv_url = add_query_arg(
                [ 'rest_route' => "/wsa/v1/events/{$post->ID}/rsvp/csv", '_wpnonce' => wp_create_nonce( 'wp_rest' ) ],
                get_site_url() . '/wp-json'
            );
        ?>
            <tr>
                <td><strong><?php echo esc_html( $post->post_title ); ?></strong></td>
                <td><?php echo esc_html( $cat ); ?></td>
                <td><?php echo esc_html( $start ); ?></td>
                <td><?php echo esc_html( $end ); ?></td>
                <td>
                    <?php echo esc_html( $count ); ?>
                    <?php if ( $limit ) echo esc_html( " / {$limit}" ); ?>
                    <?php if ( $count > 0 ) : ?>
                        &nbsp;<a href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'CSV', 'wsa-agenda' ); ?></a>
                    <?php endif; ?>
                </td>
                <td><code><?php echo esc_html( $uuid ); ?></code></td>
            </tr>
        <?php endforeach; ?>
        <?php if ( empty( $posts ) ) : ?>
            <tr><td colspan="6"><?php esc_html_e( 'Geen evenementen gevonden.', 'wsa-agenda' ); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <p>
        <?php
        $agenda = get_page_by_path( 'agenda' );
        if ( $agenda ) :
        ?>
        <a class="button button-primary" href="<?php echo esc_url( get_permalink( $agenda ) ); ?>" target="_blank">
            <?php esc_html_e( 'Agenda bekijken', 'wsa-agenda' ); ?>
        </a>
        <?php endif; ?>
    </p>
</div>
