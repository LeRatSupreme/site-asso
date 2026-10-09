'use strict';

/**
 * Tests de la logique pure de la grille de saisie d'achats
 * (public/assets/js/compta-saisie.js) — exécuté avec Node.js :
 *
 *     node tests/js/compta-saisie.test.js
 *
 * Pont PHPUnit : tests/Unit/PurchaseGridJsTest.php (code 0 = succès).
 * Aucune dépendance : le module 'assert' natif suffit.
 */

const assert = require('assert');
const H = require('../../public/assets/js/compta-saisie.js');

let passed = 0;
const failures = [];

/** Mini-harnais : chaque cas doit passer sans lever. */
function t(name, fn) {
    try {
        fn();
        passed++;
    } catch (e) {
        failures.push('  ✗ ' + name + ' : ' + (e && e.message ? e.message : e));
    }
}

/* ------------------------------------------------------------------ *
 * normKey — miroir JS de StockPublic::normalizeKey (anti-doublons)
 * ------------------------------------------------------------------ */

t('normKey : casse, accents et espaces supprimés', function () {
    assert.strictEqual(H.normKey('Red Bull'), 'redbull');
    assert.strictEqual(H.normKey('  Crème Fraîche  '), 'cremefraiche');
    assert.strictEqual(H.normKey('Oasis Pomme Cassis Framboise'), 'oasispommecassisframboise');
});

t('normKey : séparateurs _ - . ( ) convergents', function () {
    assert.strictEqual(H.normKey('Bueno_white'), 'buenowhite');
    assert.strictEqual(H.normKey('Coca-Cherry'), 'cocacherry');
    assert.strictEqual(H.normKey('red.bull'), 'redbull');
    assert.strictEqual(H.normKey('Perrier (33cl)'), 'perrier33cl');
    assert.strictEqual(H.normKey('Bueno white'), 'buenowhite');
    assert.strictEqual(H.normKey('Bueno_white'), H.normKey('Bueno white'), '« Bueno_white » et « Bueno white » désignent le même produit.');
    assert.strictEqual(H.normKey('Redbull Peach'), H.normKey('red-bull peach'), 'Variantes d’écriture d’un même produit.');
});

t('normKey : « & » et apostrophes sont conservés (limite assumée)', function () {
    // C’est exactement la classe de bug MMS : « m&ms » ≠ « MMS » en clé
    // normalisée — le Mapping libellés doit les rattacher explicitement.
    assert.strictEqual(H.normKey('M&Ms'), 'm&ms');
    assert.notStrictEqual(H.normKey('M&Ms'), H.normKey('MMS'));
});

t('normKey : vide et null', function () {
    assert.strictEqual(H.normKey(''), '');
    assert.strictEqual(H.normKey(null), '');
    assert.strictEqual(H.normKey(undefined), '');
    assert.strictEqual(H.normKey('   '), '');
});

/* ------------------------------------------------------------------ *
 * parseAmount — montant à la française
 * ------------------------------------------------------------------ */

t('parseAmount : virgule décimale et espaces de milliers', function () {
    assert.strictEqual(H.parseAmount('18,60'), 18.6);
    assert.strictEqual(H.parseAmount('1 234,56'), 1234.56);
    assert.strictEqual(H.parseAmount('1234.56'), 1234.56);
    assert.strictEqual(H.parseAmount(' 22,113 '), 22.113);
});

t('parseAmount : entrées invalides -> NaN', function () {
    assert.ok(Number.isNaN(H.parseAmount('abc')));
    assert.ok(Number.isNaN(H.parseAmount('')));
    assert.ok(Number.isNaN(H.parseAmount(null)));
    assert.ok(Number.isNaN(H.parseAmount('12,3,4')));
});

/* ------------------------------------------------------------------ *
 * splitVat — décomposition HT/TTC selon la base et le taux
 * ------------------------------------------------------------------ */

t('splitVat : base HT, la TVA est ajoutée', function () {
    assert.deepStrictEqual(H.splitVat(18.6, 20, 'ht'), { ht: 18.6, ttc: 18.6 * 1.2 });
    assert.deepStrictEqual(H.splitVat(100, 5.5, 'ht'), { ht: 100, ttc: 105.5 });
});

t('splitVat : base TTC, la TVA est déduite', function () {
    assert.deepStrictEqual(H.splitVat(105.5, 5.5, 'ttc'), { ht: 100, ttc: 105.5 });
    const s = H.splitVat(22.32, 20, 'ttc');
    assert.ok(Math.abs(s.ht - 18.6) < 1e-9, '22,32 € TTC / 1,20 = 18,60 € HT');
});

t('splitVat : taux 0 % ou invalide -> HT = TTC', function () {
    assert.deepStrictEqual(H.splitVat(50, 0, 'ht'), { ht: 50, ttc: 50 });
    assert.deepStrictEqual(H.splitVat(50, 0, 'ttc'), { ht: 50, ttc: 50 });
    assert.deepStrictEqual(H.splitVat(50, NaN, 'ht'), { ht: 50, ttc: 50 });
});

/* ------------------------------------------------------------------ *
 * unitHint — libellé « ≈ HT · TTC € /u »
 * ------------------------------------------------------------------ */

t('unitHint : hint complet pour 18,60 € les 24', function () {
    assert.strictEqual(H.unitHint(18.6, 24, 5.5, 'ht'),
        '≈ 0,775 · 0,818 € TTC /u');
});

t('unitHint : vide si montant ou quantité inexploitable', function () {
    assert.strictEqual(H.unitHint(0, 24, 5.5, 'ht'), '');
    assert.strictEqual(H.unitHint(NaN, 24, 5.5, 'ht'), '');
    assert.strictEqual(H.unitHint(18.6, 0, 5.5, 'ht'), '');
    assert.strictEqual(H.unitHint(18.6, -3, 5.5, 'ht'), '');
    assert.strictEqual(H.unitHint(18.6, NaN, 5.5, 'ht'), '');
});

/* ------------------------------------------------------------------ *
 * parsePasteLine — collage « Nom ; Qté ; Montant »
 * ------------------------------------------------------------------ */

t('parsePasteLine : Nom ; Qté ; Montant', function () {
    assert.deepStrictEqual(H.parsePasteLine('Coca 33cl ; 24 ; 18,60'),
        { name: 'Coca 33cl', qty: 24, amount: '18,60' });
});

t('parsePasteLine : tabulations Excel', function () {
    assert.deepStrictEqual(H.parsePasteLine('Fanta Orange 33cl\t24\t15,20'),
        { name: 'Fanta Orange 33cl', qty: 24, amount: '15,20' });
});

t('parsePasteLine : deux parties — montant repéré à sa virgule', function () {
    assert.deepStrictEqual(H.parsePasteLine('Bonbons ; 10,45'),
        { name: 'Bonbons', qty: 1, amount: '10,45' });
});

t('parsePasteLine : deux parties — entier court = quantité', function () {
    assert.deepStrictEqual(H.parsePasteLine('Coca ; 24'),
        { name: 'Coca', qty: 24, amount: '' });
});

t('parsePasteLine : nom seul -> Qté 1, montant vide', function () {
    assert.deepStrictEqual(H.parsePasteLine('Coca'),
        { name: 'Coca', qty: 1, amount: '' });
});

t('parsePasteLine : espaces et bornes tolérés', function () {
    assert.deepStrictEqual(H.parsePasteLine('  Eau ; 96 ; 14,88  '),
        { name: 'Eau', qty: 96, amount: '14,88' });
});

t('parsePasteLine : lignes inutilisables -> null', function () {
    assert.strictEqual(H.parsePasteLine(''), null);
    assert.strictEqual(H.parsePasteLine('   '), null);
    assert.strictEqual(H.parsePasteLine(null), null);
    assert.strictEqual(H.parsePasteLine('; 24 ; 18,60'), null, 'Sans nom de produit : refusé.');
});

/* ------------------------------------------------------------------ *
 * findDuplicateLine — doublon dans la commande en cours
 * ------------------------------------------------------------------ */

t('findDuplicateLine : aucun doublon -> -1', function () {
    assert.strictEqual(H.findDuplicateLine(['Coca', 'Fanta', 'Perrier'], 2), -1);
});

t('findDuplicateLine : même produit à une écriture différente détecté', function () {
    assert.strictEqual(H.findDuplicateLine(['Red Bull', 'Fanta', 'red bull'], 2), 1,
        '« red bull » ligne 3 duplique « Red Bull » ligne 1.');
});

t('findDuplicateLine : premier doublon retenu en 1-based', function () {
    assert.strictEqual(H.findDuplicateLine(['Coca', 'coca ', 'COCA'], 2), 1);
    assert.strictEqual(H.findDuplicateLine(['Coca', 'coca ', 'COCA'], 1), 1);
});

t('findDuplicateLine : ligne vide ou sans nom ignorée', function () {
    assert.strictEqual(H.findDuplicateLine(['', '  ', ''], 2), -1, 'Les lignes vides ne se dupliquent pas.');
    assert.strictEqual(H.findDuplicateLine(['', 'Fanta', ''], 2), -1);
});

t('findDuplicateLine : limite documentée M&M’s', function () {
    // Scénario réel MMS : « M&M’s » et « MMS » ne convergent PAS vers la
    // même clé normalisée (le « & » est conservé) — c’est le rôle du
    // Mapping libellés de les rattacher, pas celui de l’anti-doublon.
    assert.strictEqual(H.findDuplicateLine(['M&M’s', 'MMS'], 1), -1);
    assert.strictEqual(H.findDuplicateLine(['MMS', 'MMS'], 1), 1);
});

/* ------------------------------------------------------------------ *
 * invoiceToRows — facture scannée -> lignes de la grille
 * ------------------------------------------------------------------ */

t('invoiceToRows : facture METRO à 3 lignes -> format du collage', function () {
    const invoice = {
        supplier: 'METRO',
        invoice_number: '3007 02 0090',
        purchased_at: '2026-10-02',
        vat_rate: 5.5,
        amount_basis: 'ht',
        total_ht: 250.46,
        total_ttc: 264.24,
        lines: [
            { label: 'MINUTE MAID POMME 1L', ean: '5449000000099', article: '2040110',
              unit_price: 0.72, units: 24, total: 17.28, notes: 'EAN 5449000000099 · art. 2040110' },
            { label: 'COCA COLA 33CL', ean: '5449000000996', article: '2040041',
              unit_price: 0.45, units: 24, total: 10.8, notes: 'EAN 5449000000996 · art. 2040041' },
            { label: 'EAU MINERALE 50CL', ean: '3057640257546', article: '2040999',
              unit_price: 0.2, units: 48, total: 9.6 }
        ],
        warnings: ['Taux de TVA non trouvé — 5,5 % appliqué.']
    };
    assert.deepStrictEqual(H.invoiceToRows(invoice), [
        { key: 'MINUTE MAID POMME 1L', qty: 24, total: '17,28', notes: 'EAN 5449000000099 · art. 2040110', vat_rate: null },
        { key: 'COCA COLA 33CL', qty: 24, total: '10,80', notes: 'EAN 5449000000996 · art. 2040041', vat_rate: null },
        { key: 'EAU MINERALE 50CL', qty: 48, total: '9,60', notes: 'EAN 3057640257546 · art. 2040999', vat_rate: null }
    ], 'Sans champ notes, « EAN … · art. … » est reconstitué depuis ean/article ; sans taux par ligne, vat_rate est null.');
});

t('invoiceToRows : sans lignes (ou lines absente) -> []', function () {
    assert.deepStrictEqual(H.invoiceToRows({ supplier: 'METRO', lines: [] }), []);
    assert.deepStrictEqual(H.invoiceToRows({ supplier: 'METRO' }), []);
    assert.deepStrictEqual(H.invoiceToRows(null), []);
});

t('invoiceToRows : lignes sans libellé ignorées, qté et montant assainis', function () {
    assert.deepStrictEqual(H.invoiceToRows({ lines: [
        { units: 5, total: 12.3 },
        { label: 'Bonbons', units: 0, total: 0 },
        { label: 'Chips', units: '6', total: '8,90' }
    ] }), [
        { key: 'Bonbons', qty: 1, total: '', notes: '', vat_rate: null },
        { key: 'Chips', qty: 6, total: '8,90', notes: '', vat_rate: null }
    ], 'Qté < 1 -> 1 ; total nul/invalide -> chaîne vide ; libellé manquant -> ligne ignorée.');
});

t('invoiceToRows : taux par ligne (line.vat_rate) canonisé, sinon null', function () {
    const rows = H.invoiceToRows({ lines: [
        { label: 'Soda', units: 2, total: 3, vat_rate: 20 },
        { label: 'Pain', units: 1, total: 1.2, vat_rate: '5,5' },
        { label: 'Sac', units: 1, total: 0.1 },
        { label: 'Presse', units: 1, total: 2, vat_rate: 'abc' }
    ] });
    assert.deepStrictEqual(rows.map(function (r) { return r.vat_rate; }),
        ['20', '5.5', null, null],
        'Taux de la ligne canonisé (« 5,5 » -> « 5.5 ») ; absent ou invalide -> null (héritera de l\u2019en-tête).');
});

/* ------------------------------------------------------------------ *
 * applyInvoiceState — fusion pure facture -> état du formulaire
 * ------------------------------------------------------------------ */

t('applyInvoiceState : la facture remplit en-tête et lignes', function () {
    const st = H.applyInvoiceState({
        supplier: '', invoice_number: '', purchased_at: '2026-10-08',
        vat_rate: '5.5', amount_basis: 'ht', rows: []
    }, {
        supplier: 'METRO', invoice_number: '3007 02 0090', purchased_at: '2026-10-02',
        vat_rate: 5.5, amount_basis: 'ht',
        lines: [{ label: 'Coca 33cl', units: 24, total: 10.8 }]
    });
    assert.strictEqual(st.supplier, 'METRO');
    assert.strictEqual(st.invoice_number, '3007 02 0090');
    assert.strictEqual(st.purchased_at, '2026-10-02');
    assert.strictEqual(st.vat_rate, '5.5', 'Taux canonisé en chaîne pour le select.');
    assert.strictEqual(st.amount_basis, 'ht');
    assert.deepStrictEqual(st.rows, [{ key: 'Coca 33cl', qty: 24, total: '10,80', notes: '', vat_rate: null }]);
});

t('applyInvoiceState : champs null -> état courant conservé', function () {
    const st = H.applyInvoiceState({
        supplier: 'Metro CASH', invoice_number: 'A1', purchased_at: '2026-10-01',
        vat_rate: '20', amount_basis: 'ttc', rows: []
    }, { supplier: null, invoice_number: null, purchased_at: null, vat_rate: null, lines: [] });
    assert.strictEqual(st.supplier, 'Metro CASH');
    assert.strictEqual(st.invoice_number, 'A1');
    assert.strictEqual(st.purchased_at, '2026-10-01');
    assert.strictEqual(st.vat_rate, '20');
    assert.strictEqual(st.amount_basis, 'ttc');
    assert.deepStrictEqual(st.rows, []);
});

t('applyInvoiceState : date ou taux invalides ignorés', function () {
    const st = H.applyInvoiceState(null, {
        purchased_at: '02/10/2026', vat_rate: 'abc', supplier: 'METRO', lines: []
    });
    assert.strictEqual(st.purchased_at, '', 'Seul le format aaaa-mm-jj est accepté (input[type=date]).');
    assert.strictEqual(st.vat_rate, null);
    assert.strictEqual(st.supplier, 'METRO');
});

t('applyInvoiceState : taux à la française « 5,5 » canonisé', function () {
    const st = H.applyInvoiceState({}, { vat_rate: '5,5', lines: [] });
    assert.strictEqual(st.vat_rate, '5.5');
});

/* ------------------------------------------------------------------ *
 * applyInvoiceToExpense — ticket scanné -> formulaire de dépense
 * (préremplissage non destructif)
 * ------------------------------------------------------------------ */

/** État « formulaire de dépense vide » (sélecteur TVA sur Aucune). */
function emptyExpenseState() {
    return {
        spent_at: '', label: '', amount: '', basis: 'ttc',
        vat_rate: '', vat_amount: '', invoice_number: ''
    };
}

t('applyInvoiceToExpense : formulaire vide -> tout est prérempli (base TTC)', function () {
    const res = H.applyInvoiceToExpense(emptyExpenseState(), {
        supplier: 'Leroy Merlin', invoice_number: '159-10007284',
        purchased_at: '2026-08-31', amount_basis: 'ttc',
        vat_rate: 20, total_ht: 31.07, total_ttc: 37.24,
        vat_rates: [], lines: []
    });
    assert.strictEqual(res.spent_at, '2026-08-31');
    assert.strictEqual(res.label, 'Leroy Merlin');
    assert.strictEqual(res.amount, '37,24', 'Base TTC : le montant vient du total_ttc, au format français.');
    assert.strictEqual(res.basis, 'ttc');
    assert.strictEqual(res.vat_rate, '20', 'Taux canonisé en chaîne pour le select.');
    assert.strictEqual(res.vat_amount, '', 'Taux unique : pas de vat_amount.');
    assert.strictEqual(res.invoice_number, '159-10007284');
    assert.strictEqual(res.filled, true);
    assert.deepStrictEqual(res.skipped, []);
});

t('applyInvoiceToExpense : base HT -> montant repris de total_ht', function () {
    const res = H.applyInvoiceToExpense(emptyExpenseState(), {
        supplier: 'METRO', purchased_at: '2026-08-31', amount_basis: 'ht',
        vat_rate: 5.5, total_ht: 250.46, total_ttc: 264.24, lines: []
    });
    assert.strictEqual(res.amount, '250,46');
    assert.strictEqual(res.basis, 'ht');
    assert.strictEqual(res.vat_rate, '5.5');
});

t('applyInvoiceToExpense : champs déjà remplis -> conservés et signalés dans skipped', function () {
    const res = H.applyInvoiceToExpense({
        spent_at: '2026-10-08', label: 'Ma dépense', amount: '30',
        basis: 'ttc', vat_rate: '5.5', vat_amount: '', invoice_number: 'REF'
    }, {
        supplier: 'METRO', invoice_number: '159-10007284',
        purchased_at: '2026-08-31', amount_basis: 'ttc',
        vat_rate: 20, total_ht: 31.07, total_ttc: 37.24, lines: []
    });
    assert.strictEqual(res.spent_at, '2026-10-08', 'Rien n\u2019est écrasé.');
    assert.strictEqual(res.label, 'Ma dépense');
    assert.strictEqual(res.amount, '30');
    assert.strictEqual(res.vat_rate, '5.5');
    assert.strictEqual(res.invoice_number, 'REF');
    assert.strictEqual(res.filled, false, 'Aucun champ vide à remplir.');
    assert.deepStrictEqual(
        res.skipped.map(function (s) { return s.field; }).sort(),
        ['amount', 'invoice_number', 'label', 'spent_at', 'vat_rate']
    );
    assert.ok(res.skipped.every(function (s) {
        return typeof s.reason === 'string' && s.reason.indexOf('déjà renseigné') !== -1;
    }), 'Chaque champ écarté porte sa raison (avec la valeur du ticket).');
});

t('applyInvoiceToExpense : multi-taux (vat_rate null) -> vat_amount = somme des TVA', function () {
    const res = H.applyInvoiceToExpense(emptyExpenseState(), {
        supplier: 'METRO', purchased_at: '2026-08-31', amount_basis: 'ttc',
        vat_rate: null, total_ht: 100, total_ttc: 125.5,
        vat_rates: [
            { letter: 'A', rate: 5.5, base_ht: 50, vat: 2.75, total_ttc: 52.75 },
            { letter: 'B', rate: 20, base_ht: 50, vat: 10, total_ttc: 60 }
        ],
        lines: []
    });
    assert.strictEqual(res.vat_rate, null, 'Multi-taux : pas de taux unique.');
    assert.strictEqual(res.vat_amount, '12,75', 'Somme des TVA détaillées, 2 décimales, virgule française.');
    assert.strictEqual(res.amount, '125,50');
    assert.strictEqual(res.filled, true);
});

t('applyInvoiceToExpense : multi-taux mais vat_amount déjà saisi -> conservé + skipped', function () {
    const res = H.applyInvoiceToExpense({
        spent_at: '', label: '', amount: '', basis: 'ttc',
        vat_rate: '', vat_amount: '12,70', invoice_number: ''
    }, {
        supplier: 'METRO', amount_basis: 'ttc', vat_rate: null,
        total_ttc: 125.5,
        vat_rates: [
            { letter: 'A', rate: 5.5, vat: 2.75 },
            { letter: 'B', rate: 20, vat: 10 }
        ],
        lines: []
    });
    assert.strictEqual(res.vat_amount, '12,70');
    assert.deepStrictEqual(
        res.skipped.map(function (s) { return s.field; }),
        ['vat_amount']
    );
});

t('applyInvoiceToExpense : ticket null/vide -> état intact, rien de rempli', function () {
    const state = {
        spent_at: '2026-10-01', label: 'Verrou x4', amount: '36,60',
        basis: 'ttc', vat_rate: '20', vat_amount: '', invoice_number: 'A1'
    };
    const resNull = H.applyInvoiceToExpense(state, null);
    assert.deepStrictEqual(
        [resNull.spent_at, resNull.label, resNull.amount, resNull.basis,
         resNull.vat_rate, resNull.vat_amount, resNull.invoice_number],
        ['2026-10-01', 'Verrou x4', '36,60', 'ttc', '20', '', 'A1'],
        'Facture absente : chaque champ garde sa valeur courante.'
    );
    assert.strictEqual(resNull.filled, false);
    assert.deepStrictEqual(resNull.skipped, []);
    const resEmpty = H.applyInvoiceToExpense(emptyExpenseState(), { lines: [] });
    assert.strictEqual(resEmpty.filled, false, 'Facture sans données exploitables : rien n\u2019est prérempli.');
});

t('applyInvoiceToExpense : date mal formée ou montants absents ignorés', function () {
    const res = H.applyInvoiceToExpense(emptyExpenseState(), {
        supplier: 'METRO', purchased_at: '31/08/2026',
        amount_basis: 'ttc', total_ttc: null, total_ht: null, lines: []
    });
    assert.strictEqual(res.spent_at, '', 'Seul aaaa-mm-jj est accepté (input[type=date]).');
    assert.strictEqual(res.amount, '', 'Pas de total exploitable : le montant reste vide.');
    assert.strictEqual(res.label, 'METRO');
});

/* ------------------------------------------------------------------ *
 * serializeExpenseNotes / expenseNotesFromInvoice — détail produits
 * -> champ notes
 * ------------------------------------------------------------------ */

t('serializeExpenseNotes : format exact « Détail tickets : … »', function () {
    assert.strictEqual(
        H.serializeExpenseNotes([
            { key: 'RED BULL', qty: 24, total: '59,16' },
            { key: 'NUTELLA', qty: 3, total: '8,07' }
        ]),
        'Détail tickets : 24 × RED BULL (59,16 €) · 3 × NUTELLA (8,07 €)'
    );
});

t('serializeExpenseNotes : total au format nombre -> normalisé en français', function () {
    assert.strictEqual(
        H.serializeExpenseNotes([{ key: 'Café', qty: 2, total: 7.2 }]),
        'Détail tickets : 2 × Café (7,20 €)'
    );
});

t('serializeExpenseNotes : lignes sans nom ignorées, total absent sans parenthèses', function () {
    assert.strictEqual(H.serializeExpenseNotes([]), '');
    assert.strictEqual(H.serializeExpenseNotes(null), '');
    assert.strictEqual(
        H.serializeExpenseNotes([
            { key: '', qty: 2, total: '5,00' },
            { key: 'Stylo', qty: 2, total: '' },
            { key: '  ', qty: 1, total: 3 }
        ]),
        'Détail tickets : 2 × Stylo',
        'Sans libellé -> ignorée ; sans total -> ligne nue ; qty < 1 -> 1.'
    );
});

t('expenseNotesFromInvoice : compose invoiceToRows + serializeExpenseNotes', function () {
    const invoice = {
        supplier: 'METRO',
        lines: [
            { label: 'RED BULL', units: 24, total: 59.16 },
            { label: 'NUTELLA', units: 3, total: 8.07 },
            { label: '', units: 2, total: 4 }
        ]
    };
    assert.strictEqual(
        H.expenseNotesFromInvoice(invoice),
        'Détail tickets : 24 × RED BULL (59,16 €) · 3 × NUTELLA (8,07 €)'
    );
    assert.strictEqual(H.expenseNotesFromInvoice(null), '');
    assert.strictEqual(H.expenseNotesFromInvoice({ lines: [] }), '');
});

/* ------------------------------------------------------------------ *
 * buildPurchaseBulkPayload — achats (stock + coût de revient) depuis
 * le scan du livre comptable, POST save-bulk (contrat as_json=1)
 * ------------------------------------------------------------------ */

/** En-tête typique d'un ticket scanné mono-taux. */
function bulkHeader(overrides) {
    return Object.assign({
        purchased_at: '2026-10-09', supplier: 'METRO', invoice_number: '3007 02 0090',
        amount_basis: 'ht', vat_rate: 5.5, update_cost: true
    }, overrides || {});
}

t('buildPurchaseBulkPayload : mono-taux — taux global + vat_rate[] par ligne', function () {
    const p = H.buildPurchaseBulkPayload(bulkHeader(), [
        { key: 'MINUTE MAID 1L', qty: 24, total: '17,28', vat_rate: null, no_stock: false },
        { key: 'EAU 50CL', qty: '48', total: 9.6, vat_rate: 5.5, no_stock: true }
    ]);
    assert.strictEqual(p.purchased_at, '2026-10-09');
    assert.strictEqual(p.supplier, 'METRO');
    assert.strictEqual(p.invoice_number, '3007 02 0090');
    assert.strictEqual(p.amount_basis, 'ht');
    assert.strictEqual(p.vat_rate, '5.5', 'Taux global canonisé en chaîne.');
    assert.strictEqual(p.update_cost, '1');
    assert.deepStrictEqual(p.product_key, ['MINUTE MAID 1L', 'EAU 50CL']);
    assert.deepStrictEqual(p.quantity, [24, 48], 'Quantités entières.');
    assert.deepStrictEqual(p.total_amount, ['17.28', '9.60'], 'Montants TOTAUX de ligne, 2 décimales, en chaîne.');
    assert.deepStrictEqual(p.vat_rate_lines, ['', '5.5'], 'vat_rate[] PAR LIGNE, même longueur que product_key[] ; ligne sans taux -> \u2018\u2019 (hérite du global).');
    assert.deepStrictEqual(p.no_stock_indexes, [1], 'Indexes 0-based des lignes « hors stock » (postées no_stock[1]=1).');
    assert.deepStrictEqual(p.rejected, []);
});

t('buildPurchaseBulkPayload : TVA mixte — global null -> clé absente, taux par ligne seuls', function () {
    const p = H.buildPurchaseBulkPayload(bulkHeader({ vat_rate: null, amount_basis: 'ttc' }), [
        { key: 'Soda', qty: 2, total: '3,00', vat_rate: 20, no_stock: false },
        { key: 'Pain', qty: 1, total: '1,20', vat_rate: '5,5', no_stock: false },
        { key: 'Sac', qty: 1, total: '0,10', vat_rate: null, no_stock: true }
    ]);
    assert.ok(!('vat_rate' in p), 'Taux global null : la clé vat_rate ne doit PAS être mise.');
    assert.deepStrictEqual(p.vat_rate_lines, ['20', '5.5', ''], '« 5,5 » canonisé ; ligne sans taux -> \u2018\u2019 (hérite de l\u2019en-tête).');
    assert.strictEqual(p.amount_basis, 'ttc');
    assert.deepStrictEqual(p.no_stock_indexes, [2]);
});

t('buildPurchaseBulkPayload : lignes invalides rejetées avec raison', function () {
    const p = H.buildPurchaseBulkPayload(bulkHeader(), [
        { key: '   ', qty: 3, total: '5,00' },
        { key: 'Coca', qty: 0, total: '5,00' },
        { key: 'Fanta', qty: 2, total: 'abc' },
        { key: 'Eau', qty: '', total: '4,00' },
        { key: 'Chips', qty: 2, total: 0 },
        { key: 'Eau', qty: 2, total: '4,00' }
    ]);
    assert.deepStrictEqual(p.rejected, [
        { index: 0, key: '', reason: 'produit manquant' },
        { index: 1, key: 'Coca', reason: 'quantité invalide' },
        { index: 2, key: 'Fanta', reason: 'montant invalide' },
        { index: 3, key: 'Eau', reason: 'quantité invalide' },
        { index: 4, key: 'Chips', reason: 'montant invalide' }
    ], 'Chaque rejet porte l\u2019index d\u2019origine, la clé et la raison.');
    assert.deepStrictEqual(p.product_key, ['Eau'], 'Seules les lignes valides alimentent les tableaux.');
    assert.deepStrictEqual(p.quantity, [2]);
    assert.deepStrictEqual(p.total_amount, ['4.00']);
    assert.deepStrictEqual(p.vat_rate_lines, ['']);
});

t('buildPurchaseBulkPayload : arrondis à 2 décimales et quantités entières', function () {
    const p = H.buildPurchaseBulkPayload(bulkHeader(), [
        { key: 'A', qty: '07', total: 59.156 },
        { key: 'B', qty: 2.9, total: '10,804' },
        { key: 'C', qty: 1, total: '1 234,5' }
    ]);
    assert.deepStrictEqual(p.quantity, [7, 2, 1], '« 07 » -> 7 ; 2,9 tronqué à l\u2019entier (la grille impose step=1).');
    assert.deepStrictEqual(p.total_amount, ['59.16', '10.80', '1234.50'], 'Totaux à 2 décimales, format montant français accepté en entrée.');
});

t('buildPurchaseBulkPayload : en-tête trimé, update_cost false -> clé absente', function () {
    const p = H.buildPurchaseBulkPayload(bulkHeader({
        purchased_at: ' 2026-10-09 ', supplier: ' Metro ', invoice_number: ' A1 ',
        amount_basis: 'xyz', vat_rate: '', update_cost: false
    }), [{ key: 'A', qty: 1, total: '2' }]);
    assert.strictEqual(p.purchased_at, '2026-10-09');
    assert.strictEqual(p.supplier, 'Metro');
    assert.strictEqual(p.invoice_number, 'A1');
    assert.strictEqual(p.amount_basis, 'ht', 'Base invalide -> ht (défaut).');
    assert.ok(!('update_cost' in p), 'update_cost false : clé absente — le contrôleur teste isset(), « 0 » vaudrait vrai.');
    assert.ok(!('vat_rate' in p), 'Taux global vide -> traité comme null : clé absente.');
});

t('buildPurchaseBulkPayload : aucune ligne valide -> tableaux vides, rien de rejeté', function () {
    const p = H.buildPurchaseBulkPayload(bulkHeader(), []);
    assert.deepStrictEqual(p.product_key, []);
    assert.deepStrictEqual(p.quantity, []);
    assert.deepStrictEqual(p.total_amount, []);
    assert.deepStrictEqual(p.vat_rate_lines, []);
    assert.deepStrictEqual(p.no_stock_indexes, []);
    assert.deepStrictEqual(p.rejected, []);
    assert.strictEqual(p.vat_rate, '5.5', 'L\u2019en-tête reste posté même sans ligne.');
});

/* ------------------------------------------------------------------ *
 * scanUpload / scanParseText — CSRF OPTIONNEL (POST kiosque sans
 * session : null = ni champ _csrf, ni en-tête X-CSRF-Token ; le jeton
 * admin de l'URL EST l'authentification). fetch et FormData sont
 * stubbés : les assertions tournent dans le harnais Node.
 * ------------------------------------------------------------------ */

/** Tests asynchrones (promesses) : exécutés après les tests synchrones. */
const asyncTests = [];

/** Mini-harnais asynchrone : même convention d'échec que t(). */
function ta(name, fn) {
    asyncTests.push({ name: name, fn: fn });
}

/** FormData factice : enregistre les champs appended (k, v). */
function FakeFormData() { this.fields = []; }
FakeFormData.prototype.append = function (k, v) { this.fields.push([k, v]); };

/** fetch factice : capture (url, init), répond {ok:true, json:{ok:true}}. */
function stubFetch(captured) {
    return function (url, init) {
        captured.url = url;
        captured.init = init;
        return Promise.resolve({
            ok: true,
            json: function () { return Promise.resolve({ ok: true, invoice: null }); }
        });
    };
}

/** Installe les stubs, rend leur restauration (finally). */
function withStubs(captured, fn) {
    const origFetch = global.fetch;
    const origFormData = global.FormData;
    global.fetch = stubFetch(captured);
    global.FormData = FakeFormData;
    return Promise.resolve().then(fn).finally(function () {
        global.fetch = origFetch;
        global.FormData = origFormData;
    });
}

function fieldNames(fd) {
    return fd.fields.map(function (f) { return f[0]; });
}

ta('scanUpload : csrf null -> ni champ _csrf ni en-tête X-CSRF-Token', function () {
    const captured = {};
    return withStubs(captured, function () {
        return H.scanUpload({ name: 'ticket.jpg' }, null, '/kiosque/admin/ledger/scan/TOKEN').then(function () {
            const headers = captured.init.headers || {};
            assert.ok(!Object.prototype.hasOwnProperty.call(headers, 'X-CSRF-Token'),
                'Pas d\u2019en-tête X-CSRF-Token sans CSRF (kiosque).');
            assert.deepStrictEqual(fieldNames(captured.init.body), ['file'],
                'Seul le fichier part : PAS de champ _csrf.');
            assert.strictEqual(captured.url, '/kiosque/admin/ledger/scan/TOKEN',
                'L\u2019endpoint passé est utilisé tel quel.');
            assert.strictEqual(captured.init.method, 'POST');
        });
    });
});

ta('scanUpload : csrf fourni -> champ _csrf ET en-tête X-CSRF-Token (admin inchangé)', function () {
    const captured = {};
    return withStubs(captured, function () {
        return H.scanUpload({ name: 'ticket.jpg' }, 'TOK', '/admin/compta/depenses/scan').then(function () {
            const headers = captured.init.headers || {};
            assert.strictEqual(headers['X-CSRF-Token'], 'TOK', 'En-tête présent côté admin.');
            assert.deepStrictEqual(fieldNames(captured.init.body), ['file', '_csrf'],
                'Le champ _csrf part avec la valeur du formulaire.');
        });
    });
});

ta('scanParseText : csrf null -> texte seul, sans trace de CSRF', function () {
    const captured = {};
    return withStubs(captured, function () {
        return H.scanParseText('METRO\n2 x Coca', null, '/kiosque/admin/ledger/scan/TOKEN').then(function () {
            const headers = captured.init.headers || {};
            assert.ok(!Object.prototype.hasOwnProperty.call(headers, 'X-CSRF-Token'));
            assert.deepStrictEqual(fieldNames(captured.init.body), ['text']);
            assert.strictEqual(captured.init.body.fields[0][1], 'METRO\n2 x Coca');
        });
    });
});

/* ------------------------------------------------------------------ */

/* Exécution SÉQUENTIELLE des tests asynchrones : les stubs globaux
 * (fetch/FormData) d'un test ne doivent jamais chevaucher ceux d'un
 * autre (une exécution en parallèle ferait gagner le dernier stub). */
asyncTests.reduce(function (chain, c) {
    return chain.then(function () {
        return Promise.resolve().then(c.fn).then(
            function () { passed++; },
            function (e) {
                failures.push('  ✗ ' + c.name + ' : ' + (e && e.message ? e.message : e));
            }
        );
    });
}, Promise.resolve()).then(function () {
    if (failures.length > 0) {
        console.error('JS ÉCHEC : ' + failures.length + ' test(s) en échec, ' + passed + ' OK');
        console.error(failures.join('\n'));
        process.exit(1);
    }
    console.log('JS OK : ' + passed + ' tests de la grille de saisie.');
});
