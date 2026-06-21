<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
    <h1><?php esc_html_e( 'WSA ICS Feeds', 'wsa-agenda' ); ?></h1>
    <p><?php esc_html_e( 'Voeg externe ICS/iCal-feeds toe. Evenementen worden als achtergrondblok getoond (cache: 24 uur).', 'wsa-agenda' ); ?></p>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Feed opgeslagen.', 'wsa-agenda' ); ?></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['deleted'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Feed verwijderd.', 'wsa-agenda' ); ?></p></div>
    <?php endif; ?>

    <div style="display:flex;gap:40px;align-items:flex-start;margin-top:20px;">

        <!-- List -->
        <div style="flex:1;">
            <h2><?php esc_html_e( 'Geconfigureerde feeds', 'wsa-agenda' ); ?></h2>
            <?php $feeds = WSA_DB::get_ics_feeds(); ?>
            <?php if ( empty( $feeds ) ) : ?>
                <p><?php esc_html_e( 'Nog geen feeds toegevoegd.', 'wsa-agenda' ); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Naam', 'wsa-agenda' ); ?></th>
                        <th><?php esc_html_e( 'URL', 'wsa-agenda' ); ?></th>
                        <th width="120"><?php esc_html_e( 'Acties', 'wsa-agenda' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $feeds as $feed ) : ?>
                <tr>
                    <td><?php echo esc_html( $feed['name'] ); ?></td>
                    <td><a href="<?php echo esc_url( $feed['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $feed['url'] ); ?></a></td>
                    <td>
                        <a href="#" onclick="wsaEditFeed(<?php echo esc_attr( $feed['id'] ); ?>,'<?php echo esc_js( $feed['name'] ); ?>','<?php echo esc_js( $feed['url'] ); ?>');return false;"
                           class="button button-small"><?php esc_html_e( 'Bewerken', 'wsa-agenda' ); ?></a>
                        <form method="post" style="display:inline-block;">
                            <?php wp_nonce_field( 'wsa_admin_action' ); ?>
                            <input type="hidden" name="wsa_action" value="delete_ics_feed">
                            <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed['id'] ); ?>">
                            <button type="submit" class="button button-small button-link-delete"
                                onclick="return confirm('<?php esc_attr_e( 'Feed verwijderen?', 'wsa-agenda' ); ?>')">
                                <?php esc_html_e( 'Verwijderen', 'wsa-agenda' ); ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Form -->
        <div style="width:340px;">
            <h2 id="feed-form-title"><?php esc_html_e( 'Feed toevoegen', 'wsa-agenda' ); ?></h2>
            <form method="post" id="feed-form">
                <?php wp_nonce_field( 'wsa_admin_action' ); ?>
                <input type="hidden" name="wsa_action" value="save_ics_feed">
                <input type="hidden" name="feed_id" id="feed_id" value="0">

                <table class="form-table">
                    <tr>
                        <th><label for="feed_name"><?php esc_html_e( 'Naam', 'wsa-agenda' ); ?></label></th>
                        <td><input type="text" name="feed_name" id="feed_name" class="regular-text" required
                            placeholder="<?php esc_attr_e( 'bv. KNWV evenementen', 'wsa-agenda' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="feed_url"><?php esc_html_e( 'URL', 'wsa-agenda' ); ?></label></th>
                        <td>
                            <input type="url" name="feed_url" id="feed_url" class="regular-text" required
                                placeholder="https://example.com/calendar.ics">
                            <p class="description"><?php esc_html_e( 'Moet een geldig .ics-bestand retourneren.', 'wsa-agenda' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Opslaan', 'wsa-agenda' ); ?></button>
                    <button type="button" class="button" onclick="wsaResetFeedForm()"><?php esc_html_e( 'Annuleren', 'wsa-agenda' ); ?></button>
                </p>
            </form>
        </div>
    </div>
</div>
<script>
function wsaEditFeed(id, name, url) {
    document.getElementById('feed-form-title').textContent = '<?php esc_html_e( 'Feed bewerken', 'wsa-agenda' ); ?>';
    document.getElementById('feed_id').value   = id;
    document.getElementById('feed_name').value = name;
    document.getElementById('feed_url').value  = url;
    document.getElementById('feed-form').scrollIntoView({behavior:'smooth'});
}
function wsaResetFeedForm() {
    document.getElementById('feed-form-title').textContent = '<?php esc_html_e( 'Feed toevoegen', 'wsa-agenda' ); ?>';
    document.getElementById('feed_id').value   = '0';
    document.getElementById('feed_name').value = '';
    document.getElementById('feed_url').value  = '';
}
</script>
