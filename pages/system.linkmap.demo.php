<?php

use FriendsOfRedaxo\Linkmap\DemoTableset;
use FriendsOfRedaxo\Linkmap\Source\SourceRegistry;
use FriendsOfRedaxo\Linkmap\Widget;

/**
 * Demo-Seite. Aufbau:
 *   1. Picker ausprobieren (JS-API)
 *   2. Widget fuer Artikel
 *   3. Klassische Widgets (REX_LINK / REX_LINKLIST)
 *   4. YForm & Datensaetze -- erst nach Installation des optionalen
 *      Demo-Tablesets (DemoTableset), damit niemand Beispiele fuer etwas sieht,
 *      das in seiner Installation gar nicht existiert.
 * Alle Vorbelegungen kommen aus vorhandenen Inhalten (zufaellige Artikel).
 */

$csrf = rex_csrf_token::factory('linkmap_demo');
$message = '';
$action = rex_post('linkmap_demo_action', 'string', '');
if ('' !== $action) {
    if (!$csrf->isValid()) {
        $message = rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif (('install' === $action || 'uninstall' === $action) && DemoTableset::isAvailable()) {
        'install' === $action ? DemoTableset::install(rex_post('linkmap_demo_resolver', 'string', '')) : DemoTableset::uninstall();
        // Redirect statt Weiterrendern: YForms Tabellen-Cache dieses Requests
        // kennt die neue/entfernte Tabelle noch nicht.
        rex_response::sendRedirect(rex_url::currentBackendPage(['linkmap_demo_msg' => $action], false));
    }
}
$flash = rex_get('linkmap_demo_msg', 'string', '');
if ('install' === $flash) {
    $message = rex_view::success(rex_i18n::msg('linkmap_demo_tableset_installed'));
} elseif ('uninstall' === $flash) {
    $message = rex_view::success(rex_i18n::msg('linkmap_demo_tableset_removed'));
}
echo $message;

$demoInstalled = DemoTableset::isInstalled();
$hasSources = [] !== SourceRegistry::getAvailableSources();

$sql = rex_sql::factory();
$randomArticles = $sql->getArray('SELECT id FROM ' . rex::getTable('article') . ' WHERE clang_id = ? ORDER BY RAND() LIMIT 3', [rex_clang::getStartId()]);
$articleIds = array_map(static fn (array $row): int => (int) $row['id'], $randomArticles);
$articleId1 = $articleIds[0] ?? 0;
$articleIdList = implode(',', $articleIds);

$code = static fn (string $code): string => '<pre class="lm-demo-code"><code>' . rex_escape($code) . '</code></pre>';
$section = static function (string $title, string $body): void {
    $fragment = new rex_fragment();
    $fragment->setVar('title', $title, false);
    $fragment->setVar('body', $body, false);
    echo $fragment->parse('core/page/section.php');
};

echo '<style>.lm-demo-code{font-size:12px;margin-top:12px}.lm-demo-buttons{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}.lm-demo-sub{margin-top:24px;padding-top:16px;border-top:1px solid #dfe3e9}.lm-demo-sub h4{margin:0 0 6px}</style>';
echo '<p style="max-width:900px;">' . rex_i18n::msg('linkmap_demo_intro') . '</p>';

// ---- 1. Picker ausprobieren ----------------------------------------------
$body = '<p>' . rex_i18n::msg('linkmap_demo_api_intro') . '</p><div class="lm-demo-buttons">'
    . '<button class="btn btn-primary" onclick="LM.open(function (link, name) { alert(name + \' → \' + link); })"><i class="fa-solid fa-file"></i> ' . rex_i18n::msg('linkmap_demo_pick_article') . '</button>'
    . '<button class="btn btn-default" onclick="LM.open(function (items) { alert(items.map(function (i) { return i.name + \' → \' + i.link; }).join(\'\\n\')); }, { multiple: true })"><i class="fa-solid fa-list-check"></i> ' . rex_i18n::msg('linkmap_demo_pick_articles') . '</button>'
    . ($hasSources
        ? '<button class="btn btn-success" onclick="LM.open(function (link, name) { alert(name + \' → \' + link); }, { sources: \'all\' })"><i class="fa-solid fa-database"></i> ' . rex_i18n::msg('linkmap_demo_pick_all') . '</button>'
        : '')
    . '<button class="btn btn-default" onclick="LM.open()"><i class="fa-solid fa-eye"></i> ' . rex_i18n::msg('linkmap_demo_browse') . '</button>'
    . '</div>'
    . '<p><small class="text-muted">' . rex_i18n::msg('linkmap_demo_sources_hint') . '</small></p>'
    . $code("// Artikel wählen: link = \"redaxo://ID\", name = Artikelname (ohne ID)
LM.open(function (link, name, article) { ... });

// Mehrfachauswahl: items = [{ id, link, name, item }]
LM.open(function (items) { ... }, { multiple: true });

// Datensätze zusätzlich anbieten (nur wenn der Wert ein Link-String sein darf)
LM.open(function (link, name, item) { ... }, { sources: 'all' });

// Weitere Optionen: clang, categoryId, domain, fullscreen, onClose, title
// Bridge für Editoren und Formbuilder: rex5LinkmapBridge.pick(cb, options)");
$section(rex_i18n::msg('linkmap_demo_section_api'), $body);

// ---- 2. Widget fuer Artikel -------------------------------------------------
$body = '<p>' . rex_i18n::msg('linkmap_demo_widget_intro') . '</p>'
    . '<div class="form-group"><label>' . rex_i18n::msg('linkmap_demo_widget_single') . '</label>' . Widget::render('demo_article', '') . '</div>'
    . '<div class="form-group"><label>' . rex_i18n::msg('linkmap_demo_widget_prefilled') . '</label>' . Widget::render('demo_article_prefilled', $articleId1) . '</div>'
    . '<div class="form-group"><label>' . rex_i18n::msg('linkmap_demo_widget_multi') . '</label>' . Widget::render('demo_articles', $articleIdList, ['multiple' => true, 'max' => 5]) . '</div>'
    . '<div class="form-group"><label>' . rex_i18n::msg('linkmap_demo_widget_categories') . '</label>' . Widget::render('demo_category', '', ['categories' => true]) . '</div>'
    . $code("// PHP
echo \\FriendsOfRedaxo\\Linkmap\\Widget::render('link', \$value);
echo \\FriendsOfRedaxo\\Linkmap\\Widget::render('links', \$value, ['multiple' => true, 'max' => 5, 'category' => 5, 'domain' => 'example.org']);
echo \\FriendsOfRedaxo\\Linkmap\\Widget::render('category', \$value, ['categories' => true]); // Kategorie-Picker

// HTML (Wert = Artikel-ID, bei Mehrfachauswahl kommasepariert)
<input class=\"lm-widget\" name=\"link\" value=\"12\">
<input class=\"lm-widget\" name=\"links\" data-lm-multiple=\"true\" data-lm-max=\"5\" value=\"12,15\">
<input class=\"lm-widget\" name=\"category\" data-lm-categories=\"true\" value=\"5\">

// JS: LM.open(cb, { categoriesOnly: true }) oder rex5LinkmapBridge.pickCategory(cb)");
$section(rex_i18n::msg('linkmap_demo_section_widget'), $body);

// ---- 3. Klassische Widgets ---------------------------------------------------
$body = '<p>' . rex_i18n::msg('linkmap_demo_classic_intro') . '</p>'
    . '<div class="form-group"><label>REX_LINK[1]</label>' . rex_var_link::getWidget(1, 'REX_INPUT_LINK[1]', (string) $articleId1) . '</div>'
    . '<div class="form-group"><label>REX_LINKLIST[1]</label>' . rex_var_linklist::getWidget(1, 'REX_INPUT_LINKLIST[1]', $articleIdList) . '</div>';
$section(rex_i18n::msg('linkmap_demo_section_classic'), $body);

// ---- 4. YForm & Datensaetze ---------------------------------------------------
if (!DemoTableset::isAvailable()) {
    $section(rex_i18n::msg('linkmap_demo_section_yform'), '<p class="text-muted">' . rex_i18n::msg('linkmap_demo_yform_unavailable') . '</p>');
    return;
}

$form = static fn (string $act, string $label, string $class, string $extra = ''): string => '<form method="post" action="' . rex_url::currentBackendPage() . '" style="display:inline">'
    . '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">'
    . '<input type="hidden" name="linkmap_demo_action" value="' . $act . '">' . $csrf->getHiddenField() . $extra
    . '<button class="btn ' . $class . '" type="submit">' . $label . '</button></form>';

if (!$demoInstalled) {
    // URL-Aufloesung waehlbar: vorhandene Addons (virtual_urls, url) oder
    // nur ein URL-Template. Ohne beides gibt es keine Wahl und keine
    // Schema-Beispiele.
    $resolvers = DemoTableset::availableResolvers();
    $resolverLabels = [
        'vu' => rex_i18n::msg('linkmap_demo_resolver_vu', implode('/, /', DemoTableset::TRIGGERS)),
        'url' => rex_i18n::msg('linkmap_demo_resolver_url', implode(', ', DemoTableset::URL_NAMESPACES)),
        'template' => rex_i18n::msg('linkmap_demo_resolver_template'),
    ];
    $choice = '';
    if (count($resolvers) > 1) {
        $choice = '<div class="form-group"><label>' . rex_i18n::msg('linkmap_demo_resolver_label') . '</label>';
        foreach ($resolvers as $i => $resolver) {
            $choice .= '<div class="radio"><label><input type="radio" name="linkmap_demo_resolver" value="' . $resolver . '"' . (0 === $i ? ' checked' : '') . '> ' . $resolverLabels[$resolver] . '</label></div>';
        }
        $choice .= '</div>';
    } else {
        $choice = '<input type="hidden" name="linkmap_demo_resolver" value="template">';
    }
    $body = '<p>' . rex_i18n::msg('linkmap_demo_tableset_intro') . '</p><ul>'
        . '<li>' . rex_i18n::msg('linkmap_demo_tableset_item_table') . '</li>'
        . '<li>' . rex_i18n::msg('linkmap_demo_tableset_item_source') . '</li>'
        . (count($resolvers) > 1
            ? '<li>' . rex_i18n::msg('linkmap_demo_tableset_item_resolver') . '</li>'
            : '<li>' . rex_i18n::msg('linkmap_demo_tableset_item_template') . '</li>')
        . '</ul><p>' . rex_i18n::msg('linkmap_demo_tableset_remove_hint') . '</p>'
        . $form('install', '<i class="fa-solid fa-download"></i> ' . rex_i18n::msg('linkmap_demo_tableset_install'), 'btn-primary', $choice);
    $section(rex_i18n::msg('linkmap_demo_section_yform'), $body);
    return;
}

$table = DemoTableset::TABLE;
$tableLabel = rex_i18n::translate(rex_yform_manager_table::get($table)?->getName() ?? $table);
$demoRows = $sql->getArray('SELECT id FROM ' . $sql->escapeIdentifier($table) . ' ORDER BY RAND() LIMIT 2');
$demoIds = array_map(static fn (array $row): int => (int) $row['id'], $demoRows);
$activeResolver = DemoTableset::activeResolver();
$schemeHint = [
    'vu' => rex_i18n::msg('linkmap_demo_scheme_hint_vu'),
    'url' => rex_i18n::msg('linkmap_demo_scheme_hint_url'),
    'template' => rex_i18n::msg('linkmap_demo_scheme_hint_template'),
][$activeResolver] ?? '';

$body = '<p>' . rex_i18n::msg('linkmap_demo_tableset_active', $tableLabel) . ' ' . $form('uninstall', '<i class="fa-solid fa-trash"></i> ' . rex_i18n::msg('linkmap_demo_tableset_uninstall'), 'btn-default btn-xs') . '</p>'
    . '<p><small class="text-muted">' . $schemeHint . '</small></p>';

// 4a Datensaetze im Picker (Link-Format)
$body .= '<div class="lm-demo-sub"><h4>' . rex_i18n::msg('linkmap_demo_sub_links') . '</h4><p>' . rex_i18n::msg('linkmap_demo_sub_links_intro') . '</p>'
    . '<div class="form-group"><label>' . rex_i18n::msg('linkmap_demo_widget_link_single') . '</label>' . Widget::render('demo_target', '', ['format' => 'link', 'sources' => 'all']) . '</div>'
    . '<div class="form-group"><label>' . rex_i18n::msg('linkmap_demo_widget_link') . '</label>' . Widget::render('demo_targets', 'redaxo://' . $articleId1 . ',yform://' . $table . '/' . ($demoIds[0] ?? 1), ['multiple' => true, 'format' => 'link', 'sources' => 'all']) . '</div>'
    . $code("// Link-Format: Artikel UND Datensätze, Wert z. B. \"redaxo://12,yform://{$table}/3\"
echo \\FriendsOfRedaxo\\Linkmap\\Widget::render('targets', \$value, ['multiple' => true, 'format' => 'link', 'sources' => 'all']);
<input class=\"lm-widget\" name=\"targets\" data-lm-format=\"link\" data-lm-sources=\"all\" data-lm-multiple=\"true\">

// Frontend: yform://-Links werden per OUTPUT_FILTER aufgelöst, in Modulen:
\$url = \\FriendsOfRedaxo\\Linkmap\\Link\\LinkResolver::url('yform://{$table}/3');") . '</div>';

// 4b Relation-Modus
$body .= '<div class="lm-demo-sub"><h4>' . rex_i18n::msg('linkmap_demo_sub_relation') . '</h4><p>' . rex_i18n::msg('linkmap_demo_relation_intro') . '</p>'
    . '<div class="form-group"><label>' . rex_i18n::msg('linkmap_demo_widget_relation', $tableLabel) . '</label>' . Widget::render('demo_relation', (string) ($demoIds[0] ?? ''), ['table' => $table]) . '</div>'
    . '<div class="form-group"><label>' . rex_i18n::msg('linkmap_demo_widget_relation_multi', $tableLabel) . '</label>' . Widget::render('demo_relation_multi', implode(',', $demoIds), ['table' => $table, 'multiple' => true]) . '</div>'
    . $code("// Relation-Modus: nur Datensätze dieser Tabelle, Wert = ID(s) wie bei be_manager_relation
echo \\FriendsOfRedaxo\\Linkmap\\Widget::render('ref', \$value, ['table' => '{$table}', 'multiple' => true]);
<input class=\"lm-widget\" name=\"ref\" data-lm-table=\"{$table}\" data-lm-multiple=\"true\" value=\"" . implode(',', $demoIds) . "\">") . '</div>';

// 4c YForm-Formular
$yform = new rex_yform();
$yform->setObjectparams('form_action', rex_url::currentBackendPage());
$yform->setObjectparams('form_name', 'linkmap_demo');
$yform->setObjectparams('form_showformafterupdate', 1);
$yform->setObjectparams('real_field_names', true);
$yform->setValueField('linkmap', ['link', rex_i18n::msg('linkmap_demo_yform_field_single'), 0, '', '', '', $articleId1]);
$yform->setValueField('linkmap', ['links', rex_i18n::msg('linkmap_demo_yform_field_multi'), 1, '3', '', '', $articleIdList]);
$yform->setValueField('linkmap', ['target', rex_i18n::msg('linkmap_demo_yform_field_link_single'), 0, '', '', '', '', '', 'link', 'all']);
$yform->setValueField('linkmap', ['targets', rex_i18n::msg('linkmap_demo_yform_field_link'), 1, '', '', '', '', '', 'link', 'all']);
$yform->setValueField('linkmap_relation', ['ref', rex_i18n::msg('linkmap_demo_yform_field_relation', $tableLabel), $table, 1, '', implode(',', $demoIds)]);
$yform->setActionField('showtext', [rex_i18n::msg('linkmap_demo_yform_submitted')]);
$yformHtml = $yform->getForm();
$submitted = '';
if ($yform->objparams['actions_executed']) {
    $pool = $yform->objparams['value_pool']['sql'];
    $submitted = $code((string) json_encode([
        'link' => $pool['link'] ?? null,
        'links' => $pool['links'] ?? null,
        'target' => $pool['target'] ?? null,
        'targets' => $pool['targets'] ?? null,
        'ref' => $pool['ref'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
$body .= '<div class="lm-demo-sub"><h4>' . rex_i18n::msg('linkmap_demo_sub_form') . '</h4><p>' . rex_i18n::msg('linkmap_demo_yform_intro') . '</p>'
    . $yformHtml . $submitted
    . $code("// PHP -- Parameter linkmap: name, label, multiple, max, category, domain, default, notice, format, sources
\$yform->setValueField('linkmap', ['link', 'Zielseite']);
\$yform->setValueField('linkmap', ['links', 'Zielseiten', 1, '3']);
\$yform->setValueField('linkmap', ['targets', 'Ziele', 1, '', '', '', '', '', 'link', 'all']);
// Parameter linkmap_relation: name, label, table, multiple, max, default, notice
\$yform->setValueField('linkmap_relation', ['ref', 'Referenz', '{$table}', 1]);

// Pipe-Syntax
linkmap|link|Zielseite|
linkmap|targets|Ziele|1||||||link|all
linkmap_relation|ref|Referenz|{$table}|1

// Table Manager: Feldtyp \"Wert\" -> linkmap bzw. linkmap_relation") . '</div>';

$section(rex_i18n::msg('linkmap_demo_section_yform'), $body);
