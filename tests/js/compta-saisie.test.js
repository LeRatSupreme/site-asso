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
        { key: 'MINUTE MAID POMME 1L', qty: 24, total: '17,28', notes: 'EAN 5449000000099 · art. 2040110' },
        { key: 'COCA COLA 33CL', qty: 24, total: '10,80', notes: 'EAN 5449000000996 · art. 2040041' },
        { key: 'EAU MINERALE 50CL', qty: 48, total: '9,60', notes: 'EAN 3057640257546 · art. 2040999' }
    ], 'Sans champ notes, « EAN … · art. … » est reconstitué depuis ean/article.');
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
        { key: 'Bonbons', qty: 1, total: '', notes: '' },
        { key: 'Chips', qty: 6, total: '8,90', notes: '' }
    ], 'Qté < 1 -> 1 ; total nul/invalide -> chaîne vide ; libellé manquant -> ligne ignorée.');
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
    assert.deepStrictEqual(st.rows, [{ key: 'Coca 33cl', qty: 24, total: '10,80', notes: '' }]);
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

/* ------------------------------------------------------------------ */

if (failures.length > 0) {
    console.error('JS ÉCHEC : ' + failures.length + ' test(s) en échec, ' + passed + ' OK');
    console.error(failures.join('\n'));
    process.exit(1);
}
console.log('JS OK : ' + passed + ' tests de la grille de saisie.');
