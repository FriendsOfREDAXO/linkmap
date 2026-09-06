/**
 * Linkmap – Overlay-Kern (window.LM)
 *
 * Ersetzt das klassische Linkmap-Popup durch ein Overlay mit Strukturbaum,
 * Live-Suche, Verlauf, Favoriten und yrewrite-Domain-Filter. Konfiguration
 * und Uebersetzungen kommen serverseitig aus boot.php (#lm-config,
 * #lm-i18n-data), die Daten aus den linkmap_*-Endpunkten (lib/Api).
 *
 * Oeffentliche API:
 *   LM.open(callback?, options?)   callback(link, name, article) bzw. bei
 *                                  options.multiple callback(items[]) mit
 *                                  items = [{ id, link, name, article }].
 *                                  Ohne callback: nur ansehen (Browse-only).
 *   LM.close()
 *   LM.isOpen()
 *   LM.on(event, fn) / LM.off()    Events: open, close, select
 *
 * options: clang, categoryId, domain, multiple, fullscreen, onClose,
 *          closeHref, title, selected (Array von IDs, multiple),
 *          sources ('all' | ['article', 'yform', ...]; Default ['article']),
 *          container ({ source, id, label }: direkt in diesem Container oeffnen),
 *          lockContainer (true: nur dieser Container, keine Struktur/Sidebar --
 *          Relation-Modus, Ergebnis wird als Datensatz-ID gespeichert; der
 *          Container muss dafuer nicht als Quelle freigegeben sein),
 *          categoriesOnly (true: Kategorie-Picker -- nur Kategorien in Liste
 *          und Suche, Ergebnis ist der Startartikel der Kategorie)
 *
 * Datensatz-Quellen (config.sources, siehe lib/Source) liefern Links wie
 * yform://tabelle/id; Aufrufer, die nur Artikel-IDs speichern (REX_LINK),
 * duerfen sie nicht freischalten.
 */
(function () {
    'use strict';

    var config = null;
    var dict = {};

    var state = {
        built: false,
        open: false,
        callback: null,
        options: {},
        multiple: false,
        browseOnly: false,
        clang: 1,
        domain: '',
        categoryId: 0,
        view: 'category',
        query: '',
        selected: [],
        treeCache: {},
        currentTree: null,
        favorites: [],
        history: [],
        rows: [],
        activeIndex: -1,
        hideOffline: false,
        collapsed: {},
        requestSeq: 0,
        pageScroll: 0,
        allowedSources: ['article'],
        sourceView: null,
        locked: false,
        extraContainer: null,
        categoriesOnly: false,
        currentCategory: null
    };

    var els = {};
    var listeners = {};
    var searchTimer = null;

    // ---------------------------------------------------------------- utils

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function t(key, vars) {
        var str = Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
        if (vars) {
            Object.keys(vars).forEach(function (k) {
                str = str.replace('{' + k + '}', vars[k]);
            });
        }
        return str;
    }

    function storageGet(key, fallback) {
        try {
            var raw = window.localStorage.getItem('lm.' + key);
            return raw === null ? fallback : JSON.parse(raw);
        } catch (e) {
            return fallback;
        }
    }

    function storageSet(key, value) {
        try { window.localStorage.setItem('lm.' + key, JSON.stringify(value)); } catch (e) { /* ignore */ }
    }

    function readJson(id) {
        var el = document.getElementById(id);
        if (!el) return null;
        try { return JSON.parse(el.textContent || '{}'); } catch (e) { return null; }
    }

    function ensureConfig() {
        if (config) return true;
        config = readJson('lm-config');
        dict = readJson('lm-i18n-data') || {};
        return !!config;
    }

    function withParams(url, params) {
        var parts = [];
        Object.keys(params || {}).forEach(function (k) {
            if (params[k] === undefined || params[k] === null || params[k] === '') return;
            parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
        });
        if (!parts.length) return url;
        return url + (url.indexOf('?') === -1 ? '?' : '&') + parts.join('&');
    }

    function api(url, params, postBody) {
        var init = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (postBody) {
            init.method = 'POST';
            init.headers['Content-Type'] = 'application/x-www-form-urlencoded';
            init.body = Object.keys(postBody).map(function (k) {
                return encodeURIComponent(k) + '=' + encodeURIComponent(postBody[k]);
            }).join('&');
        }
        return fetch(withParams(url, params), init).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
                if (data && data.error) throw new Error(data.error);
                return data;
            });
        });
    }

    function formatDate(ts) {
        if (!ts) return '';
        try {
            return new Date(ts * 1000).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
        } catch (e) {
            return new Date(ts * 1000).toLocaleString();
        }
    }

    function clangFromLocation() {
        try {
            var m = /[?&]clang=(\d+)/.exec(window.location.search);
            return m ? parseInt(m[1], 10) : 0;
        } catch (e) {
            return 0;
        }
    }

    function clangExists(id) {
        return (config.clangs || []).some(function (c) { return c.id === id; });
    }

    function emit(event, payload) {
        (listeners[event] || []).forEach(function (fn) {
            try { fn(payload); } catch (e) { if (window.console) console.error(e); }
        });
    }

    // Startartikel einer Kategorie und Startseite bekommen eigenes Icon und
    // eigene Farbe (CSS: .lm-icon-start / .lm-icon-sitestart), damit sie sich
    // in Liste, Suche und Verlauf von normalen Artikeln abheben.
    function articleIcon(article) {
        if (article.sitestart) return 'fa-solid fa-house lm-icon-sitestart';
        if (article.startarticle) return 'fa-solid fa-folder-open lm-icon-start';
        return 'fa-regular fa-file';
    }

    // Domain-Badge nur, wenn das Element NICHT auf der Standard-Domain
    // (Mountpoint 0) liegt -- sonst traegt jede Zeile dasselbe Badge.
    function showDomain(item) {
        return !!(item.domain && config.domains && config.domains.length && item.domain !== config.defaultDomain);
    }

    function matchesDomain(item) {
        if (!state.domain) return true;
        return (item.domain || '') === state.domain;
    }

    // Status-Anzeige aus den Server-Definitionen (ART_/CAT_STATUS_TYPES):
    // Status 1 = online, 0 = offline, alles weitere Erweiterungen wie
    // "gesperrt" (accessdenied). Unbekannte Werte fallen auf online/offline.
    function statusInfo(item, kind) {
        var types = (config.statusTypes && config.statusTypes[kind]) || [];
        var status = typeof item.status === 'number' ? item.status : (item.online ? 1 : 0);
        var type = types[status];
        return {
            status: status,
            label: type && type.label ? type.label : (status === 1 ? t('linkmap_status_online') : t('linkmap_status_offline')),
            icon: type && type.icon && status > 1 ? type.icon : ''
        };
    }

    function statusHtml(item, kind) {
        var info = statusInfo(item, kind);
        return '<span class="lm-state lm-state-' + info.status + '">' +
            (info.icon ? '<i class="' + esc(info.icon) + '"></i> ' : '<span class="lm-status-dot"></span>') +
            esc(info.label) + '</span>';
    }

    function passesFilters(item) {
        if (!matchesDomain(item)) return false;
        if (state.hideOffline && item.online === false) return false;
        return true;
    }

    // ---------------------------------------------------------------- build

    function build() {
        if (state.built) return;
        var root = document.getElementById('lm-root');
        if (!root) {
            root = document.createElement('div');
            root.id = 'lm-root';
            document.body.appendChild(root);
        }

        var domainOptions = '';
        if (config.domains && config.domains.length) {
            domainOptions = '<select class="lm-select lm-domain" title="' + esc(t('linkmap_domain')) + '">' +
                '<option value="">' + esc(t('linkmap_all_domains')) + '</option>' +
                config.domains.map(function (d) {
                    return '<option value="' + esc(d.name) + '">' + esc(d.name) + '</option>';
                }).join('') + '</select>';
        }

        var clangOptions = '';
        if (config.clangs && config.clangs.length > 1) {
            clangOptions = '<select class="lm-select lm-clang" title="' + esc(t('linkmap_language')) + '">' +
                config.clangs.map(function (c) {
                    return '<option value="' + c.id + '">' + esc(c.name) + ' (' + esc(c.code) + ')</option>';
                }).join('') + '</select>';
        }

        root.innerHTML =
            '<div id="lm-overlay" role="dialog" aria-modal="true" aria-label="' + esc(t('linkmap_title')) + '">' +
                '<div class="lm-modal">' +
                    '<div class="lm-header">' +
                        '<span class="lm-title"><i class="fa-solid fa-link"></i> <span class="lm-title-text">' + esc(t('linkmap_title')) + '</span></span>' +
                        '<div class="lm-header-tools">' +
                            '<button type="button" class="lm-mobile-sidebar-btn" title="' + esc(t('linkmap_sidebar_structure')) + '"><i class="fa-solid fa-folder-tree"></i></button>' +
                            domainOptions +
                            clangOptions +
                            '<div class="lm-search-wrap">' +
                                '<i class="fa-solid fa-magnifying-glass"></i>' +
                                '<input type="text" class="lm-search" placeholder="' + esc(t('linkmap_search_placeholder')) + '" autocomplete="off">' +
                                '<button type="button" class="lm-search-clear" title="' + esc(t('linkmap_cancel')) + '"><i class="fa-solid fa-xmark"></i></button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="lm-header-actions">' +
                            '<button type="button" class="lm-fullscreen-toggle" title="' + esc(t('linkmap_fullscreen')) + '"><i class="fa-solid fa-expand"></i></button>' +
                            '<button type="button" class="lm-close" title="' + esc(t('linkmap_close')) + '"><i class="fa-solid fa-xmark"></i></button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="lm-body">' +
                        '<div class="lm-sidebar">' +
                            '<div class="lm-sb-section lm-sb-structure" data-section="structure">' +
                                '<button type="button" class="lm-sb-toggle"><i class="fa-solid fa-chevron-down"></i> <i class="fa-solid fa-folder-tree"></i> ' + esc(t('linkmap_sidebar_structure')) + '</button>' +
                                '<div class="lm-sb-body">' +
                                    '<div class="lm-filter-wrap"><i class="fa-solid fa-filter"></i><input type="text" class="lm-filter" placeholder="' + esc(t('linkmap_filter_placeholder')) + '" autocomplete="off"></div>' +
                                    '<div class="lm-tree"></div>' +
                                '</div>' +
                            '</div>' +
                            '<div class="lm-sb-section" data-section="favorites">' +
                                '<button type="button" class="lm-sb-toggle"><i class="fa-solid fa-chevron-down"></i> <i class="fa-regular fa-star"></i> ' + esc(t('linkmap_sidebar_favorites')) + '</button>' +
                                '<div class="lm-sb-body lm-favorites"></div>' +
                            '</div>' +
                            '<div class="lm-sb-section" data-section="sources" hidden>' +
                                '<button type="button" class="lm-sb-toggle"><i class="fa-solid fa-chevron-down"></i> <i class="fa-solid fa-database"></i> ' + esc(t('linkmap_sidebar_sources')) + '</button>' +
                                '<div class="lm-sb-body lm-sources"></div>' +
                            '</div>' +
                            '<div class="lm-sb-section" data-section="history" hidden>' +
                                '<button type="button" class="lm-sb-toggle"><i class="fa-solid fa-chevron-down"></i> <i class="fa-regular fa-clock"></i> ' + esc(t('linkmap_sidebar_history')) + '</button>' +
                                '<div class="lm-sb-body lm-history"></div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="lm-sidebar-backdrop"></div>' +
                        '<div class="lm-content">' +
                            '<div class="lm-breadcrumb"></div>' +
                            '<div class="lm-list" tabindex="0"></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="lm-footer">' +
                        '<span class="lm-status"></span>' +
                        '<label class="lm-hide-offline"><input type="checkbox" class="lm-hide-offline-input"> ' + esc(t('linkmap_hide_offline')) + '</label>' +
                        '<div class="lm-footer-actions">' +
                            '<span class="lm-sel-count" hidden></span>' +
                            '<button type="button" class="lm-btn lm-btn-cancel">' + esc(t('linkmap_cancel')) + '</button>' +
                            '<button type="button" class="lm-btn lm-btn-primary lm-apply" hidden><i class="fa-solid fa-check"></i> ' + esc(t('linkmap_apply')) + '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        els.root = root;
        els.overlay = qs('#lm-overlay', root);
        els.modal = qs('.lm-modal', root);
        els.titleText = qs('.lm-title-text', root);
        els.domain = qs('.lm-domain', root);
        els.clang = qs('.lm-clang', root);
        els.search = qs('.lm-search', root);
        els.searchClear = qs('.lm-search-clear', root);
        els.sidebar = qs('.lm-sidebar', root);
        els.sidebarBackdrop = qs('.lm-sidebar-backdrop', root);
        els.favorites = qs('.lm-favorites', root);
        els.historySection = qs('[data-section="history"]', root);
        els.sourcesSection = qs('[data-section="sources"]', root);
        els.sources = qs('.lm-sources', root);
        els.history = qs('.lm-history', root);
        els.filter = qs('.lm-filter', root);
        els.tree = qs('.lm-tree', root);
        els.breadcrumb = qs('.lm-breadcrumb', root);
        els.list = qs('.lm-list', root);
        els.status = qs('.lm-status', root);
        els.hideOffline = qs('.lm-hide-offline-input', root);
        els.selCount = qs('.lm-sel-count', root);
        els.apply = qs('.lm-apply', root);
        els.cancel = qs('.lm-btn-cancel', root);

        state.hideOffline = !!storageGet('hideOffline', false);
        state.collapsed = storageGet('collapsed', null);
        if (!state.collapsed) state.collapsed = { history: true };
        els.hideOffline.checked = state.hideOffline;
        Object.keys(state.collapsed).forEach(function (name) {
            var section = qs('[data-section="' + name + '"]', root);
            if (section && state.collapsed[name]) section.classList.add('lm-sb-collapsed');
        });

        bindEvents();
        state.built = true;
    }

    function bindEvents() {
        els.overlay.addEventListener('click', function (e) {
            if (e.target === els.overlay) close();
        });
        qs('.lm-close', els.root).addEventListener('click', function () { close(); });
        els.cancel.addEventListener('click', function () { close(); });
        qs('.lm-fullscreen-toggle', els.root).addEventListener('click', function () {
            els.modal.classList.toggle('lm-fullscreen');
        });
        qs('.lm-mobile-sidebar-btn', els.root).addEventListener('click', function () {
            els.sidebar.classList.toggle('lm-sidebar-open');
        });
        els.sidebarBackdrop.addEventListener('click', function () {
            els.sidebar.classList.remove('lm-sidebar-open');
        });

        qsa('.lm-sb-toggle', els.root).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var section = btn.closest('.lm-sb-section');
                var name = section.getAttribute('data-section');
                var collapsed = section.classList.toggle('lm-sb-collapsed');
                state.collapsed[name] = collapsed;
                storageSet('collapsed', state.collapsed);
            });
        });

        if (els.domain) {
            els.domain.addEventListener('change', function () {
                state.domain = els.domain.value;
                renderTree();
                renderFavorites();
                renderHistory();
                // In die Mountpoint-Kategorie der Domain springen, damit der
                // Inhalt direkt die Domain zeigt (Suche bleibt bestehen).
                var domain = (config.domains || []).filter(function (d) { return d.name === state.domain; })[0];
                if (state.view !== 'search') {
                    openCategory(domain && domain.mountId > 0 ? domain.mountId : 0);
                } else {
                    refreshView();
                }
            });
        }
        if (els.clang) {
            els.clang.addEventListener('change', function () {
                state.clang = parseInt(els.clang.value, 10);
                state.currentTree = null;
                loadTree().then(function () {
                    renderFavorites();
                    refreshView();
                });
                loadFavorites();
            });
        }

        els.search.addEventListener('input', function () {
            var q = els.search.value.trim();
            els.searchClear.hidden = q === '';
            clearTimeout(searchTimer);
            if (state.sourceView) {
                // Suche innerhalb des aktiven Datensatz-Containers (serverseitig)
                searchTimer = setTimeout(function () { loadContainer(q, 1, false); }, 220);
                return;
            }
            if (q === '') {
                state.query = '';
                state.view = 'category';
                openCategory(state.categoryId);
                return;
            }
            searchTimer = setTimeout(function () { runSearch(q); }, 220);
        });
        els.search.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                els.list.focus();
                moveActive(1);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (state.activeIndex < 0 && state.rows.length) setActive(0);
                activateRow();
            }
        });
        els.searchClear.addEventListener('click', function () {
            els.search.value = '';
            els.searchClear.hidden = true;
            state.query = '';
            if (state.sourceView) {
                loadContainer('', 1, false);
            } else {
                state.view = 'category';
                openCategory(state.categoryId);
            }
            els.search.focus();
        });

        els.filter.addEventListener('input', function () { renderTree(); });

        els.hideOffline.addEventListener('change', function () {
            state.hideOffline = els.hideOffline.checked;
            storageSet('hideOffline', state.hideOffline);
            renderTree();
            renderHistory();
            refreshView();
        });

        els.apply.addEventListener('click', function () {
            if (state.categoriesOnly && state.callback && !state.multiple) {
                if (state.currentCategory) {
                    var item = categoryAsItem(state.currentCategory);
                    articleCache[item.clang + ':' + item.id] = item;
                    finishSingle(item.link, item.label, item);
                }
                return;
            }
            applyMultiple();
        });

        // Delegation: Sidebar
        els.sidebar.addEventListener('click', function (e) {
            var favToggle = e.target.closest('.lm-fav-toggle');
            if (favToggle) {
                e.preventDefault();
                e.stopPropagation();
                toggleFavorite(parseInt(favToggle.getAttribute('data-id'), 10));
                return;
            }
            var toggle = e.target.closest('.lm-tree-toggle');
            if (toggle) {
                e.preventDefault();
                var node = toggle.closest('.lm-tree-node');
                node.classList.toggle('lm-expanded');
                return;
            }
            var containerLink = e.target.closest('[data-container]');
            if (containerLink) {
                e.preventDefault();
                openContainer(containerLink.getAttribute('data-source'), containerLink.getAttribute('data-container'));
                els.sidebar.classList.remove('lm-sidebar-open');
                return;
            }
            var link = e.target.closest('[data-cat]');
            if (link) {
                e.preventDefault();
                els.search.value = '';
                els.searchClear.hidden = true;
                state.query = '';
                state.view = 'category';
                openCategory(parseInt(link.getAttribute('data-cat'), 10));
                els.sidebar.classList.remove('lm-sidebar-open');
                return;
            }
            var histRow = e.target.closest('.lm-hist-item');
            if (histRow) {
                e.preventDefault();
                var article = state.history.filter(function (a) {
                    return a.id === parseInt(histRow.getAttribute('data-id'), 10) && a.clang === parseInt(histRow.getAttribute('data-clang'), 10);
                })[0];
                if (article) chooseArticle(article, e);
            }
        });

        // Delegation: Content
        els.list.addEventListener('click', function (e) {
            // Bearbeiten-Link: echter Link ins neue Fenster, keine Zeilenauswahl
            if (e.target.closest('[data-action="edit-dataset"]')) {
                e.stopPropagation();
                return;
            }
            var schemeBtn = e.target.closest('.lm-scheme-option');
            if (schemeBtn) {
                e.preventDefault();
                var chooser = schemeBtn.closest('.lm-scheme-chooser');
                var item = chooser ? datasetCache[chooser.getAttribute('data-link')] : null;
                if (item) finishSingle(schemeBtn.getAttribute('data-link'), item.label, item);
                return;
            }
            if (e.target.closest('.lm-scheme-cancel')) {
                e.preventDefault();
                closeSchemeChooser();
                return;
            }
            var sortTh = e.target.closest('[data-sort]');
            if (sortTh) {
                e.preventDefault();
                sortContainer(sortTh.getAttribute('data-sort'));
                return;
            }
            var more = e.target.closest('.lm-load-more');
            if (more) {
                e.preventDefault();
                if (state.sourceView) loadContainer(state.sourceView.query, state.sourceView.page + 1, true);
                return;
            }
            var pickCat = e.target.closest('[data-action="pick-category"]');
            if (pickCat) {
                e.preventDefault();
                e.stopPropagation();
                var catRow = pickCat.closest('.lm-row-category');
                if (catRow) pickCategory(catRow);
                return;
            }
            var row = e.target.closest('.lm-row');
            if (!row) return;
            e.preventDefault();
            var index = state.rows.indexOf(row);
            if (index >= 0) setActive(index);
            activateRow(e, row);
        });
        els.list.addEventListener('dblclick', function (e) {
            var row = e.target.closest('.lm-row-article');
            if (!row || !state.multiple) return;
            var article = rowArticle(row);
            if (!article) return;
            // Doppelklick in Mehrfachauswahl: sofort uebernehmen
            if (!isSelected(article.id)) toggleSelected(article);
            applyMultiple();
        });
        els.list.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(-1); }
            else if (e.key === 'Enter') { e.preventDefault(); activateRow(e); }
            else if (e.key === ' ' && state.multiple) {
                e.preventDefault();
                var row = state.rows[state.activeIndex];
                if (row && row.classList.contains('lm-row-category')) { pickCategory(row); return; }
                var picked = row ? (row.classList.contains('lm-row-dataset') ? datasetCache[row.getAttribute('data-link')] : rowArticle(row)) : null;
                if (picked) toggleSelected(picked);
            } else if (e.key === 'Backspace') {
                e.preventDefault();
                var crumbs = qsa('.lm-bc-item', els.breadcrumb);
                if (crumbs.length > 1) openCategory(parseInt(crumbs[crumbs.length - 2].getAttribute('data-cat'), 10));
            }
        });

        els.breadcrumb.addEventListener('click', function (e) {
            var action = e.target.closest('[data-action]');
            if (action) {
                e.preventDefault();
                if (action.getAttribute('data-action') === 'add-dataset') {
                    window.open(action.getAttribute('data-url'), '_blank', 'noopener');
                } else if (action.getAttribute('data-action') === 'reload' && state.sourceView) {
                    loadContainer(state.sourceView.query, 1, false);
                }
                return;
            }
            var item = e.target.closest('[data-cat]');
            if (!item) return;
            e.preventDefault();
            openCategory(parseInt(item.getAttribute('data-cat'), 10));
        });

        document.addEventListener('keydown', function (e) {
            if (!state.open || e.key !== 'Escape') return;
            e.preventDefault();
            if (qs('.lm-scheme-chooser', els.list)) { closeSchemeChooser(); return; }
            close();
        });
    }

    // ---------------------------------------------------------------- open/close

    function open(callback, options) {
        if (typeof callback === 'object' && callback !== null && options === undefined) {
            options = callback;
            callback = null;
        }
        options = options || {};
        if (!ensureConfig()) {
            if (window.console) console.error('Linkmap: config missing (#lm-config)');
            return;
        }
        if (!config.canPick) return;
        build();

        state.callback = typeof callback === 'function' ? callback : null;
        state.options = options;
        state.multiple = !!options.multiple && !!state.callback;
        state.browseOnly = !state.callback;
        state.selected = [];
        state.treeCache = {};
        state.currentTree = null;
        state.query = '';
        state.view = 'category';
        state.rows = [];
        state.activeIndex = -1;

        var clang = parseInt(options.clang, 10);
        if (!clang || !clangExists(clang)) clang = clangFromLocation();
        if (!clang || !clangExists(clang)) clang = config.currentClang;
        if (!clangExists(clang) && config.clangs.length) clang = config.clangs[0].id;
        state.clang = clang;
        if (els.clang) els.clang.value = String(clang);

        state.domain = typeof options.domain === 'string' ? options.domain : '';
        if (els.domain) {
            var known = (config.domains || []).some(function (d) { return d.name === state.domain; });
            if (!known) state.domain = '';
            els.domain.value = state.domain;
        }

        state.categoryId = parseInt(options.categoryId, 10) || 0;
        state.sourceView = null;
        state.locked = !!(options.lockContainer && options.container && options.container.source && options.container.id);
        state.extraContainer = state.locked ? { source: options.container.source, id: options.container.id, label: options.container.label || options.container.id } : null;
        els.modal.classList.toggle('lm-mode-locked', state.locked);
        if (els.domain) els.domain.hidden = state.locked;
        state.categoriesOnly = !!options.categoriesOnly && !state.locked;
        els.modal.classList.toggle('lm-mode-categories', state.categoriesOnly);
        if (state.categoriesOnly) options.sources = ['article'];
        state.allowedSources = options.sources === 'all'
            ? ['article'].concat((config.sources || []).map(function (src) { return src.id; }))
            : (Array.isArray(options.sources) && options.sources.length ? options.sources : ['article']);
        renderSources();

        if (Array.isArray(options.selected) && options.selected.length && state.multiple) {
            window.LM.resolve(options.selected, state.clang).then(function (articles) {
                if (!state.open || !state.multiple) return;
                state.selected = articles;
                updateSelectionUi();
                refreshView();
            });
        }

        els.titleText.textContent = options.title || t('linkmap_title');
        // Alte Liste/Breadcrumb sofort leeren -- sonst bleiben bis zur Antwort
        // die Zeilen der letzten Sitzung sichtbar und klickbar (state.rows ist
        // aber bereits zurueckgesetzt).
        els.list.innerHTML = '<div class="lm-muted lm-list-loading">' + esc(t('linkmap_loading')) + '</div>';
        els.breadcrumb.innerHTML = '';
        els.status.textContent = '';
        els.search.value = '';
        els.searchClear.hidden = true;
        els.filter.value = '';

        els.modal.classList.toggle('lm-fullscreen', !!options.fullscreen);
        els.modal.classList.toggle('lm-mode-multiple', state.multiple);
        els.modal.classList.toggle('lm-mode-browse', state.browseOnly);
        els.modal.classList.toggle('lm-mode-pick', !!state.callback);
        els.apply.hidden = !state.multiple && !(state.categoriesOnly && state.callback);
        els.selCount.hidden = !state.multiple;
        updateSelectionUi();

        els.historySection.hidden = !config.canHistory || state.categoriesOnly;

        // Scrollposition der darunterliegenden Seite merken -- das
        // Scroll-Lock auf <body> darf sie nicht verlieren (lange Modulformulare).
        state.pageScroll = window.pageYOffset || document.documentElement.scrollTop || 0;
        els.root.hidden = false;
        els.overlay.classList.add('lm-open');
        document.documentElement.classList.add('lm-scroll-lock');
        state.open = true;

        if (state.locked) {
            // Relation-Modus: nur dieser Container, kein Strukturbaum noetig
            openContainer(options.container.source, options.container.id);
        } else {
            loadTree().then(function () {
                if (options.container && options.container.source && options.container.id) {
                    openContainer(options.container.source, options.container.id);
                } else {
                    openCategory(state.categoryId);
                }
            });
            loadFavorites();
            if (config.canHistory) loadHistory();
        }

        setTimeout(function () { els.search.focus(); }, 30);
        emit('open', { options: options });
    }

    function close() {
        if (!state.open) return;
        state.open = false;
        clearTimeout(searchTimer);
        els.overlay.classList.remove('lm-open');
        els.root.hidden = true;
        document.documentElement.classList.remove('lm-scroll-lock');
        els.sidebar.classList.remove('lm-sidebar-open');
        var pageScroll = state.pageScroll;
        window.scrollTo(0, pageScroll);
        window.requestAnimationFrame(function () { window.scrollTo(0, pageScroll); });
        var options = state.options || {};
        state.callback = null;
        emit('close', {});
        if (typeof options.onClose === 'function') {
            try { options.onClose(); } catch (e) { if (window.console) console.error(e); }
        }
        if (options.closeHref && !window.opener) {
            window.location.href = options.closeHref;
        }
    }

    // ---------------------------------------------------------------- data

    function loadTree() {
        if (state.treeCache[state.clang]) {
            state.currentTree = state.treeCache[state.clang];
            renderTree();
            return Promise.resolve(state.currentTree);
        }
        els.tree.innerHTML = '<div class="lm-muted">' + esc(t('linkmap_loading')) + '</div>';
        return api(config.urls.tree, { clang: state.clang }).then(function (data) {
            state.treeCache[state.clang] = data;
            state.currentTree = data;
            renderTree();
            return data;
        }).catch(function (err) {
            els.tree.innerHTML = '<div class="lm-error">' + esc(err.message || t('linkmap_error')) + '</div>';
            return null;
        });
    }

    function loadFavorites() {
        return api(config.urls.favorites, { clang: state.clang }).then(function (data) {
            state.favorites = data.favorites || [];
            renderFavorites();
        }).catch(function () {
            state.favorites = [];
            renderFavorites();
        });
    }

    function toggleFavorite(categoryId) {
        var body = Object.assign({ action: 'toggle', category_id: categoryId, clang: state.clang }, config.csrf || {});
        api(config.urls.favorites, {}, body).then(function (data) {
            state.favorites = data.favorites || [];
            renderFavorites();
            renderTree();
        }).catch(function (err) {
            if (window.console) console.error('Linkmap favorites:', err);
        });
    }

    function loadHistory() {
        els.history.innerHTML = '<div class="lm-muted">' + esc(t('linkmap_loading')) + '</div>';
        return api(config.urls.history, {}).then(function (data) {
            state.history = data.articles || [];
            renderHistory();
        }).catch(function (err) {
            els.history.innerHTML = '<div class="lm-error">' + esc(err.message || t('linkmap_error')) + '</div>';
        });
    }

    function openCategory(categoryId) {
        state.categoryId = categoryId || 0;
        state.currentCategory = null;
        state.view = 'category';
        state.sourceView = null;
        highlightSource(null, null);
        els.search.placeholder = t(state.categoriesOnly ? 'linkmap_search_categories_placeholder' : 'linkmap_search_placeholder');
        var seq = ++state.requestSeq;
        els.list.innerHTML = '<div class="lm-muted lm-list-loading">' + esc(t('linkmap_loading')) + '</div>';
        highlightTree(state.categoryId);
        api(config.urls.articles, { category_id: state.categoryId, clang: state.clang }).then(function (data) {
            if (seq !== state.requestSeq) return;
            state.categoryId = data.categoryId;
            state.currentCategory = data.category || null;
            highlightTree(state.categoryId);
            renderBreadcrumb(data.breadcrumb || []);
            renderCategoryList(data);
            updateSelectionUi();
        }).catch(function (err) {
            if (seq !== state.requestSeq) return;
            els.list.innerHTML = '<div class="lm-error">' + esc(err.message || t('linkmap_error')) + '</div>';
        });
    }

    function refreshView() {
        if (state.sourceView) {
            loadContainer(state.sourceView.query, 1, false);
            return;
        }
        if (state.view === 'search' && state.query) {
            runSearch(state.query);
        } else {
            openCategory(state.categoryId);
        }
    }

    function runSearch(query) {
        state.query = query;
        state.view = 'search';
        var seq = ++state.requestSeq;
        els.list.innerHTML = '<div class="lm-muted lm-list-loading">' + esc(t('linkmap_loading')) + '</div>';
        api(config.urls.search, { q: query, clang: state.clang, domain: state.domain }).then(function (data) {
            if (seq !== state.requestSeq) return;
            renderBreadcrumb(null, t('linkmap_search_results', { query: query }));
            renderSearchResults(data);
        }).catch(function (err) {
            if (seq !== state.requestSeq) return;
            els.list.innerHTML = '<div class="lm-error">' + esc(err.message || t('linkmap_error')) + '</div>';
        });
    }

    // ---------------------------------------------------------------- render: sidebar

    function isFavorite(id) {
        return state.favorites.some(function (f) { return f.id === id; });
    }

    function renderFavorites() {
        var items = state.favorites.filter(matchesDomain);
        if (!items.length) {
            els.favorites.innerHTML = '<div class="lm-muted">' + esc(t('linkmap_favorites_empty')) + '</div>';
            return;
        }
        els.favorites.innerHTML = '<ul class="lm-sb-list">' + items.map(function (cat) {
            return '<li>' +
                '<a href="#" class="lm-sb-link' + (cat.id === state.categoryId ? ' lm-current' : '') + '" data-cat="' + cat.id + '" title="' + esc(cat.path.concat([cat.name]).join(' / ')) + '">' +
                    '<i class="fa-solid fa-folder"></i> <span class="lm-sb-name">' + esc(cat.name) + '</span>' +
                    (cat.id ? '<span class="lm-id">' + cat.id + '</span>' : '') +
                    (showDomain(cat) ? '<span class="lm-domain-badge">' + esc(cat.domain) + '</span>' : '') +
                '</a>' +
                '<button type="button" class="lm-fav-toggle lm-fav-active" data-id="' + cat.id + '" title="' + esc(t('linkmap_favorite_remove')) + '"><i class="fa-solid fa-star"></i></button>' +
            '</li>';
        }).join('') + '</ul>';
    }

    function renderHistory() {
        if (!config.canHistory) return;
        var items = state.history.filter(passesFilters);
        if (!items.length) {
            els.history.innerHTML = '<div class="lm-muted">' + esc(t('linkmap_history_empty')) + '</div>';
            return;
        }
        var multiClang = (config.clangs || []).length > 1;
        els.history.innerHTML = '<ul class="lm-sb-list">' + items.map(function (a) {
            return '<li>' +
                '<a href="#" class="lm-sb-link lm-hist-item' + (a.online ? '' : ' lm-offline') + '" data-id="' + a.id + '" data-clang="' + a.clang + '" title="' + esc(a.path.join(' / ')) + '">' +
                    '<i class="' + articleIcon(a) + '"></i> <span class="lm-sb-name">' + esc(a.name) + '</span>' +
                    '<span class="lm-id">' + a.id + '</span>' +
                    '<span class="lm-sb-meta">' + (multiClang ? '<span class="lm-clang-badge">' + esc(a.clangCode) + '</span> ' : '') +
                        (a.online ? '' : statusHtml(a, 'article') + ' · ') +
                        esc(a.updateuser) + ' · ' + esc(formatDate(a.updatedate)) +
                        (showDomain(a) ? ' · <i class="fa-solid fa-globe"></i> ' + esc(a.domain) : '') +
                    '</span>' +
                '</a>' +
            '</li>';
        }).join('') + '</ul>';
    }

    function nodeMatches(node, filter) {
        if (!filter) return true;
        return node.name.toLowerCase().indexOf(filter) !== -1
            || String(node.id) === filter
            || (node.domain && node.domain.toLowerCase().indexOf(filter) !== -1);
    }

    /** Baum mit Domain-/Offline-/Textfilter; Rueckgabe null wenn nichts uebrig. */
    function filterTree(nodes, filter) {
        var result = [];
        nodes.forEach(function (node) {
            if (state.hideOffline && node.online === false) return;
            var children = filterTree(node.children || [], filter);
            var selfDomain = matchesDomain(node);
            var selfMatch = nodeMatches(node, filter);
            if ((selfDomain && selfMatch) || children.length) {
                result.push({ node: node, children: children, self: selfDomain && selfMatch, forced: !!filter && children.length > 0 });
            }
        });
        return result;
    }

    function renderTreeNodes(entries, depth, filterActive) {
        return '<ul class="lm-tree-list" data-depth="' + depth + '">' + entries.map(function (entry) {
            var node = entry.node;
            var hasChildren = entry.children.length > 0;
            var expanded = filterActive ? hasChildren : false;
            return '<li class="lm-tree-node' + (expanded ? ' lm-expanded' : '') + (node.online ? '' : ' lm-offline') + '" data-id="' + node.id + '">' +
                '<div class="lm-tree-row">' +
                    (hasChildren
                        ? '<button type="button" class="lm-tree-toggle" tabindex="-1"><i class="fa-solid fa-chevron-right"></i></button>'
                        : '<span class="lm-tree-toggle lm-tree-leaf"></span>') +
                    '<a href="#" class="lm-tree-link" data-cat="' + node.id + '" title="' + esc(node.name) + ' [' + node.id + ']' + (showDomain(node) ? ' · ' + esc(node.domain) : '') + '">' +
                        '<i class="fa-solid fa-folder lm-tree-icon"></i> <span class="lm-tree-name">' + esc(node.name) + '</span>' +
                        (statusInfo(node, 'category').icon ? '<i class="lm-tree-status ' + esc(statusInfo(node, 'category').icon) + '" title="' + esc(statusInfo(node, 'category').label) + '"></i>' : '') +
                        '<span class="lm-id">' + node.id + '</span>' +
                    '</a>' +
                    '<button type="button" class="lm-fav-toggle' + (isFavorite(node.id) ? ' lm-fav-active' : '') + '" data-id="' + node.id + '" tabindex="-1" title="' + esc(isFavorite(node.id) ? t('linkmap_favorite_remove') : t('linkmap_favorite_add')) + '"><i class="' + (isFavorite(node.id) ? 'fa-solid' : 'fa-regular') + ' fa-star"></i></button>' +
                '</div>' +
                (hasChildren ? renderTreeNodes(entry.children, depth + 1, filterActive) : '') +
            '</li>';
        }).join('') + '</ul>';
    }

    function renderTree() {
        if (!state.currentTree) return;
        var filter = (els.filter.value || '').trim().toLowerCase();
        var entries = filterTree(state.currentTree.tree || [], filter);
        var html = '';
        if (state.currentTree.rootAccess && !state.domain && (!filter || nodeMatches({ name: t('root_level'), id: 0, domain: '' }, filter))) {
            html += '<div class="lm-tree-root-row"><a href="#" class="lm-tree-link lm-tree-root" data-cat="0"><i class="fa-solid fa-house-chimney lm-tree-icon"></i> <span class="lm-tree-name">' + esc(t('root_level')) + '</span></a>' +
                '<button type="button" class="lm-fav-toggle' + (isFavorite(0) ? ' lm-fav-active' : '') + '" data-id="0" tabindex="-1" title="' + esc(isFavorite(0) ? t('linkmap_favorite_remove') : t('linkmap_favorite_add')) + '"><i class="' + (isFavorite(0) ? 'fa-solid' : 'fa-regular') + ' fa-star"></i></button></div>';
        }
        html += entries.length ? renderTreeNodes(entries, 0, !!filter) : '<div class="lm-muted">' + esc(t('linkmap_no_results')) + '</div>';
        els.tree.innerHTML = html;
        highlightTree(state.categoryId);
    }

    function highlightTree(categoryId) {
        qsa('.lm-tree-link.lm-current, .lm-sb-link.lm-current', els.sidebar).forEach(function (el) { el.classList.remove('lm-current'); });
        qsa('[data-cat="' + categoryId + '"]', els.sidebar).forEach(function (el) { el.classList.add('lm-current'); });
        var node = qs('.lm-tree-node[data-id="' + categoryId + '"]', els.tree);
        if (!node) return;
        var parent = node.parentElement;
        while (parent && parent !== els.tree) {
            if (parent.classList.contains('lm-tree-node')) parent.classList.add('lm-expanded');
            parent = parent.parentElement;
        }
        node.classList.add('lm-expanded');
        var link = qs('.lm-tree-link', node);
        if (link && typeof link.scrollIntoView === 'function') {
            try { link.scrollIntoView({ block: 'nearest' }); } catch (e) { /* ignore */ }
        }
    }

    // ---------------------------------------------------------------- render: content

    function renderBreadcrumb(items, label) {
        if (items === null) {
            els.breadcrumb.innerHTML = '<span class="lm-bc-label"><i class="fa-solid fa-magnifying-glass"></i> ' + esc(label || '') + '</span>';
            return;
        }
        var html = '<a href="#" class="lm-bc-item' + (items.length ? '' : ' lm-bc-current') + '" data-cat="0"><i class="fa-solid fa-house-chimney"></i> ' + esc(t('root_level')) + '</a>';
        items.forEach(function (item, i) {
            var last = i === items.length - 1;
            html += '<span class="lm-bc-sep"><i class="fa-solid fa-chevron-right"></i></span>' +
                '<a href="#" class="lm-bc-item' + (last ? ' lm-bc-current' : '') + '" data-cat="' + item.id + '">' + esc(item.name) + '</a>';
        });
        els.breadcrumb.innerHTML = html;
    }

    function articleRowHtml(article, showPath) {
        var flags = [];
        if (article.sitestart) flags.push(t('linkmap_sitestart'));
        else if (article.startarticle) flags.push(t('linkmap_startarticle'));
        if (!article.hasTemplate) flags.push(t('linkmap_no_template'));
        return '<div class="lm-row lm-row-article' + (article.online ? '' : ' lm-offline') + (isSelected(article.id) ? ' lm-selected' : '') + '" data-id="' + article.id + '" data-clang="' + article.clang + '" role="option" aria-selected="' + (isSelected(article.id) ? 'true' : 'false') + '">' +
            (state.multiple ? '<span class="lm-check"><i class="fa-solid fa-check"></i></span>' : '') +
            '<i class="lm-row-icon ' + articleIcon(article) + '"></i>' +
            '<div class="lm-row-main">' +
                '<div class="lm-row-title">' +
                    '<span class="lm-row-name">' + esc(article.name) + '</span>' +
                    '<span class="lm-id">' + article.id + '</span>' +
                    flags.map(function (f) { return '<span class="lm-flag">' + esc(f) + '</span>'; }).join('') +
                    (showDomain(article) && !state.domain ? '<span class="lm-domain-badge">' + esc(article.domain) + '</span>' : '') +
                '</div>' +
                '<div class="lm-row-meta">' +
                    statusHtml(article, 'article') +
                    (showPath && article.path.length ? ' · <span class="lm-row-path">' + esc(article.path.join(' / ')) + '</span>' : '') +
                    (article.updatedate ? ' · ' + esc(formatDate(article.updatedate)) + (article.updateuser ? ' (' + esc(article.updateuser) + ')' : '') : '') +
                '</div>' +
            '</div>' +
            (state.callback && !state.multiple ? '<div class="lm-row-actions"><span class="lm-row-action lm-row-pick" title="' + esc(t('linkmap_select')) + '"><i class="fa-solid fa-check"></i></span></div>' : '') +
        '</div>';
    }

    // Kategorie direkt als Ziel uebernehmen (= ihr Startartikel, gleiche ID),
    // ohne sie erst zu oeffnen -- Button neben dem Pfeil.
    function categoryRowHtml(cat) {
        var pickable = !!state.callback;
        var selected = pickable && state.multiple && isSelected(cat.id);
        // Kategorie-Picker: Blaetter (ohne Unterkategorien) werden per Klick
        // direkt gewaehlt, es gibt nichts zu oeffnen -- kein Pfeil.
        var leaf = state.categoriesOnly && cat.hasChildren === false;
        return '<div class="lm-row lm-row-category' + (cat.online ? '' : ' lm-offline') + (selected ? ' lm-selected' : '') + (leaf ? ' lm-row-leaf' : '') + '" data-id="' + cat.id + '" role="option" aria-selected="' + (selected ? 'true' : 'false') + '">' +
            (state.multiple ? '<span class="lm-check lm-check-category" data-action="pick-category" title="' + esc(t('linkmap_pick_category')) + '"><i class="fa-solid fa-check"></i></span>' : '') +
            '<i class="lm-row-icon fa-solid fa-folder"></i>' +
            '<div class="lm-row-main">' +
                '<div class="lm-row-title"><span class="lm-row-name">' + esc(cat.name) + '</span><span class="lm-id">' + cat.id + '</span>' +
                    (showDomain(cat) && !state.domain ? '<span class="lm-domain-badge">' + esc(cat.domain) + '</span>' : '') +
                '</div>' +
                '<div class="lm-row-meta">' + statusHtml(cat, 'category') + '</div>' +
            '</div>' +
            '<div class="lm-row-actions">' +
                (pickable && !state.multiple ? '<button type="button" class="lm-row-action lm-row-pick lm-row-pick-category" data-action="pick-category" title="' + esc(t('linkmap_pick_category')) + '"><i class="fa-solid fa-check"></i></button>' : '') +
                (leaf ? '' : '<span class="lm-row-action lm-row-open" title="' + esc(t('linkmap_open_category_hint')) + '"><i class="fa-solid fa-chevron-right"></i></span>') +
            '</div>' +
        '</div>';
    }

    // Kategorie als artikel-aehnliches Item (Startartikel hat dieselbe ID)
    function categoryAsItem(cat) {
        return {
            id: cat.id, name: cat.name, label: cat.label || cat.name, link: 'redaxo://' + cat.id,
            online: cat.online, status: cat.status, startarticle: true, sitestart: false, hasTemplate: true,
            categoryId: cat.id, parentId: cat.parentId, clang: cat.clang, domain: cat.domain, path: cat.path || [],
            source: 'article'
        };
    }

    function pickCategory(row) {
        var cat = categoryCache[row.getAttribute('data-id')];
        if (!cat || !state.callback) return;
        var item = categoryAsItem(cat);
        articleCache[item.clang + ':' + item.id] = item;
        if (state.multiple) {
            toggleSelected(item);
            return;
        }
        finishSingle(item.link, item.label, item);
    }

    function renderCategoryList(data) {
        var categories = (data.categories || []).filter(passesFilters);
        var articles = state.categoriesOnly ? [] : (data.articles || []).filter(passesFilters);
        cacheCategories(categories);
        var html = '';
        if (categories.length) {
            html += '<div class="lm-group-label">' + esc(t('linkmap_categories')) + ' <span class="lm-group-count">' + categories.length + '</span></div>' +
                categories.map(categoryRowHtml).join('');
        }
        if (state.categoriesOnly) {
            if (!categories.length) html += '<div class="lm-muted lm-empty">' + esc(t('linkmap_no_categories')) + '</div>';
        } else {
            html += '<div class="lm-group-label">' + esc(t('linkmap_articles')) + ' <span class="lm-group-count">' + articles.length + '</span></div>';
            html += articles.length ? articles.map(function (a) { return articleRowHtml(a, false); }).join('')
                : '<div class="lm-muted lm-empty">' + esc(t('linkmap_no_articles')) + '</div>';
        }
        els.list.innerHTML = html;
        state.rows = qsa('.lm-row', els.list);
        state.activeIndex = -1;
        els.status.textContent = state.categoriesOnly
            ? t('linkmap_count_categories', { count: categories.length })
            : t('linkmap_count_categories', { count: categories.length }) + ' · ' + t('linkmap_count_articles', { count: articles.length });
        cacheArticles(articles);
    }

    function renderSearchResults(data) {
        var articles = (data.articles || []).filter(function (a) { return (!state.hideOffline || a.online) && (!state.categoriesOnly || a.startarticle); });
        var html = '';
        if (!articles.length) {
            html = '<div class="lm-muted lm-empty">' + esc(t('linkmap_no_results')) + '</div>';
        } else {
            html = articles.map(function (a) { return articleRowHtml(a, true); }).join('');
            if (data.truncated) html += '<div class="lm-muted lm-truncated">' + esc(t('linkmap_results_truncated')) + '</div>';
        }
        els.list.innerHTML = html;
        state.rows = qsa('.lm-row', els.list);
        state.activeIndex = -1;
        els.status.textContent = t('linkmap_count_articles', { count: articles.length });
        cacheArticles(articles);
    }

    var articleCache = {};
    var categoryCache = {};
    function cacheArticles(list) {
        list.forEach(function (a) { articleCache[a.clang + ':' + a.id] = a; });
    }
    function cacheCategories(list) {
        list.forEach(function (c) { categoryCache[c.id] = c; });
    }
    function rowArticle(row) {
        if (!row.classList.contains('lm-row-article')) return null;
        return articleCache[row.getAttribute('data-clang') + ':' + row.getAttribute('data-id')] || null;
    }

    // ---------------------------------------------------------------- sources (Datensaetze)

    var datasetCache = {};

    function allowedSourceList() {
        return (config.sources || []).filter(function (src) {
            return state.allowedSources.indexOf(src.id) !== -1;
        });
    }

    function renderSources() {
        var sources = allowedSourceList();
        var html = '';
        sources.forEach(function (src) {
            src.containers.forEach(function (c) {
                html += '<li><a href="#" class="lm-sb-link lm-source-link' + (c.linkable ? '' : ' lm-source-unlinkable') + '" data-source="' + esc(src.id) + '" data-container="' + esc(c.id) + '" title="' + esc(src.label + ' · ' + c.id) + (c.linkable ? '' : ' · ' + t('linkmap_source_no_scheme')) + '">' +
                    '<i class="' + esc(c.icon || src.icon) + '"></i> <span class="lm-sb-name">' + esc(c.label) + '</span>' +
                    (c.linkable ? '' : '<i class="fa-solid fa-link-slash lm-source-warn"></i>') +
                '</a></li>';
            });
        });
        els.sourcesSection.hidden = html === '';
        els.sources.innerHTML = html ? '<ul class="lm-sb-list">' + html + '</ul>' : '';
    }

    function highlightSource(source, container) {
        qsa('.lm-source-link.lm-current', els.sources).forEach(function (el) { el.classList.remove('lm-current'); });
        if (!source) return;
        var el = qs('.lm-source-link[data-source="' + source + '"][data-container="' + container + '"]', els.sources);
        if (el) el.classList.add('lm-current');
    }

    function containerMeta(source, container) {
        var src = allowedSourceList().filter(function (s) { return s.id === source; })[0];
        var c = src ? src.containers.filter(function (x) { return x.id === container; })[0] : null;
        if (!c && state.extraContainer && state.extraContainer.source === source && state.extraContainer.id === container) {
            // Relation-Modus: Container ist nicht als Quelle freigegeben, kommt vom Aufrufer
            var providerMeta = (config.sources || []).filter(function (s) { return s.id === source; })[0];
            src = src || providerMeta || { id: source, label: source, icon: 'fa-solid fa-database', containers: [] };
            c = { id: container, label: state.extraContainer.label, icon: 'fa-solid fa-table-list', linkable: false };
        }
        return { source: src, container: c };
    }

    function openContainer(source, container) {
        var meta = containerMeta(source, container);
        if (!meta.source || !meta.container) return;
        state.view = 'source';
        state.sourceView = { source: source, container: container, page: 1, query: '', total: 0, pages: 0, sort: '', dir: 'asc', columns: [] };
        highlightTree(-1);
        highlightSource(source, container);
        els.search.value = '';
        els.searchClear.hidden = true;
        els.search.placeholder = t('linkmap_search_datasets_placeholder');
        loadContainer('', 1, false);
    }

    function loadContainer(query, page, append) {
        var view = state.sourceView;
        if (!view) return;
        var meta = containerMeta(view.source, view.container);
        view.query = query;
        var seq = ++state.requestSeq;
        if (!append) {
            els.list.innerHTML = '<div class="lm-muted lm-list-loading">' + esc(t('linkmap_loading')) + '</div>';
            renderBreadcrumb(null, (meta.source ? meta.source.label + ' › ' : '') + (meta.container ? meta.container.label : view.container) + (query ? ' · ' + t('linkmap_search_results', { query: query }) : ''));
        } else {
            var moreBtn = qs('.lm-load-more', els.list);
            if (moreBtn) moreBtn.disabled = true;
        }
        api(config.urls.sourceBrowse, { source: view.source, container: view.container, q: query, p: page, clang: state.clang, sort: view.sort, dir: view.dir, mode: state.locked ? 'relation' : '' }).then(function (data) {
            if (seq !== state.requestSeq || state.sourceView !== view) return;
            view.page = data.page || page;
            view.total = data.total || 0;
            view.pages = data.pages || 0;
            view.columns = data.columns || [];
            view.sort = data.sort || '';
            view.addUrl = data.addUrl || (meta.container && meta.container.addUrl) || '';
            if (!append) renderContainerActions(view);
            renderDatasetList(data, append, meta);
        }).catch(function (err) {
            if (seq !== state.requestSeq) return;
            els.list.innerHTML = '<div class="lm-error">' + esc(err.message || t('linkmap_error')) + '</div>';
        });
    }

    // Tabellarische Listenansicht (Vorbild MediaPlace-Liste): Spalten kommen
    // vom Provider (browse().columns), Sortierung serverseitig per Klick auf
    // den Spaltenkopf.
    function datasetRowHtml(item, columns) {
        var selected = isSelected(item.link);
        var cells = item.cells || {};
        var html = '<tr class="lm-row lm-row-dataset' + (item.online ? '' : ' lm-offline') + (selected ? ' lm-selected' : '') + (item.linkable ? '' : ' lm-row-unlinkable') + '" data-link="' + esc(item.link) + '" data-id="' + item.id + '" role="option" aria-selected="' + (selected ? 'true' : 'false') + '">';
        if (state.multiple) html += '<td class="lm-td-check"><span class="lm-check"><i class="fa-solid fa-check"></i></span></td>';
        html += '<td class="lm-td-label"><i class="lm-row-icon fa-solid fa-table-list lm-icon-dataset"></i> <span class="lm-row-name">' + esc(item.name) + '</span> <span class="lm-id">' + item.id + '</span>' +
            (item.linkable || state.locked ? '' : ' <span class="lm-flag lm-flag-warn">' + esc(t('linkmap_source_no_scheme')) + '</span>') + '</td>';
        columns.forEach(function (col) {
            if (col.key === 'label') return;
            var value = cells[col.key];
            if (col.key === 'status' && item.status !== null && item.status !== undefined) {
                html += '<td class="lm-td-status"><span class="lm-state lm-state-' + (item.online ? '1' : '0') + '"><span class="lm-status-dot"></span>' + esc(item.online ? t('linkmap_status_online') : t('linkmap_status_offline')) + '</span></td>';
                return;
            }
            html += '<td class="lm-td-' + esc(col.key) + '">' + esc(value === undefined || value === null ? '' : value) + '</td>';
        });
        if (!state.locked) html += '<td class="lm-td-link" title="' + esc(item.link) + '"><code class="lm-row-link">' + esc(item.link.replace(/^yform:\/\//, '')) + '</code></td>';
        html += '<td class="lm-td-edit">' + (item.editUrl ? '<a class="lm-row-action lm-row-edit" href="' + esc(item.editUrl) + '" target="_blank" rel="noopener" data-action="edit-dataset" title="' + esc(t('linkmap_dataset_edit')) + '"><i class="fa-solid fa-pen"></i></a>' : '') + '</td>';
        if (state.callback && !state.multiple) html += '<td class="lm-td-pick"><span class="lm-row-action lm-row-pick"><i class="fa-solid fa-check"></i></span></td>';
        return html + '</tr>';
    }

    function datasetTableHead(columns) {
        var view = state.sourceView || {};
        var html = '<thead><tr>';
        if (state.multiple) html += '<th class="lm-th-check"></th>';
        columns.forEach(function (col) {
            var active = view.sort === col.key;
            var arrow = active ? (view.dir === 'desc' ? ' <i class="fa-solid fa-caret-down"></i>' : ' <i class="fa-solid fa-caret-up"></i>') : '';
            var thClass = (col.key === 'label' ? 'lm-th-label' : 'lm-th-' + col.key) + (col.sortable ? ' lm-th-sortable' : '') + (active ? ' lm-th-active' : '');
            html += col.sortable
                ? '<th class="' + esc(thClass) + '" data-sort="' + esc(col.key) + '" title="' + esc(t('linkmap_sort_by', { label: col.label })) + '">' + esc(col.label) + arrow + '</th>'
                : '<th class="' + esc(thClass) + '">' + esc(col.label) + '</th>';
        });
        if (!state.locked) html += '<th class="lm-th-link">' + esc(t('linkmap_col_link')) + '</th>';
        html += '<th class="lm-th-edit"></th>';
        if (state.callback && !state.multiple) html += '<th class="lm-th-pick"></th>';
        return html + '</tr></thead>';
    }

    function renderDatasetList(data, append, meta) {
        var items = (data.items || []).filter(function (i) { return !state.hideOffline || i.online; });
        items.forEach(function (i) { datasetCache[i.link] = i; });
        var view = state.sourceView;
        var columns = (view && view.columns.length) ? view.columns : [{ key: 'label', label: t('linkmap_col_label'), sortable: false }];
        var rowsHtml = items.map(function (i) { return datasetRowHtml(i, columns); }).join('');
        var moreHtml = view && view.page < view.pages
            ? '<button type="button" class="lm-btn lm-load-more">' + esc(t('linkmap_load_more', { count: Math.min(50, view.total - view.page * 50) })) + '</button>'
            : '';
        if (append) {
            var old = qs('.lm-load-more', els.list);
            if (old) old.remove();
            var tbody = qs('.lm-table tbody', els.list);
            if (tbody) tbody.insertAdjacentHTML('beforeend', rowsHtml);
            els.list.insertAdjacentHTML('beforeend', moreHtml);
        } else {
            els.list.innerHTML = rowsHtml
                ? '<div class="lm-table-wrap"><table class="lm-table">' + datasetTableHead(columns) + '<tbody>' + rowsHtml + '</tbody></table></div>' + moreHtml
                : '<div class="lm-muted lm-empty">' + esc(t('linkmap_no_results')) + '</div>';
        }
        state.rows = qsa('.lm-row', els.list);
        state.activeIndex = -1;
        els.status.textContent = t('linkmap_count_datasets', { count: view ? view.total : items.length }) +
            (meta && meta.container && !meta.container.linkable && !state.locked ? ' · ' + t('linkmap_source_no_scheme') : '');
    }

    // "Neuer Datensatz" (YForm-Formular im neuen Fenster) + Neu laden in der
    // Breadcrumb-Leiste des Containers.
    function renderContainerActions(view) {
        var old = qs('.lm-bc-actions', els.breadcrumb);
        if (old) old.remove();
        var html = '<span class="lm-bc-actions">';
        if (view.addUrl) {
            html += '<button type="button" class="lm-btn lm-btn-sm" data-action="add-dataset" data-url="' + esc(view.addUrl) + '" title="' + esc(t('linkmap_dataset_add_hint')) + '"><i class="fa-solid fa-plus"></i> ' + esc(t('linkmap_dataset_add')) + '</button>';
        }
        html += '<button type="button" class="lm-btn lm-btn-sm lm-btn-icon" data-action="reload" title="' + esc(t('linkmap_reload')) + '"><i class="fa-solid fa-arrows-rotate"></i></button></span>';
        els.breadcrumb.insertAdjacentHTML('beforeend', html);
    }

    function sortContainer(key) {
        var view = state.sourceView;
        if (!view) return;
        if (view.sort === key) {
            view.dir = view.dir === 'asc' ? 'desc' : 'asc';
        } else {
            view.sort = key;
            view.dir = 'asc';
        }
        loadContainer(view.query, 1, false);
    }

    function chooseDataset(item, event, row) {
        if (state.browseOnly) return;
        if (state.multiple) {
            toggleSelected(item);
            return;
        }
        // Einzelauswahl: bei mehreren URL-Schemata der Tabelle waehlen lassen
        // (nicht im Relation-Modus -- dort zaehlt nur die ID)
        closeSchemeChooser();
        if (!item.linkable || state.locked) {
            finishSingle(item.link, item.label, item);
            return;
        }
        var seq = ++state.requestSeq;
        var chooserHtml = '<div class="lm-scheme-chooser" data-link="' + esc(item.link) + '"><div class="lm-muted">' + esc(t('linkmap_loading')) + '</div></div>';
        if (row.tagName === 'TR') {
            row.insertAdjacentHTML('afterend', '<tr class="lm-scheme-row"><td colspan="' + row.children.length + '">' + chooserHtml + '</td></tr>');
        } else {
            row.insertAdjacentHTML('afterend', chooserHtml);
        }
        api(config.urls.linkSchemes, { link: item.link, clang: state.clang }).then(function (data) {
            if (seq !== state.requestSeq || !state.open) return;
            var candidates = data.candidates || [];
            if (candidates.length <= 1) {
                closeSchemeChooser();
                finishSingle(item.link, item.label, item);
                return;
            }
            var chooser = qs('.lm-scheme-chooser[data-link="' + item.link + '"]', els.list);
            if (!chooser) return;
            chooser.innerHTML = '<div class="lm-scheme-title"><i class="fa-solid fa-code-branch"></i> ' + esc(t('linkmap_scheme_choose')) + '</div>' +
                '<button type="button" class="lm-scheme-option lm-scheme-auto" data-link="' + esc(item.link) + '">' +
                    '<span class="lm-scheme-label">' + esc(t('linkmap_scheme_auto')) + '</span><span class="lm-scheme-url">' + esc(candidates[0].url) + '</span></button>' +
                candidates.map(function (c) {
                    return '<button type="button" class="lm-scheme-option" data-link="' + esc(c.scheme ? item.link + '?scheme=' + encodeURIComponent(c.scheme) : item.link) + '">' +
                        '<span class="lm-scheme-label">' + esc(c.label) + '</span><span class="lm-scheme-url">' + esc(c.url) + '</span></button>';
                }).join('') +
                '<button type="button" class="lm-btn lm-scheme-cancel">' + esc(t('linkmap_cancel')) + '</button>';
            try { chooser.scrollIntoView({ block: 'nearest' }); } catch (e) { /* ignore */ }
        }).catch(function () {
            closeSchemeChooser();
            finishSingle(item.link, item.label, item);
        });
    }

    function closeSchemeChooser() {
        qsa('.lm-scheme-row, .lm-scheme-chooser', els.list).forEach(function (el) { el.remove(); });
    }

    // ---------------------------------------------------------------- keyboard / activation

    function setActive(index) {
        if (state.activeIndex >= 0 && state.rows[state.activeIndex]) state.rows[state.activeIndex].classList.remove('lm-active');
        state.activeIndex = index;
        var row = state.rows[index];
        if (row) {
            row.classList.add('lm-active');
            try { row.scrollIntoView({ block: 'nearest' }); } catch (e) { /* ignore */ }
        }
    }

    function moveActive(delta) {
        if (!state.rows.length) return;
        var next = state.activeIndex + delta;
        if (next < 0) next = 0;
        if (next >= state.rows.length) next = state.rows.length - 1;
        setActive(next);
    }

    function activateRow(event, row) {
        row = row || state.rows[state.activeIndex];
        if (!row) return;
        if (row.classList.contains('lm-row-category')) {
            openCategory(parseInt(row.getAttribute('data-id'), 10));
            return;
        }
        if (row.classList.contains('lm-row-dataset')) {
            var item = datasetCache[row.getAttribute('data-link')];
            if (item) chooseDataset(item, event, row);
            return;
        }
        var article = rowArticle(row);
        if (article) chooseArticle(article, event);
    }

    // ---------------------------------------------------------------- selection

    // Auswahl wird ueber den Link identifiziert (redaxo://ID bzw.
    // yform://tabelle/id) -- damit koennen Artikel und Datensaetze gemischt
    // in einer Mehrfachauswahl liegen.
    function isSelected(idOrLink) {
        var link = typeof idOrLink === 'number' ? 'redaxo://' + idOrLink : idOrLink;
        return state.selected.some(function (a) { return a.link === link; });
    }

    function toggleSelected(item) {
        var index = -1;
        state.selected.forEach(function (a, i) { if (a.link === item.link) index = i; });
        if (index >= 0) state.selected.splice(index, 1);
        else state.selected.push(item);
        qsa('.lm-row-article[data-id="' + item.id + '"], .lm-row-category[data-id="' + item.id + '"], .lm-row-dataset[data-link="' + item.link + '"]', els.list).forEach(function (row) {
            if (!row.classList.contains('lm-row-dataset') && item.link !== 'redaxo://' + item.id) return;
            row.classList.toggle('lm-selected', index < 0);
            row.setAttribute('aria-selected', index < 0 ? 'true' : 'false');
        });
        updateSelectionUi();
    }

    function updateSelectionUi() {
        if (state.categoriesOnly && state.callback && !state.multiple) {
            // Kategorie-Picker: "Uebernehmen" nimmt die gerade geoeffnete Kategorie
            // (auch per Baum angesteuert); auf der Hauptebene gibt es nichts zu waehlen.
            var cat = state.currentCategory;
            els.apply.disabled = !cat;
            els.apply.innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(cat ? t('linkmap_apply_category', { name: cat.name }) : t('linkmap_apply'));
            return;
        }
        if (!state.multiple) return;
        els.apply.innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('linkmap_apply'));
        els.selCount.textContent = t('linkmap_selected_count', { count: state.selected.length });
        els.apply.disabled = state.selected.length === 0;
    }

    function chooseArticle(article, event, force) {
        if (state.browseOnly) {
            // Nur ansehen: Auswahl hat kein Ziel, Zeile bleibt lediglich markiert.
            return;
        }
        if (state.multiple && !force) {
            toggleSelected(article);
            return;
        }
        if (state.multiple && force) {
            if (!isSelected(article.id)) toggleSelected(article);
            applyMultiple();
            return;
        }
        finishSingle(article.link, article.label, article);
    }

    function finishSingle(link, name, article) {
        var callback = state.callback;
        emit('select', { link: link, name: name, article: article });
        close();
        if (callback) callback(link, name, article);
    }

    function applyMultiple() {
        if (!state.selected.length) return;
        var callback = state.callback;
        var items = state.selected.map(function (a) {
            return { id: a.id, link: a.link, name: a.label, article: a.source && a.source !== 'article' ? null : a, item: a };
        });
        emit('select', { items: items });
        close();
        if (callback) callback(items);
    }

    // ---------------------------------------------------------------- public

    window.LM = {
        open: open,
        close: close,
        isOpen: function () { return state.open; },
        on: function (event, fn) { (listeners[event] = listeners[event] || []).push(fn); },
        off: function (event, fn) {
            if (!listeners[event]) return;
            listeners[event] = fn ? listeners[event].filter(function (f) { return f !== fn; }) : [];
        },
        t: t,
        config: function () { ensureConfig(); return config; },
        /** Links (redaxo://ID, yform://tabelle/id) zu Items aufloesen -- Promise<Array<item>>. */
        resolveLinks: function (links, clang) {
            if (!ensureConfig()) return Promise.resolve([]);
            var list = (Array.isArray(links) ? links : String(links || '').split(',')).map(function (v) { return String(v || '').trim(); }).filter(Boolean);
            if (!list.length) return Promise.resolve([]);
            return api(config.urls.linkResolve, { links: list.join(','), clang: clang || clangFromLocation() || config.currentClang }).then(function (data) {
                return data.items || [];
            });
        },
        /** Artikel per ID(s) aufloesen -- Promise<Array<article>>. */
        resolve: function (ids, clang) {
            if (!ensureConfig()) return Promise.resolve([]);
            var list = Array.isArray(ids) ? ids : String(ids || '').split(',');
            list = list.map(function (v) { return parseInt(v, 10); }).filter(function (v) { return v > 0; });
            if (!list.length) return Promise.resolve([]);
            return api(config.urls.article, { ids: list.join(','), clang: clang || clangFromLocation() || config.currentClang }).then(function (data) {
                return data.articles || [];
            });
        }
    };
})();
