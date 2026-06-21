<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
    <h1><?php esc_html_e( 'WSA Vakanties', 'wsa-agenda' ); ?></h1>
    <p><?php esc_html_e( 'Beheer schoolvakanties die als achtergrondblok op de agenda worden getoond.', 'wsa-agenda' ); ?></p>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Vakantie opgeslagen.', 'wsa-agenda' ); ?></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['deleted'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Vakantie verwijderd.', 'wsa-agenda' ); ?></p></div>
    <?php endif; ?>

    <div style="display:flex;gap:40px;align-items:flex-start;margin-top:20px;">

        <!-- List -->
        <div style="flex:1;">
            <h2><?php esc_html_e( 'Bestaande vakanties', 'wsa-agenda' ); ?></h2>
            <?php $rows = WSA_DB::get_vacations(); ?>
            <?php if ( empty( $rows ) ) : ?>
                <p><?php esc_html_e( 'Nog geen vakanties ingevoerd.', 'wsa-agenda' ); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Naam', 'wsa-agenda' ); ?></th>
                        <th><?php esc_html_e( 'Start', 'wsa-agenda' ); ?></th>
                        <th><?php esc_html_e( 'Einde', 'wsa-agenda' ); ?></th>
                        <th><?php esc_html_e( 'Regio\'s', 'wsa-agenda' ); ?></th>
                        <th width="120"><?php esc_html_e( 'Acties', 'wsa-agenda' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row['name'] ); ?></td>
                    <td><?php echo esc_html( $row['start_date'] ); ?></td>
                    <td><?php echo esc_html( $row['end_date'] ); ?></td>
                    <td><?php echo esc_html( $row['regions'] ); ?></td>
                    <td>
                        <a href="#" onclick="wsaEditVac(<?php echo esc_attr( $row['id'] ); ?>,'<?php echo esc_js( $row['name'] ); ?>','<?php echo esc_js( $row['start_date'] ); ?>','<?php echo esc_js( $row['end_date'] ); ?>','<?php echo esc_js( $row['regions'] ); ?>');return false;"
                           class="button button-small"><?php esc_html_e( 'Bewerken', 'wsa-agenda' ); ?></a>
                        <form method="post" style="display:inline-block;">
                            <?php wp_nonce_field( 'wsa_admin_action' ); ?>
                            <input type="hidden" name="wsa_action" value="delete_vacation">
                            <input type="hidden" name="vac_id" value="<?php echo esc_attr( $row['id'] ); ?>">
                            <button type="submit" class="button button-small button-link-delete"
                                onclick="return confirm('<?php esc_attr_e( 'Verwijderen?', 'wsa-agenda' ); ?>')">
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
        <div style="width:320px;">
            <h2 id="vac-form-title"><?php esc_html_e( 'Vakantie toevoegen', 'wsa-agenda' ); ?></h2>
            <form method="post" id="vac-form">
                <?php wp_nonce_field( 'wsa_admin_action' ); ?>
                <input type="hidden" name="wsa_action" value="save_vacation">
                <input type="hidden" name="vac_id" id="vac_id" value="0">

                <table class="form-table">
                    <tr>
                        <th><label for="vac_name"><?php esc_html_e( 'Naam', 'wsa-agenda' ); ?></label></th>
                        <td><input type="text" name="vac_name" id="vac_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="vac_start"><?php esc_html_e( 'Startdatum', 'wsa-agenda' ); ?></label></th>
                        <td><input type="date" name="vac_start" id="vac_start" required></td>
                    </tr>
                    <tr>
                        <th><label for="vac_end"><?php esc_html_e( 'Einddatum', 'wsa-agenda' ); ?></label></th>
                        <td><input type="date" name="vac_end" id="vac_end" required></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Regio\'s', 'wsa-agenda' ); ?></th>
                        <td>
                            <?php foreach ( [ 'Noord', 'Midden', 'Zuid' ] as $r ) : ?>
                            <label style="margin-right:12px;">
                                <input type="checkbox" name="vac_regions[]" value="<?php echo esc_attr( $r ); ?>"
                                    class="vac-region-cb" checked>
                                <?php echo esc_html( $r ); ?>
                            </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Opslaan', 'wsa-agenda' ); ?></button>
                    <button type="button" class="button" onclick="wsaResetVacForm()"><?php esc_html_e( 'Annuleren', 'wsa-agenda' ); ?></button>
                </p>
            </form>
        </div>
    </div>
</div>
<script>
function wsaEditVac(id, name, start, end, regions) {
    document.getElementById('vac-form-title').textContent = '<?php esc_html_e( 'Vakantie bewerken', 'wsa-agenda' ); ?>';
    document.getElementById('vac_id').value    = id;
    document.getElementById('vac_name').value  = name;
    document.getElementById('vac_start').value = start;
    document.getElementById('vac_end').value   = end;
    const parts = regions.split(',');
    document.querySelectorAll('.vac-region-cb').forEach(cb => {
        cb.checked = parts.includes(cb.value);
    });
    document.getElementById('vac-form').scrollIntoView({behavior:'smooth'});
}
function wsaResetVacForm() {
    document.getElementById('vac-form-title').textContent = '<?php esc_html_e( 'Vakantie toevoegen', 'wsa-agenda' ); ?>';
    document.getElementById('vac_id').value    = '0';
    document.getElementById('vac_name').value  = '';
    document.getElementById('vac_start').value = '';
    document.getElementById('vac_end').value   = '';
    document.querySelectorAll('.vac-region-cb').forEach(cb => cb.checked = true);
}
</script>
