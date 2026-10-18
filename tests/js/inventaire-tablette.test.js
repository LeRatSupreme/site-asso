'use strict';

/**
 * Tests de la logique pure du collage « Inventaire tablette SumUp »
 * (public/assets/js/inventaire-tablette.js) — exécuté avec Node.js :
 *
 *     node tests/js/inventaire-tablette.test.js
 *
 * Pont PHPUnit : tests/Unit/InventaireTabletJsTest.php (code 0 = succès).
 * Aucune dépendance : le module 'assert' natif suffit.
 */

const assert = require('assert');
const H = require('../../public/assets/js/inventaire-tablette.js');

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

/* Jeu de clés produits (proche des vraies clés SumUp du site). */
const KEYS = [
    'eau', 'caprisun', 'Coca Cola', 'Monster Blanche', 'Bueno white',
    'Oasis (ice tea)', 'MMS', 'Perrier', 'Redbull', 'Red Bull',
    'Redbull Abricot', 'Perrier 33cl',
];
const INDEX = H.buildIndex(KEYS.map((key) => ({ key, theoretical: null, unit_cost: null })));

/* ------------------------------------------------------------------ *
 * Normalisations (miroir AliasSuggester::normalizeKey)
 * ------------------------------------------------------------------ */

t('tightNorm : miroir de AliasSuggester::normalizeKey + espaces retirés', function () {
    assert.strictEqual(H.tightNorm('Red Bull'), 'redbull');
    assert.strictEqual(H.tightNorm('Bueno_white'), 'buenowhite');
    assert.strictEqual(H.tightNorm('Bueno white'), 'buenowhite');
    assert.strictEqual(H.tightNorm('Oasis (ice tea)'), 'oasisicetea');
    assert.strictEqual(H.tightNorm('Redbull Abricot'), 'redbullabricot');
    assert.strictEqual(H.tightNorm('Crème Fraîche'), 'cremefraiche');
    assert.strictEqual(H.tightNorm('capri-sun'), 'caprisun');
    // Limite assumée (identique PHP) : « & » conservé.
    assert.strictEqual(H.tightNorm('M&Ms'), 'm&ms');
    assert.notStrictEqual(H.tightNorm('M&Ms'), H.tightNorm('MMS'));
});

t('spaceNorm : égalité lisible, séparateurs repliés en espaces', function () {
    assert.strictEqual(H.spaceNorm('Bueno_white'), 'bueno white');
    assert.strictEqual(H.spaceNorm('Coca-Cola'), 'coca cola');
    assert.strictEqual(H.spaceNorm('Oasis (ice tea)'), 'oasis ice tea');
});

/* ------------------------------------------------------------------ *
 * Parsing — les formats annoncés dans l'aide de la zone de collage
 * ------------------------------------------------------------------ */

t('parse : « Nom<TAB>12 »', function () {
    const r = H.parse('Redbull\t12');
    assert.deepStrictEqual(r.rows, [{ label: 'Redbull', qty: 12 }]);
    assert.strictEqual(r.ignored, 0);
    assert.deepStrictEqual(H.parse('capri-sun\t21').rows, [{ label: 'capri-sun', qty: 21 }]);
});

t('parse : « Nom 12 » simple', function () {
    assert.deepStrictEqual(H.parse('Redbull 12').rows, [{ label: 'Redbull', qty: 12 }]);
});

t('parse : « Nom - 12 » et « Nom — 12 » (tirets espacés)', function () {
    assert.deepStrictEqual(H.parse('eau - 104').rows, [{ label: 'eau', qty: 104 }]);
    assert.deepStrictEqual(H.parse('eau — 104').rows, [{ label: 'eau', qty: 104 }]);
    assert.deepStrictEqual(H.parse('eau – 104').rows, [{ label: 'eau', qty: 104 }]);
});

t('parse : « Nom : 12 », « Nom, 12 », « Nom; 12 », « Nom | 12 »', function () {
    for (const sep of [':', ',', ';', '|']) {
        assert.deepStrictEqual(
            H.parse('Monster Blanche ' + sep + ' 2').rows,
            [{ label: 'Monster Blanche', qty: 2 }],
            'séparateur « ' + sep + ' »'
        );
    }
});

t('parse : quantité négative signée', function () {
    assert.deepStrictEqual(H.parse('Redbull -3').rows, [{ label: 'Redbull', qty: -3 }]);
    assert.deepStrictEqual(H.parse('Redbull −3').rows, [{ label: 'Redbull', qty: -3 }]);
});

t('parse : nombre seul rattaché au libellé du dessus (lignes tablette)', function () {
    assert.deepStrictEqual(H.parse('Bueno white\n28').rows, [{ label: 'Bueno white', qty: 28 }]);
    assert.deepStrictEqual(H.parse('Bueno white\n28\nPerrier\n7').rows, [
        { label: 'Bueno white', qty: 28 },
        { label: 'Perrier', qty: 7 },
    ]);
});

t('parse : « -\\t12 » / « — 12 » après un libellé = quantité positive', function () {
    assert.deepStrictEqual(H.parse('Perrier\n-\t12').rows, [{ label: 'Perrier', qty: 12 }]);
    assert.deepStrictEqual(H.parse('Perrier\n— 12').rows, [{ label: 'Perrier', qty: 12 }]);
    assert.deepStrictEqual(H.parse('Perrier\n• 7').rows, [{ label: 'Perrier', qty: 7 }]);
    // « -12 » nu reste une quantité NÉGATIVE (signe collé).
    assert.deepStrictEqual(H.parse('Perrier\n-12').rows, [{ label: 'Perrier', qty: -12 }]);
});

t('parse : nombre seul sans libellé au-dessus = ignoré', function () {
    const r = H.parse('12');
    assert.deepStrictEqual(r.rows, []);
    assert.strictEqual(r.ignored, 1);
});

t('parse : libellé sans quantité = ligne en attente (qty null)', function () {
    assert.deepStrictEqual(H.parse('Bueno white').rows, [{ label: 'Bueno white', qty: null }]);
});

t('parse : quantités avec espaces insécables/fines', function () {
    assert.deepStrictEqual(H.parse('Redbull\u00a012').rows, [{ label: 'Redbull', qty: 12 }]);
    assert.deepStrictEqual(H.parse('Redbull\u202f12').rows, [{ label: 'Redbull', qty: 12 }]);
    assert.deepStrictEqual(H.parse('Redbull 1\u00a0234').rows, [{ label: 'Redbull', qty: 1234 }]);
});

t('parse : lignes d’interface et ponctuation ignorées', function () {
    const r = H.parse('Sélectionner\nModifier\nOptions\nActiver le suivi\nProduit\nStock\n—\n---\n•••\nProduit\tStock\nRedbull 4');
    assert.deepStrictEqual(r.rows, [{ label: 'Redbull', qty: 4 }]);
    assert.strictEqual(r.ignored, 10);
});

t('parse : lignes vides sautées sans être comptées « ignorées »', function () {
    const r = H.parse('\n\nRedbull 4\n\n');
    assert.deepStrictEqual(r.rows, [{ label: 'Redbull', qty: 4 }]);
    assert.strictEqual(r.ignored, 0);
});

t('parse : puces et guillemets retirés des libellés', function () {
    assert.deepStrictEqual(H.parse('- Redbull 12').rows, [{ label: 'Redbull', qty: 12 }]);
    assert.deepStrictEqual(H.parse('• Perrier 3').rows, [{ label: 'Perrier', qty: 3 }]);
    assert.deepStrictEqual(H.parse('« Bueno white » 5').rows, [{ label: 'Bueno white', qty: 5 }]);
});

t('parse : collage tablette complet multi-lignes', function () {
    const r = H.parse(
        'Produit\tStock\n' +
        'Redbull\t12\n' +
        'eau - 104\n' +
        'Monster Blanche : 2\n' +
        'Bueno white\n28\n' +
        'Sélectionner\n' +
        '—\n' +
        'Oasis (ice tea) 0\n'
    );
    assert.deepStrictEqual(r.rows, [
        { label: 'Redbull', qty: 12 },
        { label: 'eau', qty: 104 },
        { label: 'Monster Blanche', qty: 2 },
        { label: 'Bueno white', qty: 28 },
        { label: 'Oasis (ice tea)', qty: 0 },
    ]);
    assert.strictEqual(r.ignored, 3);
});

t('parse : vide / null -> aucun résultat', function () {
    assert.deepStrictEqual(H.parse('').rows, []);
    assert.deepStrictEqual(H.parse(null).rows, []);
});

/* ------------------------------------------------------------------ *
 * Appariement — priorité a (lisible), b (clé normalisée),
 * c (inclusion unique -> à vérifier), d (ambigu / aucun)
 * ------------------------------------------------------------------ */

t('match (a) : égalité lisible — Bueno_white, Coca-Cola, Oasis (ice tea)', function () {
    assert.deepStrictEqual(H.match('Bueno_white', INDEX), { status: 'exact', key: 'Bueno white', candidates: ['Bueno white'] });
    assert.deepStrictEqual(H.match('Coca-Cola', INDEX), { status: 'exact', key: 'Coca Cola', candidates: ['Coca Cola'] });
    assert.deepStrictEqual(H.match('Oasis (ice tea)', INDEX), { status: 'exact', key: 'Oasis (ice tea)', candidates: ['Oasis (ice tea)'] });
});

t('match (b) : clé normalisée — capri-sun -> caprisun, Redbull_Abricot', function () {
    assert.deepStrictEqual(H.match('capri-sun', INDEX), { status: 'exact', key: 'caprisun', candidates: ['caprisun'] });
    assert.deepStrictEqual(H.match('Redbull_Abricot', INDEX), { status: 'exact', key: 'Redbull Abricot', candidates: ['Redbull Abricot'] });
});

t('match (c) : inclusion unique -> « check » (à vérifier)', function () {
    assert.deepStrictEqual(H.match('Monster', INDEX), { status: 'check', key: 'Monster Blanche', candidates: ['Monster Blanche'] });
    assert.deepStrictEqual(H.match('Coca', INDEX), { status: 'check', key: 'Coca Cola', candidates: ['Coca Cola'] });
});

t('match (d) : formes normalisées identiques — départage par écriture brute (casse ignorée), sinon ambigu', function () {
    // « Bueno white » et « Bueno_White » ont la même forme lisible :
    // le libellé « Bueno_White » égale UNE seule candidate en casse
    // ignorée -> exact, clé préférente « Bueno_White ».
    const idxA = H.buildIndex([{ key: 'Bueno white' }, { key: 'Bueno_White' }]);
    assert.deepStrictEqual(H.match('Bueno_White', idxA), {
        status: 'exact', key: 'Bueno_White', candidates: ['Bueno white', 'Bueno_White'],
    });
    // « Redbull » et « RedBull » convergent aussi, mais les DEUX égalent
    // le libellé en casse ignorée : départage impossible -> ambigu (choix manuel).
    const idx2 = H.buildIndex([{ key: 'Redbull' }, { key: 'RedBull' }]);
    const r = H.match('Redbull', idx2);
    assert.strictEqual(r.status, 'ambiguous');
    assert.deepStrictEqual(r.candidates, ['Redbull', 'RedBull']);
    assert.strictEqual(H.match('REDBULL', idx2).status, 'ambiguous');
    // À noter : « Red Bull » (avec espace) reste distinct au niveau (a) —
    // face à {« RedBull », « Red Bull »}, le libellé « RedBull » ne voit
    // qu'une seule candidate : match exact direct.
    const idx3 = H.buildIndex([{ key: 'RedBull' }, { key: 'Red Bull' }]);
    assert.deepStrictEqual(H.match('RedBull', idx3), {
        status: 'exact', key: 'RedBull', candidates: ['RedBull'],
    });
});

t('match (d) : « Redbull red » — inclusion multiple -> ambigu (choix manuel)', function () {
    const r = H.match('Redbull red', INDEX);
    assert.strictEqual(r.status, 'ambiguous');
    assert.strictEqual(r.key, null);
    assert.ok(r.candidates.indexOf('Redbull') !== -1, '« Redbull » candidat (contenu dans le libellé)');
    assert.ok(r.candidates.indexOf('Red Bull') !== -1, '« Red Bull » candidat (contenu dans le libellé)');
    // Avec une clé « RedBull Red » unique : égalité de clé normalisée -> exact.
    const idx2 = H.buildIndex([{ key: 'RedBull Red' }, { key: 'Perrier' }]);
    assert.deepStrictEqual(H.match('Redbull red', idx2), { status: 'exact', key: 'RedBull Red', candidates: ['RedBull Red'] });
});

t('match (d) : « M&Ms » sans clé équivalente -> aucun match (& conservé)', function () {
    const r = H.match('M&Ms', INDEX);
    assert.strictEqual(r.status, 'none');
    assert.strictEqual(r.key, null);
    assert.deepStrictEqual(r.candidates, []);
});

t('match : libellé vide / index absent -> aucun match', function () {
    assert.strictEqual(H.match('', INDEX).status, 'none');
    assert.strictEqual(H.match('Redbull', null).status, 'none');
});

/* ------------------------------------------------------------------ *
 * InventaireTabletParse (contract vue) : liste {label, qty}
 * ------------------------------------------------------------------ */

t('window API : InventaireTabletParse renvoie la liste des lignes', function () {
    const G = require('../../public/assets/js/inventaire-tablette.js');
    assert.deepStrictEqual(G.parse('Redbull\t12').rows, [{ label: 'Redbull', qty: 12 }]);
});

/* ------------------------------------------------------------------ */

if (failures.length > 0) {
    console.error('Échecs (' + failures.length + '/' + (passed + failures.length) + ') :');
    console.error(failures.join('\n'));
    process.exit(1);
}
console.log('JS OK — ' + passed + ' test(s) harnais inventaire-tablette.');
process.exit(0);
