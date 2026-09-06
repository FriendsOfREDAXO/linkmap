<?php

use FriendsOfRedaxo\Linkmap\DomainResolver;
use FriendsOfRedaxo\Linkmap\Link\LinkResolver;
use FriendsOfRedaxo\Linkmap\Link\Provider\UrlAddonSchemeProvider;
use FriendsOfRedaxo\Linkmap\Link\Provider\VirtualUrlsSchemeProvider;
use FriendsOfRedaxo\Linkmap\Link\SchemeRegistry;
use FriendsOfRedaxo\Linkmap\Permission;
use FriendsOfRedaxo\Linkmap\Source\SourceRegistry;
use FriendsOfRedaxo\Linkmap\Source\YFormSourceProvider;

// Eigene Rechte fuer Verlauf im Overlay. Unconditional (auch ohne
// eingeloggten User), damit die Rollenverwaltung sie auflisten kann.
// Labels kommen aus lang/*.lang (perm_general_linkmap[...]), siehe rex_perm::register().
rex_perm::register('linkmap[history]');
rex_perm::register('linkmap[all_changes]');

// Namespaced rex_api_function-Klassen muessen explizit registriert werden
// (siehe mediaplace/boot.php). Die Endpunkte pruefen Login + Strukturrecht
// selbst (AbstractEndpoint::execute()).
rex_api_function::register('linkmap_tree', FriendsOfRedaxo\Linkmap\Api\Tree::class);
rex_api_function::register('linkmap_articles', FriendsOfRedaxo\Linkmap\Api\Articles::class);
rex_api_function::register('linkmap_search', FriendsOfRedaxo\Linkmap\Api\Search::class);
rex_api_function::register('linkmap_history', FriendsOfRedaxo\Linkmap\Api\History::class);
rex_api_function::register('linkmap_favorites', FriendsOfRedaxo\Linkmap\Api\Favorites::class);
rex_api_function::register('linkmap_article', FriendsOfRedaxo\Linkmap\Api\Article::class);
rex_api_function::register('linkmap_source_browse', FriendsOfRedaxo\Linkmap\Api\SourceBrowse::class);
rex_api_function::register('linkmap_link_schemes', FriendsOfRedaxo\Linkmap\Api\LinkSchemes::class);
rex_api_function::register('linkmap_link_resolve', FriendsOfRedaxo\Linkmap\Api\LinkResolve::class);

// Datensatz-Quellen fuer den Picker (siehe lib/Source): YForm eingebaut,
// weitere per SourceRegistry::register() aus anderen Addons.
if (YFormSourceProvider::isAvailable()) {
    SourceRegistry::register(new YFormSourceProvider());
}

// URL-Schemata fuer yform://-Links (siehe lib/Link): url-Addon und
// virtual_urls als eingebaute Provider, weitere per SchemeRegistry::register()
// aus anderen Addons (nach unserer boot.php, PACKAGES_INCLUDED reicht).
// Unconditional: die Aufloesung laeuft im Frontend (OUTPUT_FILTER unten) und
// im Backend (Schema-Auswahl im Picker, LinkResolver::url() in Modulen).
if (UrlAddonSchemeProvider::isAvailable()) {
    SchemeRegistry::register(new UrlAddonSchemeProvider());
}
if (VirtualUrlsSchemeProvider::isAvailable()) {
    SchemeRegistry::register(new VirtualUrlsSchemeProvider());
}

// Frontend: href="yform://..." (und optional Altformate wie news://5) in der
// Ausgabe durch die aufgeloeste URL ersetzen -- damit brauchen CKE5/TinyMCE/
// MForm keine eigene Datensatz-Linkloesung mehr. Abschaltbar, falls ein
// Projekt bereits selbst aufloest.
if (rex::isFrontend() && rex_config::get('linkmap', 'resolve_yform_links', true)) {
    rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep) {
        $ep->setSubject(LinkResolver::replaceInHtml((string) $ep->getSubject(), rex_clang::getCurrentId()));
    }, rex_extension::LATE);
}

// YForm-Werttyp "linkmap" (lib/yform/value/yform_value_linkmap.php, per
// Klassennamenskonvention von YForm erkannt): nur Template-Verzeichnis und
// Loeschsperre registrieren. Unconditional wie bei mediaplace/boot.php --
// YForms Loeschsperre (be_link::isArticleInUse) laeuft auf PACKAGES_INCLUDED.
if (rex_addon::get('yform')->isAvailable()) {
    rex_yform::addTemplatePath(rex_addon::get('linkmap')->getPath('ytemplates'));
    rex_extension::register('YFORM_ARTICLE_IS_IN_USE', rex_yform_value_linkmap::addFieldsToArticleInUse(...));
}

if (rex::isBackend() && rex::getUser() && rex_backend_login::hasSession()) {
    $addon = rex_addon::get('linkmap');
    $addonName = $addon->getName();
    $replaceClassic = (bool) rex_config::get($addonName, 'replace_classic_linkmap', true);

    // Pro-Datei-Cache-Buster (siehe mediaplace/boot.php).
    $bust = function (string $file) use ($addon) {
        return '?v=' . filemtime($addon->getPath('assets/' . $file));
    };

    rex_view::addCssFile($addon->getAssetsUrl('linkmap.css') . $bust('linkmap.css'));
    rex_view::addJsFile($addon->getAssetsUrl('linkmap.js') . $bust('linkmap.js'));

    // Widget: <input class="lm-widget"> -> Artikelauswahl mit Anzeige
    rex_view::addCssFile($addon->getAssetsUrl('linkmap_widget.css') . $bust('linkmap_widget.css'));
    rex_view::addJsFile($addon->getAssetsUrl('linkmap_widget.js') . $bust('linkmap_widget.js'));

    // Klassische Aufrufer (openLinkMap/openREXLinklist/newLinkMapWindow aus
    // structure/assets/linkmap.js, genutzt von REX_LINK[n]/REX_LINKLIST[n],
    // CKE5, TinyMCE, MForm, builder) auf den Overlay umbiegen. Definiert
    // ausserdem window.rex5LinkmapBridge fuer neue Aufrufer.
    if ($replaceClassic) {
        rex_view::addJsFile($addon->getAssetsUrl('linkmap_classic.js') . $bust('linkmap_classic.js'));
    }

    // Die versteckte Popup-Seite ?page=linkmap (structure/package.yml) bekommt
    // unsere Takeover-Seite: Aufrufer, die das Popup ohne unsere JS-Weiche
    // direkt oeffnen (fremde Addons, Deep-Links), landen so ebenfalls im
    // Overlay, inkl. Nachbildung des klassischen Opener-Vertrags
    // (pages/linkmap_takeover.php). TinyMCEs ?page=insertlink bindet
    // structure/pages/linkmap.php per require_once ein und wird deshalb
    // clientseitig abgefangen (linkmap_classic.js, newLinkMapWindow()).
    rex_extension::register('PAGES_PREPARED', static function () use ($addonName) {
        if (!rex_config::get($addonName, 'replace_classic_linkmap', true)) {
            return;
        }
        // structure registriert "linkmap" als eigene Hauptseite (pages: ...,
        // main: true) -- deren Datei haengt am path der rex_be_page_main,
        // nicht am subPath.
        $linkmapPage = rex_be_controller::getPages()['linkmap'] ?? null;
        if ($linkmapPage instanceof rex_be_page_main) {
            $linkmapPage->setPath(rex_path::addon($addonName, 'pages/linkmap_takeover.php'));
        } elseif ($linkmapPage instanceof rex_be_page) {
            $linkmapPage->setSubPath(rex_path::addon($addonName, 'pages/linkmap_takeover.php'));
        }
    });

    rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep) use ($addonName) {
        $content = $ep->getSubject();
        $lastBodyPos = strrpos($content, '</body>');
        if (false === $lastBodyPos) {
            return;
        }

        $user = rex::requireUser();

        $clangs = [];
        foreach (rex_clang::getAll() as $clang) {
            $clangs[] = ['id' => $clang->getId(), 'code' => $clang->getCode(), 'name' => $clang->getName()];
        }

        $currentClang = rex_request('clang', 'int', 0);
        if (!rex_clang::exists($currentClang)) {
            $currentClang = rex_clang::getCurrentId();
        }

        $urls = [
            'tree' => rex_url::backendController(['rex-api-call' => 'linkmap_tree'], false),
            'articles' => rex_url::backendController(['rex-api-call' => 'linkmap_articles'], false),
            'search' => rex_url::backendController(['rex-api-call' => 'linkmap_search'], false),
            'history' => rex_url::backendController(['rex-api-call' => 'linkmap_history'], false),
            'favorites' => rex_url::backendController(['rex-api-call' => 'linkmap_favorites'], false),
            'article' => rex_url::backendController(['rex-api-call' => 'linkmap_article'], false),
            'sourceBrowse' => rex_url::backendController(['rex-api-call' => 'linkmap_source_browse'], false),
            'linkSchemes' => rex_url::backendController(['rex-api-call' => 'linkmap_link_schemes'], false),
            'linkResolve' => rex_url::backendController(['rex-api-call' => 'linkmap_link_resolve'], false),
        ];

        // Status-Definitionen inkl. Erweiterungen (ART_STATUS_TYPES /
        // CAT_STATUS_TYPES, z.B. "gesperrt" aus dem accessdenied-Addon):
        // Index = Statuswert, [Label, CSS-Klasse, Icon]. Der Client rendert
        // Label/Icon daraus statt nur online/offline zu kennen.
        $statusTypes = static function (array $types): array {
            $result = [];
            foreach ($types as $type) {
                $result[] = [
                    'label' => (string) ($type[0] ?? ''),
                    'class' => (string) ($type[1] ?? ''),
                    'icon' => (string) ($type[2] ?? ''),
                ];
            }
            return $result;
        };

        $config = [
            'clangs' => $clangs,
            'statusTypes' => [
                'article' => $statusTypes(rex_article_service::statusTypes()),
                'category' => $statusTypes(rex_category_service::statusTypes()),
            ],
            'currentClang' => $currentClang,
            'domains' => DomainResolver::hasFilter() ? DomainResolver::getDomains() : [],
            'defaultDomain' => DomainResolver::getDefaultDomainName(),
            'canPick' => Permission::hasStructureAccess(),
            'canHistory' => Permission::hasHistoryAccess(),
            'rootAccess' => Permission::hasRootAccess(),
            // Datensatz-Quellen (Provider + Container), die der User sehen darf
            'sources' => Permission::hasStructureAccess() ? SourceRegistry::getAvailableSources() : [],
            'csrf' => rex_csrf_token::factory(FriendsOfRedaxo\Linkmap\Api\Favorites::class)->getUrlParams(),
            'isPopup' => rex_be_controller::getCurrentPageObject()?->isPopup() ?? false,
            'user' => $user->getLogin(),
            'urls' => $urls,
        ];

        // JS-Uebersetzungen: alle linkmap_*-Schluessel der de_de.lang fuer die
        // aktive Locale aufloesen (Muster mediaplace-i18n.js).
        $i18n = [];
        $langFile = rex_path::addon($addonName, 'lang/de_de.lang');
        $langLines = is_file($langFile) ? file($langFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if (false !== $langLines) {
            foreach ($langLines as $line) {
                if (preg_match('/^(linkmap_[a-zA-Z0-9_]+)\s*=/', $line, $m)) {
                    $i18n[$m[1]] = rex_i18n::msg($m[1]);
                }
            }
        }
        $i18n['root_level'] = rex_i18n::msg('root_level');

        $jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
        $inject = '<div id="lm-root" hidden></div>'
            . "\n" . '<script type="application/json" id="lm-config">' . json_encode($config, $jsonFlags) . '</script>'
            . "\n" . '<script type="application/json" id="lm-i18n-data">' . json_encode($i18n, $jsonFlags) . '</script>';

        $ep->setSubject(substr_replace($content, $inject . "\n" . '</body>', $lastBodyPos, strlen('</body>')));
    });
}
