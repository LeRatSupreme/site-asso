/* =========================================================
   AEIC — Comptabilité : helpers purs de la saisie d'achats
   (grille « Saisir des achats », page Opérations).

   Logique pure extraite de la vue pour être testée
   automatiquement (tests/js/compta-saisie.test.js, exécuté
   via Node.js par tests/Unit/PurchaseGridJsTest.php).

   Comportements couverts :
   - normKey        : normalisation des clés produit (miroir JS de
                      StockPublic::normalizeKey) pour l'anti-doublon ;
   - parseAmount    : montant à la française (« 1 234,56 ») ;
   - parsePasteLine : collage « Nom ; Qté ; Montant » (tabs Excel) ;
   - splitVat       : décomposition HT/TTC selon la base et le taux ;
   - unitHint       : libellé « ≈ HT · TTC € /u » d'une ligne ;
   - findDuplicateLine : ligne en double dans la commande en cours.
   ========================================================= */
(function (global) {
    'use strict';

    /**
     * Normalisation d'une clé produit pour l'anti-doublon : minuscules,
     * sans accents, séparateurs et espaces supprimés (« Red Bull »,
     * « RedBull » et « red-bull » convergent vers « redbull »).
     *
     * Miroir JS de StockPublic::normalizeKey (AliasSuggester::normalizeKey
     * + retrait des espaces). Limites assumées, identiques côté PHP :
     * « & » et apostrophes ne sont PAS des séparateurs (M&M's ≠ MMS —
     * c'est le rôle du Mapping libellés de les rattacher).
     */
    function normKey(v) {
        return String(v || '')
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[_\-\.()\[\]\{\},;:\/]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/ /g, '');
    }

    /**
     * Montant saisi à la française : espaces de milliers et virgule
     * décimale acceptés. Renvoie NaN si non numérique ou ambigu
     * (« 12,3,4 » a plusieurs séparateurs : refusé).
     */
    function parseAmount(v) {
        var s = String(v == null ? '' : v).replace(/\s/g, '');
        if (!/^-?\d+(?:[.,]\d+)?$/.test(s)) return NaN;
        return parseFloat(s.replace(',', '.'));
    }

    /** Montant à 3 décimales, virgule française + « € ». */
    function fmt3(n) {
        return n.toFixed(3).replace('.', ',') + ' €';
    }

    /**
     * Décomposition HT/TTC d'un montant saisi, selon la base et le taux.
     * - base « ht »  : le montant est HT, TTC = montant × (1 + taux/100) ;
     * - base « ttc » : le montant est TTC, HT = montant / (1 + taux/100).
     * Taux invalide (NaN) traité comme 0 %.
     */
    function splitVat(amount, ratePct, basis) {
        var r = parseFloat(ratePct);
        if (!isFinite(r)) r = 0;
        if (basis === 'ttc') {
            var ht = r > 0 ? amount / (1 + r / 100) : amount;
            return { ht: ht, ttc: amount };
        }
        var ttc = r > 0 ? amount * (1 + r / 100) : amount;
        return { ht: amount, ttc: ttc };
    }

    /**
     * Aide « ≈ HT · TTC € /u » d'une ligne : chaîne vide si le montant
     * ou la quantité n'est pas exploitable.
     */
    function unitHint(amount, qty, ratePct, basis) {
        if (!isFinite(amount) || amount <= 0 || !qty || qty < 1) return '';
        var s = splitVat(amount, ratePct, basis);
        return '≈ ' + (s.ht / qty).toFixed(3).replace('.', ',') +
            ' · ' + (s.ttc / qty).toFixed(3).replace('.', ',') + ' € TTC /u';
    }

    /**
     * Analyse d'une ligne collée : « Nom ; Qté ; Montant » (séparateur
     * point-virgule ou tabulation Excel). Qté et Montant optionnels :
     * « Nom ; Montant » (montant repéré à sa virgule/point) et « Nom ; Qté »
     * (entier court) sont acceptés ; « Nom » seul vaut Qté 1.
     *
     * @returns {?{name:string, qty:number, amount:string}} null si sans nom.
     */
    function parsePasteLine(line) {
        line = String(line == null ? '' : line).trim();
        var sep = line.indexOf('\t') !== -1 ? '\t' : (line.indexOf(';') !== -1 ? ';' : null);
        var parts = (sep ? line.split(sep) : [line]).map(function (p) { return p.trim(); });
        var name = parts[0] || '';
        if (name === '') return null;

        var qty = 1;
        var amount = '';
        if (parts.length >= 3) {
            qty = parseInt(parts[1], 10) || 1;
            amount = parts[2];
        } else if (parts.length === 2) {
            if (/^\d{1,4}$/.test(parts[1])) {
                qty = parseInt(parts[1], 10) || 1;
            } else {
                amount = parts[1];
            }
        }

        return { name: name, qty: qty, amount: amount };
    }

    /**
     * Détection de doublon dans la commande en cours : pour la ligne
     * `index`, renvoie le numéro (1-based) de la PREMIÈRE autre ligne de
     * même clé normalisée, sinon -1.
     *
     * @param {string[]} keys Noms de produits de chaque ligne (ordre des lignes).
     * @param {number} index  Index 0-based de la ligne contrôlée.
     * @returns {number} Ligne 1-based du premier doublon, ou -1.
     */
    function findDuplicateLine(keys, index) {
        var me = normKey(keys[index]);
        if (me === '') return -1;
        for (var i = 0; i < keys.length; i++) {
            if (i === index) continue;
            var other = String(keys[i] == null ? '' : keys[i]).trim();
            if (other !== '' && normKey(other) === me) return i + 1;
        }
        return -1;
    }

    var ComptaSaisie = {
        normKey: normKey,
        parseAmount: parseAmount,
        fmt3: fmt3,
        splitVat: splitVat,
        unitHint: unitHint,
        parsePasteLine: parsePasteLine,
        findDuplicateLine: findDuplicateLine
    };

    /* Navigateur : global partagé avec les scripts inline des vues. */
    if (typeof window !== 'undefined') {
        window.ComptaSaisie = ComptaSaisie;
    }
    /* Node.js : export CommonJS pour le harnais de tests. */
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = ComptaSaisie;
    }
})(typeof window !== 'undefined' ? window : this);
