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
   - invoiceToRows  : facture scannée -> lignes de la grille (avec le
     taux de TVA de chaque ligne quand la facture en porte un) ;
   - applyInvoiceState : fusion pure d'une facture dans l'état du
     formulaire (en-tête + grille) ;
   - applyInvoiceToExpense : fusion pure d'un ticket scanné dans
     l'état du formulaire de DÉPENSE (préremplissage non destructif) ;
   - serializeExpenseNotes / expenseNotesFromInvoice : détail des
     produits détectés -> champ `notes` (« Détail tickets : … ») ;
   - buildPurchaseBulkPayload : grille du scan du livre comptable ->
     charge utile du POST /admin/compta/achats/save-bulk (contrat
     as_json=1) — achats (stock + coût de revient) créés depuis le
     ticket, avec rejet motivé des lignes invalides ;
    - downscaleImageSpec / downscaleImage : compression locale d'une
      photo avant l'upload (canvas ; au-delà de 3000 px de côté long
      OU de 4 Mo, JPEG 0,9 puis replis qualité/échelle ; échec ->
      fichier original) ;
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
       Scan de facture : compression photo (canvas) puis OCR serveur.
       ------------------------------------------------------------ */

    /** Endpoint par défaut de l'API « scan de facture » (contrôle :
        routes POST /admin/compta/achats/scan, contrôleur Achats). */
    var SCAN_ENDPOINT = '/admin/compta/achats/scan';

    /**
     * Côté long maximal d'une photo recompressée avant upload (px). Les
     * vraies photos de téléphone font ~2900 px de côté long et le bench
     * réel a validé le pipeline OCR à cette résolution : on ne descend
     * plus à 2200 px, seulement au-delà de 3000 px.
     */
    var DOWNSCALE_MAX_SIDE = 3000;

    /** Qualité JPEG de la recompression canvas (0-1). */
    var DOWNSCALE_QUALITY = 0.9;

    /**
     * Poids d'upload visé pour une photo recompressée (octets). Le PHP
     * du serveur est réglé à 12M et l'app limite à 10 Mo : viser 4 Mo
     * garde une marge large tout en évitant l'« erreur 1 » historique
     * (upload_max_filesize à 2 M sur des photos de 2 Mo et plus).
     */
    var DOWNSCALE_TARGET_BYTES = 4 * 1024 * 1024;

    /**
     * Spécification PURE du redimensionnement d'une photo avant upload
     * (testée côté Node ; la partie canvas — downscaleImage — ne l'est
     * pas). Une photo est recompressée (JPEG, proportions conservées)
     * si son côté long dépasse 3000 px OU si elle pèse plus de 4 Mo —
     * une photo de 2,5 Mo toute à fait nette mais trop lourde pour le
     * serveur doit passer par le canvas, pas par l'« erreur 1 ». Une
     * photo sous les deux limites part telle quelle (zéro perte, OCR
     * fidèle au bench). Dimensions illisibles (0/NaN) -> fichier
     * original (skip) — prudence, jamais de perte.
     *
     * @param {number} fileSize Poids du fichier (octets, informatif).
     * @param {number} w Largeur naturelle (px).
     * @param {number} h Hauteur naturelle (px).
     * @returns {{skip:boolean, maxSide:number, quality:number,
     *            mime:string, targetW:number, targetH:number,
     *            fileSize:number}} skip=true : envoyer le fichier original.
     */
    function downscaleImageSpec(fileSize, w, h) {
        var size = parseInt(fileSize, 10);
        var width = parseInt(w, 10) || 0;
        var height = parseInt(h, 10) || 0;
        var spec = {
            skip: true,
            maxSide: DOWNSCALE_MAX_SIDE,
            quality: DOWNSCALE_QUALITY,
            mime: 'image/jpeg',
            targetW: width > 0 ? width : 0,
            targetH: height > 0 ? height : 0,
            fileSize: isNaN(size) ? 0 : size
        };
        if (width < 1 || height < 1) return spec;          // dimensions inconnues
        var longSide = Math.max(width, height);
        var tooBig = !isNaN(size) && size > DOWNSCALE_TARGET_BYTES;
        if (longSide <= DOWNSCALE_MAX_SIDE && !tooBig) return spec; // rien à faire
        var cap = Math.min(DOWNSCALE_MAX_SIDE, longSide);
        var scale = cap / longSide;
        spec.skip = false;
        spec.targetW = Math.max(1, Math.round(width * scale));
        spec.targetH = Math.max(1, Math.round(height * scale));
        return spec;
    }

    /**
     * Encode la photo dans un canvas aux dimensions/qualité données et
     * résout avec le Blob JPEG produit (null si le canvas échoue).
     */
    function encodeToBlob(img, targetW, targetH, quality) {
        return new Promise(function (resolve) {
            try {
                var canvas = document.createElement('canvas');
                canvas.width = targetW;
                canvas.height = targetH;
                var ctx = canvas.getContext('2d');
                if (!ctx) return resolve(null);
                ctx.drawImage(img, 0, 0, targetW, targetH);
                canvas.toBlob(function (blob) { resolve(blob || null); }, 'image/jpeg', quality);
            } catch (e) {
                resolve(null);
            }
        });
    }

    /**
     * Partie CANVAS du redimensionnement (non testable sous Node) :
     * résout une Promise avec un Blob JPEG recompressé, ou avec le
     * fichier ORIGINAL si tout ne se passe pas comme prévu (fichier non
     * image, canvas indisponible, erreur de décodage, blob null) —
     * l'upload ne doit jamais échouer à cause de la compression. Si le
     * premier encodage dépasse encore le poids visé (photo très
     * détaillée), deux replis sont tentés (qualité 0,72, puis côté long
     * 2200 px) ; le plus léger des blobs obtenus est envoyé.
     */
    function downscaleImage(file) {
        if (!file || String(file.type || '').indexOf('image/') !== 0) {
            return Promise.resolve(file);
        }
        if (typeof document === 'undefined' || typeof window === 'undefined'
            || typeof URL === 'undefined' || !URL.createObjectURL) {
            return Promise.resolve(file);
        }
        return new Promise(function (resolve) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            var settled = false;
            var best = null;
            var done = function (out) {
                if (settled) return;
                settled = true;
                URL.revokeObjectURL(url);
                resolve(out || file);
            };
            img.onload = function () {
                var spec = downscaleImageSpec(file.size, img.naturalWidth, img.naturalHeight);
                if (spec.skip) return done(file);
                var side = Math.max(spec.targetW, spec.targetH);
                var fallback = Math.min(1, 2200 / side);
                var steps = [
                    { w: spec.targetW, h: spec.targetH, q: DOWNSCALE_QUALITY },
                    { w: spec.targetW, h: spec.targetH, q: 0.72 },
                    {
                        w: Math.max(1, Math.round(spec.targetW * fallback)),
                        h: Math.max(1, Math.round(spec.targetH * fallback)),
                        q: 0.8
                    }
                ];
                var i = 0;
                var step = function () {
                    if (i >= steps.length) return done(best);
                    var s = steps[i++];
                    encodeToBlob(img, s.w, s.h, s.q)
                        .then(function (blob) {
                            if (blob && (!best || blob.size < best.size)) best = blob;
                            if (best && best.size <= DOWNSCALE_TARGET_BYTES) {
                                return done(best);
                            }
                            step();
                        })
                        .catch(step);
                };
                step();
            };
            img.onerror = function () { done(file); };
            img.src = url;
        });
    }

    /**
     * Jeton CSRF disponible ? null/undefined/'' = absent — c'est le cas
     * des POST kiosque (auth par jeton dans l'URL, pas de session) : ni
     * champ `_csrf`, ni en-tête X-CSRF-Token ne doivent alors partir.
     */
    function hasCsrf(csrfToken) {
        return csrfToken !== null && csrfToken !== undefined && String(csrfToken) !== '';
    }

    /**
     * POST commun aux deux appels du scan. Le jeton CSRF part à la
     * fois en champ `_csrf` du FormData et en en-tête `X-CSRF-Token` :
     * Csrf::checkRequest (app/core/Csrf.php) accepte l'un ou l'autre.
     * ABSENT (null/'') en kiosque : aucune trace de CSRF dans la
     * requête. Réponse attendue : {ok:true,...} ; en cas d'erreur (JSON
     * {ok:false,error} ou HTTP non-JSON), la promesse est rejetée avec
     * un message en français.
     */
    function scanRequest(body, csrfToken, endpoint) {
        var headers = hasCsrf(csrfToken) ? { 'X-CSRF-Token': String(csrfToken) } : {};
        return fetch(endpoint || SCAN_ENDPOINT, {
            method: 'POST',
            headers: headers,
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
     * au endpoint de scan en multipart (champ `file`). Une PHOTO est
     * d'abord recompressée en local (canvas : côté long ramené à
     * 2200 px, JPEG qualité 0,9 — upload ~3× plus rapide, OCR plus
     * fiable) ; tout échec de la compression renvoie le fichier original,
     * et les PDF ne sont jamais touchés. Le nom suit la recompression
     * (extension .jpg) pour que le contrôle extension/MIME du serveur
     * reste cohérent. Renvoie le JSON du serveur {ok, source, text,
     * invoice} ; rejette avec le message français du serveur sinon.
     * `endpoint` optionnel (URL rendue par la vue via url(), pour
     * respecter un éventuel sous-chemin). `csrfToken` optionnel : null
     * (POST kiosque sans session) = pas de champ `_csrf` dans le FormData.
     */
    function scanUpload(file, csrfToken, endpoint) {
        return downscaleImage(file)
            .catch(function () { return file; })
            .then(function (sendFile) {
                var name = (file && file.name) || 'facture';
                if (sendFile && sendFile !== file && sendFile.type === 'image/jpeg') {
                    // Blob recompressé = JPEG : l'extension doit suivre,
                    // sinon le serveur refuse (extension ≠ MIME réel).
                    name = String(name).replace(/\.(jpe?g|png|webp|gif|bmp|heic|heif)$/i, '') + '.jpg';
                }
                var fd = new FormData();
                fd.append('file', sendFile, name);
                if (hasCsrf(csrfToken)) fd.append('_csrf', String(csrfToken));
                return scanRequest(fd, csrfToken, endpoint);
            });
    }

    /**
     * Envoie le texte OCR (édité par l'utilisateur) au même endpoint
     * (champ `text`). Même contrat que scanUpload (csrfToken null = pas
     * de champ `_csrf`).
     */
    function scanParseText(text, csrfToken, endpoint) {
        var fd = new FormData();
        fd.append('text', String(text == null ? '' : text));
        if (hasCsrf(csrfToken)) fd.append('_csrf', String(csrfToken));
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
     * [{key, qty, total, notes, vat_rate}]
     * - key      : libellé produit — les lignes sans libellé sont ignorées ;
     * - qty      : unités (entier, < 1 ou absent -> 1) ;
     * - total    : montant au format français (« 17,28 »), '' si absent ;
     * - notes    : « EAN … · art. … » (ou le champ notes du serveur) ;
     * - vat_rate : taux de TVA DE LA LIGNE canonisé (« 5.5 ») si la
     *   facture en porte un (line.vat_rate), sinon null (héritera de
     *   l'en-tête).
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
            rows.push({ key: key, qty: qty, total: total, notes: lineNotes(line), vat_rate: normVatRate(line.vat_rate) });
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

    /* ------------------------------------------------------------
        Livre comptable : scan -> ACHATS (stock + coût de revient).
        POST /admin/compta/achats/save-bulk, contrat as_json=1.
        ------------------------------------------------------------ */

    /** Booléen de case à cocher tolérant (true, 1, '1'). */
    function isTruthyFlag(v) {
        return v === true || v === 1 || v === '1';
    }

    /**
     * Construit la charge utile d'une création d'achats en lot à partir
     * de l'en-tête de la facture scannée et des lignes du pavé « Détail
     * des produits » (livre comptable). L'objet renvoyé est PLAT, prêt
     * à être recopié dans un FormData — NE PAS y poster la propriété
     * `rejected`, réservée à l'affichage des lignes refusées.
     *
     * @param {Object} header En-tête commun à toutes les lignes :
     *   {purchased_at, supplier, invoice_number,
     *    amount_basis ('ht'|'ttc'), vat_rate (taux global ou null),
     *    update_cost (bool)}.
     * @param {Object[]} rows Lignes de la grille :
     *   [{key, qty, total, vat_rate (null|5.5|'5,5'|…), no_stock (bool)}].
     *
     * @returns {Object}
     *   - purchased_at / supplier / invoice_number : chaînes trimées ;
     *   - amount_basis : 'ht' | 'ttc' (défaut 'ht') ;
     *   - vat_rate : taux GLOBAL canonisé (« 5.5 ») — CLÉ ABSENTE si
     *     null (TVA mixte : seules les lignes portent un taux) ;
     *   - update_cost : '1' — CLÉ ABSENTE si false (le contrôleur teste
     *     la présence du champ, pas sa valeur) ;
     *   - product_key / quantity / total_amount / vat_rate_lines :
     *     tableaux PARALLÈLES des lignes valides (total à 2 décimales
     *     en chaîne, quantity entier ; vat_rate_lines est posté sous le
     *     nom `vat_rate[]` — '' = la ligne hérite du taux d'en-tête) ;
     *   - no_stock_indexes : indexes (0-based) des lignes « hors stock »,
     *     à poster sous `no_stock[N]=1` (format indexé lu par
     *     AdminStockController::savePurchasesBulk, aligné sur
     *     product_key[N]) ;
     *   - rejected : [{index, key, reason}] des lignes rejetées —
     *     produit manquant (key vide), quantité invalide (qty < 1 ou
     *     non entière), montant invalide (<= 0 ou illisible).
     */
    function buildPurchaseBulkPayload(header, rows) {
        var h = header && typeof header === 'object' ? header : {};
        var list = Array.isArray(rows) ? rows : [];

        var payload = {
            purchased_at: cleanStr(h.purchased_at),
            supplier: cleanStr(h.supplier),
            invoice_number: cleanStr(h.invoice_number),
            amount_basis: h.amount_basis === 'ttc' ? 'ttc' : 'ht',
            product_key: [],
            quantity: [],
            total_amount: [],
            vat_rate_lines: [],
            no_stock_indexes: []
        };
        var globalVat = normVatRate(h.vat_rate);
        if (globalVat !== null) payload.vat_rate = globalVat;
        if (isTruthyFlag(h.update_cost)) payload.update_cost = '1';

        var rejected = [];
        for (var i = 0; i < list.length; i++) {
            var r = list[i] || {};
            var key = cleanStr(r.key !== undefined ? r.key : r.label);
            var qty = parseInt(r.qty, 10);
            var total = typeof r.total === 'number' ? r.total : parseAmount(r.total);

            // Lignes invalides : rejetées avec raison, hors des tableaux —
            // les motifs reprennent les libellés du contrôleur PHP.
            if (key === '') {
                rejected.push({ index: i, key: '', reason: 'produit manquant' });
                continue;
            }
            if (!isFinite(qty) || qty < 1) {
                rejected.push({ index: i, key: key, reason: 'quantité invalide' });
                continue;
            }
            if (!isFinite(total) || total <= 0) {
                rejected.push({ index: i, key: key, reason: 'montant invalide' });
                continue;
            }

            payload.product_key.push(key);
            payload.quantity.push(qty);
            payload.total_amount.push(total.toFixed(2));
            payload.vat_rate_lines.push(normVatRate(r.vat_rate) || '');
            if (isTruthyFlag(r.no_stock)) {
                payload.no_stock_indexes.push(payload.product_key.length - 1);
            }
        }
        payload.rejected = rejected;
        return payload;
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
        buildPurchaseBulkPayload: buildPurchaseBulkPayload,
        downscaleImageSpec: downscaleImageSpec,
        downscaleImage: downscaleImage,
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
