<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Compta\Reco\Recommendation;
use App\Core\Compta\Reco\RecommendationEngine;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Reco\RecoScenario;

/**
 * Rendu des composants « Idées » (lot 3) SANS base : les vues
 * views/admin/reco/carte.php, kiosk.php et historique.php sont incluses
 * directement avec des payloads construits par le harnais pur
 * RecoScenario + RecommendationEngine (même contrat que
 * AdminRecoController::payloadPourAnalytics()).
 *
 * Vérifications de mission :
 *  - les DEUX titres de blocs (« À corriger » / « Idées pour vendre plus ») ;
 *  - au plus 3 cartes d'idées (occurrences du bouton « Je le fais ») ;
 *  - regroupement par nature : 1 carte par règle, produits en <details> ;
 *  - AUCUN code de règle /\bR\d{1,2}\b/ dans le HTML ;
 *  - libellés de boutons exacts ;
 *  - historique : 3 sections + bouton « Rétablir » ;
 *  - état vide « Rien d'important cette semaine. ».
 */
final class RecoCarteRenderTest extends TestCase
{
    // -----------------------------------------------------------------
    //  Fabriques de payload (contrat du contrôleur)
    // -----------------------------------------------------------------

    /**
     * Scénario moteur complet : 2 idées R4 (une carte regroupée) + R11
     * (coût à renseigner) + R2 (rupture) en corrections, horloge figée.
     * Produits « volume » insérés pour rendre les médianes R4 réalistes ;
     * horaire retiré du forecast pour garder R7 silencieuse ; probabilité
     * d'objectif haute pour garder R10 silencieuse.
     *
     * @return array{idees:list<Recommendation>,corrections:list<Recommendation>,autres:list<array<string,mixed>>}
     */
    private function scenarioMoteur(): array
    {
        $now = new \DateTimeImmutable('2026-10-21 12:00', new \DateTimeZone('Europe/Paris'));
        $r4 = static fn (string $k): \App\Core\Compta\Reco\RecoProduct => RecoScenario::produit($k, 'Produit ' . $k, 'Boissons', 10.0, 20.0, 2.0, 0.8, null, null, 10.0, 8.0, 12.0);
        $volume = static fn (string $k): \App\Core\Compta\Reco\RecoProduct => RecoScenario::produit($k, 'Produit ' . $k, 'Boissons', 100.0, 200.0, 2.0, 1.8, null, null, 20.0, 16.0, 24.0);
        $produits = [
            $r4('P1'), // R4 (retenue)
            $r4('P2'), // R4 (retenue)
            $r4('P3'), // R4 (écartée par la diversité, jamais affichée)
            $volume('P4'),
            $volume('P5'),
            $volume('P6'),
            $volume('P7'),
            new \App\Core\Compta\Reco\RecoProduct('P9', 'Produit P9', 'Snacks', 50.0, 100.0, null, null, 'cout_inconnu', null, 10.0, 8.0, 12.0), // R11
            new \App\Core\Compta\Reco\RecoProduct('P10', 'Rupture X', 'Snacks', 50.0, 100.0, 2.0, 1.0, null, 0, 10.0, 8.0, 12.0), // R2
        ];
        $fc = RecoScenario::forecastMinimal();
        $fc['semaine']['prob_atteinte'] = 0.9;
        unset($fc['horaire']);
        $ds = RecoScenario::dataset($produits, [], [], 1.0, false, 30, $fc);

        ['idees' => $idees, 'meta' => $meta] = RecommendationEngine::recommend($ds, $now, ['autres_idees' => true]);

        $ideesOut = [];
        $correctionsOut = [];
        foreach ($idees as $i) {
            if ($i->bloc === 'correction') {
                $correctionsOut[] = $i;
            } else {
                $ideesOut[] = $i;
            }
        }

        return [
            'idees'       => $ideesOut,
            'corrections' => $correctionsOut,
            'autres'      => is_array($meta['autres_idees'] ?? null) ? $meta['autres_idees'] : [],
        ];
    }

    /**
     * Décore une reco comme le contrôleur : toArray() + 'lien'
     * {href, libelle} résolu par la nature de la correction.
     *
     * @return array<string,mixed>
     */
    private function decorer(Recommendation $r): array
    {
        $tab = $r->toArray();
        $regle = $tab['regle'];
        $titre = $tab['titre'];
        $lien = null;
        if ($regle === 'R2') {
            $lien = ['href' => 'https://example.test/admin/compta/liste', 'libelle' => 'Ajouter à la liste de courses'];
        } elseif ($regle === 'R11' && str_contains($titre, 'Renseigner le coût')) {
            $cle = (string) ($tab['produits'][0]['key'] ?? '');
            $lien = ['href' => 'https://example.test/admin/compta/couts?product_key=' . rawurlencode($cle) . '#costs-form', 'libelle' => 'Ouvrir la fiche du produit'];
        }
        $tab['lien'] = $lien;

        return $tab;
    }

    /**
     * Payload complet au format payloadPourAnalytics().
     *
     * @param list<Recommendation>          $idees
     * @param list<Recommendation>          $corrections
     * @param list<array<string,mixed>>     $autres
     */
    private function payload(array $idees, array $corrections = [], array $autres = []): array
    {
        return [
            'idees'        => array_map($this->decorer(...), $idees),
            'corrections'  => array_map($this->decorer(...), $corrections),
            'autres_idees' => $autres,
            'meta'         => ['indisponible' => false, 'raison' => '', 'message' => null],
            'insuffisant'  => false,
            'indisponible' => false,
            'raison'       => '',
            'fenetre'      => ['from' => '2026-09-22', 'to' => '2026-10-21', 'jours_ouverts' => 30],
        ];
    }

    /** Payload vide (aucune idée, aucune correction). */
    private function payloadVide(): array
    {
        return $this->payload([], [], []);
    }

    // -----------------------------------------------------------------
    //  Rendus (inclusion directe des vues, variables extraites)
    // -----------------------------------------------------------------

    private function rendreCarteAdmin(array $reco): string
    {
        return $this->capturerVue(AEIC_VIEWS . '/admin/reco/carte.php', [
            'reco'  => $reco,
            'kiosk' => false,
            'token' => '',
        ]);
    }

    private function rendreKiosque(array $vars): string
    {
        $vars['kiosk'] = true;

        return $this->capturerVue(AEIC_VIEWS . '/admin/reco/kiosk.php', $vars);
    }

    private function rendreHistorique(array $vars): string
    {
        return $this->capturerVue(AEIC_VIEWS . '/admin/reco/historique.php', $vars);
    }

    /**
     * Inclut une vue avec les variables données (extract, comme
     * Controller::render) et renvoie le HTML capturé.
     *
     * @param array<string,mixed> $vars
     */
    private function capturerVue(string $vue, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();

        try {
            include $vue;
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return $html;
    }

    // -----------------------------------------------------------------
    //  Carte admin
    // -----------------------------------------------------------------

    public function test_deux_titres_de_blocs_et_maximum_trois_boutons_je_le_fais(): void
    {
        $s = $this->scenarioMoteur();
        $html = $this->rendreCarteAdmin($this->payload($s['idees'], $s['corrections'], $s['autres']));

        self::assertStringContainsString('À corriger', $html, 'Titre du bloc corrections absent.');
        self::assertStringContainsString('Idées pour vendre plus', $html, 'Titre du bloc idées absent (orthographe « vendre »).');
        self::assertStringNotContainsString('vender', $html, 'Faute d\'orthographe interdite.');

        $boutons = substr_count($html, '>Je le fais</button>');
        self::assertLessThanOrEqual(3, $boutons, 'Au plus 3 cartes d\'idées (cap moteur).');
        self::assertSame(2, $boutons, 'Le scénario moteur retient 2 idées R4.');
    }

    public function test_regroupement_une_carte_par_regle_avec_liste_produits_depliable(): void
    {
        $s = $this->scenarioMoteur();
        $html = $this->rendreCarteAdmin($this->payload($s['idees'], [], []));

        // 2 idées R4 → UNE seule carte idée (class="reco-idee" exact ;
        // les corrections portent « reco-idee reco-correction »).
        self::assertSame(1, substr_count($html, '<div class="reco-idee">'), 'Les idées d\'une même règle forment UNE carte.');
        self::assertSame(1, substr_count($html, 'Produits concernés (2)'), 'Liste dépliable des 2 produits de la règle.');
        self::assertStringContainsString('<details', $html, 'Les produits sont en <details>.');
        self::assertStringContainsString('Produit P1', $html);
        self::assertStringContainsString('Produit P2', $html);
        self::assertStringContainsString('Hypothèses et mesure', $html, 'Hypothèses + « comment on mesurera » présents.');
    }

    public function test_aucun_code_de_regle_dans_le_html(): void
    {
        $s = $this->scenarioMoteur();
        $html = $this->rendreCarteAdmin($this->payload($s['idees'], $s['corrections'], $s['autres']));

        self::assertDoesNotMatchRegularExpression('/\bR\d{1,2}\b/', $html, 'AUCUN code de règle (R1, R4…) ne doit fuiter dans la carte.');
    }

    public function test_libelles_de_boutons_exactes_et_gain_en_fourchette(): void
    {
        $s = $this->scenarioMoteur();
        $html = $this->rendreCarteAdmin($this->payload($s['idees'], $s['corrections'], []));

        // Idées commerciales.
        self::assertStringContainsString('Je le fais', $html);
        self::assertStringContainsString('>Plus tard</button>', $html);
        self::assertStringContainsString('>Pas pour moi</button>', $html);
        self::assertStringContainsString('Motif', $html);
        // Corrections : bouton principal + options discrètes.
        self::assertStringContainsString('Ouvrir la fiche du produit', $html);
        self::assertStringContainsString('Ajouter à la liste de courses', $html);
        self::assertStringContainsString('/admin/compta/couts?product_key=', $html);
        self::assertStringContainsString('/admin/compta/liste', $html);
        // Anciens libellés bannis.
        self::assertStringNotContainsString('>Appliquer</button>', $html);
        self::assertStringNotContainsString('>Ignorer</button>', $html);
        self::assertStringNotContainsString('Ouvrir la fiche ↗', $html);
        // Gain estimé EN FOURCHETTE (format fr via formatPrice).
        self::assertMatchesRegularExpression('/Gain estimé : entre [\d\s]+ € et [\d\s]+ € par semaine/u', $html, 'Gain en fourchette « entre X et Y € par semaine ».');
        // Confiance EN MOTS.
        self::assertMatchesRegularExpression('/confiance (faible|moyenne|élevée)/u', $html);
        self::assertDoesNotMatchRegularExpression('/\d+\s*% de confiance/u', $html, 'Jamais de pourcentage brut de confiance.');
    }

    public function test_depliant_voir_les_autres_idees_avec_compteur(): void
    {
        $s = $this->scenarioMoteur();
        $autres = [
            ['regle' => 'R4', 'titre' => 'Forte marge peu vendue : Produit 3', 'pourquoi' => '10,0 u/30 j'],
        ];
        $html = $this->rendreCarteAdmin($this->payload($s['idees'], [], $autres));

        self::assertStringContainsString('Voir les autres idées (1)', $html);
        self::assertStringContainsString('Produit 3', $html);
        self::assertDoesNotMatchRegularExpression('/\bR\d{1,2}\b/', $html, 'Le dépliant n\'affiche aucun code de règle.');
    }

    public function test_etat_vide_rien_d_important_cette_semaine(): void
    {
        $html = $this->rendreCarteAdmin($this->payloadVide());

        self::assertStringContainsString('Rien d\'important cette semaine.', $html);
        self::assertStringNotContainsString('À corriger', $html, 'Aucun bloc affiché quand tout est vide (état sobre).');
        self::assertStringNotContainsString('Idées pour vendre plus', $html);
        self::assertStringNotContainsString('Je le fais', $html);
    }

    // -----------------------------------------------------------------
    //  Version kiosque (mêmes règles, téléphone)
    // -----------------------------------------------------------------

    private function kiosqueVars(array $reco, array $hist = []): array
    {
        return [
            'idees'        => $reco['idees'],
            'corrections'  => $reco['corrections'],
            'autres_idees' => $reco['autres_idees'],
            'meta'         => $reco['meta'],
            'insuffisant'  => $reco['insuffisant'],
            'indisponible' => $reco['indisponible'],
            'raison'       => $reco['raison'],
            'token'        => 'jeton-rendu-test',
            'hist'         => $hist,
        ];
    }

    public function test_kiosque_memes_blocs_boutons_et_reglement(): void
    {
        $s = $this->scenarioMoteur();
        $html = $this->rendreKiosque($this->kiosqueVars($this->payload($s['idees'], $s['corrections'])));

        self::assertStringContainsString('À corriger', $html);
        self::assertStringContainsString('Idées pour vendre plus', $html);
        self::assertSame(2, substr_count($html, '>Je le fais</button>'));
        self::assertStringContainsString('>Pas pour moi</button>', $html);
        self::assertStringContainsString('Ajouter à la liste de courses', $html);
        self::assertSame(1, substr_count($html, 'Produits concernés (2)'), 'Regroupement : 1 carte par règle, produits en <details>.');
        self::assertDoesNotMatchRegularExpression('/\bR\d{1,2}\b/', $html);
    }

    public function test_kiosque_etat_vide_rien_d_important(): void
    {
        $html = $this->rendreKiosque($this->kiosqueVars($this->payloadVide()));

        self::assertStringContainsString('Rien d\'important cette semaine.', $html);
        self::assertStringNotContainsString('Je le fais', $html);
    }

    // -----------------------------------------------------------------
    //  Historique : 3 sections + Rétablir + libellés humains
    // -----------------------------------------------------------------

    /**
     * Ligne recommendations « appliquée » façon PDO (payload_json brut).
     *
     * @return array<string,mixed>
     */
    private function ligneHistorique(string $id, string $regle, string $titre, string $bloc, string $conclusion = '', ?array $effet = null): array
    {
        return [
            'id'               => $id,
            'regle'            => $regle,
            'titre'            => $titre,
            'produits_json'    => '[{"key":"P1","label":"Produit 1"}]',
            'payload_json'     => (string) json_encode(['bloc' => $bloc], JSON_UNESCAPED_UNICODE),
            'baseline_json'    => null,
            'effet_json'       => $effet !== null ? (string) json_encode($effet, JSON_UNESCAPED_UNICODE) : null,
            // $l['effet'] : tableau déjà décodé/normalisé par le contrôleur
            // (AdminRecoController::effetDepuisJson) — contrat de la vue.
            'effet'            => $effet,
            'effet_conclusion' => $conclusion,
            'fenetre_json'     => '{}',
            'decided_at'       => '2026-10-01 10:00:00',
            'applied_at'       => '2026-10-02 10:00:00',
            'effet_checked_at' => $conclusion !== '' ? '2026-10-20 10:00:00' : null,
        ];
    }

    public function test_historique_trois_sections_retablir_et_aucun_code_de_regle(): void
    {
        $effetConcluant = ['etat' => 'concluant', 'effet' => 12.5, 'bas' => 4.0, 'haut' => 21.0, 'jours' => 14, 'note' => ''];
        $effetNonConcluant = ['etat' => 'non_concluant', 'effet' => 1.0, 'bas' => -3.0, 'haut' => 5.0, 'jours' => 14, 'note' => ''];

        $vars = [
            'title'     => 'Idées appliquées',
            'lignes'    => [
                $this->ligneHistorique('h1', 'R4', 'Mise en avant : Produit 1', 'idee'),
                $this->ligneHistorique('h2', 'R1', 'Offre fin de semaine : Produit 2', 'idee', 'concluant', $effetConcluant),
                $this->ligneHistorique('h3', 'R5', 'Duo Produit 3 + Produit 4', 'idee', 'non_concluant', $effetNonConcluant),
                $this->ligneHistorique('h4', 'R11', 'Renseigner le coût de Produit P9', 'correction'),
            ],
            'reussies'  => [],
            'proposees' => [
                [
                    'id' => 'p1', 'regle' => 'R6', 'titre' => 'Seuil cadeau panier',
                    'created_at' => '2026-10-21 08:00:00',
                ],
            ],
            'ignorees'  => [
                [
                    'id' => 'i1', 'regle' => 'R7', 'titre' => 'Heure creuse : Produit 5',
                    'motif' => 'non_pertinent : déjà testé', 'decided_at' => '2026-10-15 09:00:00',
                ],
            ],
            'seuils'    => [],
        ];
        $html = $this->rendreHistorique($vars);

        // Les 3 sections de mission + annexes conservées.
        self::assertStringContainsString('En cours', $html);
        self::assertStringContainsString('Mesurées', $html);
        self::assertStringContainsString('Ignorées', $html);
        self::assertStringContainsString('Corrections appliquées', $html, 'Les corrections appliquées restent visibles (sans mesure).');
        self::assertStringContainsString('Idées en attente', $html);
        self::assertStringContainsString('Seuils du moteur', $html);

        // Répartition : en cours / mesurées (fourchette € ou non concluant).
        self::assertStringContainsString('Mise en avant : Produit 1', $html);
        self::assertStringContainsString('mesure en cours', $html);
        self::assertMatchesRegularExpression('/Effet mesuré \+12,50 € \[4,00 ; 21,00\] €\/période/u', $html, 'Effet en € avec fourchette.');
        self::assertStringContainsString('Non concluant', $html);

        // Section Ignorées : motif + bouton Rétablir vers le nouvel endpoint.
        self::assertStringContainsString('non_pertinent : déjà testé', $html);
        self::assertStringContainsString('>Rétablir</button>', $html);
        self::assertStringContainsString('/admin/reco/retablir', $html);

        // Décisions en attente : nouveaux libellés.
        self::assertStringContainsString('>Je le fais</button>', $html);
        self::assertStringNotContainsString('>Appliquer</button>', $html);
        self::assertStringNotContainsString('>Ignorer</button>', $html);

        // JAMAIS de code de règle dans l'historique.
        self::assertDoesNotMatchRegularExpression('/\bR\d{1,2}\b/', $html);
    }

    // -----------------------------------------------------------------
    //  « ✅ Ce qui a marché » : section de tête de l'historique (P1-4)
    // -----------------------------------------------------------------

    /**
     * Extrait le HTML d'une section entre son titre et le titre de la
     * section suivante ( assertions localisées, pas page entière).
     */
    private function trancher(string $html, string $depuis, string $jusqua): string
    {
        $debut = mb_strpos($html, $depuis);
        $fin   = mb_strpos($html, $jusqua);
        self::assertIsInt($debut, "Le marqueur « {$depuis} » est absent du rendu.");
        self::assertIsInt($fin, "Le marqueur « {$jusqua} » est absent du rendu.");

        return mb_substr($html, $debut, $fin - $debut);
    }

    public function test_historique_section_ce_qui_a_marche_en_tete_avec_effets_concluants(): void
    {
        $effetConcluant = ['etat' => 'concluant', 'effet' => 12.5, 'bas' => 4.0, 'haut' => 21.0, 'jours' => 14, 'note' => ''];
        $effetAutre = ['etat' => 'concluant', 'effet' => 8.0, 'bas' => 2.0, 'haut' => 14.0, 'jours' => 14, 'note' => ''];

        $vars = [
            'title'     => 'Idées appliquées',
            'lignes'    => [],
            'reussies'  => [
                $this->ligneHistorique('w1', 'R1', 'Offre fin de semaine : Produit 2', 'idee', 'concluant', $effetConcluant),
                $this->ligneHistorique('w2', 'R4', 'Mise en avant : Produit 1', 'idee', 'concluant', $effetAutre),
            ],
            'proposees' => [],
            'ignorees'  => [],
            'seuils'    => [],
        ];
        $html = $this->rendreHistorique($vars);

        // Section présente, avec ses 2 idées, EN TÊTE (avant « En cours »).
        self::assertStringContainsString('✅ Ce qui a marché', $html, 'La section « Ce qui a marché » est absente.');
        self::assertLessThan(
            mb_strpos($html, '<h2 class="chart-title">En cours</h2>'),
            mb_strpos($html, '✅ Ce qui a marché'),
            'La section « Ce qui a marché » doit être en tête, avant « En cours ».'
        );
        $section = $this->trancher($html, '✅ Ce qui a marché', '<h2 class="chart-title">En cours</h2>');
        self::assertStringContainsString('Offre fin de semaine : Produit 2', $section);
        self::assertStringContainsString('Mise en avant : Produit 1', $section);

        // Effet mesuré en libellé HUMAIN (EffetMesure::libelle), jamais R#.
        self::assertMatchesRegularExpression('/Effet mesuré \+12,50 € \[4,00 ; 21,00\] €\/période/u', $section, 'Effet chiffré avec fourchette attendu dans « Ce qui a marché ».');
        self::assertStringContainsString('Stock à écouler', $section, 'Nature en libellé humain (RecoLibelles).');
        self::assertDoesNotMatchRegularExpression('/\bR\d{1,2}\b/', $html, 'Aucun code de règle ne doit fuiter.');
    }

    public function test_historique_section_ce_qui_a_marche_vide_message_neutre(): void
    {
        $vars = [
            'title'     => 'Idées appliquées',
            'lignes'    => [],
            'reussies'  => [],
            'proposees' => [],
            'ignorees'  => [],
            'seuils'    => [],
        ];
        $html = $this->rendreHistorique($vars);

        $section = $this->trancher($html, '✅ Ce qui a marché', '<h2 class="chart-title">En cours</h2>');
        self::assertStringContainsString('Aucune idée concluante pour l\'instant.', $section, 'Message neutre attendu quand rien n\'a marché.');

        // Page inchangée par ailleurs : toutes les sections restent là.
        self::assertStringContainsString('En cours', $html);
        self::assertStringContainsString('Mesurées', $html);
        self::assertStringContainsString('Ignorées', $html);
        self::assertStringContainsString('Idées en attente', $html);
        self::assertStringContainsString('Seuils du moteur', $html);
    }

    public function test_kiosque_bloc_ce_qui_a_marche_top3_et_libelles_humains(): void
    {
        $concluant = static fn (float $e): array => ['etat' => 'concluant', 'effet' => $e, 'bas' => $e - 5.0, 'haut' => $e + 5.0, 'jours' => 14, 'note' => ''];
        $ligne = static fn (string $titre, string $conclusion, ?array $effet): array => [
            'titre'       => $titre,
            'regle_lib'   => 'Forte marge peu vendue',
            'applied_at'  => '2026-10-02 10:00:00',
            'conclusion'  => $conclusion,
            'libelle'     => $effet !== null ? \App\Core\Compta\Reco\EffetMesure::libelle($effet) : '',
            'note'        => '',
            'effet'       => $effet !== null ? (float) $effet['effet'] : 0.0,
        ];

        $html = $this->rendreKiosque($this->kiosqueVars($this->payloadVide(), [
            $ligne('Idée réussie 1', 'concluant', $concluant(12.5)),
            $ligne('Idée réussie 2', 'concluant', $concluant(8.0)),
            $ligne('Idée réussie 3', 'concluant', $concluant(30.0)),
            $ligne('Idée réussie 4 (hors top)', 'concluant', $concluant(5.0)), // 4e : coupée par le cap top 3
            $ligne('Idée concluante négative', 'concluant', $concluant(-50.0)), // concluante mais effet ≤ 0
            $ligne('Idée non concluante', 'non_concluant', ['etat' => 'non_concluant', 'effet' => 1.0, 'bas' => -3.0, 'haut' => 5.0, 'jours' => 14, 'note' => '']),
            $ligne('Idée en cours de mesure', '', null),
        ]));

        $bloc = $this->trancher($html, '<h2 class="ka-chart-title">Ce qui a marché</h2>', '<h2 class="ka-chart-title">Idées appliquées</h2>');
        self::assertSame(3, substr_count($bloc, 'ça a marché'), 'Le bloc « Ce qui a marché » du kiosque est un TOP 3.');
        self::assertStringContainsString('Idée réussie 1', $bloc);
        self::assertStringContainsString('Idée réussie 2', $bloc);
        self::assertStringContainsString('Idée réussie 3', $bloc);
        self::assertStringNotContainsString('Idée réussie 4', $bloc, 'Seules les 3 premières concluantes positives sont affichées.');
        self::assertStringNotContainsString('Idée concluante négative', $bloc, 'Une conclusion à effet négatif n\'est PAS « ce qui a marché ».');
        self::assertStringNotContainsString('Idée non concluante', $bloc);
        self::assertStringNotContainsString('Idée en cours de mesure', $bloc);

        // Libellés humains : EffetMesure + RecoLibelles, jamais de code R#.
        self::assertMatchesRegularExpression('/Effet mesuré \+12,50 € \[7,50 ; 17,50\] €\/période/u', $bloc);
        self::assertStringContainsString('Forte marge peu vendue', $bloc);
        self::assertDoesNotMatchRegularExpression('/\bR\d{1,2}\b/', $html);

        // L'historique complet reste inchangé : TOUTES les idées y figurent.
        $histComplet = mb_substr($html, mb_strpos($html, '<h2 class="ka-chart-title">Idées appliquées</h2>'));
        self::assertStringContainsString('Idée réussie 4 (hors top)', $histComplet);
        self::assertStringContainsString('Idée concluante négative', $histComplet);
    }

    public function test_kiosque_bloc_ce_qui_a_marche_vide_message_neutre(): void
    {
        $html = $this->rendreKiosque($this->kiosqueVars($this->payloadVide(), []));

        $bloc = $this->trancher($html, '<h2 class="ka-chart-title">Ce qui a marché</h2>', '<h2 class="ka-chart-title">Idées appliquées</h2>');
        self::assertStringContainsString('Aucune idée concluante pour l\'instant.', $bloc, 'Message neutre attendu quand aucune idée concluante.');
        self::assertStringContainsString('Aucune idée appliquée pour l\'instant.', $html, 'L\'historique compact reste inchangé (état vide).');
    }
}
