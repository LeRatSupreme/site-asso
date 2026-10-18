/* =========================================================
 * Inventaire tablette SumUp — logique pure du collage.
 *
 * La saisie d'un relevé tablette se fait par COPIER-COLLER du
 * contenu de la tablette SumUp dans une zone de texte. Ce fichier
 * contient les deux fonctions pures de cette chaîne :
 *
 *  - InventaireTabletParseFull(text) : découpe le collage en lignes
 *    {label, qty} (formats « Nom 12 », « Nom - 12 », « Nom : 12 »,
 *    « Nom, 12 », « Nom ; 12 », « Nom | 12 », « Nom<TAB>12 », nombre
 *    seul sur une ligne rattaché au libellé du dessus, quantités
 *    signées) et compte les lignes d'interface ignorées ;
 *  - InventaireTabletMatch(label, index) : apparie un libellé aux
 *    clés produits connues (égalité brute insensible à la casse /
 *    aux accents / aux séparateurs, puis clé normalisée façon
 *    AliasSuggester::normalizeKey, puis inclusion unique) ;
 *  - InventaireTabletBuildIndex(products) : index normalisé des
 *    produits passés par la vue.
 *
 * Miroir JS d'AliasSuggester::normalizeKey (app/core/Compta) :
 * minuscules, accents supprimés, séparateurs repliés. Limite
 * assumée, identique côté PHP : « & » et apostrophes ne sont PAS
 * des séparateurs (M&M's ≠ MMS).
 *
 * Le navigateur reçoit les globales window.InventaireTabletParse /
 * InventaireTabletMatch / InventaireTabletBuildIndex ; Node.js
 * exporte le module complet (harnais tests/js/inventaire-tablette.test.js,
 * pont PHPUnit tests/Unit/InventaireTabletJsTest.php).
 * ========================================================= */
(function (global) {
    'use strict';

    /* ---------------- Normalisations ---------------- */

    /** Retire les accents (décomposition NFD + signs diacritiques). */
    function stripAccents(v) {
        return String(v == null ? '' : v)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\u0153/g, 'oe')
            .replace(/\u0152/g, 'oe')
            .replace(/\u00e6/g, 'ae')
            .replace(/\u00c6/g, 'ae');
    }

    /**
     * Niveau (a) : égalité « lisible » — minuscules, sans accents,
     * séparateurs _ - . ( ) [ ] { } , ; : / repliés en espaces.
     * (« Bueno_white » ≡ « Bueno white », « Coca-Cola » ≡ « Coca Cola ».)
     */
    function spaceNorm(v) {
        return stripAccents(v)
            .toLowerCase()
            .replace(/[_\-.()\[\]\{\},;:\/]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    /**
     * Niveau (b) : clé normalisée — miroir JS d'AliasSuggester::normalizeKey
     * suivi du retrait de TOUS les espaces (même convention que
     * StockPublic::normalizeKey / compta-saisie normKey).
     * (« Oasis (ice tea) » ≡ « Oasis Ice Tea », « capri-sun » ≡ « caprisun ».)
     */
    function tightNorm(v) {
        return spaceNorm(v).replace(/ /g, '');
    }

    /* ---------------- Parsing du collage ---------------- */

    // Espaces visibles et insécables (normale, fine, étroite insécable).
    var S = '\\s\\u00a0\\u202f';
    var RE_SPACE_CLASS = new RegExp('[' + S + ']');

    var RE_ONLY_PUNCT = /^[\s\u00a0\u202f\-.:;,|_\u2013\u2014\u2022\u00b7*()+\/\\]+$/;
    var RE_BARE_NUM = /^([\u2212+-]?)[0-9][0-9\u00a0\u202f ]*$/;
    var RE_LEADING_BULLETS = /^[\s\u00a0\u202f\-\u2013\u2014\u2022\u00b7*:;,|._]+/;
    var RE_SEP_PUNCT = new RegExp(
        '^(.+?)[' + S + ']*[:;,|][' + S + ']*([\\u2212+-]?[0-9][0-9\\u00a0\\u202f]*)[' + S + ']*$'
    );
    var RE_SEP_DASH = new RegExp(
        '^(.+?)[' + S + ']+[-\\u2013\\u2014][' + S + ']+([\\u2212+-]?[0-9][0-9\\u00a0\\u202f]*)[' + S + ']*$'
    );
    var RE_TRAILING_NUM = new RegExp(
        '^(.+?)[' + S + ']+([\\u2212+-]?[0-9][0-9\\u00a0\\u202f]*)[' + S + ']*$'
    );

    // Lignes d'interface de la tablette, ignorées silencieusement
    // (comparées sans accents ni casse).
    var JUNK_PHRASES = [
        'selectionner', 'modifier', 'options', 'activer le suivi',
        'produit', 'produits', 'stock', 'stocks', 'qte', 'quantite',
    ];

    /**
     * Quantité d'un token isolé : entier signé, espaces insécables/fines
     * tolérées comme séparateurs de milliers, signe − typographique
     * accepté. NaN si non numérique.
     */
    function parseQtyToken(tok) {
        var s = String(tok == null ? '' : tok)
            .replace(/\u2212/g, '-')
            .replace(/[\u00a0\u202f]/g, '');
        if (!/^[+-]?[0-9]+$/.test(s)) return NaN;
        var n = parseInt(s, 10);
        return isFinite(n) ? n : NaN;
    }

    /**
     * Ligne réduite à UN nombre seul ? Renvoie l'entier (signé) ou null.
     * Gère « 28 », « -12 » (négatif) et « -\t12 » / « — 12 » / « • 7 »
     * (tiret/puce de cellule tableau suivi du nombre : positif).
     */
    function tryBareQty(line) {
        var t = String(line == null ? '' : line).trim();
        if (t === '') return null;

        // Nombre nu, signe collé aux chiffres (« -12 » est négatif).
        var m = t.match(RE_BARE_NUM);
        if (m) {
            var neg = (m[1] === '-' || m[1] === '\u2212');
            var digits = t.replace(/[\u00a0\u202f ]/g, '').replace(/^[\u2212+-]/, '');
            var n = parseInt(digits, 10);
            if (!isFinite(n)) return null;
            return neg ? -n : n;
        }

        // Tiret / puce / ponctuation de cellule puis nombre (« -\t12 »).
        var stripped = t.replace(RE_LEADING_BULLETS, '');
        if (stripped !== '' && stripped.length < t.length) {
            var q = parseQtyToken(stripped);
            if (!isNaN(q)) return q;
        }
        return null;
    }

    /** Ligne d'interface de la tablette (« Sélectionner », « Stock »…) ? */
    function isJunkLine(line) {
        var s = stripAccents(line).toLowerCase().replace(/[\u00a0\u202f]/g, ' ').trim();
        if (s === '') return true;
        if (RE_ONLY_PUNCT.test(s)) return true;
        var rest = s;
        for (var i = 0; i < JUNK_PHRASES.length; i++) {
            rest = rest.split(JUNK_PHRASES[i]).join(' ');
        }
        rest = rest.replace(/[^0-9a-z&']/g, '');
        return rest === '';
    }

    /**
     * Nettoie un libellé : espaces unifiés, puces/tirets de tête et
     * séparateurs traînants retirés, guillemets enveloppants retirés.
     */
    function cleanLabel(s) {
        var t = String(s == null ? '' : s)
            .replace(/[\u00a0\u202f]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        t = t.replace(/^[\-\u2013\u2014\u2022\u00b7*]+\s*/, '');
        t = t.replace(/^[\u00ab\u00bb"'‘’“”]+/, '').replace(/[\u00ab\u00bb"'‘’“”]+$/, '');
        t = t.replace(/[\s\-\u2013\u2014:;,.|]+$/, '').trim();
        return t;
    }

    /**
     * Ligne « libellé + quantité » ? Renvoie {label, qty} ou null.
     * Essaie, dans l'ordre : tabulation (« Nom<TAB>12 »), séparateur
     * : ; , | (« Nom : 12 »), tiret espacé (« Nom - 12 », « Nom — 12 »),
     * nombre final signé (« Nom 12 », « Nom -12 »).
     */
    function splitLabelQty(line) {
        var raw = String(line == null ? '' : line);

        var ti = raw.lastIndexOf('\t');
        if (ti > 0) {
            var labelPart = raw.slice(0, ti);
            var tq = parseQtyToken(raw.slice(ti + 1));
            if (!isNaN(tq) && labelPart.trim() !== '') {
                return { label: labelPart, qty: tq };
            }
        }

        var m = raw.match(RE_SEP_PUNCT);
        if (!m) m = raw.match(RE_SEP_DASH);
        if (!m) m = raw.match(RE_TRAILING_NUM);
        if (!m) return null;

        var q = parseQtyToken(m[2]);
        if (isNaN(q)) return null;
        return { label: m[1], qty: q };
    }

    /**
     * Analyse complète d'un collage.
     *
     * @param {string} text Contenu brut de la zone de collage.
     * @returns {{rows: {label: string, qty: ?number}[], ignored: number}}
     *      rows : lignes produit dans l'ordre du collage (qty null =
     *      libellé en attente d'une quantité jamais arrivée) ;
     *      ignored : lignes d'interface / orphelines écartées.
     */
    function parse(text) {
        var lines = String(text == null ? '' : text).split(/\r\n|\r|\n/);
        var rows = [];
        var ignored = 0;
        var pending = -1;

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].replace(/^[\s\u00a0\u202f]+/, '').replace(/[\s\u00a0\u202f]+$/, '');
            if (line === '') continue;              // vide : sautée, non comptée
            if (isJunkLine(line)) { ignored++; continue; }

            var bare = tryBareQty(line);
            if (bare !== null) {
                if (pending >= 0 && rows[pending].qty === null) {
                    rows[pending].qty = bare;       // nombre seul -> libellé du dessus
                } else {
                    ignored++;                      // nombre orphelin
                }
                continue;
            }

            var sp = splitLabelQty(line);
            var label = cleanLabel(sp ? sp.label : line);
            if (label === '') { ignored++; continue; }

            rows.push({ label: label, qty: sp ? sp.qty : null });
            pending = rows.length - 1;
        }

        return { rows: rows, ignored: ignored };
    }

    /* ---------------- Appariement aux produits ---------------- */

    /**
     * Index normalisé des produits (issus de $products côté PHP :
     * liste de {key, theoretical, unit_cost}).
     */
    function buildIndex(products) {
        var keys = [];
        var bySpace = {};
        var byTight = {};
        (products || []).forEach(function (p) {
            var key = String(p && p.key != null ? p.key : '').trim();
            if (key === '') return;
            keys.push(key);
            var sn = spaceNorm(key);
            var tn = tightNorm(key);
            if (sn !== '') (bySpace[sn] = bySpace[sn] || []).push(key);
            if (tn !== '') (byTight[tn] = byTight[tn] || []).push(key);
        });
        return { keys: keys, bySpace: bySpace, byTight: byTight };
    }

    /**
     * Départage de plusieurs clés de même forme normalisée : on préfère
     * la candidate dont l'écriture brute égale le libellé (casse
     * ignorée) ; sinon ambigu.
     */
    function resolveCandidates(rawLower, cands) {
        if (cands.length === 1) {
            return { status: 'exact', key: cands[0], candidates: cands };
        }
        var exact = cands.filter(function (k) { return k.toLowerCase() === rawLower; });
        if (exact.length === 1) {
            return { status: 'exact', key: exact[0], candidates: cands };
        }
        return { status: 'ambiguous', key: null, candidates: cands };
    }

    /**
     * Apparie un libellé collé aux clés produits (priorité) :
     *  a. égalité brute normalisée « lisible » (casse/accents/séparateurs) ;
     *  b. égalité de clé normalisée (AliasSuggester + espaces retirés) ;
     *  c. inclusion unique (dans un sens ou l'autre, aiguille >= 3) ->
     *     statut « check » (à vérifier) ;
     *  d. ambigu (plusieurs candidates) ou aucun match -> « ambiguous » /
     *     « none » : la ligne attend un choix manuel.
     *
     * @param {string} label Libellé collé.
     * @param {{keys: string[], bySpace: Object, byTight: Object}} index
     * @returns {{status: 'exact'|'check'|'ambiguous'|'none', key: ?string, candidates: string[]}}
     */
    function match(label, index) {
        var raw = String(label == null ? '' : label).trim();
        if (raw === '' || !index) {
            return { status: 'none', key: null, candidates: [] };
        }
        var rawLower = raw.toLowerCase();
        var sn = spaceNorm(raw);
        var tn = tightNorm(raw);

        if (sn !== '' && index.bySpace[sn]) {
            return resolveCandidates(rawLower, index.bySpace[sn].slice());
        }
        if (tn !== '' && index.byTight[tn]) {
            return resolveCandidates(rawLower, index.byTight[tn].slice());
        }

        if (tn.length >= 3) {
            var cands = [];
            index.keys.forEach(function (k) {
                var t = tightNorm(k);
                if (t === tn || t.length < 3) return;
                if (t.indexOf(tn) !== -1 || tn.indexOf(t) !== -1) {
                    if (cands.indexOf(k) === -1) cands.push(k);
                }
            });
            if (cands.length === 1) {
                return { status: 'check', key: cands[0], candidates: cands };
            }
            if (cands.length > 1) {
                return { status: 'ambiguous', key: null, candidates: cands };
            }
        }

        return { status: 'none', key: null, candidates: [] };
    }

    /* ---------------- Exports ---------------- */

    var api = {
        spaceNorm: spaceNorm,
        tightNorm: tightNorm,
        parse: parse,
        buildIndex: buildIndex,
        match: match,
    };

    if (typeof global !== 'undefined') {
        // Contract demandé par la vue : fenêtre globale.
        global.InventaireTabletParse = function (text) { return parse(text).rows; };
        global.InventaireTabletParseFull = parse;
        global.InventaireTabletMatch = match;
        global.InventaireTabletBuildIndex = buildIndex;
        global.InventaireTabletNorm = { space: spaceNorm, tight: tightNorm };
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})(typeof window !== 'undefined' ? window : this);
