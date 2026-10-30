<?php
/**
 * Enhance Manager - Settings tab: client area appearance, visible sections,
 * listing, cache and backup restore. Values are stored by EnhanceSettings
 * (table mod_enhance_settings); config.php constants lock a setting.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

function enhance_importer_settings_page(string $moduleLink): void
{
    $action = $moduleLink . '&tab=settings';
    $feedback = '';
    if (enhance_importer_valid_post('save_settings')) {
        $invalid = EnhanceSettings::save($_POST);
        $feedback = $invalid
            ? enhance_importer_alert('warning', enhance_importer_t('set.saved_invalid', ['fields' => implode(', ', array_map(fn($k) => EnhanceLang::t('set.' . $k), $invalid))]))
            : enhance_importer_alert('success', enhance_importer_t('set.saved'));
        EnhanceLang::reset(); // the interface language may have changed
    } elseif (enhance_importer_valid_post('reset_settings')) {
        EnhanceSettings::reset();
        EnhanceLang::reset();
        $feedback = enhance_importer_alert('success', enhance_importer_t('set.reset_done'));
    }
    echo $feedback;
    echo enhance_importer_settings_form(EnhanceSettings::all(), $action);
}

/** The settings form (separate from the page so it can be tested). */
function enhance_importer_settings_form(array $settings, string $action): string
{
    $h = 'enhance_importer_h';
    $t = fn(string $k, array $v = []) => enhance_importer_t($k, $v);

    $lock = function (string $key) use ($settings, $h, $t): string {
        return $settings[$key]['locked']
            ? '<span class="label label-default" title="config.php">' . $t('set.locked', ['constant' => $settings[$key]['constant']]) . '</span>'
            : '';
    };
    $disabled = fn(string $key) => $settings[$key]['locked'] ? ' disabled' : '';

    $checkbox = function (string $key) use ($settings, $h, $t, $lock, $disabled): string {
        $checked = $settings[$key]['value'] ? ' checked' : '';
        return '<div class="checkbox" style="margin:6px 0;"><label><input type="checkbox" name="' . $h($key) . '" value="1"' . $checked . $disabled($key) . '> '
            . '<strong>' . $t('set.' . $key) . '</strong> <span class="text-muted">' . $t('set.' . $key . '_help') . '</span></label> ' . $lock($key) . '</div>';
    };
    $number = function (string $key) use ($settings, $h, $t, $lock, $disabled): string {
        $s = $settings[$key];
        return '<div class="form-group"><label for="enh-' . $h($key) . '">' . $t('set.' . $key) . '</label> ' . $lock($key)
            . '<input type="number" class="form-control" style="max-width:140px;" id="enh-' . $h($key) . '" name="' . $h($key) . '" min="' . (int) $s['min'] . '" max="' . (int) $s['max'] . '" value="' . (int) $s['value'] . '"' . $disabled($key) . '>'
            . '<p class="help-block">' . $t('set.' . $key . '_help', ['min' => $s['min'], 'max' => $s['max']]) . '</p></div>';
    };
    $color = function (string $key) use ($settings, $h, $t, $lock, $disabled): string {
        $value = (string) $settings[$key]['value'];
        return '<div class="form-group"><label for="enh-' . $h($key) . '">' . $t('set.' . $key) . '</label> ' . $lock($key)
            . '<div style="display:flex;gap:8px;align-items:center;">'
            . '<input type="color" class="enh-color-picker" data-target="enh-' . $h($key) . '" value="' . $h($value !== '' ? $value : '#1f6feb') . '" style="width:44px;height:34px;padding:2px;"' . $disabled($key) . '>'
            . '<input type="text" class="form-control enh-color-text" style="max-width:140px;" id="enh-' . $h($key) . '" name="' . $h($key) . '" value="' . $h($value) . '" placeholder="' . $t('set.follow_theme') . '" pattern="#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?"' . $disabled($key) . '>'
            . '<button type="button" class="btn btn-default btn-sm enh-color-clear" data-target="enh-' . $h($key) . '"' . $disabled($key) . '>' . $t('set.follow_theme') . '</button>'
            . '</div><p class="help-block">' . $t('set.' . $key . '_help') . '</p></div>';
    };

    $lang = $settings['language'];
    $langOptions = '';
    foreach (['' => $t('set.language_auto'), 'english' => 'English', 'portuguese-pt' => 'Português (Portugal)'] as $value => $label) {
        $langOptions .= '<option value="' . $h($value) . '"' . ($lang['value'] === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    $token = enhance_importer_token_field();
    $panel = fn(string $title, string $body) => '<div class="panel panel-default"><div class="panel-heading"><strong>' . $title . '</strong></div><div class="panel-body">' . $body . '</div></div>';

    $appearance = '<div class="row"><div class="col-md-7">'
        . $color('color_primary') . $color('color_secondary')
        . '<div class="form-group"><label for="enh-language">' . $t('set.language') . '</label> ' . $lock('language')
        . '<select class="form-control" style="max-width:260px;" id="enh-language" name="language"' . $disabled('language') . '>' . $langOptions . '</select>'
        . '<p class="help-block">' . $t('set.language_help') . '</p></div>'
        . '</div><div class="col-md-5">' . enhance_importer_settings_preview() . '</div></div>';

    $sections = $checkbox('show_usage') . $checkbox('show_ssl') . $checkbox('show_webmail') . $checkbox('show_phpmyadmin')
        . $checkbox('show_wordpress') . $checkbox('show_backups') . $checkbox('show_backup_size') . $checkbox('show_features');
    $listing = '<div class="row"><div class="col-md-4">' . $number('websites_per_page') . '</div><div class="col-md-4">' . $number('backups_shown')
        . '</div><div class="col-md-4">' . $number('cache_ttl') . '</div></div>';
    $restore = $checkbox('restore_enabled');

    $resetConfirm = $h(EnhanceLang::t('set.reset_confirm'));
    return '<form method="post" action="' . $h($action) . '" id="enhance-settings">'
        . '<input type="hidden" name="enhance_importer_action" value="save_settings">' . $token
        . $panel($t('set.section_appearance'), $appearance)
        . $panel($t('set.section_sections'), '<p class="text-muted">' . $t('set.section_sections_help') . '</p>' . $sections)
        . $panel($t('set.section_listing'), $listing)
        . $panel($t('set.section_restore'), $restore . '<p class="help-block">' . $t('set.restore_help') . '</p>')
        . '<p class="text-muted">' . $t('set.config_help') . '</p>'
        . '<button type="submit" class="btn btn-primary">' . $t('set.save') . '</button> '
        . '</form>'
        . '<form method="post" action="' . $h($action) . '" style="margin-top:10px;" onsubmit="return confirm(\'' . $resetConfirm . '\');">'
        . '<input type="hidden" name="enhance_importer_action" value="reset_settings">' . $token
        . '<button type="submit" class="btn btn-default btn-sm">' . $t('set.reset') . '</button></form>'
        . enhance_importer_settings_script();
}

/** Small live preview of the client area colours. */
function enhance_importer_settings_preview(): string
{
    $t = fn(string $k) => enhance_importer_t($k);
    return '<div class="enh-preview" style="border:1px solid #e7ecf2;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(18,38,63,.08);">'
        . '<div class="enh-preview-hero" style="padding:18px 20px;color:#fff;background:linear-gradient(135deg,#1f6feb 0%,#1849a9 100%);">'
        . '<div style="font-size:18px;font-weight:700;">' . enhance_importer_h(EnhanceLang::t('ca.title')) . '</div>'
        . '<div style="opacity:.88;font-size:12px;">' . enhance_importer_h(EnhanceLang::t('ca.subtitle')) . '</div></div>'
        . '<div style="padding:16px 20px;text-align:center;background:#fff;">'
        . '<span class="enh-preview-btn" style="display:inline-block;padding:9px 18px;border-radius:999px;color:#fff;font-weight:700;background:#1f6feb;">' . enhance_importer_h(EnhanceLang::t('ca.open_panel')) . '</span>'
        . '<div class="text-muted" style="font-size:11px;margin-top:10px;">' . $t('set.preview_help') . '</div></div></div>';
}

function enhance_importer_settings_script(): string
{
    return <<<'HTML'
<script>
(function () {
    var form = document.getElementById('enhance-settings');
    if (!form) return;
    var text = function (id) { return document.getElementById(id); };
    function valid(v) { return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v); }
    function darker(hex) {
        if (hex.length === 4) hex = '#' + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3];
        var n = parseInt(hex.slice(1), 16), f = 0.7;
        var r = Math.round(((n >> 16) & 255) * f), g = Math.round(((n >> 8) & 255) * f), b = Math.round((n & 255) * f);
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }
    function preview() {
        var p = text('enh-color_primary').value.trim(), s = text('enh-color_secondary').value.trim();
        var primary = valid(p) ? p : '#1f6feb';
        var secondary = valid(s) ? s : darker(primary);
        form.querySelector('.enh-preview-hero').style.background = 'linear-gradient(135deg,' + primary + ' 0%,' + secondary + ' 100%)';
        form.querySelector('.enh-preview-btn').style.background = primary;
    }
    form.querySelectorAll('.enh-color-picker').forEach(function (picker) {
        picker.addEventListener('input', function () { text(picker.dataset.target).value = picker.value; preview(); });
    });
    form.querySelectorAll('.enh-color-text').forEach(function (input) {
        input.addEventListener('input', function () {
            var picker = form.querySelector('.enh-color-picker[data-target="' + input.id + '"]');
            if (valid(input.value.trim()) && picker) picker.value = input.value.trim().length === 7 ? input.value.trim() : picker.value;
            preview();
        });
    });
    form.querySelectorAll('.enh-color-clear').forEach(function (btn) {
        btn.addEventListener('click', function () { text(btn.dataset.target).value = ''; preview(); });
    });
    preview();
})();
</script>
HTML;
}
