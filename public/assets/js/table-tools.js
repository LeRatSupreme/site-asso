/*
 * Boîte à outils tableaux (lot 4B) — vanilla JS, sans dépendance.
 *
 * Rend manipulables côté client les tableaux marqués data-table :
 *  - tri par colonne (clic sur l'en-tête, aria-sort, indicateur ▲/▼) ;
 *  - filtre rapide : input[data-table-filter="sélecteur"] (insensible à
 *    la casse et aux accents) ;
 *  - choix des colonnes : details[data-table-cols="sélecteur"] listant
 *    les th[data-col] masquables (data-col-fixed = jamais masquable),
 *    choix mémorisé par table (localStorage) ;
 *  - compteur « X lignes affichées sur Y » : [data-table-count].
 *
 * Le sélecteur des commandes (filter/cols/count) peut viser plusieurs
 * tableaux d'un coup (ex. ".prod-table") : la commande s'applique alors
 * à tous et le choix de colonnes est mémorisé sous la clé commune.
 * Hors tableau, le sélecteur désigne des éléments simples (cartes) :
 * seuls le filtre et le compteur s'appliquent (affichage/masquage).
 *
 * Aucun calcul métier ici : ce fichier trie, filtre, masque et compte
 * des lignes déjà rendues par le serveur.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'aeic.table-tools.cols.v1';
    /* Lignes non données : jamais triées, jamais comptées, jamais
       masquées par le filtre (ligne d'état vide, en-tête de groupe…). */
    var STATIC_CLASS = 'tt-static';

    /* Texte normalisé : minuscules, accents retirés (même principe que
       les helpers existants des vues). */
    function norm(s) {
        return String(s == null ? '' : s)
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    /* Nombre à partir d'une cellule « td.num » (« 1 234,50 € » → 1234.5). */
    function parseNum(s) {
        var clean = String(s == null ? '' : s)
            .replace(/\s|\u00a0|\u202f/g, '')
            .replace(/[€%]/g, '')
            .replace(',', '.');
        if (clean === '' || clean === '—' || clean === '-') return null;
        var n = parseFloat(clean);
        return isFinite(n) ? n : null;
    }

    function pad(n) { return n.length < 2 ? '0' + n : n; }

    /* Date « jj/mm/aaaa [hh:mm] » → chaîne ISO (l'ordre lexicographique
       trie correctement), sinon null. */
    function parseFrDate(s) {
        var m = String(s == null ? '' : s)
            .match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2}))?$/);
        if (!m) return null;
        var v = m[3] + '-' + pad(m[2]) + '-' + pad(m[1]);
        if (m[4] !== undefined) v += ' ' + pad(m[4]) + ':' + m[5];
        return v;
    }

    function isStructural(tr) {
        return tr.classList.contains(STATIC_CLASS) || tr.querySelector('[colspan]') !== null;
    }

    /* ── Mémoire des colonnes (localStorage, tolérant aux erreurs) ── */

    function readStore() {
        try {
            return JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}') || {};
        } catch (e) { return {}; }
    }

    function writeStore(store) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(store));
        } catch (e) { /* stockage indisponible : choix non mémorisé */ }
    }

    /* ── Modèle d'un tableau-outil ────────────────────────────────── */

    function TableTools(table) {
        this.table = table;
        this.headers = Array.prototype.slice.call(table.querySelectorAll('thead th'));
        this.sortable = !table.hasAttribute('data-table-nosort');
        this.colKeys = [];   // data-col de chaque colonne (null si non masquable)
        this.fixedKeys = []; // colonnes jamais masquables
        this.labels = {};    // data-col → libellé lisible
        this.indexColumns();
        this.makeSortable();
        table.__tableTools = this;
    }

    TableTools.prototype.indexColumns = function () {
        var self = this;
        this.headers.forEach(function (th, i) {
            var key = th.getAttribute('data-col');
            self.colKeys.push(key || null);
            if (key) {
                self.labels[key] = (th.getAttribute('data-col-label') || th.textContent || '').trim() || key;
                if (th.hasAttribute('data-col-fixed')) self.fixedKeys.push(key);
            }
            // Étiquette chaque cellule simple avec l'indice de sa colonne
            // (les cellules avec colspan couvrent plusieurs colonnes :
            // jamais masquées individuellement).
            Array.prototype.forEach.call(self.table.rows, function (tr) {
                if (tr.parentElement.tagName === 'THEAD') return;
                var cursor = 0;
                Array.prototype.forEach.call(tr.cells, function (cell) {
                    var span = cell.colSpan || 1;
                    if (span === 1 && cursor === i) cell.__ttCol = i;
                    cursor += span;
                });
            });
        });
    };

    TableTools.prototype.dataRows = function () {
        var rows = [];
        Array.prototype.forEach.call(this.table.tBodies, function (tbody) {
            Array.prototype.forEach.call(tbody.rows, function (tr) {
                if (!isStructural(tr)) rows.push(tr);
            });
        });
        return rows;
    };

    TableTools.prototype.cellAt = function (tr, colIndex) {
        var found = null;
        Array.prototype.forEach.call(tr.cells, function (cell) {
            if (cell.__ttCol === colIndex) found = cell;
        });
        return found;
    };

    /* Clé de tri d'une cellule : numérique si la colonne est entièrement
       « td.num », date FR si reconnue, texte sinon. */
    TableTools.prototype.sortValue = function (tr, colIndex, numeric) {
        var cell = this.cellAt(tr, colIndex);
        if (!cell) return numeric ? -Infinity : '';
        var text = (cell.textContent || '').trim();
        if (numeric) {
            var n = parseNum(text);
            return n === null ? -Infinity : n;
        }
        var d = parseFrDate(text);
        if (d !== null) return d;
        return norm(text);
    };

    TableTools.prototype.isNumericColumn = function (colIndex) {
        var rows = this.dataRows();
        if (rows.length === 0) return false;
        for (var i = 0; i < rows.length; i++) {
            var cell = this.cellAt(rows[i], colIndex);
            if (!cell || !cell.classList.contains('num')) return false;
        }
        return true;
    };

    TableTools.prototype.makeSortable = function () {
        if (!this.sortable) return;
        var self = this;
        this.headers.forEach(function (th, i) {
            var key = th.getAttribute('data-col');
            if (!key) return;
            var label = self.labels[key];
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tt-sort';
            btn.textContent = label;
            btn.setAttribute('aria-pressed', 'false');
            btn.setAttribute('title', 'Trier par « ' + label + ' »');
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                self.toggleSort(i);
            });
            th.appendChild(btn);
            th.classList.add('tt-th-sort');
            th.addEventListener('click', function () { self.toggleSort(i); });
            th.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    self.toggleSort(i);
                }
            });
        });
    };

    TableTools.prototype.toggleSort = function (colIndex) {
        var th = this.headers[colIndex];
        var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';

        // Un seul tri actif par tableau.
        this.headers.forEach(function (other) {
            other.removeAttribute('aria-sort');
            var b = other.querySelector('.tt-sort');
            if (b) {
                b.setAttribute('aria-pressed', 'false');
                b.classList.remove('tt-desc');
            }
        });
        th.setAttribute('aria-sort', dir);
        var btn = th.querySelector('.tt-sort');
        if (btn) {
            btn.setAttribute('aria-pressed', 'true');
            btn.classList.toggle('tt-desc', dir === 'descending');
        }

        var self = this;
        var numeric = this.isNumericColumn(colIndex);
        var cmp = function (a, b) {
            var va = self.sortValue(a, colIndex, numeric);
            var vb = self.sortValue(b, colIndex, numeric);
            var r;
            if (typeof va === 'number' || typeof vb === 'number') {
                r = va < vb ? -1 : va > vb ? 1 : 0;
            } else {
                r = String(va).localeCompare(String(vb), 'fr', { numeric: true });
            }
            return dir === 'ascending' ? r : -r;
        };

        // Tri par tbody : les lignes de données sont réordonnées, les
        // lignes structurelles gardent leur position relative.
        Array.prototype.forEach.call(this.table.tBodies, function (tbody) {
            var original = Array.prototype.slice.call(tbody.rows);
            var sorted = original.filter(function (tr) { return !isStructural(tr); }).sort(cmp);
            var di = 0;
            var frag = document.createDocumentFragment();
            original.forEach(function (tr) {
                frag.appendChild(isStructural(tr) ? tr : sorted[di++]);
            });
            tbody.appendChild(frag);
        });

        refreshCounts();
    };

    /* ── Masquage de colonnes ─────────────────────────────────────── */

    TableTools.prototype.applyColumns = function (visibleKeys) {
        var self = this;
        Array.prototype.forEach.call(this.table.rows, function (tr) {
            Array.prototype.forEach.call(tr.cells, function (cell) {
                if (cell.__ttCol === undefined || cell.__ttCol === null) return;
                var key = self.colKeys[cell.__ttCol];
                var hide = key !== null
                    && self.fixedKeys.indexOf(key) === -1
                    && visibleKeys.indexOf(key) === -1;
                cell.classList.toggle('tt-col-hidden', hide);
            });
        });
    };

    /* ── Filtrage ─────────────────────────────────────────────────── */

    TableTools.prototype.applyFilter = function (query) {
        var q = norm(query);
        var shown = 0;
        var total = 0;

        Array.prototype.forEach.call(this.table.tBodies, function (tbody) {
            var staticRows = [];
            var lastMatch = false;
            Array.prototype.forEach.call(tbody.rows, function (tr) {
                if (isStructural(tr)) {
                    if (tr.classList.contains('tt-sub-row')) {
                        // Ligne liée (ex. édition inline) : suit toujours
                        // l'état visible de sa ligne de données.
                        tr.classList.toggle('tt-row-hidden', !lastMatch);
                    } else {
                        staticRows.push(tr);
                        lastMatch = false;
                    }
                    return;
                }
                total++;
                var match = q === '' || norm(tr.textContent).indexOf(q) !== -1;
                tr.classList.toggle('tt-row-hidden', !match);
                if (match) shown++;
                lastMatch = match;
            });
            // En-têtes de groupe statiques : masqués si aucune ligne
            // visible ne les suit (jusqu'au prochain statique).
            staticRows.forEach(function (head) {
                var anyVisible = false;
                var row = head.nextElementSibling;
                while (row && !row.classList.contains(STATIC_CLASS)) {
                    if (!row.classList.contains('tt-row-hidden')) { anyVisible = true; break; }
                    row = row.nextElementSibling;
                }
                head.classList.toggle('tt-row-hidden', q !== '' && !anyVisible);
            });
        });

        return { shown: shown, total: total };
    };

    /* ── Initialisation ───────────────────────────────────────────── */

    function targets(selector) {
        return Array.prototype.slice.call(document.querySelectorAll(selector));
    }

    function tableTargets(selector) {
        return targets(selector).filter(function (el) {
            return !!(el.__tableTools);
        });
    }

    function storageKeyFor(selector) {
        var matched = targets(selector).filter(function (el) {
            return el.tagName === 'TABLE' && el.hasAttribute('data-table');
        });
        return matched.length > 1 ? selector : (matched[0] ? (matched[0].id || selector) : null);
    }

    function defaultKeysFor(tool) {
        return (tool.table.getAttribute('data-cols-visible') || '')
            .split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    }

    function storedKeysFor(selector) {
        var store = readStore();
        var key = storageKeyFor(selector);
        return key && Object.prototype.hasOwnProperty.call(store, key) ? store[key] : null;
    }

    function setupCommands() {
        // Filtre rapide.
        Array.prototype.forEach.call(document.querySelectorAll('[data-table-filter]'), function (input) {
            var selector = input.getAttribute('data-table-filter');
            input.addEventListener('input', function () {
                applyFilter(selector, input.value);
            });
        });

        // Choix des colonnes : menu construit dans le <details>.
        Array.prototype.forEach.call(document.querySelectorAll('details[data-table-cols]'), function (details) {
            var selector = details.getAttribute('data-table-cols');
            var tools = tableTargets(selector);
            var first = tools[0];
            if (!first) return;

            var menu = details.querySelector('.tt-cols-menu');
            if (!menu) {
                menu = document.createElement('div');
                menu.className = 'tt-cols-menu';
                menu.setAttribute('role', 'group');
                menu.setAttribute('aria-label', 'Colonnes affichées');
                details.appendChild(menu);
            }

            first.colKeys.forEach(function (key) {
                if (key === null || first.fixedKeys.indexOf(key) !== -1) return;
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.setAttribute('data-col-key', key);
                var label = document.createElement('label');
                label.appendChild(cb);
                label.appendChild(document.createTextNode(first.labels[key] || key));
                menu.appendChild(label);
            });

            details.addEventListener('change', function () {
                var visible = [];
                Array.prototype.forEach.call(menu.querySelectorAll('input[type="checkbox"]'), function (cb) {
                    if (cb.checked) visible.push(cb.getAttribute('data-col-key'));
                });
                applyColumns(selector, visible, true);
            });
            details.__ttSync = function (visible) {
                Array.prototype.forEach.call(menu.querySelectorAll('input[type="checkbox"]'), function (cb) {
                    cb.checked = visible.indexOf(cb.getAttribute('data-col-key')) !== -1;
                });
            };
        });

        // État initial : mémoire (ou défauts de la vue) appliqué partout.
        Array.prototype.forEach.call(document.querySelectorAll('details[data-table-cols]'), function (details) {
            var selector = details.getAttribute('data-table-cols');
            var first = tableTargets(selector)[0];
            if (!first) return;
            var visible = storedKeysFor(selector) || defaultKeysFor(first);
            applyColumns(selector, visible, false);
            if (details.__ttSync) details.__ttSync(visible);
        });
    }

    function applyColumns(selector, visibleKeys, persist) {
        tableTargets(selector).forEach(function (tool) {
            tool.applyColumns(visibleKeys);
        });
        if (persist) {
            var key = storageKeyFor(selector);
            if (key) {
                var store = readStore();
                store[key] = visibleKeys;
                writeStore(store);
            }
        }
    }

    function applyFilter(selector, query) {
        var shown = 0;
        var total = 0;
        targets(selector).forEach(function (el) {
            if (el.__tableTools) {
                var r = el.__tableTools.applyFilter(query);
                shown += r.shown;
                total += r.total;
            } else {
                // Cible non tableau (carte…) : afficher/masquer l'élément.
                var match = norm(query) === '' || norm(el.textContent).indexOf(norm(query)) !== -1;
                el.classList.toggle('tt-row-hidden', !match);
                total++;
                if (match) shown++;
            }
        });
        updateCounters(selector, shown, total);
    }

    function refreshCounts() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-table-count]'), function (el) {
            var selector = el.getAttribute('data-table-count');
            var shown = 0;
            var total = 0;
            targets(selector).forEach(function (el) {
                if (el.__tableTools) {
                    el.__tableTools.dataRows().forEach(function (tr) {
                        total++;
                        if (!tr.classList.contains('tt-row-hidden')) shown++;
                    });
                }
            });
            updateCounters(selector, shown, total);
        });
    }

    function updateCounters(selector, shown, total) {
        Array.prototype.forEach.call(document.querySelectorAll('[data-table-count]'), function (el) {
            if (el.getAttribute('data-table-count') !== selector) return;
            var isItems = el.getAttribute('data-count-label') === 'items';
            el.textContent = isItems
                ? shown + ' affiché' + (shown > 1 ? 's' : '') + ' sur ' + total
                : shown + ' ligne' + (shown > 1 ? 's' : '') + ' affichée' + (shown > 1 ? 's' : '') + ' sur ' + total;
        });
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('table[data-table]'), function (t) {
            if (!t.__tableTools) new TableTools(t);
        });
        setupCommands();
        // Filtres préremplis + compteurs initiaux.
        Array.prototype.forEach.call(document.querySelectorAll('[data-table-filter]'), function (input) {
            if (input.value !== '') applyFilter(input.getAttribute('data-table-filter'), input.value);
        });
        refreshCounts();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
