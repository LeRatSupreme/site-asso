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
   - findDuplicateLine : ligne en double dans la commande en cours ;
   - invoiceToRows  : facture scannée -> lignes de la grille ;
   - applyInvoiceState : fusion pure d'une facture dans l'état du
     formulaire (en-tête + grille) ;
   - applyInvoiceToExpense : fusion pure d'un ticket scanné dans
     l'état du formulaire de DÉPENSE (préremplissage non destructif) ;
   - serializeExpenseNotes / expenseNotesFromInvoice : détail des
     produits détectés -> champ `notes` (« Détail tickets : … ») ;
   - scanUpload / scanParseText : envoi du fichier ou du texte OCR à
     l'endpoint de scan (JSON {ok, invoice}).
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

    /* ------------------------------------------------------------
       Scan de facture : OCR serveur puis analyse du texte renvoyé.
       ------------------------------------------------------------ */

    /** Endpoint par défaut de l'API « scan de facture » (contrôle :
        routes POST /admin/compta/achats/scan, contrôleur Achats). */
    var SCAN_ENDPOINT = '/admin/compta/achats/scan';

    /**
     * POST commun aux deux appels du scan. Le jeton CSRF part à la
     * fois en champ `_csrf` du FormData et en en-tête `X-CSRF-Token` :
     * Csrf::checkRequest (app/core/Csrf.php) accepte l'un ou l'autre,
     * le Router l'exige pour toute requête POST. Réponse attendue :
     * {ok:true,...} ; en cas d'erreur (JSON {ok:false,error} ou HTTP
     * non-JSON), la promesse est rejetée avec un message en français.
     */
    function scanRequest(body, csrfToken, endpoint) {
        return fetch(endpoint || SCAN_ENDPOINT, {
            method: 'POST',
            headers: { 'X-CSRF-Token': String(csrfToken || '') },
            credentials: 'same-origin',
            body: body
        }).then(function (res) {
            return res.json().catch(function () { return null; }).then(function (json) {
                if (res.ok && json && json.ok === true) return json;
                throw new Error(json && json.error
                    ? String(json.error)
                    : 'Erreur ' + res.status + ' : l\u2019analyse de la facture a échoué.');
            });
        });
    }

    /**
     * Envoie un fichier (photo/PDF de facture, 10 Mo max côté serveur)
     * au endpoint de scan en multipart (champ `file`). Renvoie le JSON
     * du serveur {ok, source, text, invoice} ; rejette avec le message
     * français du serveur sinon. `endpoint` optionnel (URL rendue par
     * la vue via url(), pour respecter un éventuel sous-chemin).
     */
    function scanUpload(file, csrfToken, endpoint) {
        var fd = new FormData();
        fd.append('file', file, (file && file.name) || 'facture');
        fd.append('_csrf', String(csrfToken || ''));
        return scanRequest(fd, csrfToken, endpoint);
    }

    /**
     * Envoie le texte OCR (édité par l'utilisateur) au même endpoint
     * (champ `text`). Même contrat que scanUpload.
     */
    function scanParseText(text, csrfToken, endpoint) {
        var fd = new FormData();
        fd.append('text', String(text == null ? '' : text));
        fd.append('_csrf', String(csrfToken || ''));
        return scanRequest(fd, csrfToken, endpoint);
    }

    /* ------------------------------------------------------------
       Facture analysée -> état du formulaire (fonctions pures,
       testées par tests/js/compta-saisie.test.js).
       ------------------------------------------------------------ */

    /** Champ texte nettoyé : null/undefined -> '', sinon trim(). */
    function cleanStr(v) {
        return String(v == null ? '' : v).trim();
    }

    /** Taux de TVA canonisé en chaîne (« 5.5 ») ou null si absent/invalide. */
    function normVatRate(v) {
        if (v === null || v === undefined || v === '') return null;
        var n = typeof v === 'number' ? v : parseFloat(cleanStr(v).replace(',', '.'));
        return isFinite(n) ? String(n) : null;
    }

    /** Notes d'une ligne : champ `notes` s'il existe, sinon
        « EAN … · art. … » reconstitué ('' si rien des deux). */
    function lineNotes(line) {
        var notes = cleanStr(line && line.notes);
        if (notes !== '') return notes;
        var parts = [];
        var ean = cleanStr(line && line.ean);
        var art = cleanStr(line && line.article);
        if (ean !== '') parts.push('EAN ' + ean);
        if (art !== '') parts.push('art. ' + art);
        return parts.join(' · ');
    }

    /**
     * Lignes d'une facture analysée -> lignes de la grille, dans le
     * même format que le collage (parsePasteLine) :
     * [{key, qty, total, notes}]
     * - key   : libellé produit — les lignes sans libellé sont ignorées ;
     * - qty   : unités (entier, < 1 ou absent -> 1) ;
     * - total : montant au format français (« 17,28 »), '' si absent ;
     * - notes : « EAN … · art. … » (ou le champ notes du serveur).
     */
    function invoiceToRows(invoice) {
        var lines = invoice && Array.isArray(invoice.lines) ? invoice.lines : [];
        var rows = [];
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i] || {};
            var key = cleanStr(line.label);
            if (key === '') continue;
            var qty = parseInt(line.units, 10);
            if (!isFinite(qty) || qty < 1) qty = 1;
            var total = '';
            var n = typeof line.total === 'number'
                ? line.total
                : parseFloat(cleanStr(line.total).replace(',', '.'));
            if (isFinite(n) && n > 0) total = n.toFixed(2).replace('.', ',');
            rows.push({ key: key, qty: qty, total: total, notes: lineNotes(line) });
        }
        return rows;
    }

    /**
     * Fusion pure d'une facture analysée dans l'état courant du
     * formulaire. Renvoie un NOUVEL état
     * {supplier, invoice_number, purchased_at, vat_rate, amount_basis,
     * rows} : les champs null/invalides de la facture laissent l'état
     * courant inchangé ; vat_rate est canonisé (« 5.5 ») pour être
     * confronté aux options du select ; purchased_at n'est repris qu'au
     * format date HTML (aaaa-mm-jj, input[type=date]) ; amount_basis
     * n'accepte que « ht » / « ttc » ; rows = invoiceToRows(invoice).
     */
    function applyInvoiceState(state, invoice) {
        var cur = state && typeof state === 'object' ? state : {};
        var inv = invoice && typeof invoice === 'object' ? invoice : {};

        var purchasedAt = cleanStr(inv.purchased_at);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(purchasedAt)) purchasedAt = cleanStr(cur.purchased_at);

        var vat = normVatRate(inv.vat_rate);
        if (vat === null) vat = normVatRate(cur.vat_rate);

        return {
            supplier: cleanStr(inv.supplier) !== '' ? cleanStr(inv.supplier) : cleanStr(cur.supplier),
            invoice_number: cleanStr(inv.invoice_number) !== '' ? cleanStr(inv.invoice_number) : cleanStr(cur.invoice_number),
            purchased_at: purchasedAt,
            vat_rate: vat,
            amount_basis: (inv.amount_basis === 'ht' || inv.amount_basis === 'ttc')
                ? inv.amount_basis
                : (cur.amount_basis === 'ttc' ? 'ttc' : 'ht'),
            rows: invoiceToRows(inv)
        };
    }

    /* ------------------------------------------------------------
       Ticket scanné -> formulaire de DÉPENSE (fonctions pures,
       testées par tests/js/compta-saisie.test.js). Utilisées par la
       saisie express du livre comptable et par la page Dépenses.
       ------------------------------------------------------------ */

    /**
     * Fusion pure d'un ticket analysé dans l'état du formulaire de
     * dépense. Préremplissage NON destructif : seuls les champs VIDES
     * reprennent la valeur du ticket ; un champ déjà renseigné garde
     * sa valeur et est signalé dans `skipped` (le pavé « Informations
     * extraites » de la vue affiche alors la valeur du ticket).
     *
     * @param {Object} state État courant du formulaire :
     *   {spent_at, label, amount, basis, vat_rate, vat_amount,
     *    invoice_number} — chaînes ('' = vide, vat_rate '' = aucune).
     * @param {Object} invoice Ticket analysé (invoice de la réponse
     *   du scan) ; null/undefined autorisé.
     * @returns {{spent_at:string, label:string, amount:string,
     *   basis:string, vat_rate:?string, vat_amount:string,
     *   invoice_number:string, filled:bool,
     *   skipped:list<{field:string, reason:string}>}}
     *   basis n'accepte que « ht » / « ttc » (défaut : état courant,
     *   sinon « ttc ») ; amount est au format français (« 37,24 ») ;
     *   vat_amount (TVA multi-taux) = Σ vat_rates[].vat à 2 décimales.
     */
    function applyInvoiceToExpense(state, invoice) {
        var cur = state && typeof state === 'object' ? state : {};
        var inv = invoice && typeof invoice === 'object' ? invoice : {};
        var skipped = [];
        var filled = false;

        /** Champ texte : conserve si déjà renseigné, sinon prend le
            ticket ; extract ''/invalide -> état courant conservé. */
        function pickText(field, current, extracted) {
            var from = cleanStr(extracted);
            if (from === '') return cleanStr(current);
            if (cleanStr(current) !== '') {
                skipped.push({
                    field: field,
                    reason: 'déjà renseigné (« ' + cleanStr(current) + ' ») — ticket : « ' + from + ' »'
                });
                return cleanStr(current);
            }
            filled = true;
            return from;
        }

        /** Montant numérique du ticket -> chaîne française, ou null. */
        function ticketAmount(v) {
            var n = typeof v === 'number' ? v : parseFloat(cleanStr(v).replace(',', '.'));
            return isFinite(n) && n > 0 ? n.toFixed(2).replace('.', ',') : null;
        }

        // Base des montants : celle du ticket si valide (chips, pas un
        // contenu saisi — pas de logique « skipped »), sinon l'état courant.
        var basis = (inv.amount_basis === 'ht' || inv.amount_basis === 'ttc')
            ? inv.amount_basis
            : (cur.basis === 'ht' ? 'ht' : 'ttc');
        if (basis !== cur.basis && cleanStr(cur.basis) !== '') filled = true;

        // Montant : TTC du ticket si base TTC, HT sinon ; absent -> ''.
        var amount = pickText('amount', cur.amount,
            ticketAmount(basis === 'ttc' ? inv.total_ttc : inv.total_ht));

        // Taux unique seulement : null en multi-taux (vat_amount prend
        // alors le relais) ; « '' » (Aucune) compte comme vide.
        var vatRate = normVatRate(inv.vat_rate);
        if (vatRate !== null && cleanStr(cur.vat_rate) !== '') {
            skipped.push({
                field: 'vat_rate',
                reason: 'déjà renseigné (« ' + cleanStr(cur.vat_rate) + ' ») — ticket : « ' + vatRate + ' »'
            });
            vatRate = cleanStr(cur.vat_rate);
        } else if (vatRate !== null) {
            filled = true;
        } else {
            vatRate = normVatRate(cur.vat_rate);
        }

        // TVA multi-taux : somme des TVA détaillées (2 décimales),
        // injectée dans le champ `vat_amount` (lu par save()).
        var rates = Array.isArray(inv.vat_rates) ? inv.vat_rates : [];
        var vatAmount = '';
        if (normVatRate(inv.vat_rate) === null && rates.length >= 2) {
            var sum = 0;
            for (var i = 0; i < rates.length; i++) {
                var v = rates[i] && typeof rates[i].vat === 'number' ? rates[i].vat : parseFloat(cleanStr(rates[i] && rates[i].vat).replace(',', '.'));
                if (isFinite(v)) sum += v;
            }
            vatAmount = (Math.round(sum * 100) / 100).toFixed(2).replace('.', ',');
        }
        if (vatAmount !== '' && cleanStr(cur.vat_amount) !== '') {
            skipped.push({
                field: 'vat_amount',
                reason: 'déjà renseigné (« ' + cleanStr(cur.vat_amount) + ' ») — ticket : « ' + vatAmount + ' »'
            });
            vatAmount = cleanStr(cur.vat_amount);
        } else if (vatAmount !== '') {
            filled = true;
        }

        // Date : seul le format aaaa-mm-jj (input[type=date]) est repris.
        var spentAt = cleanStr(inv.purchased_at);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(spentAt)) spentAt = null;

        return {
            spent_at: pickText('spent_at', cur.spent_at, spentAt),
            label: pickText('label', cur.label, cleanStr(inv.supplier)),
            amount: amount,
            basis: basis,
            vat_rate: vatRate,
            vat_amount: vatAmount,
            invoice_number: pickText('invoice_number', cur.invoice_number, cleanStr(inv.invoice_number)),
            filled: filled,
            skipped: skipped
        };
    }

    /**
     * Détail des produits détectés -> champ `notes` de la dépense :
     * « Détail tickets : 24 × RED BULL (59,16 €) · 3 × NUTELLA (8,07 €) ».
     * rows = [{key, qty, total}] (format d'invoiceToRows — key est
     * aussi accepté sous le nom label) ; les lignes sans libellé sont
     * ignorées, un total absent/invalide donne une ligne sans
     * parenthèses ; [] -> '' (aucune note injectée).
     */
    function serializeExpenseNotes(rows) {
        var list = Array.isArray(rows) ? rows : [];
        var parts = [];
        for (var i = 0; i < list.length; i++) {
            var r = list[i] || {};
            var key = cleanStr(r.key !== undefined ? r.key : r.label);
            if (key === '') continue;
            var qty = parseInt(r.qty, 10);
            if (!isFinite(qty) || qty < 1) qty = 1;
            var piece = qty + ' \u00d7 ' + key;
            var n = typeof r.total === 'number'
                ? r.total
                : parseFloat(cleanStr(r.total).replace(',', '.'));
            if (isFinite(n) && n > 0) piece += ' (' + n.toFixed(2).replace('.', ',') + ' \u20ac)';
            parts.push(piece);
        }
        return parts.length > 0 ? 'D\u00e9tail tickets : ' + parts.join(' \u00b7 ') : '';
    }

    /**
     * Compose invoiceToRows + serializeExpenseNotes : le détail des
     * lignes d'un ticket scanné, prêt pour le champ `notes` ('' si le
     * ticket n'a aucune ligne exploitable).
     */
    function expenseNotesFromInvoice(invoice) {
        return serializeExpenseNotes(invoiceToRows(invoice));
    }

    var ComptaSaisie = {
        normKey: normKey,
        parseAmount: parseAmount,
        fmt3: fmt3,
        splitVat: splitVat,
        unitHint: unitHint,
        parsePasteLine: parsePasteLine,
        findDuplicateLine: findDuplicateLine,
        invoiceToRows: invoiceToRows,
        applyInvoiceState: applyInvoiceState,
        applyInvoiceToExpense: applyInvoiceToExpense,
        serializeExpenseNotes: serializeExpenseNotes,
        expenseNotesFromInvoice: expenseNotesFromInvoice,
        scanUpload: scanUpload,
        scanParseText: scanParseText
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
