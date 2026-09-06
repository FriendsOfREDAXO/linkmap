# Changelog

## 1.0.0

- Overlay-Picker als Ersatz für das klassische Linkmap-Popup (Strukturbaum mit Live-Filter, Artikelsuche, Verlauf, Favoriten, Mehrfachauswahl, Tastaturbedienung, Dark Mode)
- yrewrite-Domain-Filter (Kopfzeile), Domain-Badges im Baum, in Suche und Verlauf
- Umleitung von `openLinkMap()`, `openREXLinklist()` und `newLinkMapWindow()` inkl. Popup-kompatiblem Rückgabeobjekt (CKE5, TinyMCE, MForm, Builder)
- Takeover von `?page=linkmap` mit Nachbildung des klassischen Opener-Vertrags
- JS-API `window.LM`, Bridge `window.rex5LinkmapBridge`, Widget `<input class="lm-widget">`
- PHP-Widget-Renderer `FriendsOfRedaxo\Linkmap\Widget::render()` als gemeinsame Basis für YForm, MForm, Builder und eigene Module
- YForm-Werttyp `linkmap` (Einzel-/Mehrfachauswahl, Startkategorie, Domain; Speicherformat wie `be_link`, inkl. Löschsperre, Listenansicht und Suche)
- Rechte `linkmap[history]` und `linkmap[all_changes]`, Favoriten pro Benutzer
- `rex:selectLink`/Callbacks liefern nur den Artikelnamen (ohne „[ID]“); das Core-Format bleibt nur in REX_LINK_NAME/REX_LINKLIST
- Status-Erweiterungen (`ART_STATUS_TYPES`/`CAT_STATUS_TYPES`, z. B. „gesperrt“ aus accessdenied) mit eigenem Label und Icon
- Datensatz-Quellen: YForm-Tabellen im Picker (Sidebar „Datensätze“, tabellarische Listenansicht mit sortierbaren Spalten, Suche, Paginierung, Tabellenkonfiguration), `SourceProviderInterface`/`SourceRegistry` für weitere Quellen
- Linkformat `yform://tabelle/id[?scheme=…]`, Schema-Auswahl im Picker bei mehreren Profilen, Bridge `pickDataset()`
- `LinkResolver` mit `SchemeRegistry` (url-Addon, virtual_urls ab 1.2.0, Extension Point `LINKMAP_RESOLVE_URL`, URL-Template), Frontend-OUTPUT_FILTER für `yform://`, optional Altformate
- lm-widget und YForm-Werttyp mit Link-Format (`redaxo://`, `yform://`) und Quellenauswahl
- Relation-Modus: Widget `data-lm-table`, `Widget::render([... 'table' => ...])`, YForm-Werttyp `linkmap_relation`, Overlay-Option `lockContainer` (feste Tabelle, Datensatz-ID, keine Struktur)
- „Neuer Datensatz“ und „Datensatz bearbeiten“ (YForm-Formular im neuen Fenster, CSRF) sowie „Liste neu laden“ in der Tabellenansicht
- Demo nutzt zufällige vorhandene Artikel und eine bestehende YForm-Tabelle
- Verwaltung als Unterseiten von „System“ (System → Linkmap: Einstellungen, Demo, Hilfe), kein eigener Menüpunkt
- Demo-Seite neu aufgebaut; optionales Demo-Tableset „REDAXO-News“ mit Installieren/Entfernen, YForm-Beispiele erst danach
- Erlaubte URL-Schemata pro Tabelle (Datensatz-Quellen): nur angehakte Profile im Picker und bei der Auflösung
