<?php

declare(strict_types=1);

namespace Tests\Unit\Compta;

use App\Core\Compta\T1\T1AutoFusion;
use App\Core\Compta\T1\T1Quarantaine;
use App\Core\Compta\T1\T1Suggestions;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la logique PURE du moteur d'autonomie graduée (T1.5) :
 * prédicats niveau 1/2 (casse, préfixe base↔variante, suffixe couleur
 * à flexion près, garde chiffres), garde-fou coûts dev-couts,
 * orientation cible, classification, maturité 48 h en Europe/Paris
 * (dates aux limites, heure d'été/hiver, UTC explicite) et mutateurs
 * d'état de la quarantaine (idempotence, veto jamais contourné).
 *
 * Aucun accès base, aucun fichier : tout est injecté.
 */
final class T1AutoFusionTest extends TestCase
{
    // ── Niveau 1 : casse pure ────────────────────────────────────────────

    public function test_doublon_casse(): void
    {
        self::assertTrue(T1AutoFusion::estDoublonCasse('Pepsi', 'pepsi'), 'La casse pure est un doublon.');
        self::assertTrue(T1AutoFusion::estDoublonCasse('Bueno_white', 'bueno white'), 'Séparateurs et espaces repliés.');
        self::assertTrue(T1AutoFusion::estDoublonCasse('Café', 'cafe'), 'Accents repliés (normalizeKey partagé).');
        self::assertFalse(T1AutoFusion::estDoublonCasse('Pepsi', 'Pepsi sans sucre'));
        self::assertFalse(T1AutoFusion::estDoublonCasse('', ''));
    }

    public function test_doublons_casse_depuis_keystats(): void
    {
        $keyStats = [
            'Pepsi'  => ['sales' => 5],
            'pepsi'  => ['sales' => 0],
            'PEPSI ' => ['sales' => 0],   // bord : espace rogné par keyStats réelle
            'Fanta'  => ['sales' => 0],
        ];
        $paires = T1AutoFusion::doublonsCasse($keyStats);

        // 2 paires vers le REPRÉSENTANT à ventes (Pepsi), la forme Fanta
        // seule n'en produit aucune.
        self::assertCount(2, $paires);
        foreach ($paires as $p) {
            self::assertSame('Pepsi', $p['target'], 'La clé des ventes SumUp est conservée.');
            self::assertSame(T1Suggestions::pairEntityId($p['source'], 'Pepsi'), $p['id']);
        }
    }

    public function test_doublons_casse_representant_alphabetique_sans_ventes(): void
    {
        $paires = T1AutoFusion::doublonsCasse([
            'zeta' => ['sales' => 0],
            'Zeta' => ['sales' => 0],
        ]);
        self::assertCount(1, $paires);
        self::assertSame('Zeta', $paires[0]['target'], 'Sans ventes : première clé alphabétique.');
        self::assertSame('zeta', $paires[0]['source']);
    }

    // ── Niveau 2 : prédicats sémantiques (décision utilisateur 27/10) ────

    public function test_variante_prefixe_base_vs_variante(): void
    {
        // Décisions utilisateur : Lion_Peanut = Lion, Lipton = Lipton Ice Tea.
        self::assertTrue(T1AutoFusion::estVariantePrefixe('Lion', 'Lion_Peanut'));
        self::assertTrue(T1AutoFusion::estVariantePrefixe('Lipton', 'Lipton Ice Tea'));
        self::assertTrue(T1AutoFusion::estVariantePrefixe('Fanta', 'Fanta Orange'));
        self::assertTrue(T1AutoFusion::estVariantePrefixe('Coca', 'Coca Cola'), 'Symétrique : la base est préfixe de la variante.');
        // Deux variantes SŒURS (préfixe commun mais ni l'une ni l'autre
        // préfixe de l'autre) : deux produits distincts (dur négatif 0,9273).
        self::assertFalse(T1AutoFusion::estVariantePrefixe('Monster Bleu', 'Monster Verte'));
        self::assertFalse(T1AutoFusion::estVariantePrefixe('Fanta Orange', 'Fanta Citron'));
        // Base trop courte (< 4 caractères) : un trigramme porte trop peu
        // de sens pour autoriser l'auto.
        self::assertFalse(T1AutoFusion::estVariantePrefixe('Eau', 'Eau pétillante'));
    }

    public function test_variante_suffixe_meme_variante_a_flexion_pres(): void
    {
        // Même produit, orthographe genre/pluriel du suffixe couleur.
        self::assertTrue(T1AutoFusion::estVarianteSuffixe('Monster bleu', 'Monster Bleue'));
        self::assertTrue(T1AutoFusion::estVarianteSuffixe('Redbull blanc', 'Redbull Blanches'));
        self::assertTrue(T1AutoFusion::estVarianteSuffixe('Monster vert rose', 'Monster rose vert'), 'Ordre des teintes : même variante.');
        // Deux variantes sœurs : JAMAIS (dur négatif du jeu figé).
        self::assertFalse(T1AutoFusion::estVarianteSuffixe('Monster Bleu', 'Monster Verte'));
        // Suffixes différents non repliables (white vs blanc) : conservateur.
        self::assertFalse(T1AutoFusion::estVarianteSuffixe('Bueno White', 'Bueno blanc'));
        // Casse pure : voie niveau 1, pas le suffixe.
        self::assertFalse(T1AutoFusion::estVarianteSuffixe('Bueno white', 'bueno white'));
    }

    public function test_garde_chiffres(): void
    {
        self::assertTrue(T1AutoFusion::contientChiffre('Sucette 1', 'Sucette 1 sucette'), 'Conditionnement : jamais d\'auto.');
        self::assertTrue(T1AutoFusion::contientChiffre('Coca', 'Coca Bte 33cl'));
        self::assertFalse(T1AutoFusion::contientChiffre('Fanta', 'Fanta Orange'));
    }

    // ── Garde-fou coûts (dev-couts : zéro perte) ─────────────────────────

    public function test_couts_divergents(): void
    {
        // 0,50 vs 0,60 : écart relatif au plus petit = 20 % > 15 % → niveau 3.
        self::assertTrue(T1AutoFusion::coutsDivergents(0.50, 0.60));
        // 0,50 vs 0,56 : 12 % ≤ 15 % → auto possible.
        self::assertFalse(T1AutoFusion::coutsDivergents(0.50, 0.56));
        // Coût inconnu d'un côté : rien à opposer, l'auto reste possible.
        self::assertFalse(T1AutoFusion::coutsDivergents(null, 9.99));
        self::assertFalse(T1AutoFusion::coutsDivergents(0.50, null));
        // Coût nul/absurde : pas une divergence exploitable.
        self::assertFalse(T1AutoFusion::coutsDivergents(0.0, 0.50));
    }

    // ── Classification ───────────────────────────────────────────────────

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function statsVivantes(): array
    {
        return [
            'Coca'          => ['sales' => 10],
            'Coca Cola'     => ['sales' => 0],
            'Fanta'         => ['sales' => 3],
            'Fanta Orange'  => ['sales' => 2],
            'Sucette 1'     => ['sales' => 1],
            'Sucette 1 sucette' => ['sales' => 1],
            'Pepper'        => ['sales' => 1],
            'Dr Pepper'     => ['sales' => 1],
        ];
    }

    public function test_classifie_les_trois_niveaux(): void
    {
        $paires = [
            // Niveau 2 : score ≥ seuil (0.8936) + préfixe, sans chiffre.
            ['a' => 'Coca', 'b' => 'Coca Cola', 'score' => 0.9228],
            // Niveau 3 : score sous le seuil (Dr Pepper, réel du jeu).
            ['a' => 'Pepper', 'b' => 'Dr Pepper', 'score' => 0.8060],
            // Niveau 3 : garde conditionnement (chiffres).
            ['a' => 'Sucette 1', 'b' => 'Sucette 1 sucette', 'score' => 0.9321],
        ];
        $res = T1AutoFusion::classifie($paires, self::statsVivantes(), []);

        self::assertCount(1, $res['niveau2'], 'Seule la paire base↔variante sûre passe en niveau 2.');
        self::assertSame('Coca', $res['niveau2'][0]['a']);
        self::assertSame(T1AutoFusion::MOTIF_PREFIXE, $res['niveau2'][0]['motif']);
        self::assertCount(2, $res['niveau3']);
        self::assertSame(T1AutoFusion::MOTIF_SCORE, $res['niveau3'][0]['motif']);
        self::assertSame(T1AutoFusion::MOTIF_CHIFFRE, $res['niveau3'][1]['motif']);
    }

    public function test_classifie_retrograde_si_couts_divergents(): void
    {
        $res = T1AutoFusion::classifie(
            [['a' => 'Coca', 'b' => 'Coca Cola', 'score' => 0.9228]],
            self::statsVivantes(),
            ['Coca' => 0.50, 'Coca Cola' => 0.90] // 80 % d'écart : zéro perte.
        );
        self::assertSame([], $res['niveau2'], 'Coûts divergents > 15 % : jamais d\'auto.');
        self::assertSame(T1AutoFusion::MOTIF_COUTS, $res['niveau3'][0]['motif']);
    }

    public function test_classifie_ignore_cles_mortes_et_casse(): void
    {
        $res = T1AutoFusion::classifie(
            [
                ['a' => 'Fantome', 'b' => 'Clé absente', 'score' => 0.99],   // clés mortes
                ['a' => 'Coca', 'b' => 'coca', 'score' => 0.99],             // casse pure → niveau 1
            ],
            self::statsVivantes(),
            []
        );
        self::assertSame([], $res['niveau2']);
        self::assertSame([], $res['niveau3'], 'Ni clés mortes ni casse pure en niveau 2/3.');
    }

    public function test_classifie_motif_suffixe(): void
    {
        // Même variante (« vert rose ») à l'ordre des teintes près : le
        // radical suggest() coïncide, les formes sans espaces ne sont PAS
        // préfixe l'une de l'autre → le motif retenu est bien « suffixe ».
        $stats = ['Monster vert rose' => ['sales' => 1], 'Monster rose vert' => ['sales' => 0]];
        $res = T1AutoFusion::classifie(
            [['a' => 'Monster vert rose', 'b' => 'Monster rose vert', 'score' => 0.95]],
            $stats,
            []
        );
        self::assertCount(1, $res['niveau2']);
        self::assertSame(T1AutoFusion::MOTIF_SUFFIXE, $res['niveau2'][0]['motif']);
    }

    // ── Maturité 48 h — Europe/Paris, limites exactes ────────────────────

    public function test_maturite_47h59_rien_48h01_fusion(): void
    {
        $cree = '2026-11-02T10:00:00+01:00'; // lundi, heure d'hiver Paris

        // 47 h 59 : pas mûre, 60 s restantes.
        self::assertFalse(T1AutoFusion::estMure($cree, '2026-11-04T09:59:00+01:00'));
        self::assertSame(60, T1AutoFusion::secondesRestantes($cree, '2026-11-04T09:59:00+01:00'));

        // 48 h 01 : mûre.
        self::assertTrue(T1AutoFusion::estMure($cree, '2026-11-04T10:01:00+01:00'));

        // Exactement 48 h 00 : mûre (« révolues »).
        self::assertTrue(T1AutoFusion::estMure($cree, '2026-11-04T10:00:00+01:00'));
    }

    public function test_maturite_heure_d_ete(): void
    {
        // Créée en heure d'été (CEST +02:00), maturité en heure d'hiver.
        $cree = '2026-06-01T12:00:00+02:00';
        self::assertFalse(T1AutoFusion::estMure($cree, '2026-06-03T11:59:00+02:00'));
        self::assertTrue(T1AutoFusion::estMure($cree, '2026-06-03T12:01:00+02:00'));
    }

    public function test_maturite_passage_heure_d_hiver(): void
    {
        // Passer du CEST au CET pendant la quarantaine : sémantique durée
        // ÉCOULÉE (PT48H) — 48 h révolues ENTRE INSTANTS, décalages
        // explicites. Créée sam. 24/10 12:00 CEST ; 48 h plus tard =
        // lun. 26/10 11:00 CET (une heure « gagnée » par le recul).
        $cree = '2026-10-24T12:00:00+02:00';
        self::assertSame(
            '2026-10-26T11:00:00+01:00',
            T1AutoFusion::maturiteIso($cree),
            'La maturité est l\'instant absolu created+48h, exprimé à l\'heure de Paris.'
        );
        self::assertFalse(T1AutoFusion::estMure($cree, '2026-10-26T10:59:00+01:00'), '47 h 59 écoulées : rien.');
        self::assertTrue(T1AutoFusion::estMure($cree, '2026-10-26T11:01:00+01:00'), '48 h 01 écoulées : fusion.');
    }

    public function test_maturite_reference_utc_explicite(): void
    {
        // Leçon #1 (VPS en UTC) : une référence UTC à décalage explicite
        // est convertie correctement — 09:01 UTC = 10:01 Paris (CET).
        $cree = '2026-11-02T10:00:00+01:00';
        self::assertTrue(T1AutoFusion::estMure($cree, '2026-11-04T09:01:00+00:00'));
        self::assertFalse(T1AutoFusion::estMure($cree, '2026-11-04T08:59:00+00:00'));
    }

    public function test_maturite_created_illisible_jamais_mure(): void
    {
        self::assertFalse(T1AutoFusion::estMure('nimportequoi', '2027-01-01T00:00:00+01:00'));
        self::assertSame(PHP_INT_MAX, T1AutoFusion::secondesRestantes(''));
    }

    public function test_maintenant_iso_paris_avec_decalage(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            T1AutoFusion::maintenantIso(),
            'Toujours un décalage explicite, jamais une heure locale nue.'
        );
    }

    // ── Quarantaine : mutateurs purs (idempotence, veto) ─────────────────

    /**
     * @return array{version:int,paires:list<array<string,mixed>>}
     */
    private static function etatVide(): array
    {
        return ['version' => T1Quarantaine::VERSION, 'paires' => []];
    }

    public function test_quarantaine_declaration_idempotente(): void
    {
        $etat = self::etatVide();
        $paire = [['source' => 'Coca', 'target' => 'Coca Cola', 'score' => 0.9228, 'motif' => 'prefixe']];

        self::assertSame(1, T1Quarantaine::appliquerDeclaration($etat, $paire, '2026-11-02T10:00:00+01:00'));
        // Redéclarée au rechargement suivant : le created_at initial fait foi.
        self::assertSame(0, T1Quarantaine::appliquerDeclaration($etat, $paire, '2026-11-03T10:00:00+01:00'));
        self::assertCount(1, $etat['paires']);
        self::assertSame('2026-11-02T10:00:00+01:00', $etat['paires'][0]['created_at']);
        self::assertSame(T1Quarantaine::STATUT_EN_ATTENTE, $etat['paires'][0]['statut']);
    }

    public function test_quarantaine_veto_jamais_contourne(): void
    {
        $etat = self::etatVide();
        $paire = [['source' => 'Coca', 'target' => 'Coca Cola', 'score' => 0.9228, 'motif' => 'prefixe']];
        T1Quarantaine::appliquerDeclaration($etat, $paire, '2026-11-02T10:00:00+01:00');
        $id = $etat['paires'][0]['id'];

        self::assertTrue(T1Quarantaine::appliquerAnnulation($etat, $id, 'u_root'));
        self::assertSame(T1Quarantaine::STATUT_ANNULE, $etat['paires'][0]['statut']);

        // Un veto ne se réouvre ni par rechargement, ni par application.
        self::assertSame(0, T1Quarantaine::appliquerDeclaration($etat, $paire, '2026-11-03T10:00:00+01:00'));
        self::assertFalse(T1Quarantaine::appliquerApplication($etat, $id, '2026-11-04T12:00:00+01:00', 'aud_x'));
        self::assertSame(T1Quarantaine::STATUT_ANNULE, $etat['paires'][0]['statut']);
    }

    public function test_quarantaine_application_et_expiration(): void
    {
        $etat = self::etatVide();
        T1Quarantaine::appliquerDeclaration(
            $etat,
            [['source' => 'Coca', 'target' => 'Coca Cola', 'score' => 0.9228, 'motif' => 'prefixe']],
            '2026-11-02T10:00:00+01:00'
        );
        $id = $etat['paires'][0]['id'];

        self::assertTrue(T1Quarantaine::appliquerApplication($etat, $id, '2026-11-04T10:00:00+01:00', 'aud_merge'));
        self::assertSame(T1Quarantaine::STATUT_APPLIQUE, $etat['paires'][0]['statut']);
        self::assertSame('aud_merge', $etat['paires'][0]['audit_id']);
        // Déjà appliquée : aucune double application.
        self::assertFalse(T1Quarantaine::appliquerApplication($etat, $id, '2026-11-05T10:00:00+01:00', 'aud2'));

        // Une paire EXPIRÉE peut être redéclarée (clé revenue à la vie).
        $etat2 = self::etatVide();
        $paire = [['source' => 'Fanta', 'target' => 'Fanta Orange', 'score' => 0.9, 'motif' => 'prefixe']];
        T1Quarantaine::appliquerDeclaration($etat2, $paire, '2026-11-02T10:00:00+01:00');
        $id2 = $etat2['paires'][0]['id'];
        self::assertTrue(T1Quarantaine::appliquerExpiration($etat2, $id2, 'clé disparue'));
        self::assertSame(1, T1Quarantaine::appliquerDeclaration($etat2, $paire, '2026-11-06T10:00:00+01:00'));
        self::assertSame(T1Quarantaine::STATUT_EN_ATTENTE, $etat2['paires'][0]['statut']);
        self::assertSame('2026-11-06T10:00:00+01:00', $etat2['paires'][0]['created_at']);
    }

    public function test_quarantaine_lecture_fichier_defensive(): void
    {
        $chemin = sys_get_temp_dir() . '/aeic_t15_q_' . uniqid('', true) . '.json';
        $q = new T1Quarantaine($chemin);

        // Absent → vide, jamais d'exception.
        self::assertSame([], $q->entrees());
        self::assertSame([], $q->idsStatuts());

        // Corrompu → vide aussi.
        @mkdir(dirname($chemin), 0775, true);
        file_put_contents($chemin, '{pas du json');
        self::assertSame([], $q->entrees());

        // Écrit + relu : round-trip complet via l'enveloppe verrouillée.
        $q->declarer(
            [['source' => 'Coca', 'target' => 'Coca Cola', 'score' => 0.9228, 'motif' => 'prefixe']],
            '2026-11-02T10:00:00+01:00'
        );
        $entrees = $q->entrees();
        self::assertCount(1, $entrees);
        self::assertSame('Coca', $entrees[0]['source']);
        self::assertSame(T1Suggestions::pairEntityId('Coca', 'Coca Cola'), $entrees[0]['id']);

        @unlink($chemin);
        @unlink($chemin . '.lock');
    }
}
