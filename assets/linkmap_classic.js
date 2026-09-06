/**
 * Linkmap – Classic Interception + Bridge
 *
 * Biegt die globalen Funktionen aus structure/assets/linkmap.js
 * (openLinkMap, openREXLinklist, newLinkMapWindow) auf das Overlay um.
 * Anders als bei MediaPlace (mediaplace_classic.js) werden hier bewusst die
 * GLOBALS ueberschrieben, nicht nur Klicks abgefangen: CKE5, TinyMCE, MForm
 * und builder rufen openLinkMap()/openREXLinklist() direkt auf und haengen
 * per jQuery ein rex:selectLink an das zurueckgegebene Popup-Fenster. Statt
 * eines Fensters bekommen sie hier ein FakePopup-Objekt, das denselben
 * Vertrag erfuellt: jQuery-Events (rex:selectLink), close(), closed,
 * addEventListener('beforeunload') (MForm list-widget).
 *
 * Nur geladen, wenn die Einstellung "Klassische Linkmap ersetzen" aktiv ist
 * (boot.php). Definiert ausserdem window.rex5LinkmapBridge fuer neue
 * Aufrufer (gleiches Muster wie rex5MediaplaceBridge in mform/tinymce).
 */
(function () {
    'use strict';

    function FakePopup() {
        this.closed = false;
        this.name = 'linkmappopup';
        this._listeners = {};
    }
    FakePopup.prototype.addEventListener = function (type, fn) {
        (this._listeners[type] = this._listeners[type] || []).push(fn);
    };
    FakePopup.prototype.removeEventListener = function (type, fn) {
        if (!this._listeners[type]) return;
        this._listeners[type] = this._listeners[type].filter(function (f) { return f !== fn; });
    };
    FakePopup.prototype._emit = function (type) {
        (this._listeners[type] || []).forEach(function (fn) {
            try { fn({ type: type }); } catch (e) { if (window.console) console.error(e); }
        });
    };
    FakePopup.prototype.focus = function () {};
    FakePopup.prototype.close = function () {
        if (this.closed) return;
        this.closed = true;
        if (window.LM && window.LM.isOpen()) window.LM.close();
    };
    FakePopup.prototype._markClosed = function () {
        if (this.closed) return;
        this.closed = true;
        this._emit('beforeunload');
        this._emit('unload');
    };

    // "&clang=1&category_id=5" bzw. komplette URL -> Objekt
    function parseParams(str) {
        var out = {};
        String(str || '').replace(/^[^?]*\?/, '').split('&').forEach(function (pair) {
            if (!pair) return;
            var idx = pair.indexOf('=');
            var key = decodeURIComponent(idx === -1 ? pair : pair.slice(0, idx));
            var value = idx === -1 ? '' : decodeURIComponent(pair.slice(idx + 1).replace(/\+/g, ' '));
            out[key] = value;
        });
        return out;
    }

    function fireChange(el) {
        if (!el) return;
        if (window.jQuery) {
            window.jQuery(el).trigger('change');
            return;
        }
        var evt;
        try { evt = new Event('change', { bubbles: true }); }
        catch (e) { evt = document.createEvent('Event'); evt.initEvent('change', true, true); }
        el.dispatchEvent(evt);
    }

    // Core-Format "Name [ID]" fuer REX_LINK_*_NAME / REX_LINKLIST-Optionen
    // (rex_var_link::getWidget()); Editoren bekommen im Event nur den Namen.
    function classicLabel(name, link) {
        return name + ' [' + String(link).replace('redaxo://', '') + ']';
    }

    function optionsFromParams(params) {
        var options = {};
        if (params.clang) options.clang = parseInt(params.clang, 10);
        if (params.category_id) options.categoryId = parseInt(params.category_id, 10);
        if (params.domain) options.domain = params.domain;
        return options;
    }

    /**
     * Einzelauswahl (openLinkMap): rex:selectLink auf dem FakePopup
     * triggern; ohne preventDefault die Felder <id> und <id>_NAME setzen
     * (vgl. insertLink() in structure/pages/linkmap.php).
     */
    function openSingle(id, params) {
        var popup = new FakePopup();
        var options = optionsFromParams(params);
        options.onClose = function () { popup._markClosed(); };
        // Ohne Zielfeld (CKE5/TinyMCE/Fremdcode: openLinkMap('', ...)) landet
        // der Link als String im Editor -- dort sind Datensatz-Quellen erlaubt.
        // Mit Zielfeld (REX_LINK_n) wird eine Artikel-ID gespeichert: nur Artikel.
        if (!id) options.sources = 'all';

        window.LM.open(function (link, name) {
            var prevented = false;
            if (window.jQuery) {
                var event = window.jQuery.Event('rex:selectLink');
                window.jQuery(popup).trigger(event, [link, name]);
                prevented = event.isDefaultPrevented();
            }
            if (prevented || !id) return;
            var linkid = String(link).replace('redaxo://', '');
            var input = document.getElementById(id);
            var nameInput = document.getElementById(id + '_NAME');
            if (input) {
                input.value = linkid;
                fireChange(input);
            }
            if (nameInput) nameInput.value = classicLabel(name, link);
        }, options);

        return popup;
    }

    /** Mehrfachauswahl (openREXLinklist): Optionen an REX_LINKLIST_SELECT_<id> anhaengen. */
    function openList(id, params) {
        var popup = new FakePopup();
        var options = optionsFromParams(params);
        options.multiple = true;
        options.onClose = function () { popup._markClosed(); };

        window.LM.open(function (items) {
            var select = document.getElementById('REX_LINKLIST_SELECT_' + id);
            if (select) {
                items.forEach(function (item) {
                    var exists = Array.prototype.some.call(select.options, function (o) { return o.value === String(item.id); });
                    if (!exists) select.add(new Option(classicLabel(item.name, item.link), String(item.id)));
                });
                if (typeof window.writeREXLinklist === 'function') window.writeREXLinklist(id);
                fireChange(select);
            }
            if (window.jQuery) {
                window.jQuery(popup).trigger('rex:selectLinklist', [items]);
            }
        }, options);

        return popup;
    }

    var originalNewLinkMapWindow = null;

    function install() {
        if (!window.LM || typeof window.LM.open !== 'function') return;
        if (window.openLinkMap && window.openLinkMap.__linkmapOverlay) return;

        window.openLinkMap = function (id, param) {
            return openSingle(id || '', parseParams(param));
        };
        window.openLinkMap.__linkmapOverlay = true;

        window.openREXLinklist = function (id, param) {
            return openList(id, parseParams(param));
        };
        window.openREXLinklist.__linkmapOverlay = true;

        // TinyMCE (openMyLinkMap -> ?page=insertlink) und Fremdcode, der die
        // Linkmap-URL direkt baut: nur page=linkmap|insertlink umbiegen,
        // alles andere (z.B. mediapool-Popups) unveraendert durchreichen.
        if (typeof window.newLinkMapWindow === 'function' && !window.newLinkMapWindow.__linkmapOverlay) {
            originalNewLinkMapWindow = window.newLinkMapWindow;
        }
        window.newLinkMapWindow = function (link) {
            if (/[?&]page=(linkmap|insertlink)(&|$)/.test(String(link || ''))) {
                var params = parseParams(link);
                var field = params.opener_input_field || '';
                if (/^REX_LINKLIST_/.test(field)) {
                    return openList(field.slice('REX_LINKLIST_'.length), params);
                }
                return openSingle(field, params);
            }
            if (originalNewLinkMapWindow) return originalNewLinkMapWindow(link);
            return window.newWindow ? window.newWindow('linkmappopup', link, 1200, 800, ',status=yes,resizable=yes') : window.open(link);
        };
        window.newLinkMapWindow.__linkmapOverlay = true;
    }

    install();
    document.addEventListener('DOMContentLoaded', install);
    // structure/assets/linkmap.js ist JS_IMMUTABLE und kann in manchen
    // Konstellationen nach uns ausgewertet werden -- nach dem Laden erneut
    // sicherstellen, dass unsere Ueberschreibung gewinnt.
    window.addEventListener('load', install);

    /**
     * Bridge fuer neue Aufrufer (Editoren, Formbuilder): unabhaengig davon,
     * ob die Classic-Interception aktiv ist. onSelect(link, name, article)
     * bzw. bei options.multiple onSelect(items[]).
     */
    window.rex5LinkmapBridge = window.rex5LinkmapBridge || {
        isActive: function () {
            return !!(window.LM && typeof window.LM.open === 'function');
        },
        pick: function (onSelect, options) {
            window.LM.open(onSelect, options || {});
        },
        browse: function (options) {
            window.LM.open(null, options || {});
        },
        // Kategorie waehlen (Ergebnis: Startartikel der Kategorie, redaxo://ID)
        pickCategory: function (onSelect, options) {
            options = options || {};
            options.categoriesOnly = true;
            window.LM.open(onSelect, options);
        },
        // Datensatz einer YForm-Tabelle waehlen: onSelect(link, name, item) mit
        // link = "yform://tabelle/id[?scheme=...]". Ersetzt die bisherigen
        // rex_yform_manager_opener-Popups von CKE5/TinyMCE/MForm/Builder.
        pickDataset: function (table, onSelect, options) {
            options = options || {};
            options.sources = options.sources || ['yform'];
            options.container = { source: 'yform', id: table };
            window.LM.open(onSelect, options);
        },
        resolve: function (ids, clang) {
            return window.LM.resolve(ids, clang);
        }
    };
})();
