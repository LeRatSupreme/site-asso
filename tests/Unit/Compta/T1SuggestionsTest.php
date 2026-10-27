<?php

declare(strict_types=1);

namespace Tests\Unit\Compta;

use App\Core\Compta\T1\T1Suggestions;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la logique PURE des « Suggestions de rapprochement »
 * (T1 lot C) : identifiant de paire, lecture défensive des livrables
 * (JSON/CSV, fixtures corrompues/absentes/vides), filtrage de
 * l'assemblage (clés vivantes, liste noire, dédoublonnage, plafond).
 *
 * Aucun accès base : tout est injecté (keyStats, liste noire).
 */
final class T1SuggestionsTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/t1';

    // ── Identifiant de paire (boucle P3) ───────────────────────────────────

    public function test_entity_id_40_hex_symetrique(): void
    {
        $ab = T1Suggestions::pairEntityId('Pepsi', 'Pepsi sans sucre');
        $ba = T1Suggestions::pairEntityId('Pepsi sans sucre', 'Pepsi');

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $ab);
        self::assertSame($ab, $ba, 'L\'identifiant de paire doit être symétrique.');
        self::assertNotSame(
            $ab,
            T1Suggestions::pairEntityId('Coca', 'Coca zéro'),
            'Deux paires distinctes doivent avoir des identifiants distincts.'
        );
    }

    public function test_entity_id_insensible_casse_et_accents(): void
    {
        // Même paire exprimée différemment (casse, accents, séparateurs) :
        // StockPublic::normalizeKey fait foi — jamais de comparaison brute.
        self::assertSame(
            T1Suggestions::pairEntityId('Minute Maid', 'Minute Maid Pomme'),
            T1Suggestions::pairEntityId('minute_maid_pomme', 'MINUTE MAÏD')
        );
    }

    // ── Lecture défensive : candidats_fusion.json ──────────────────────────

    public function test_parse_candidats_valide(): void
    {
        $c = T1Suggestions::parseCandidats(self::FIXTURES . '/candidats_ok.json');

        // Entrées invalides sautées (b manquant, vide, identiques, non
        // chaînes, non tableaux) ; score hors borne ramené dans [0,1] ;
        // tri score décroissant puis ordre lexicographique.
        self::assertSame(
            [
                ['a' => 'Hors borne', 'b' => 'Hors borne Max', 'score' => 1.0],
                // « Fantôme » est gardée ici : c'est l'assemblage
                // (clés vivantes) qui l'éliminera, pas le parsing.
                ['a' => 'Fantôme', 'b' => 'Caprisun', 'score' => 0.99],
                ['a' => 'Caprisun', 'b' => 'Caprisun tropical', 'score' => 0.95],
                ['a' => 'Pepsi', 'b' => 'Pepsi sans sucre', 'score' => 0.8],
                ['a' => 'AA égalité', 'b' => 'AA autre', 'score' => 0.7],
                ['a' => 'Égalité Z', 'b' => 'AA autre', 'score' => 0.7],
                // Raw-distinctes mais formes normalisées identiques :
                // conservées à la lecture (le filtrage canMerge se fait
                // à l'assemblage, pas au parsing).
                ['a' => 'Gaufre', 'b' => 'gaufre', 'score' => 0.6],
                ['a' => 'Sans score', 'b' => 'Sans score B', 'score' => 0.0],
            ],
            $c
        );
    }

    public function test_parse_candidats_degrades(): void
    {
        self::assertSame([], T1Suggestions::parseCandidats(self::FIXTURES . '/candidats_corrompu.json'), 'JSON corrompu → liste vide.');
        self::assertSame([], T1Suggestions::parseCandidats(self::FIXTURES . '/candidats_vide.json'), 'Fichier vide → liste vide.');
        self::assertSame([], T1Suggestions::parseCandidats(self::FIXTURES . '/absent.json'), 'Fichier absent → liste vide.');
        self::assertSame([], T1Suggestions::parseCandidats(self::FIXTURES), 'Chemin non fichier → liste vide, pas d\'exception.');
    }

    public function test_parse_candidats_sans_cle_candidats(): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'aeic_t1_json_');
        self::assertIsString($chemin);
        file_put_contents($chemin, '{"seuil": 0.5}');

        self::assertSame([], T1Suggestions::parseCandidats($chemin));
        unlink($chemin);
    }

    // ── Lecture défensive : residuels_a_qualifier.csv ──────────────────────

    public function test_parse_residuels_valide(): void
    {
        $r = T1Suggestions::parseResiduels(self::FIXTURES . '/residuels_ok.csv');

        // Lignes sans deux libellés exploitables ignorées ; motif conservé.
        self::assertSame(
            [
                ['a' => 'Caprisun', 'b' => 'Caprisun tropical', 'motif' => 'spec §1.3 : doublon résiduel non qualifié (D2)'],
                ['a' => 'Kinder Bueno', 'b' => 'Bueno', 'motif' => 'spec §1.3 : doublon résiduel non qualifié (D2)'],
            ],
            $r
        );
    }

    public function test_parse_residuels_degrades(): void
    {
        self::assertSame([], T1Suggestions::parseResiduels(self::FIXTURES . '/residuels_corrompus.csv'), 'Lignes sans séparateur → liste vide.');
        self::assertSame([], T1Suggestions::parseResiduels(self::FIXTURES . '/residuels_vides.csv'), 'En-tête seul → liste vide.');
        self::assertSame([], T1Suggestions::parseResiduels(self::FIXTURES . '/absent.csv'), 'Fichier absent → liste vide.');
    }

    // ── Assemblage (pourInventaire) ────────────────────────────────────────

    /**
     * keyStats de référence : clés vivantes + ventes SumUp.
     *
     * @return array<string,array<string,int>>
     */
    private static function keyStats(): array
    {
        return [
            'Caprisun'          => ['sales' => 0],
            'Caprisun tropical' => ['sales' => 3],
            'Pepsi'             => ['sales' => 1],
            'Pepsi sans sucre'  => ['sales' => 0],
            'Kinder Bueno'      => ['sales' => 0],
            'Bueno'             => ['sales' => 2],
            'Gaufre'            => ['sales' => 0],
            'gaufre'            => ['sales' => 0],
        ];
    }

    public function test_pour_inventaire_filtrage_complet(): void
    {
        $t1 = T1Suggestions::pourInventaire(
            self::FIXTURES . '/candidats_ok.json',
            self::FIXTURES . '/residuels_ok.csv',
            self::keyStats(),
            []
        );

        self::assertTrue($t1['dispo']);
        self::assertSame(3, $t1['total'], '2 suggestions du modèle + 1 résiduel.');

        // Modèle : score décroissant ; cible = la clé des ventes SumUp si
        // un seul côté en a (« Caprisun tropical »), sinon l'ordre
        // alphabétique (« Pepsi ») ; paires à clé morte (« Fantôme ») ou à
        // formes normalisées identiques (« Gaufre »/« gaufre ») exclues.
        self::assertSame(
            [
                ['source' => 'Caprisun', 'target' => 'Caprisun tropical', 'score' => 0.95, 'ressemblance' => 95],
                ['source' => 'Pepsi sans sucre', 'target' => 'Pepsi', 'score' => 0.8, 'ressemblance' => 80],
            ],
            $t1['modele']
        );

        // Résiduels : (Caprisun, Caprisun tropical) déjà suggérée par le
        // modèle → dédoublonnée ; ne reste que « Kinder Bueno » → « Bueno ».
        self::assertSame(
            [
                ['source' => 'Kinder Bueno', 'target' => 'Bueno', 'motif' => 'spec §1.3 : doublon résiduel non qualifié (D2)'],
            ],
            $t1['residuels']
        );
    }

    public function test_pour_inventaire_liste_noire(): void
    {
        $t1 = T1Suggestions::pourInventaire(
            self::FIXTURES . '/candidats_ok.json',
            self::FIXTURES . '/residuels_ok.csv',
            self::keyStats(),
            [T1Suggestions::pairEntityId('Caprisun', 'Caprisun tropical')]
        );

        // Paire déjà décidée : retirée du modèle ET du résiduel (dédoublonnage
        // antérieur), les autres suggestions restent.
        self::assertSame(
            [['source' => 'Pepsi sans sucre', 'target' => 'Pepsi', 'score' => 0.8, 'ressemblance' => 80]],
            $t1['modele']
        );
        self::assertSame(
            [['source' => 'Kinder Bueno', 'target' => 'Bueno', 'motif' => 'spec §1.3 : doublon résiduel non qualifié (D2)']],
            $t1['residuels']
        );
        self::assertSame(2, $t1['total']);
    }

    public function test_pour_inventaire_fusion_fait_disparaitre_la_paire(): void
    {
        // Après une fusion, la clé absorbée n'est plus vivante : la paire
        // disparaît (ici « Caprisun » a été fusionnée dans « Caprisun tropical »).
        $stats = self::keyStats();
        unset($stats['Caprisun']);

        $t1 = T1Suggestions::pourInventaire(
            self::FIXTURES . '/candidats_ok.json',
            self::FIXTURES . '/residuels_ok.csv',
            $stats,
            []
        );

        self::assertSame(
            [['source' => 'Pepsi sans sucre', 'target' => 'Pepsi', 'score' => 0.8, 'ressemblance' => 80]],
            $t1['modele']
        );
        self::assertSame(
            [['source' => 'Kinder Bueno', 'target' => 'Bueno', 'motif' => 'spec §1.3 : doublon résiduel non qualifié (D2)']],
            $t1['residuels'],
            'Le résiduel (Caprisun → Caprisun tropical) disparaît (clé morte), les autres restent.'
        );
    }

    public function test_pour_inventaire_resout_les_cles_vivantes(): void
    {
        // Candidats saisis dans une casse différente des clés réelles :
        // source/cible repartent vers les clés VIVANTES (le POST vise des
        // clés qui existent), appariement par forme normalisée.
        $chemin = tempnam(sys_get_temp_dir(), 'aeic_t1_casse_');
        self::assertIsString($chemin);
        file_put_contents($chemin, json_encode([
            'candidats' => [
                ['a' => 'pepsi', 'b' => 'pepsi sans sucre', 'score' => 0.81],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $t1 = T1Suggestions::pourInventaire($chemin, self::FIXTURES . '/residuels_vides.csv', self::keyStats(), []);
        unlink($chemin);

        self::assertSame(
            [['source' => 'Pepsi sans sucre', 'target' => 'Pepsi', 'score' => 0.81, 'ressemblance' => 81]],
            $t1['modele']
        );
    }

    public function test_pour_inventaire_plafond_20_par_section(): void
    {
        // 25 paires vivantes toutes affichables : plafonnées à 20, le
        // total compte bien les 25 restantes.
        $stats = [];
        $candidats = [];
        for ($i = 0; $i < 25; $i++) {
            $a = sprintf('Paire%02d', $i);
            $b = sprintf('Autre%02d', $i);
            $stats[$a] = ['sales' => 0];
            $stats[$b] = ['sales' => 0];
            $candidats[] = ['a' => $a, 'b' => $b, 'score' => 0.9];
        }
        $chemin = tempnam(sys_get_temp_dir(), 'aeic_t1_cap_');
        self::assertIsString($chemin);
        file_put_contents($chemin, json_encode(['candidats' => $candidats]));

        $t1 = T1Suggestions::pourInventaire($chemin, self::FIXTURES . '/residuels_vides.csv', $stats, []);
        unlink($chemin);

        self::assertCount(T1Suggestions::MAX_PAR_SECTION, $t1['modele']);
        self::assertSame(25, $t1['total']);
    }

    public function test_pour_inventaire_degradation_totale(): void
    {
        // Fichiers absents : carte utilisable (message « aucune
        // suggestion » côté vue), jamais d'exception.
        $t1 = T1Suggestions::pourInventaire(
            self::FIXTURES . '/absent.json',
            self::FIXTURES . '/absent.csv',
            self::keyStats(),
            []
        );

        self::assertFalse($t1['dispo']);
        self::assertSame(0, $t1['total']);
        self::assertSame([], $t1['modele']);
        self::assertSame([], $t1['residuels']);
    }

    public function test_pour_inventaire_json_corrompu_mais_csv_ok(): void
    {
        $t1 = T1Suggestions::pourInventaire(
            self::FIXTURES . '/candidats_corrompu.json',
            self::FIXTURES . '/residuels_ok.csv',
            self::keyStats(),
            []
        );

        self::assertTrue($t1['dispo'], 'Le CSV lisible suffit à rendre la carte « disponible ».');
        self::assertSame([], $t1['modele'], 'JSON corrompu → aucune suggestion du modèle.');
        self::assertCount(2, $t1['residuels'], 'Le résiduel reste affichable (aucune clé morte ici : (Caprisun, Caprisun tropical) n\'est plus dédoublonnée sans le modèle).');
    }

    public function test_pour_inventaire_keyStats_degrade(): void
    {
        // keyStats inattendu (valeurs non tableaux) : aucune exception,
        // tout est filtré, la carte dégrade proprement.
        $t1 = T1Suggestions::pourInventaire(
            self::FIXTURES . '/candidats_ok.json',
            self::FIXTURES . '/residuels_ok.csv',
            ['Caprisun' => 'pas un tableau', 12 => ['sales' => 3]],
            []
        );

        self::assertTrue($t1['dispo']);
        self::assertSame([], $t1['modele']);
        self::assertSame([], $t1['residuels']);
    }
}
