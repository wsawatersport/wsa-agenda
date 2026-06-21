<?php
defined( 'ABSPATH' ) || exit;

$terms = get_terms( [ 'taxonomy' => 'wsa_event_category', 'hide_empty' => false ] );
if ( is_wp_error( $terms ) ) {
    $terms = [];
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'WSA Categorieën', 'wsa-agenda' ); ?></h1>
    <p class="description"><?php esc_html_e( 'Beheer de kleuren en namen van evenementcategorieën. Kies een kleur die voldoende contrast heeft met witte tekst (WCAG AA ≥ 4.5:1).', 'wsa-agenda' ); ?></p>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Categorie opgeslagen.', 'wsa-agenda' ); ?></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['deleted'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Categorie verwijderd.', 'wsa-agenda' ); ?></p></div>
    <?php endif; ?>

    <?php
    /* ── Deletion warning ─────────────────────────────────────────── */
    $warn_id = isset( $_GET['warn_delete'] ) ? (int) $_GET['warn_delete'] : 0;
    if ( $warn_id ) :
        $warn_term = get_term( $warn_id, 'wsa_event_category' );
    ?>
    <div class="notice notice-warning" style="padding:12px 16px;">
        <p><strong><?php esc_html_e( 'Let op:', 'wsa-agenda' ); ?></strong>
        <?php printf(
            esc_html__( 'Categorie "%s" is gekoppeld aan %d evenement(en). Kies wat er mee moet gebeuren:', 'wsa-agenda' ),
            esc_html( $warn_term->name ?? '' ),
            (int) ( $warn_term->count ?? 0 )
        ); ?></p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;">
            <form method="post">
                <?php wp_nonce_field( 'wsa_admin_action' ); ?>
                <input type="hidden" name="wsa_action"      value="delete_category">
                <input type="hidden" name="term_id"         value="<?php echo esc_attr( $warn_id ); ?>">
                <input type="hidden" name="reassign_overig" value="1">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e( 'Herindelen naar "Overig" en verwijderen', 'wsa-agenda' ); ?>
                </button>
            </form>
            <form method="post">
                <?php wp_nonce_field( 'wsa_admin_action' ); ?>
                <input type="hidden" name="wsa_action" value="delete_category">
                <input type="hidden" name="term_id"    value="<?php echo esc_attr( $warn_id ); ?>">
                <button type="submit" class="button button-link-delete"
                    onclick="return confirm('<?php esc_attr_e( 'Definitief verwijderen zonder herindelen?', 'wsa-agenda' ); ?>')">
                    <?php esc_html_e( 'Verwijderen zonder herindelen', 'wsa-agenda' ); ?>
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php /* ── Existing categories ───────────────────────────────────── */ ?>
    <table class="wp-list-table widefat fixed striped" style="max-width:640px;margin-top:20px;">
        <thead>
            <tr>
                <th style="width:60px;"><?php esc_html_e( 'Kleur', 'wsa-agenda' ); ?></th>
                <th><?php esc_html_e( 'Naam', 'wsa-agenda' ); ?></th>
                <th style="width:40px;"><?php esc_html_e( '#', 'wsa-agenda' ); ?></th>
                <th style="width:180px;"><?php esc_html_e( 'Acties', 'wsa-agenda' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $terms ) ) : ?>
            <tr><td colspan="4"><em><?php esc_html_e( 'Nog geen categorieën.', 'wsa-agenda' ); ?></em></td></tr>
        <?php else : ?>
        <?php foreach ( $terms as $term ) :
            $color = get_term_meta( $term->term_id, 'wsa_category_color', true ) ?: '#888780';
        ?>
            <tr>
                <td>
                    <!-- Save (name + colour) form -->
                    <form method="post" id="cat-save-<?php echo esc_attr( $term->term_id ); ?>"
                          style="display:contents;">
                        <?php wp_nonce_field( 'wsa_admin_action' ); ?>
                        <input type="hidden" name="wsa_action" value="save_category">
                        <input type="hidden" name="term_id"   value="<?php echo esc_attr( $term->term_id ); ?>">
                        <input type="color"  name="cat_color" value="<?php echo esc_attr( $color ); ?>"
                               style="width:40px;height:32px;padding:2px;border:1px solid #ccc;border-radius:4px;cursor:pointer;"
                               title="<?php esc_attr_e( 'Kies kleur', 'wsa-agenda' ); ?>">
                </td>
                <td>
                        <input type="text" name="cat_name" value="<?php echo esc_attr( $term->name ); ?>"
                               class="regular-text" required style="width:100%;max-width:260px;">
                        <!-- hidden description passthrough so it isn't cleared on save -->
                        <input type="hidden" name="cat_desc" value="<?php echo esc_attr( $term->description ); ?>">
                    </form>
                </td>
                <td><?php echo (int) $term->count; ?></td>
                <td style="white-space:nowrap;">
                    <button type="submit" form="cat-save-<?php echo esc_attr( $term->term_id ); ?>"
                            class="button button-small">
                        <?php esc_html_e( 'Opslaan', 'wsa-agenda' ); ?>
                    </button>
                    &nbsp;
                    <form method="post" style="display:inline-block;">
                        <?php wp_nonce_field( 'wsa_admin_action' ); ?>
                        <input type="hidden" name="wsa_action" value="delete_category">
                        <input type="hidden" name="term_id"   value="<?php echo esc_attr( $term->term_id ); ?>">
                        <button type="submit" class="button button-small button-link-delete"
                            onclick="return confirm('<?php esc_attr_e( 'Zeker weten?', 'wsa-agenda' ); ?>')">
                            <?php esc_html_e( 'Verwijderen', 'wsa-agenda' ); ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php /* ── Add new category (at bottom, per spec) ─────────────────── */ ?>
    <h2 style="margin-top:32px;"><?php esc_html_e( 'Nieuwe categorie toevoegen', 'wsa-agenda' ); ?></h2>
    <form method="post" style="max-width:480px;">
        <?php wp_nonce_field( 'wsa_admin_action' ); ?>
        <input type="hidden" name="wsa_action" value="save_category">
        <input type="hidden" name="term_id"    value="0">

        <table class="form-table">
            <tr>
                <th style="width:140px;">
                    <label for="new_cat_name"><?php esc_html_e( 'Naam', 'wsa-agenda' ); ?></label>
                </th>
                <td>
                    <input type="text" name="cat_name" id="new_cat_name"
                           class="regular-text" required
                           placeholder="<?php esc_attr_e( 'bv. Regatta', 'wsa-agenda' ); ?>">
                </td>
            </tr>
            <tr>
                <th><label for="new_cat_color"><?php esc_html_e( 'Kleur', 'wsa-agenda' ); ?></label></th>
                <td>
                    <input type="color" name="cat_color" id="new_cat_color" value="#1D9E75"
                           style="width:48px;height:34px;padding:2px;border:1px solid #ccc;border-radius:4px;cursor:pointer;">
                    <p class="description" style="margin-top:4px;">
                        <?php esc_html_e( 'Zorg voor voldoende contrast met witte tekst (WCAG AA).', 'wsa-agenda' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary">
                <?php esc_html_e( 'Categorie toevoegen', 'wsa-agenda' ); ?>
            </button>
        </p>
    </form>
</div>
