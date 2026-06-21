<?php defined( 'ABSPATH' ) || exit;
if ( ! WSA_CPT::current_user_can_manage() ) { wp_die( 'Geen toegang.' ); }

$checklist = WSA_Migration::get_checklist();
$all_ok    = array_reduce( $checklist, fn( $c, $i ) => $c && ( $i['ok'] || ! empty( $i['warning'] ) ), true );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'WSA Agenda Export / Import', 'wsa-agenda' ); ?></h1>

    <div style="display:flex;gap:40px;align-items:flex-start;margin-top:20px;flex-wrap:wrap;">

        <!-- Export -->
        <div style="flex:1;min-width:300px;">
            <h2><?php esc_html_e( 'Export (sandbox → productie)', 'wsa-agenda' ); ?></h2>
            <p><?php esc_html_e( 'Genereert een JSON-bestand met alle evenementen, categorieën, vakanties en ICS-feeds. RSVP-registraties en OAuth-inloggegevens worden NIET geëxporteerd.', 'wsa-agenda' ); ?></p>

            <h3><?php esc_html_e( 'Pre-migratie checklist', 'wsa-agenda' ); ?></h3>
            <ul style="list-style:none;padding:0;">
            <?php foreach ( $checklist as $item ) :
                $icon  = $item['ok'] ? '✅' : ( ! empty( $item['warning'] ) ? '⚠️' : '❌' );
                $style = $item['ok'] ? '' : ( ! empty( $item['warning'] ) ? 'color:#856404;' : 'color:#842029;' );
            ?>
                <li style="margin-bottom:6px;<?php echo esc_attr( $style ); ?>">
                    <?php echo $icon; // safe hardcoded emoji ?>
                    <?php echo esc_html( $item['label'] ); ?>
                    <?php if ( $item['detail'] ) : ?>
                        — <em><?php echo esc_html( $item['detail'] ); ?></em>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>

            <form id="wsa-export-form">
                <?php wp_nonce_field( 'wsa_migration', 'wsa_migration_nonce' ); ?>
                <button type="submit" class="button button-primary" <?php echo $all_ok ? '' : ''; ?>>
                    <?php esc_html_e( 'Exporteren als JSON', 'wsa-agenda' ); ?>
                </button>
            </form>
        </div>

        <!-- Import -->
        <div style="flex:1;min-width:300px;">
            <h2><?php esc_html_e( 'Import (op productie uitvoeren)', 'wsa-agenda' ); ?></h2>
            <p><?php esc_html_e( 'Upload een export-JSON van de sandbox. Bijlagen worden opnieuw gedownload van de sandbox-URL\'s.', 'wsa-agenda' ); ?></p>

            <div id="wsa-import-summary" style="display:none;background:#f0f0f1;padding:12px;border-radius:4px;margin-bottom:12px;"></div>
            <div id="wsa-import-report" style="display:none;background:#d1e7dd;padding:12px;border-radius:4px;margin-bottom:12px;"></div>

            <p>
                <label><?php esc_html_e( 'Modus:', 'wsa-agenda' ); ?>
                    <select id="wsa-import-mode">
                        <option value="merge"><?php esc_html_e( 'Samenvoegen (bestaande evenementen bijwerken)', 'wsa-agenda' ); ?></option>
                        <option value="replace"><?php esc_html_e( 'Vervangen (alle bestaande evenementen verwijderen)', 'wsa-agenda' ); ?></option>
                    </select>
                </label>
            </p>

            <p>
                <label><?php esc_html_e( 'JSON-bestand:', 'wsa-agenda' ); ?>
                    <input type="file" id="wsa-import-file" accept=".json" style="display:block;margin-top:4px;">
                </label>
            </p>

            <p>
                <button class="button" id="wsa-dryrun-btn" disabled><?php esc_html_e( 'Dry-run (samenvatting)', 'wsa-agenda' ); ?></button>
                <button class="button button-primary" id="wsa-import-btn" disabled style="margin-left:8px;"><?php esc_html_e( 'Import uitvoeren', 'wsa-agenda' ); ?></button>
            </p>
        </div>
    </div>
</div>
<script>
(function () {
    const nonce = document.querySelector('#wsa_migration_nonce')?.value ?? '';
    const file  = document.getElementById('wsa-import-file');
    const dry   = document.getElementById('wsa-dryrun-btn');
    const imp   = document.getElementById('wsa-import-btn');
    const sum   = document.getElementById('wsa-import-summary');
    const rep   = document.getElementById('wsa-import-report');

    // Export
    document.getElementById('wsa-export-form').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData();
        fd.append('action', 'wsa_export');
        fd.append('_ajax_nonce', nonce);
        const res  = await fetch(ajaxurl, {method:'POST', body:fd});
        const blob = await res.blob();
        const a    = document.createElement('a');
        a.href     = URL.createObjectURL(blob);
        a.download = 'wsa-agenda-export-<?php echo date("Y-m-d"); ?>.json';
        a.click();
    });

    // Enable buttons when file selected
    file.addEventListener('change', () => {
        dry.disabled = imp.disabled = !file.files.length;
        sum.style.display = rep.style.display = 'none';
    });

    async function ajaxImport(action) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('_ajax_nonce', nonce);
        fd.append('mode', document.getElementById('wsa-import-mode').value);
        fd.append('import_file', file.files[0]);
        const res  = await fetch(ajaxurl, {method:'POST', body:fd});
        return res.json();
    }

    dry.addEventListener('click', async () => {
        dry.disabled = true;
        dry.textContent = '<?php esc_html_e( 'Bezig...', 'wsa-agenda' ); ?>';
        const data = await ajaxImport('wsa_import_dryrun');
        dry.disabled = false;
        dry.textContent = '<?php esc_html_e( 'Dry-run (samenvatting)', 'wsa-agenda' ); ?>';
        if (data.success) {
            const d = data.data;
            let html = '<strong><?php esc_html_e( 'Samenvatting', 'wsa-agenda' ); ?>:</strong><ul>';
            html += `<li><?php esc_html_e( 'Evenementen', 'wsa-agenda' ); ?>: ${d.events}</li>`;
            html += `<li><?php esc_html_e( 'Categorieën', 'wsa-agenda' ); ?>: ${d.categories}</li>`;
            html += `<li><?php esc_html_e( 'Vakanties', 'wsa-agenda' ); ?>: ${d.vacations}</li>`;
            html += `<li>ICS feeds: ${d.ics_feeds}</li></ul>`;
            if (d.warnings.length) {
                html += '<strong><?php esc_html_e( 'Waarschuwingen', 'wsa-agenda' ); ?>:</strong><ul>';
                d.warnings.forEach(w => html += `<li>⚠️ ${w}</li>`);
                html += '</ul>';
            }
            sum.innerHTML = html;
            sum.style.display = 'block';
        } else {
            sum.innerHTML = '❌ ' + (data.data || '<?php esc_html_e( 'Fout', 'wsa-agenda' ); ?>');
            sum.style.display = 'block';
        }
    });

    imp.addEventListener('click', async () => {
        if (!confirm('<?php esc_html_e( 'Import uitvoeren? Dit kan bestaande data wijzigen.', 'wsa-agenda' ); ?>')) return;
        imp.disabled = true;
        imp.textContent = '<?php esc_html_e( 'Bezig...', 'wsa-agenda' ); ?>';
        const data = await ajaxImport('wsa_import_apply');
        imp.disabled = false;
        imp.textContent = '<?php esc_html_e( 'Import uitvoeren', 'wsa-agenda' ); ?>';
        if (data.success) {
            const d = data.data;
            rep.innerHTML = `✅ <strong><?php esc_html_e( 'Import voltooid', 'wsa-agenda' ); ?>:</strong>
                <?php esc_html_e( 'Aangemaakt', 'wsa-agenda' ); ?>: ${d.created},
                <?php esc_html_e( 'Bijgewerkt', 'wsa-agenda' ); ?>: ${d.updated},
                <?php esc_html_e( 'Overgeslagen', 'wsa-agenda' ); ?>: ${d.skipped}` +
                (d.warnings.length ? '<ul>' + d.warnings.map(w => `<li>⚠️ ${w}</li>`).join('') + '</ul>' : '');
            rep.style.display = 'block';
        } else {
            rep.style.background = '#f8d7da';
            rep.innerHTML = '❌ ' + (data.data || '<?php esc_html_e( 'Import mislukt', 'wsa-agenda' ); ?>');
            rep.style.display = 'block';
        }
    });
}());
</script>
