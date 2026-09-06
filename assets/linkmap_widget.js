/**
 * Linkmap – Widget
 *
 * Ersetzt <input class="lm-widget"> automatisch durch eine Artikelauswahl
 * mit Namens-/Pfadanzeige, die das LM-Overlay nutzt. Der Wert im Input bleibt
 * die Artikel-ID (bei Mehrfachauswahl kommasepariert) -- damit ist das
 * Widget ein Drop-in fuer REX_LINK/REX_LINKLIST-artige Speicherung.
 *
 * Attribute:
 *   data-lm-multiple="true"   → Mehrfachauswahl (Reihenfolge per Pfeiltasten)
 *   data-lm-clang="1"         → Sprache fuer Anzeige/Overlay (Default: URL-clang)
 *   data-lm-category="5"      → Startkategorie im Overlay
 *   data-lm-domain="host.tld" → yrewrite-Domain-Filter vorbelegen
 *   data-lm-max="5"           → max. Anzahl bei Mehrfachauswahl
 *   data-lm-format="link"     → Wert als Link(s) statt ID(s): "redaxo://12",
 *                                "yform://rex_news/3?scheme=url:news-id" (kommasepariert).
 *                                Nur so sind Datensatz-Quellen moeglich.
 *   data-lm-sources="all"     → erlaubte Quellen (all oder Liste "article,yform"),
 *                                nur im Link-Format wirksam; Default: article
 *   data-lm-categories="true" → Kategorie-Picker: nur Kategorien, Wert = ID
 *                                des Startartikels (= Kategorie-ID)
 *   data-lm-table="rex_news"  → Relation-Modus: nur Datensaetze dieser YForm-
 *                                Tabelle, Wert = Datensatz-ID(s), keine Struktur.
 *                                Optional data-lm-table-label fuer die Anzeige.
 *
 * Beispiel:
 *   <input class="lm-widget" name="link" value="12">
 *   <input class="lm-widget" name="links" data-lm-multiple="true" value="12,15">
 */
(function () {
    'use strict';

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function t(key, vars) {
        return window.LM && typeof window.LM.t === 'function' ? window.LM.t(key, vars) : key;
    }

    // Label aus den Status-Definitionen (siehe statusInfo() in linkmap.js).
    function statusLabel(article) {
        var cfg = window.LM && typeof window.LM.config === 'function' ? window.LM.config() : null;
        var types = cfg && cfg.statusTypes ? cfg.statusTypes.article || [] : [];
        var status = typeof article.status === 'number' ? article.status : (article.online ? 1 : 0);
        return types[status] && types[status].label ? types[status].label : (status === 1 ? t('linkmap_status_online') : t('linkmap_status_offline'));
    }

    function parseIds(value) {
        return String(value || '').split(',').map(function (v) { return parseInt(v, 10); }).filter(function (v) { return v > 0; });
    }

    function parseLinks(value) {
        return String(value || '').split(',').map(function (v) { return v.trim(); }).filter(function (v) {
            return /^(redaxo:\/\/\d+|[a-z0-9_]+:\/\/[^\s,]+)$/i.test(v);
        });
    }

    function linkId(link) {
        var m = /^redaxo:\/\/(\d+)$/.exec(link);
        return m ? parseInt(m[1], 10) : 0;
    }

    function fireChange(input) {
        if (window.jQuery) {
            window.jQuery(input).trigger('change').trigger('rex:change', [window.jQuery(input)]);
            return;
        }
        var evt;
        try { evt = new Event('change', { bubbles: true }); }
        catch (e) { evt = document.createEvent('Event'); evt.initEvent('change', true, true); }
        input.dispatchEvent(evt);
    }

    function articleIcon(article) {
        if (!article) return 'fa-regular fa-file';
        if (article.source && article.source !== 'article') return 'fa-solid fa-table-list';
        if (article.sitestart) return 'fa-solid fa-house lm-icon-sitestart';
        if (article.startarticle) return 'fa-solid fa-folder-open lm-icon-start';
        return 'fa-regular fa-file';
    }

    function isDataset(item) {
        return !!(item && item.source && item.source !== 'article');
    }

    function itemHtml(key, article, multiple, index, total, linkFormat) {
        // article === undefined: noch nicht aufgeloest (Ladezustand),
        // article === null: Server kennt den Wert nicht / kein Zugriff.
        var pending = article === undefined;
        var id = linkFormat ? (article && article.id ? article.id : (linkId(key) || key)) : key;
        var name = article ? article.name : (pending ? t('linkmap_loading') : t('linkmap_widget_unknown', { id: id }));
        var meta = '';
        if (article && isDataset(article)) {
            meta = (article.containerLabel || article.container || '') + (linkFormat ? ' · ' + key : '') + (article.online ? '' : ' · ' + t('linkmap_status_offline'));
        } else if (article) {
            meta = (article.path.length ? article.path.join(' / ') : t('root_level')) + (article.online ? '' : ' · ' + statusLabel(article));
        } else if (linkFormat && !pending) {
            meta = key;
        }
        var domain = article && article.domain && !isDataset(article) ? '<span class="lm-w-domain">' + esc(article.domain) + '</span>' : '';
        var move = '';
        if (multiple) {
            move = '<button type="button" class="lm-w-btn" data-action="up" title="' + esc(t('linkmap_widget_move_up')) + '"' + (index === 0 ? ' disabled' : '') + '><i class="fa-solid fa-chevron-up"></i></button>' +
                '<button type="button" class="lm-w-btn" data-action="down" title="' + esc(t('linkmap_widget_move_down')) + '"' + (index === total - 1 ? ' disabled' : '') + '><i class="fa-solid fa-chevron-down"></i></button>';
        }
        return '<div class="lm-w-item' + (article && !article.online ? ' lm-w-offline' : '') + (article || pending ? '' : ' lm-w-unknown') + (pending ? ' lm-w-pending' : '') + '" data-key="' + esc(key) + '">' +
            '<i class="lm-w-icon ' + articleIcon(article) + '"></i>' +
            '<div class="lm-w-main">' +
                '<div class="lm-w-title"><span class="lm-w-name">' + esc(name) + '</span><span class="lm-w-id">' + esc(String(id)) + '</span>' + domain + '</div>' +
                (meta ? '<div class="lm-w-meta">' + esc(meta) + '</div>' : '') +
            '</div>' +
            '<div class="lm-w-actions">' +
                move +
                '<button type="button" class="lm-w-btn" data-action="remove" title="' + esc(t('linkmap_widget_clear')) + '"><i class="fa-solid fa-xmark"></i></button>' +
            '</div>' +
        '</div>';
    }

    function Widget(input) {
        this.input = input;
        this.multiple = input.getAttribute('data-lm-multiple') === 'true' || input.hasAttribute('data-lm-multiple') && input.getAttribute('data-lm-multiple') !== 'false';
        this.max = parseInt(input.getAttribute('data-lm-max'), 10) || 0;
        this.clang = parseInt(input.getAttribute('data-lm-clang'), 10) || 0;
        this.categoryId = parseInt(input.getAttribute('data-lm-category'), 10) || 0;
        this.domain = input.getAttribute('data-lm-domain') || '';
        this.table = (input.getAttribute('data-lm-table') || '').trim();
        this.tableLabel = input.getAttribute('data-lm-table-label') || this.table;
        this.relation = this.table !== '';
        this.categoriesOnly = !this.relation && input.getAttribute('data-lm-categories') === 'true';
        this.linkFormat = !this.relation && input.getAttribute('data-lm-format') === 'link';
        var sourcesAttr = (input.getAttribute('data-lm-sources') || '').trim();
        this.sources = !this.linkFormat ? ['article'] : (sourcesAttr === 'all' ? 'all' : (sourcesAttr ? sourcesAttr.split(',').map(function (v) { return v.trim(); }).filter(Boolean) : ['article']));
        this.cache = {};
        this.build();
        this.render();
        this.resolve();
    }

    Widget.prototype.build = function () {
        var input = this.input;
        input.type = 'hidden';
        input.classList.remove('form-control');
        var wrap = document.createElement('div');
        wrap.className = 'lm-w' + (this.multiple ? ' lm-w-multiple' : ' lm-w-single');
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var body = document.createElement('div');
        body.className = 'lm-w-body';
        wrap.appendChild(body);
        var footer = document.createElement('div');
        footer.className = 'lm-w-footer';
        wrap.appendChild(footer);
        this.wrap = wrap;
        this.body = body;
        this.footer = footer;

        var self = this;
        wrap.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            e.preventDefault();
            var action = btn.getAttribute('data-action');
            var item = btn.closest('.lm-w-item');
            var id = item ? (self.linkFormat ? item.getAttribute('data-key') : parseInt(item.getAttribute('data-key'), 10)) : 0;
            if (action === 'open') self.open();
            else if (action === 'remove') self.remove(id);
            else if (action === 'up') self.move(id, -1);
            else if (action === 'down') self.move(id, 1);
        });
    };

    // Schluessel der Eintraege: Artikel-ID (Standard) oder Link (data-lm-format="link").
    Widget.prototype.ids = function () {
        return this.linkFormat ? parseLinks(this.input.value) : parseIds(this.input.value);
    };

    Widget.prototype.setIds = function (ids) {
        var unique = [];
        ids.forEach(function (id) { if (unique.indexOf(id) === -1) unique.push(id); });
        this.input.value = unique.join(',');
        fireChange(this.input);
        this.render();
        this.resolve();
    };

    Widget.prototype.resolve = function () {
        var self = this;
        var missing = this.ids().filter(function (id) { return self.cache[id] === undefined; });
        if (!missing.length || !window.LM) return;
        var done = function (items) {
            items.forEach(function (a) {
                if (self.linkFormat) {
                    // Item unter dem gespeicherten Schluessel ablegen (inkl. ?scheme=...)
                    missing.forEach(function (key) {
                        if (key === a.link || key.split('?')[0] === a.link) self.cache[key] = a;
                    });
                } else {
                    self.cache[a.id] = a;
                }
            });
            // Server nicht erreichbar oder unbekannt: Ladezustand nicht ewig stehen lassen.
            missing.forEach(function (id) { if (self.cache[id] === undefined) self.cache[id] = null; });
            self.render();
        };
        var request;
        if (this.relation) {
            // Datensatz-IDs -> Items ueber den Link-Resolver der Quelle
            var table = this.table;
            request = window.LM.resolveLinks(missing.map(function (id) { return 'yform://' + table + '/' + id; }), this.clang || undefined).then(function (items) {
                return items.map(function (a) { return a; });
            });
        } else if (this.linkFormat) {
            request = window.LM.resolveLinks(missing, this.clang || undefined);
        } else {
            request = window.LM.resolve(missing, this.clang || undefined);
        }
        request.then(done).catch(function () { done([]); });
    };

    Widget.prototype.render = function () {
        var ids = this.ids();
        var self = this;
        if (!ids.length) {
            this.body.innerHTML = '<div class="lm-w-empty">' + esc(t('linkmap_widget_empty')) + '</div>';
        } else {
            this.body.innerHTML = ids.map(function (id, i) {
                return itemHtml(id, self.cache[id], self.multiple, i, ids.length, self.linkFormat);
            }).join('');
        }
        var full = this.multiple && this.max > 0 && ids.length >= this.max;
        var label = this.relation
            ? (this.multiple ? t('linkmap_widget_add_dataset') : t('linkmap_widget_open_dataset'))
            : (this.categoriesOnly
                ? (this.multiple ? t('linkmap_widget_add_category') : t('linkmap_widget_open_category'))
                : (this.multiple ? t('linkmap_widget_add') : t('linkmap_widget_open')));
        var showOpen = this.multiple || !ids.length;
        this.footer.innerHTML = showOpen
            ? '<button type="button" class="btn btn-default btn-xs lm-w-open" data-action="open"' + (full ? ' disabled' : '') + '><i class="fa-solid fa-link"></i> ' + esc(label) + '</button>'
            : '<button type="button" class="btn btn-default btn-xs lm-w-open" data-action="open"><i class="fa-solid fa-arrows-rotate"></i> ' + esc(this.relation ? t('linkmap_widget_open_dataset') : (this.categoriesOnly ? t('linkmap_widget_open_category') : t('linkmap_widget_open'))) + '</button>';
    };

    Widget.prototype.open = function () {
        if (!window.LM) return;
        var self = this;
        var options = { multiple: this.multiple, sources: this.sources };
        if (this.categoriesOnly) options.categoriesOnly = true;
        if (this.relation) {
            options.sources = ['yform'];
            options.container = { source: 'yform', id: this.table, label: this.tableLabel };
            options.lockContainer = true;
        }
        if (this.clang) options.clang = this.clang;
        if (this.categoryId) options.categoryId = this.categoryId;
        if (this.domain) options.domain = this.domain;
        if (!this.multiple) {
            var current = this.cache[this.ids()[0]];
            if (current && !this.relation && !this.categoryId && current.categoryId) options.categoryId = current.categoryId;
            window.LM.open(function (link, name, article) {
                var key = self.relation ? (article ? article.id : 0) : (self.linkFormat ? String(link) : linkId(String(link)));
                if (!key) return;
                if (article) self.cache[key] = article;
                self.setIds([key]);
            }, options);
            return;
        }
        window.LM.open(function (items) {
            var ids = self.ids();
            items.forEach(function (item) {
                if (self.max > 0 && ids.length >= self.max) return;
                var key = self.linkFormat ? item.link : item.id;
                if (self.relation) key = item.item ? item.item.id : item.id;
                if (!key) return;
                if (ids.indexOf(key) === -1) ids.push(key);
                if (item.item || item.article) self.cache[key] = item.item || item.article;
            });
            self.setIds(ids);
        }, options);
    };

    Widget.prototype.remove = function (id) {
        this.setIds(this.ids().filter(function (v) { return v !== id; }));
    };

    Widget.prototype.move = function (id, delta) {
        var ids = this.ids();
        var index = ids.indexOf(id);
        var target = index + delta;
        if (index === -1 || target < 0 || target >= ids.length) return;
        ids.splice(index, 1);
        ids.splice(target, 0, id);
        this.setIds(ids);
    };

    function init(ctx) {
        qsa('input.lm-widget', ctx).forEach(function (input) {
            if (input.__lmWidget) return;
            input.__lmWidget = new Widget(input);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
    if (window.jQuery) {
        window.jQuery(document).on('rex:ready', function (event, container) {
            init(container && container.get ? container.get(0) : document);
        });
    }

    window.LMWidget = { init: init };
})();
