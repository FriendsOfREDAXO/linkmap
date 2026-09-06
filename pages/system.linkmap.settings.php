<?php

$form = rex_config_form::factory('linkmap');

$form->addFieldset(rex_i18n::msg('linkmap_settings_classic_legend'));

$field = $form->addCheckboxField('replace_classic_linkmap');
$field->addOption(rex_i18n::rawMsg('linkmap_settings_replace_label'), 1);
$field->setNotice(rex_i18n::msg('linkmap_settings_replace_hint'));

$form->addFieldset(rex_i18n::msg('linkmap_settings_features_legend'));

$field = $form->addInputField('number', 'history_limit', null, ['class' => 'form-control', 'min' => 1, 'max' => 100]);
$field->setLabel(rex_i18n::msg('linkmap_settings_history_limit_label'));
$field->setNotice(rex_i18n::msg('linkmap_settings_history_limit_hint'));

$field = $form->addInputField('number', 'search_limit', null, ['class' => 'form-control', 'min' => 1, 'max' => 200]);
$field->setLabel(rex_i18n::msg('linkmap_settings_search_limit_label'));
$field->setNotice(rex_i18n::msg('linkmap_settings_search_limit_hint'));

$form->addFieldset(rex_i18n::msg('linkmap_settings_links_legend'));

$field = $form->addCheckboxField('resolve_yform_links');
$field->addOption(rex_i18n::rawMsg('linkmap_settings_resolve_label'), 1);
$field->setNotice(rex_i18n::msg('linkmap_settings_resolve_hint'));

$field = $form->addCheckboxField('resolve_legacy_links');
$field->addOption(rex_i18n::rawMsg('linkmap_settings_resolve_legacy_label'), 1);
$field->setNotice(rex_i18n::msg('linkmap_settings_resolve_legacy_hint'));

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('linkmap_settings'), false);
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');

// ---- Datensatz-Quellen (YForm-Tabellen) ----
// Eigenes Formular (kein rex_config_form): eine Zeile pro Tabelle mit
// mehreren Feldern, gespeichert als ein Array in rex_config (TableConfig).
if (\FriendsOfRedaxo\Linkmap\Source\YFormSourceProvider::isAvailable()) {
    $csrf = rex_csrf_token::factory('linkmap_tables');
    $message = '';
    if ('1' === rex_post('linkmap_tables_save', 'string', '')) {
        if (!$csrf->isValid()) {
            $message = rex_view::error(rex_i18n::msg('csrf_token_invalid'));
        } else {
            $posted = rex_post('tables', 'array', []);
            $config = [];
            foreach ($posted as $tableName => $values) {
                $tableName = (string) $tableName;
                if (null === rex_yform_manager_table::get($tableName) || !is_array($values)) {
                    continue;
                }
                $config[$tableName] = [
                    'enabled' => !empty($values['enabled']),
                    'label' => trim((string) ($values['label'] ?? '')),
                    'search' => trim((string) ($values['search'] ?? '')),
                    'columns' => trim((string) ($values['columns'] ?? '')),
                    'order' => trim((string) ($values['order'] ?? '')),
                    'filter' => trim((string) ($values['filter'] ?? '')),
                    'clang_field' => trim((string) ($values['clang_field'] ?? '')),
                    'url_template' => trim((string) ($values['url_template'] ?? '')),
                ];
            }
            \FriendsOfRedaxo\Linkmap\TableConfig::save($config);
            $message = rex_view::success(rex_i18n::msg('linkmap_tables_saved'));
        }
    }

    $existing = \FriendsOfRedaxo\Linkmap\TableConfig::all();
    $rows = '';
    foreach (rex_yform_manager_table::getAll() as $table) {
        $name = $table->getTableName();
        $cfg = $existing[$name] ?? [];
        $schemes = \FriendsOfRedaxo\Linkmap\Link\SchemeRegistry::getSchemes($name);
        $schemeInfo = [] === $schemes
            ? '<span class="text-muted">' . rex_i18n::msg('linkmap_tables_no_scheme') . '</span>'
            : implode('<br>', array_map(static fn ($s) => '<small>' . rex_escape($s->label) . '</small>', $schemes));
        $field = static fn (string $key, string $placeholder = '') => '<input class="form-control input-sm" type="text" name="tables[' . rex_escape($name) . '][' . $key . ']" value="' . rex_escape((string) ($cfg[$key] ?? '')) . '" placeholder="' . rex_escape($placeholder) . '">';
        $rows .= '<tr' . ($table->isHidden() ? ' class="text-muted"' : '') . '>'
            . '<td class="text-center"><input type="checkbox" name="tables[' . rex_escape($name) . '][enabled]" value="1"' . (!empty($cfg['enabled']) ? ' checked' : '') . '></td>'
            . '<td><strong>' . rex_escape(rex_i18n::translate($table->getName())) . '</strong><br><small class="text-muted">' . rex_escape($name) . '</small></td>'
            . '<td>' . $field('label', '{name}') . '</td>'
            . '<td>' . $field('search', 'name, title') . '</td>'
            . '<td>' . $field('columns', 'date, category') . '</td>'
            . '<td>' . $field('order', 'id DESC') . '</td>'
            . '<td>' . $field('filter', 'status = 1') . '</td>'
            . '<td>' . $field('clang_field', 'clang_id') . '</td>'
            . '<td>' . $schemeInfo . '<div style="margin-top:4px">' . $field('url_template', '/news/{id}') . '</div></td>'
            . '</tr>';
    }

    $tableHtml = '<form method="post" action="' . rex_url::currentBackendPage() . '">'
        . '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">'
        . '<input type="hidden" name="linkmap_tables_save" value="1">'
        . $csrf->getHiddenField()
        . '<p>' . rex_i18n::msg('linkmap_tables_intro') . '</p>'
        . '<div class="table-responsive"><table class="table table-hover"><thead><tr>'
        . '<th class="text-center">' . rex_i18n::msg('linkmap_tables_col_enabled') . '</th>'
        . '<th>' . rex_i18n::msg('linkmap_tables_col_table') . '</th>'
        . '<th>' . rex_i18n::msg('linkmap_tables_col_label') . '</th>'
        . '<th>' . rex_i18n::msg('linkmap_tables_col_search') . '</th>'
        . '<th>' . rex_i18n::msg('linkmap_tables_col_columns') . '</th>'
        . '<th>' . rex_i18n::msg('linkmap_tables_col_order') . '</th>'
        . '<th>' . rex_i18n::msg('linkmap_tables_col_filter') . '</th>'
        . '<th>' . rex_i18n::msg('linkmap_tables_col_clang') . '</th>'
        . '<th>' . rex_i18n::msg('linkmap_tables_col_url') . '</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<p class="help-block small">' . rex_i18n::msg('linkmap_tables_help') . '</p>'
        . '<button class="btn btn-save" type="submit">' . rex_i18n::msg('linkmap_tables_save') . '</button>'
        . '</form>';

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', rex_i18n::msg('linkmap_tables_title'), false);
    $fragment->setVar('body', $message . $tableHtml, false);
    echo $fragment->parse('core/page/section.php');
}
