# Linkmap

Das Linkmap-Popup von REDAXO ist alt. Man klickt auf das kleine Icon, ein Fenster geht auf, man hangelt sich durch Kategorien, klickt einen Artikel an, das Fenster geht zu. Funktioniert, fühlt sich aber an wie 2008.

Dieses Addon ersetzt das Popup durch ein Overlay, so wie MediaPlace es für den Medienpool macht: Strukturbaum mit Live-Filter, Artikelsuche über die ganze Struktur, zuletzt bearbeitete Artikel, Favoriten, yrewrite-Domain-Filter, Tastaturbedienung, Dark Mode. Und weil ein Link nicht immer auf einen Artikel zeigt, kann der Picker auch YForm-Datensätze liefern. Die Frontend-URL dazu kommt aus dem **url-Addon** oder aus **virtual_urls**, beide werden direkt unterstützt, auch nebeneinander und mit mehreren Profilen pro Tabelle.

Es ist ein Picker. Er ersetzt nicht die Strukturverwaltung und nicht den Table Manager.

## Installation

Addon installieren, fertig. Ab jetzt öffnen alle klassischen Linkmap-Aufrufe das Overlay:

- REX_LINK[n] und REX_LINKLIST[n] in Modulen
- CKEditor 5, TinyMCE, MForm (customlink, list-widget, repeater), Builder
- YForm be_link (das Core-Widget dahinter)
- Direktaufrufe von `?page=linkmap`

Die Core-Widgets bleiben unverändert. Das Addon biegt die JavaScript-Funktionen `openLinkMap()`, `openREXLinklist()` und `newLinkMapWindow()` um und gibt den Aufrufern ein Objekt zurück, das sich wie das alte Popup-Fenster verhält (`rex:selectLink`-Event, `close()`, `closed`). Deshalb muss an bestehendem Code nichts geändert werden.

Verwaltung unter **System → Linkmap**: Einstellungen, Demo, Hilfe. Die Demo-Seite zeigt alle Aufrufwege mit echten Daten aus der Installation. Für die YForm-Beispiele bietet sie an, ein kleines Demo-Tableset zu installieren, das sich dort auch wieder entfernen lässt. Ohne diesen Schritt legt das Addon keine Tabellen an.

## url-Addon und virtual_urls

Das ist der Kern der Datensatz-Unterstützung: Linkmap speichert für einen Datensatz keinen fertigen Pfad, sondern `yform://<tabelle>/<id>`. Welche URL daraus wird, entscheiden die URL-Addons, die ohnehin die Frontend-Routen kennen.

| Addon | Was Linkmap daraus macht |
| --- | --- |
| **url-Addon** (ab 2.x) | Jedes Profil einer Tabelle ist ein URL-Schema (Kennung `url:<namespace>`). Die URL kommt aus der URL-Tabelle des Addons; fehlt sie für einen Datensatz noch, erzeugt Linkmap sie einmalig. Restriktionen des Profils gelten, ein Datensatz außerhalb bekommt keine URL. |
| **virtual_urls** (ab 1.2) | Jedes aktive Profil einer Tabelle ist ein Schema (Kennung `vu:<profil-id>`), inklusive Sprache und Domain des Profils. Nutzt die Profil-API der Version 1.2, die für Linkmap entstanden ist. |

**So läuft die Auswahl:** Hat die Tabelle genau ein Schema, wählt der Redakteur den Datensatz und fertig. Hat sie mehrere, etwa zwei Domains oder zwei Landingpages, klappt bei der Einzelauswahl der Dialog „URL-Schema wählen“ auf, mit Vorschau-URL pro Profil:

- **Automatisch** speichert `yform://rex_news/42`. Beim Rendern nimmt der Resolver das erste Schema, das passt: Sprache, dann aktuelle Domain, dann domainunabhängige Profile. Derselbe Link löst also auf jeder Domain zu ihrer eigenen URL auf.
- **Ein bestimmtes Profil** speichert `yform://rex_news/42?scheme=vu:3` oder `?scheme=url:news-id`. Dieses Profil wird beim Rendern immer genommen; fällt es weg, greift wieder die automatische Kette.

Mehrfachauswahl und Relation-Modus zeigen keinen Dialog, dort gilt „Automatisch“ beziehungsweise nur die ID.

**Schemata einschränken:** Hat eine Tabelle Profile, die nicht als Linkziel taugen, etwa „editnews“ oder „news_delete“ neben „news“, lassen sich unter System → Linkmap → Einstellungen → Datensatz-Quellen pro Tabelle die erlaubten Schemata anhaken. Nur diese erscheinen im Dialog und werden beim Rendern genutzt; ist nur eines erlaubt, entfällt der Dialog. Bereits gespeicherte Links mit festem `?scheme=` bleiben gültig.

**Auflösen** übernimmt Linkmap: `href="yform://…"` wird im Frontend per OUTPUT_FILTER ersetzt, in Modulen liefert `LinkResolver::url($link)` die URL. Reihenfolge: festes Schema, url-Addon, virtual_urls, Extension Point `LINKMAP_RESOLVE_URL`, URL-Template aus den Tabelleneinstellungen. Editoren wie CKE5 und TinyMCE brauchen damit keine eigene YForm-Linklösung mehr.

**Ausprobieren:** Die Demo-Seite installiert auf Wunsch eine Tabelle „REDAXO-News“ und legt dabei nach Wahl zwei url-Addon-Profile oder zwei virtual_urls-Profile an, damit der Schema-Dialog sofort sichtbar ist. Ohne beide Addons bleibt es beim URL-Template.

Zwei Stolperfallen aus der Praxis: Ein url-Addon-Profil „für alle Sprachen“ braucht eine Sprachspalte in der Tabelle, sonst erzeugt das Addon keine URLs. Und virtual_urls vor 1.2 kennt keine Profil-API, Linkmap ignoriert es dann.

## Was Redakteure sehen

| Bereich | Funktion |
| --- | --- |
| Kopfzeile | Domain-Filter (mit yrewrite und mindestens zwei Domains), Sprachwahl, Suche nach Name oder ID, Vollbild |
| Sidebar | Struktur mit Live-Filter nach Name, ID oder Domain, Favoriten (Stern an einer Kategorie), Datensätze (freigegebene YForm-Tabellen), Zuletzt bearbeitet |
| Inhalt | Unterkategorien und Artikel der Kategorie mit ID, Status und Änderungsdatum; eine Kategorie lässt sich über den Haken neben dem Pfeil direkt auswählen (verlinkt ihren Startartikel), ohne sie zu öffnen. Suchergebnisse mit Pfad. Datensätze als sortierbare Tabelle mit „Neuer Datensatz“ und „Datensatz bearbeiten“ |
| Fußzeile | „Nur Online anzeigen“, bei Mehrfachauswahl Zähler und Übernehmen |

Startartikel und Startseite haben eigene Icons und Farben. Status-Erweiterungen wie „gesperrt“ aus dem accessdenied-Addon werden mit Label und Icon angezeigt.

Tastatur: `↓`/`↑` bewegen, `Enter` wählt, `Leertaste` markiert (Mehrfachauswahl), `Backspace` eine Ebene hoch, `Esc` schließt.

## Für Entwickler

### Drei Ebenen, ein Ergebnis

Egal welcher Weg: Der Picker liefert einen Link (`redaxo://12` oder `yform://rex_news/3`) und einen Namen. Wer nur Artikel-IDs speichern kann, bekommt nur Artikel angeboten; wer Strings speichert, kann Datensätze freischalten.

| Ebene | Wann | Wie |
| --- | --- | --- |
| Automatisch | Bestehender Code mit `openLinkMap()` oder `?page=linkmap` | Nichts tun |
| JavaScript | Editoren, eigene Widgets, Formbuilder | `LM.open(callback, options)` oder `rex5LinkmapBridge` |
| PHP | Formularfelder mit gespeichertem Wert | `FriendsOfRedaxo\Linkmap\Widget::render()` oder die YForm-Werttypen |

### JavaScript

```js
// Artikel: link = "redaxo://12", name = "Kontakt", article = JSON-Objekt
LM.open(function (link, name, article) { ... });

// Mehrfachauswahl: items = [{ id, link, name, item }]
LM.open(function (items) { ... }, { multiple: true });

// Nur ansehen
LM.open();

// Datensätze freischalten -- nur wenn der Wert ein Link-String sein darf
LM.open(callback, { sources: 'all' });          // oder ['article', 'yform']

// Kategorie-Picker: nur Kategorien, Ergebnis ist der Startartikel (redaxo://<Kategorie-ID>)
LM.open(callback, { categoriesOnly: true });      // oder rex5LinkmapBridge.pickCategory(callback)

// Relation-Modus: eine feste Tabelle, keine Struktur, Ergebnis ist der Datensatz
LM.open(callback, { sources: ['yform'], container: { source: 'yform', id: 'rex_news', label: 'News' }, lockContainer: true });

// Weitere Optionen
LM.open(callback, { clang: 1, categoryId: 5, domain: 'example.org', fullscreen: false, title: 'Ziel wählen', selected: [12, 15], onClose: function () {} });

LM.close(); LM.isOpen();
LM.on('select', fn);                       // Events: open, close, select
LM.resolve([12, 15], 1);                   // Promise<Array<article>>
LM.resolveLinks(['redaxo://5', 'yform://rex_news/3'], 1); // Promise<Array<item>>
```

Die Bridge folgt dem Muster von `rex5MediaplaceBridge` und ist der empfohlene Einstieg für Editoren:

```js
if (window.rex5LinkmapBridge && rex5LinkmapBridge.isActive()) {
    rex5LinkmapBridge.pick(function (link, name, item) { ... }, { sources: 'all' });
    rex5LinkmapBridge.pickDataset('rex_news', function (link, name, item) { ... });
}
```

`pickDataset()` ersetzt die bisherigen `rex_yform_manager_opener`-Popups von CKE5, TinyMCE, MForm und Builder. Diese Addons brauchen keine eigene YForm-Linklösung mehr.

### Widget

```html
<input class="lm-widget" name="link" value="12">
<input class="lm-widget" name="links" data-lm-multiple="true" data-lm-max="5" value="12,15">
<input class="lm-widget" name="targets" data-lm-format="link" data-lm-sources="all" value="redaxo://12,yform://rex_news/3">
<input class="lm-widget" name="ref" data-lm-table="rex_news" data-lm-multiple="true" value="3,7">
<input class="lm-widget" name="category" data-lm-categories="true" value="5">
<input class="lm-widget" name="category" data-lm-categories="true" data-lm-format="link" value="redaxo://5">
```

Der Kategorie-Picker speichert wahlweise die ID (Default, wie REX_LINK) oder mit `data-lm-format="link"` den Link `redaxo://ID`; beim YForm-Werttyp entsprechend „Nur Kategorien“ plus Speicherformat.

Der Input bleibt im Formular (als `hidden`) und löst `change` sowie `rex:change` aus. Attribute: `data-lm-multiple`, `data-lm-max`, `data-lm-clang`, `data-lm-category`, `data-lm-domain`, `data-lm-format="link"`, `data-lm-sources`, `data-lm-table`, `data-lm-categories`. Auf pjax-Seiten initialisiert `rex:ready` nach, manuell per `LMWidget.init(container)`.

Dasselbe aus PHP, empfohlen für Addons:

```php
use FriendsOfRedaxo\Linkmap\Widget;

echo Widget::render('link', $value);
echo Widget::render('links', $value, ['multiple' => true, 'max' => 5, 'category' => 5, 'domain' => 'example.org']);
echo Widget::render('targets', $value, ['multiple' => true, 'format' => 'link', 'sources' => 'all']);
echo Widget::render('ref', $value, ['table' => 'rex_news', 'multiple' => true]);
echo Widget::render('category', $value, ['categories' => true]);                    // Wert = ID
echo Widget::render('category', $value, ['categories' => true, 'format' => 'link']); // Wert = redaxo://ID
```

### YForm

Zwei Werttypen, im Table Manager unter „Wert“:

- **linkmap**: Artikel, optional Datensätze. Speicherformat ID (wie be_link) oder Link. Parameter: name, label, multiple, max, category, domain, default, notice, format (`id`|`link`), sources, categories (nur Kategorien).
- **linkmap_relation**: Datensätze einer festen Tabelle, Speicherformat ID (wie be_manager_relation). Parameter: name, label, table, multiple, max, default, notice.

```php
$yform->setValueField('linkmap', ['link', 'Zielseite']);
$yform->setValueField('linkmap', ['targets', 'Ziele', 1, '', '', '', '', '', 'link', 'all']);
$yform->setValueField('linkmap_relation', ['ref', 'Referenz', 'rex_news', 1]);
```

```
linkmap|link|Zielseite|
linkmap|targets|Ziele|1||||||link|all
linkmap_relation|ref|Referenz|rex_news|1
```

Listenansicht, Suche und die Löschsperre beim Löschen verlinkter Artikel sind angebunden.

### Datensätze und `yform://`-Links

**Freigeben:** System → Linkmap → Einstellungen → Datensatz-Quellen. Pro Tabelle: Label-Template (`{title} ({date})`), Suchspalten, Spalten der Listenansicht, Sortierung, Filter, Sprachspalte, erlaubte URL-Schemata, URL-Template. Leer gelassene Felder nutzen die YForm-Felddefinitionen. Rechte über `yform_manager_table_view` / `_edit`. Im Relation-Modus muss die Tabelle nicht freigegeben sein, das Feld legt sie fest.

**Linkformat:** `yform://<tabelle>/<id>`, optional mit festem URL-Schema: `?scheme=url:<namespace>` (url-Addon-Profil) oder `?scheme=vu:<profil-id>` (virtual_urls). Ohne Schema entscheidet der Resolver beim Rendern nach Sprache und aktueller Domain. Hat eine Tabelle mehrere Schemata, zeigt der Picker bei der Einzelauswahl einen Dialog mit Vorschau-URLs.

**Auflösen:** `href="yform://…"` wird im Frontend per OUTPUT_FILTER ersetzt (Einstellung, Default an). In Modulen:

```php
use FriendsOfRedaxo\Linkmap\Link\LinkResolver;

$url = LinkResolver::url('yform://rex_news/42');            // ?string
$url = LinkResolver::url($link, $clang);
$candidates = LinkResolver::candidates('rex_news', 42, $clang); // alle Schemata mit URL
```

Kette: explizites Schema, url-Addon-Profil, virtual_urls-Profil, Extension Point `LINKMAP_RESOLVE_URL` (Params `table`, `id`, `scheme`, `clang`, Rückgabe URL), URL-Template der Tabelle (`/news/{id}`, Platzhalter `{spalte}`, `{clang}`). Nicht auflösbare Links werden `href="#"` mit `data-linkmap-unresolved`. `redaxo://` bleibt Sache der Editoren.

Altformate der bisherigen Editor-Lösungen (`news://5`, `rex-news://5`, `rex_news://5`) löst die Einstellung „Altformate ebenfalls auflösen“ (Default aus) über url-Namespace oder Tabellenname auf.

Details zu url-Addon und virtual_urls stehen oben im Abschnitt „url-Addon und virtual_urls“.

### Eigene Quellen und Schemata

Andere Addons docken in ihrer `boot.php` an:

```php
use FriendsOfRedaxo\Linkmap\Source\SourceRegistry;   // Datensatz-Quelle: getContainers(), browse(), resolve(), ownsLink()
use FriendsOfRedaxo\Linkmap\Link\SchemeRegistry;     // URL-Schema: getSchemes($table), getUrl($scheme, $id, $clang)

SourceRegistry::register(new MeineQuelle());          // implements SourceProviderInterface
SchemeRegistry::register(new MeinSchemaProvider());   // implements SchemeProviderInterface
```

`browse()` liefert `items`, `total`, `pages`, `columns` (für die Tabellenansicht) und optional `addUrl`; jedes Item trägt `id`, `link`, `name`, `online`, `linkable`, `cells` und optional `editUrl`.

## Rechte

| Recht | Wirkung |
| --- | --- |
| `structure/hasStructurePerm` (Core) | Grundvoraussetzung für den Picker |
| `linkmap[all_categories]` (Core) | Ganzer Baum statt nur eigene Mountpoints – Vorsicht, in vielen Rollen unbemerkt aktiv |
| `linkmap[history]` | Sidebar „Zuletzt bearbeitet“ |
| `linkmap[all_changes]` | Verlauf aller Benutzer statt nur eigene |
| `yform_manager_table_view` / `_edit` | Datensätze sehen bzw. anlegen und bearbeiten (gilt für Quellen, Relation-Felder, Schema-Vorschau und Link-Auflösung im Widget) |

## Einstellungen

- Klassische Linkmap ersetzen (Default an)
- Verlauf: Anzahl Artikel (15), Suche: maximale Treffer (50)
- yform://-Links im Frontend auflösen (an), Altformate ebenfalls auflösen (aus)
- Datensatz-Quellen: Tabellenfreigabe und Konfiguration

## API-Referenz

### Endpunkte

Alle Endpunkte sind `rex_api_function`s, antworten JSON und prüfen Login sowie Strukturrecht selbst.

| Aufruf | Zweck |
| --- | --- |
| `linkmap_tree&clang=1` | Kompletter Kategoriebaum |
| `linkmap_articles&category_id=5&clang=1` | Unterkategorien, Artikel, Breadcrumb |
| `linkmap_search&q=…&clang=1&domain=…` | Artikelsuche |
| `linkmap_history` | Zuletzt bearbeitete Artikel |
| `linkmap_favorites` (GET), `&action=toggle&category_id=5` (POST, CSRF) | Favoriten |
| `linkmap_article&ids=12,15&clang=1` | Artikel auflösen |
| `linkmap_source_browse&source=yform&container=rex_news&q=…&p=1&sort=title&dir=asc&clang=1` | Datensätze einer Tabelle (`mode=relation` ohne Freigabe) |
| `linkmap_link_schemes&link=yform://rex_news/42&clang=1` | URL-Schemata mit Vorschau |
| `linkmap_link_resolve&links=redaxo://5,yform://rex_news/3&clang=1` | Links zu Items |

### PHP-Klassen

| Klasse | Zweck |
| --- | --- |
| `Widget::render($name, $value, $options)` | Widget-Markup |
| `Link\LinkResolver` | `parse()`, `build()`, `url()`, `urlFor()`, `candidates()`, `replaceInHtml()`, `normalizeLegacy()` |
| `Link\SchemeRegistry`, `Link\SchemeProviderInterface`, `Link\Scheme` | URL-Schemata |
| `Source\SourceRegistry`, `Source\SourceProviderInterface` | Datensatz-Quellen |
| `TableConfig` | Konfiguration der freigegebenen Tabellen |
| `DemoTableset` | Optionales Demo-Tableset der Demo-Seite |

### Extension Points

| Name | Zweck |
| --- | --- |
| `LINKMAP_RESOLVE_URL` | URL für `yform://`-Links liefern, wenn kein Schema greift |
| `YFORM_ARTICLE_IS_IN_USE` (YForm) | Linkmap ergänzt seine Felder für die Löschsperre |

## Autor

**Friends Of REDAXO**

* http://www.redaxo.org
* https://github.com/FriendsOfREDAXO

**Projektleitung**

[Thomas Skerbis](https://github.com/skerbis)

**Danksagungen**

Dank an:

Vorbild für Overlay und Bedienung: das MediaPlace-Addon

URL-Auflösung: die Teams hinter dem url-Addon und virtual_urls

## Lizenz

MIT-Lizenz, siehe [LICENSE](LICENSE)
