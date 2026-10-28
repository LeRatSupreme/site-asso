<?php

declare(strict_types=1);

namespace Tests\Unit\Compta;

use App\Core\Compta\T1\T1AutoRetrain;
use App\Core\Compta\T1\T1FeedbackFeed;
use PHPUnit\Framework\TestCase;

/**
 * Boucle de ré-entraînement automatique (P2-auto, brief A1) — LOGIQUE
 * PURE : récolte des retours (dédoublonnage par entity_id, décision la
 * plus récente gagne, filtre « depuis le dernier entraînement » sur les
 * INSTANTS), seuil et transitions d'état de la décision de lancement
 * (horloge injectable), ligne d'indicateur sobre.
 *
 * Aucune base, aucun fichier : les lignes d'audit sont forgées au format
 * plat produit par AuditLog::rowsForAction.
 */
final class T1FeedbackFeedTest extends TestCase
{
    /** Une ligne de feedback (format plat rowsForAction). */
    private static function ligne(
        string $entityId,
        string $a,
        string $b,
        string $decision,
        string $origine,
        string $cree,
        ?float $score = null
    ): array {
        return [
            'id'         => 'aud_' . $entityId,
            'entity_id'  => $entityId,
            'created_at' => $cree,
            'a'          => $a,
            'b'          => $b,
            'score'      => $score,
            'decision'   => $decision,
            'origine'    => $origine,
        ];
    }

    // ── Récolte : tri positifs/négatifs, origines, volume ────────────────

    public function test_separe_positives_et_negatives(): void
    {
        $r = T1FeedbackFeed::collecter([
            self::ligne('e1', 'Fanta', 'Fanta Orange', 'fusion', 'modele', '2026-10-29 10:00:00', 0.92),
            self::ligne('e2', 'Redbull Bleu', 'Redbull Blanche', 'ignorer', 'residuel', '2026-10-29 10:01:00'),
            self::ligne('e3', 'Pepsi', 'pepsi', 'fusion', 'auto', '2026-10-29 10:02:00'),
        ], '2026-10-28T03:39:53+00:00');

        self::assertSame(3, $r['total'], 'Trois paires distinctes : deux fusions, un ignoré.');
        self::assertCount(2, $r['positives'], 'fusion (modele) et fusion (auto) sont positives.');
        self::assertCount(1, $r['negatives'], 'ignorer (residuel) est négative.');
        self::assertSame('Fanta', $r['positives'][0]['a']);
        self::assertSame(0.92, $r['positives'][0]['score']);
        self::assertNull($r['negatives'][0]['score'], 'Score absent → null, jamais de faux 0.');
    }

    public function test_dedoublonne_par_entity_id_decision_recente_gagne(): void
    {
        // Ordre rowsForAction : created_at DESC, id DESC — la ligne la
        // plus RÉCENTE d'une même paire porte la décision définitive.
        $r = T1FeedbackFeed::collecter([
            self::ligne('e1', 'Fanta', 'Fanta Orange', 'ignorer', 'modele', '2026-10-29 12:00:00'),
            self::ligne('e1', 'Fanta', 'Fanta Orange', 'fusion', 'modele', '2026-10-29 10:00:00'),
            self::ligne('e2', 'Lion', 'Lion Peanut', 'fusion', 'modele', '2026-10-29 09:00:00'),
        ], '2026-10-28T03:39:53+00:00');

        self::assertSame(2, $r['total'], 'Une paire = une ligne, quelle que soit la répétition des décisions.');
        self::assertCount(1, $r['negatives'], 'La décision la plus récente (ignorer) gagne pour e1.');
        self::assertCount(1, $r['positives']);
        self::assertSame('Lion', $r['positives'][0]['a']);
    }

    public function test_filtre_depuis_le_dernier_entrainement_sur_les_instants(): void
    {
        // dernier_run à décalage non nul : la comparaison est ABSOLUE.
        $depuis = '2026-10-29T12:00:00+02:00'; // = 2026-10-29 10:00:00 UTC

        $r = T1FeedbackFeed::collecter([
            self::ligne('e1', 'A', 'B', 'fusion', 'modele', '2026-10-29 09:59:59'),   // avant : exclu
            self::ligne('e2', 'C', 'D', 'fusion', 'modele', '2026-10-29 10:00:00'),   // ÉGAL : exclu (strictement après)
            self::ligne('e3', 'E', 'F', 'fusion', 'modele', '2026-10-29 10:00:01'),   // après : inclus
        ], $depuis);

        self::assertSame(1, $r['total']);
        self::assertSame('E', $r['positives'][0]['a']);
    }

    public function test_depuis_null_compte_tout_historique(): void
    {
        $r = T1FeedbackFeed::collecter([
            self::ligne('e1', 'A', 'B', 'fusion', 'auto', '2020-01-01 00:00:00'),
        ], null);

        self::assertSame(1, $r['total']);
    }

    public function test_ignore_lignes_inexploitables(): void
    {
        $r = T1FeedbackFeed::collecter([
            self::ligne('', 'A', 'B', 'fusion', 'modele', '2026-10-29 10:00:00'),          // entity_id vide
            self::ligne('e2', '', 'B', 'fusion', 'modele', '2026-10-29 10:00:00'),         // libellé vide
            self::ligne('e3', 'A', 'A', 'fusion', 'modele', '2026-10-29 10:00:00'),        // a === b
            self::ligne('e4', 'A', 'B', 'peutetre', 'modele', '2026-10-29 10:00:00'),      // décision inconnue
            self::ligne('e5', 'A', 'B', 'fusion', 'inconnue', '2026-10-29 10:00:00'),      // origine inconnue
            self::ligne('e6', 'A', 'B', 'fusion', 'modele', 'nimportequoi'),               // created_at illisible
            self::ligne('e7', 'A', 'B', 'fusion', 'modele', '2026-10-29 10:00:00'),        // VALIDE
        ], '2026-10-28T00:00:00+00:00');

        self::assertSame(1, $r['total'], 'Seule la ligne exploitable compte (PHP ne « parse » jamais une date aberrante).');
    }

    // ── Helpers d'instants (leçon #1 : jamais d'heure locale nue) ────────

    public function test_instants(): void
    {
        self::assertNotNull(T1FeedbackFeed::instantIso('2026-10-28T03:39:53+00:00'));
        self::assertNotNull(T1FeedbackFeed::instantIso('2026-10-28T03:39:53Z'));
        self::assertNull(T1FeedbackFeed::instantIso('2026-10-28 03:39:53'), 'Heure locale nue refusée.');
        self::assertNull(T1FeedbackFeed::instantIso(''), 'Chaîne vide refusée (PHP la parserait « maintenant »).');
        self::assertNull(T1FeedbackFeed::instantUtc('nimportequoi'));
        self::assertSame(
            T1FeedbackFeed::instantIso('2026-10-29T12:00:00+02:00'),
            T1FeedbackFeed::instantUtc('2026-10-29 10:00:00'),
            'created_at UTC et ISO à décalage explicite désignent le même instant.'
        );
        self::assertSame('2026-10-29 10:00:00', T1FeedbackFeed::borneSqlUtc('2026-10-29T12:00:00+02:00'));
        self::assertNull(T1FeedbackFeed::borneSqlUtc('illisible'));
    }

    // ── Décision de lancement : seuil et transitions d'état ──────────────

    private function etat(string $statut, int $seuil = 30, ?string $lancement = null): array
    {
        $etat = T1AutoRetrain::etatDefaut();
        $etat['statut'] = $statut;
        $etat['seuil'] = $seuil;
        $etat['dernier_lancement'] = $lancement;

        return $etat;
    }

    public function test_decision_seuil(): void
    {
        $now = '2026-10-30T12:00:00+02:00';

        self::assertFalse(T1AutoRetrain::decision($this->etat('pret'), 29, $now)['lancer'], '29 paires : on attend.');
        self::assertTrue(T1AutoRetrain::decision($this->etat('pret'), 30, $now)['lancer'], '30 paires : seuil atteint.');
        self::assertTrue(T1AutoRetrain::decision($this->etat('pret', 5), 5, $now)['lancer'], 'Le seuil est celui de l état.');
    }

    public function test_decision_pas_de_double_lancement(): void
    {
        $now = '2026-10-30T12:00:00+02:00';
        $lance = '2026-10-30T11:00:00+02:00'; // il y a 1 h : run encore vivant (< 4 h)

        $r = T1AutoRetrain::decision($this->etat('entrainement_en_cours', 30, $lance), 30, $now);

        self::assertFalse($r['lancer'], 'En cours : jamais de second lancement.');
        self::assertSame('entraînement déjà en cours', $r['raison']);
    }

    public function test_decision_run_mort_relpase_apres_stale(): void
    {
        $now = '2026-10-30T12:00:00+02:00';
        $lance = '2026-10-30T06:00:00+02:00'; // il y a 6 h : au-delà du budget maximal (4 h)

        $r = T1AutoRetrain::decision($this->etat('entrainement_en_cours', 30, $lance), 30, $now);

        self::assertTrue($r['lancer'], 'Un « en cours » depuis plus de 4 h est mort : on relance (auto-réparation).');
    }

    public function test_decision_anti_tempete_apres_echec(): void
    {
        $now = '2026-10-30T12:00:00+02:00';

        $recent = '2026-10-30T11:55:00+02:00'; // il y a 5 min
        self::assertFalse(
            T1AutoRetrain::decision($this->etat('echec', 30, $recent), 30, $now)['lancer'],
            'Échec il y a 5 min : pas de nouvel essai (anti-tempête 15 min).'
        );

        $ancien = '2026-10-30T11:30:00+02:00'; // il y a 30 min
        self::assertTrue(
            T1AutoRetrain::decision($this->etat('echec', 30, $ancien), 30, $now)['lancer'],
            'Échec il y a 30 min : nouvel essai autorisé.'
        );

        // Sans dernier_lancement (jamais lancé) : pas d'anti-tempête.
        self::assertTrue(T1AutoRetrain::decision($this->etat('echec'), 30, $now)['lancer']);
    }

    public function test_decision_sous_le_seuil_bloque_tout_statut(): void
    {
        $now = '2026-10-30T12:00:00+02:00';

        foreach (['pret', 'echec', 'remplace', 'consserve'] as $statut) {
            self::assertFalse(
                T1AutoRetrain::decision($this->etat($statut), 3, $now)['lancer'],
                "3 paires : aucun lancement quel que soit le statut ($statut)."
            );
        }
    }

    // ── Indicateur sobre (un seul point d'assemblage) ────────────────────

    public function test_ligne_indicateur(): void
    {
        $pret = T1AutoRetrain::etatDefaut();
        $pret['paires_cumulees'] = 12;
        self::assertSame(
            'Ré-entraînement automatique : 12/30 paires validées — prochain entraînement à 30.',
            T1AutoRetrain::ligneIndicateur($pret)
        );

        $pretPret = T1AutoRetrain::etatDefaut();
        $pretPret['paires_cumulees'] = 30;
        self::assertStringContainsString('entraînement imminent', T1AutoRetrain::ligneIndicateur($pretPret));

        $enCours = T1AutoRetrain::etatDefaut();
        $enCours['statut'] = 'entrainement_en_cours';
        self::assertSame('Ré-entraînement automatique : entraînement en cours.', T1AutoRetrain::ligneIndicateur($enCours));

        $echec = T1AutoRetrain::etatDefaut();
        $echec['statut'] = 'echec';
        $echec['dernier_run'] = '2026-10-29T12:00:00+02:00';
        $ligne = T1AutoRetrain::ligneIndicateur($echec);
        self::assertStringContainsString('en échec', $ligne);
        self::assertStringContainsString('29/10/2026', $ligne, 'Date du dernier run affichée (heure de Paris).');

        $remplace = T1AutoRetrain::etatDefaut();
        $remplace['statut'] = 'remplace';
        $remplace['dernier_run'] = '2026-10-29T12:00:00+02:00';
        self::assertStringContainsString('modèle remplacé', T1AutoRetrain::ligneIndicateur($remplace));

        $conserve = T1AutoRetrain::etatDefaut();
        $conserve['statut'] = 'consserve';
        self::assertStringContainsString('modèle conservé', T1AutoRetrain::ligneIndicateur($conserve));
    }

    public function test_format_date_paris(): void
    {
        // Heure d'été (CEST, UTC+2) : le décalage d'origine est déjà Paris.
        self::assertSame('15/08/2026 à 12:00', T1AutoRetrain::formaterDateIso('2026-08-15T12:00:00+02:00'));
        // Heure d'hiver (CET, UTC+1) et décalage d'origine new-yorkais :
        // 09:00−04:00 = 13:00 UTC = 14:00 Paris.
        self::assertSame(
            '15/01/2026 à 14:00',
            T1AutoRetrain::formaterDateIso('2026-01-15T09:00:00-04:00'),
            'Conversion en heure de Paris, quel que soit le décalage d origine (DST inclus).'
        );
        self::assertSame('', T1AutoRetrain::formaterDateIso('illisible'));
    }

    public function test_etat_defaut_coherent(): void
    {
        $etat = T1AutoRetrain::etatDefaut();

        self::assertSame(30, $etat['seuil']);
        self::assertSame('pret', $etat['statut']);
        self::assertSame(0, $etat['paires_cumulees']);
        self::assertNull($etat['dernier_run']);
        self::assertNull($etat['modele_version']);
        self::assertSame([], $etat['historique']);
    }
}
