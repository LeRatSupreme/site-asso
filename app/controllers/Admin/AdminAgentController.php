<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Assistant\AgentQuestions;
use App\Core\Logger;
use App\Models\AssistantQuestionPreparee;

/**
 * Page « Agent » de l'espace admin (V1+ rapidité) : préparation des
 * questions types de l'assistant à partir du JOURNAL des questions
 * réelles (logs/assistant.jsonl).
 *
 *   GET  /admin/agent              → la page (2 volets)
 *   POST /admin/agent/prepare      → capte le routage du dernier succès
 *                                    (filtrage anti-doublon + auto-
 *                                    génération du gabarit de réponse)
 *   POST /admin/agent/toggle       → active/désactive une question
 *   POST /admin/agent/deplacer     → réordonne ( Monter / Descendre )
 *   POST /admin/agent/supprimer    → supprime une question préparée
 *   POST /admin/agent/gabarit      → édite le gabarit pré-écrit
 *   POST /admin/agent/apercu       → aperçu live du gabarit (outils
 *                                    rejoués en lecture seule)
 *
 * Volet « Questions posées » : agrégat du journal (question normalisée,
 * fréquence, taux de réussite, latence moyenne, signal ⚠ injection
 * suspectée — AFFICHAGE seul, jamais bloquant : le journal contient de
 * vrais payloads d'injection que le pont a déjà neutralisés ; tout est
 * rendu échappé par la vue). Bouton « Préparer » conditionnel
 * (AgentQuestions::preparable, refiltré côté serveur).
 *
 * Volet « Questions préparées » : gestion des chips + du fast-path
 * (badges dates ⟳/📅, TTL, gabarit éditable + aperçu).
 *
 * Sécurité : même garde que la page Assistant
 * (guardSystemOrPage('inventory')) ; CSRF vérifié centralement par le
 * routeur pour tout POST non kiosque ; requêtes préparées partout ;
 * le journal n'est JAMAIS modifié ici (lecture seule, append-only
 * reste au pont/fast-path) ; ASSISTANT_TOKEN n'apparaît jamais en
 * page (uniquement en-tête curl boucle locale pour l'aperçu).
 */
final class AdminAgentController extends AdminBaseController
{
    /** Chemin du journal des sessions du pont (lecture seule). */
    private const JOURNAL = AEIC_ROOT . '/logs/assistant.jsonl';

    /** Cap de lignes lues (agrégat d'une page : le journal peut croître). */
    private const JOURNAL_LIGNES_MAX = 5000;

    /** Groupes affichés (triés par fréquence) : garde la page lisible. */
    private const GROUPES_MAX = 60;

    /** Libellés français des catégories. */
    private const CATEGORIES = [
        'quantite'    => 'Quantité restante',
        'restock'     => 'Réapprovisionnement',
        'performance' => 'Performance',
        'ventes'      => 'Ventes',
        'marge'       => 'Marge',
        'autre'       => 'Autre',
    ];

    /**
     * La page : agrégat du journal + questions préparées (décorées des
     * badges de dates et de leur TTL fast-path).
     */
    public function index(): void
    {
        $this->guardSystemOrPage('inventory');

        $groupes = $this->agreger();
        $totalLignes = array_sum(array_column($groupes, 'frequence'));

        $preparees = AssistantQuestionPreparee::toutes();
        foreach ($preparees as $i => $p) {
            $routage = AgentQuestions::routageValide((string) ($p['routage_json'] ?? ''));
            $preparees[$i]['dates_relatives'] = $routage !== null && AgentQuestions::routageADatesRelatives($routage);
            $preparees[$i]['dates_figees'] = $routage !== null && AgentQuestions::routageADatesFigees($routage);
            $preparees[$i]['ttl_jours'] = $routage !== null ? \App\Core\Assistant\FastPath::ttlJours($routage) : 0;
        }

        $this->renderAdmin('admin/agent', [
            'user'          => \App\Core\Auth::user(),
            'groupes'       => array_slice($groupes, 0, self::GROUPES_MAX),
            'nbGroupes'     => count($groupes),
            'totalLignes'   => $totalLignes,
            'journalPresent'=> is_file(self::JOURNAL),
            'preparees'     => $preparees,
            'categories'    => self::CATEGORIES,
        ]);
    }

    /**
     * « Préparer » une question du journal : reprend le routage
     * (outil + paramètres) du DERNIER succès — entrée ok=true avec au
     * moins un appel d'outil en HTTP 200 — et l'enregistre (upsert),
     * avec auto-génération du gabarit de réponse (steer V1+ n°1).
     */
    public function prepare(): void
    {
        $this->guardSystemOrPage('inventory');

        $question = trim((string) ($_POST['question'] ?? ''));
        if ($question === '' || mb_strlen($question) > 500) {
            $this->setFlash('error', 'Question invalide.');
            redirect(url('/admin/agent'));

            return;
        }

        $norm = AgentQuestions::normaliser($question);
        foreach ($this->agreger() as $groupe) {
            if ($groupe['norm'] !== $norm) {
                continue;
            }
            if ($groupe['routage'] === null) {
                $this->setFlash('error', 'Aucun succès routé au journal pour cette question (aucun appel d\'outil en HTTP 200) — repose-la d\'abord à l\'assistant.');
                redirect(url('/admin/agent'));

                return;
            }

            // Filtrage steer V1+ n°3 : jamais préparable sans condition
            // (répétée côté serveur — le bouton est déjà désactivé en vue).
            $verdict = AgentQuestions::preparable(
                $groupe['frequence'],
                $groupe['categorie_outil'],
                $groupe['injection'],
                $groupe['routage'] !== null
            );
            if (!$verdict['ok']) {
                $this->setFlash('error', 'Non préparable : ' . $verdict['motif'] . '.');
                redirect(url('/admin/agent'));

                return;
            }

            // AUTO-GÉNÉRATION du gabarit (steer V1+ n°1) : templatysation
            // de la dernière réponse ok sur le résultat d'outil qui l'a
            // nourrie ; rien de templatysable → gabarit NULL (formateur
            // générique du fast-path).
            $gabarit = null;
            $mappingJson = null;
            $premiers = (array) ($groupe['derniers_resultats'] ?? []);
            if ($groupe['derniere_reponse'] !== '' && $premiers !== []) {
                $genere = \App\Core\Assistant\Gabarits::generer($groupe['derniere_reponse'], $premiers[0]);
                if ($genere !== null) {
                    $gabarit = $genere['gabarit'];
                    $mappingJson = (string) json_encode($genere['mapping'], JSON_UNESCAPED_UNICODE);
                }
            }

            try {
                AssistantQuestionPreparee::upsertDepuisJournal(
                    $norm,
                    mb_substr($question, 0, 500),
                    (string) json_encode($groupe['routage'], JSON_UNESCAPED_UNICODE),
                    $gabarit,
                    $mappingJson
                );
            } catch (\Throwable $e) {
                Logger::error('agent: préparation impossible', ['question' => $norm, 'msg' => $e->getMessage()]);
                $this->setFlash('error', 'Préparation impossible (détail journalisé).');
                redirect(url('/admin/agent'));

                return;
            }

            $this->audit('agent.prepare', 'assistant_question', $norm, ['appels' => count($groupe['routage'])]);
            $this->setFlash('success', 'Question préparée : réponse instantanée par gabarit pré-écrit rempli sur les données live.');
            redirect(url('/admin/agent'));

            return;
        }

        $this->setFlash('error', 'Question introuvable dans le journal.');
        redirect(url('/admin/agent'));
    }

    /** Active/désactive une question préparée (chips + fast-path). */
    public function toggle(): void
    {
        $this->guardSystemOrPage('inventory');

        $id = (int) ($_POST['id'] ?? 0);
        $actif = trim((string) ($_POST['actif'] ?? '')) === '1';
        try {
            $ok = AssistantQuestionPreparee::definirActive($id, $actif);
        } catch (\Throwable $e) {
            Logger::error('agent: (dés)activation impossible', ['id' => $id, 'msg' => $e->getMessage()]);
            $this->setFlash('error', 'Modification impossible (détail journalisé).');
            redirect(url('/admin/agent'));

            return;
        }

        if (!$ok) {
            $this->setFlash('error', 'Question introuvable.');
        } else {
            $this->audit('agent.toggle', 'assistant_question', (string) $id, ['active' => $actif]);
            $this->setFlash('success', $actif ? 'Question activée.' : 'Question désactivée (le fast-path ne s\'applique plus).');
        }
        redirect(url('/admin/agent'));
    }

    /** Déplace une question préparée d'un cran (sens -1/+1). */
    public function deplacer(): void
    {
        $this->guardSystemOrPage('inventory');

        $id = (int) ($_POST['id'] ?? 0);
        $sens = (int) ($_POST['sens'] ?? 0);
        try {
            AssistantQuestionPreparee::deplacer($id, $sens);
        } catch (\Throwable $e) {
            Logger::error('agent: réordonnancement impossible', ['id' => $id, 'sens' => $sens, 'msg' => $e->getMessage()]);
            $this->setFlash('error', 'Réordonnancement impossible (détail journalisé).');
        }
        redirect(url('/admin/agent'));
    }

    /** Supprime une question préparée. */
    public function supprimer(): void
    {
        $this->guardSystemOrPage('inventory');

        $id = (int) ($_POST['id'] ?? 0);
        try {
            $ok = AssistantQuestionPreparee::supprimer($id);
        } catch (\Throwable $e) {
            Logger::error('agent: suppression impossible', ['id' => $id, 'msg' => $e->getMessage()]);
            $this->setFlash('error', 'Suppression impossible (détail journalisé).');
            redirect(url('/admin/agent'));

            return;
        }

        if ($ok) {
            $this->audit('agent.supprimer', 'assistant_question', (string) $id);
            $this->setFlash('success', 'Question supprimée.');
        } else {
            $this->setFlash('error', 'Question introuvable.');
        }
        redirect(url('/admin/agent'));
    }

    /** Édite le gabarit d'une question préparée (placeholders ⊆ mapping). */
    public function gabarit(): void
    {
        $this->guardSystemOrPage('inventory');

        $id = (int) ($_POST['id'] ?? 0);
        $gabarit = trim((string) ($_POST['gabarit'] ?? ''));
        $ligne = $this->trouverPreparee($id);
        if ($ligne === null) {
            $this->setFlash('error', 'Question préparée introuvable.');
            redirect(url('/admin/agent'));

            return;
        }

        if ($gabarit !== '') {
            $mapping = json_decode((string) ($ligne['mapping_json'] ?? ''), true);
            $connus = array_keys(is_array($mapping) ? $mapping : []);
            // Détection ÉLARGIE à tout token {…} (pas seulement [a-z0-9_]) :
            // une forme exotique ({Stock}, {a b}…) n'est jamais couverte
            // exactement par le mapping → REFUS (durcissement dev-secu).
            preg_match_all('/\{([^}]+)\}/', $gabarit, $m);
            $inconnus = array_values(array_diff($m[1] ?? [], $connus));
            if ($inconnus !== [] || $connus === []) {
                $this->setFlash('error', 'Gabarit refusé : placeholders sans mapping (' . implode(', ', $inconnus) . ') — utilise uniquement {' . implode('} {', $connus) . '}.');
                redirect(url('/admin/agent'));

                return;
            }
        }

        try {
            AssistantQuestionPreparee::majGabarit($id, $gabarit);
        } catch (\Throwable $e) {
            Logger::error('agent: enregistrement du gabarit impossible', ['id' => $id, 'msg' => $e->getMessage()]);
            $this->setFlash('error', 'Enregistrement impossible (détail journalisé).');
            redirect(url('/admin/agent'));

            return;
        }

        $this->audit('agent.gabarit', 'assistant_question', (string) $id);
        $this->setFlash('success', 'Gabarit enregistré.' . ($gabarit === '' ? ' (vide : le formateur générique du fast-path s' . chr(39) . 'applique)' : ' Utilise « Aperçu » pour le voir rempli sur données live.'));
        redirect(url('/admin/agent'));
    }

    /**
     * APERÇU live du gabarit (steer V1+ n°1) : rejoue les outils EN
     * LECTURE SEULE et remplit le gabarit — SANS activer/désactiver quoi
     * que ce soit ni journaliser. Échec (outil down, placeholder non
     * résolu) → message d'erreur honnête.
     */
    public function apercu(): void
    {
        $this->guardSystemOrPage('inventory');

        $id = (int) ($_POST['id'] ?? 0);
        $ligne = $this->trouverPreparee($id);
        if ($ligne === null) {
            $this->setFlash('error', 'Question préparée introuvable.');
            redirect(url('/admin/agent'));

            return;
        }

        $routage = AgentQuestions::routageValide((string) ($ligne['routage_json'] ?? ''));
        if ($routage === null) {
            $this->setFlash('error', 'Routage invalide : impossible de faire l\'aperçu.');
            redirect(url('/admin/agent'));

            return;
        }

        $jeton = env('ASSISTANT_TOKEN', '');
        $gabarit = trim((string) ($ligne['gabarit_reponse'] ?? ''));
        $mapping = json_decode((string) ($ligne['mapping_json'] ?? ''), true);
        $rendu = $jeton === '' ? null : \App\Core\Assistant\FastPath::rendre(
            $routage,
            $jeton,
            $gabarit !== '' ? $gabarit : null,
            is_array($mapping) && $mapping !== [] ? $mapping : null
        );

        if ($rendu === null) {
            $this->setFlash('error', 'Aperçu impossible : outil en échec, jeton absent ou placeholder non résolu (le fast-path replierait sur la voie LLM).');
        } else {
            $this->setFlash('success', 'Aperçu (données live) : ' . $rendu['reponse'] . ' — Sources : ' . implode(' ; ', $rendu['sources']));
        }
        redirect(url('/admin/agent'));
    }

    // -----------------------------------------------------------------
    //  Agrégat du journal (lecture seule)
    // -----------------------------------------------------------------

    /**
     * Regroupe le journal par question normalisée : fréquence, nombre de
     * réussites, latence moyenne (entrées chronophages), variantes
     * d'affichage (≤ 3), signal d'injection suspectée, routage du
     * DERNIER succès (appels en HTTP 200 uniquement, ≤ 4), réponse et
     * résultats décodés de ce dernier succès (matière première du
     * « Préparer » et de l'auto-génération du gabarit), catégorie dérivée
     * et verdict de préparation (steer V1+ n°3).
     *
     * @return list<array{norm:string,affichee:string,frequence:int,nb_ok:int,latence_ms:int,injection:bool,exemples:list<string>,routage:?list<array{outil:string,params:array<string,mixed>}},categorie_outil:string,derniere_reponse:string,derniers_resultats:list<array<string,mixed>>,preparable:array{ok:bool,motif:string}}>}
     */
    private function agreger(): array
    {
        $groupes = [];
        foreach ($this->lireJournal() as $entree) {
            $question = (string) ($entree['question'] ?? '');
            if ($question === '') {
                continue;
            }
            $norm = AgentQuestions::normaliser($question);
            if ($norm === '') {
                continue;
            }

            $g = $groupes[$norm] ?? null;
            if ($g === null) {
                $g = [
                    'norm'      => $norm,
                    'affichee'  => $question,
                    'frequence' => 0,
                    'nb_ok'     => 0,
                    'duree_tot' => 0,
                    'nb_duree'  => 0,
                    'injection' => false,
                    'exemples'  => [],
                    'routage'   => null,
                    'derniere_reponse'  => '',
                    'derniers_resultats' => [],
                ];
                $groupes[$norm] = $g;
            }

            $groupes[$norm]['frequence']++;
            $ok = ($entree['ok'] ?? false) === true;
            if ($ok) {
                $groupes[$norm]['nb_ok']++;
            }
            $duree = (int) ($entree['duree_ms'] ?? 0);
            if ($duree > 0) {
                $groupes[$norm]['duree_tot'] += $duree;
                $groupes[$norm]['nb_duree']++;
            }
            if (!in_array($question, $groupes[$norm]['exemples'], true) && count($groupes[$norm]['exemples']) < 3) {
                $groupes[$norm]['exemples'][] = $question;
            }
            if (!$groupes[$norm]['injection'] && AgentQuestions::suspecteInjection($question)) {
                $groupes[$norm]['injection'] = true;
            }

            // Routage du DERNIER succès : chaque entrée valide écrase la précédente.
            if ($ok) {
                $routage = self::routageDeSucces($entree);
                if ($routage !== null) {
                    $groupes[$norm]['routage'] = $routage;
                    $groupes[$norm]['derniere_reponse'] = (string) ($entree['reponse'] ?? '');
                    $groupes[$norm]['derniers_resultats'] = self::resultatsDecodes($entree);
                }
            }
        }

        $sorties = [];
        foreach ($groupes as $norm => $g) {
            $sorties[] = [
                'norm'      => $norm,
                'affichee'  => $g['affichee'],
                'frequence' => $g['frequence'],
                'nb_ok'     => $g['nb_ok'],
                'latence_ms'=> $g['nb_duree'] > 0 ? (int) round($g['duree_tot'] / $g['nb_duree']) : 0,
                'injection' => $g['injection'],
                'exemples'  => $g['exemples'],
                'routage'   => $g['routage'],
                'categorie_outil'   => $g['routage'] !== null ? AgentQuestions::categorieRoutage($g['routage']) : 'autre',
                'derniere_reponse'  => $g['derniere_reponse'],
                'derniers_resultats' => $g['derniers_resultats'],
                'preparable'        => AgentQuestions::preparable(
                    $g['frequence'],
                    $g['routage'] !== null ? AgentQuestions::categorieRoutage($g['routage']) : 'autre',
                    $g['injection'],
                    $g['routage'] !== null
                ),
            ];
        }
        usort($sorties, static function (array $a, array $b): int {
            return $b['frequence'] <=> $a['frequence'] ?: strcmp($a['norm'], $b['norm']);
        });

        return $sorties;
    }

    /**
     * Lit le journal (JSONL), en tolérant les lignes invalides ; borné
     * aux JOURNAL_LIGNES_MAX dernières lignes.
     *
     * @return list<array<string,mixed>>
     */
    private function lireJournal(): array
    {
        if (!is_file(self::JOURNAL)) {
            return [];
        }
        $lignes = file(self::JOURNAL, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lignes)) {
            return [];
        }
        if (count($lignes) > self::JOURNAL_LIGNES_MAX) {
            $lignes = array_slice($lignes, -self::JOURNAL_LIGNES_MAX);
        }

        $entrees = [];
        foreach ($lignes as $ligne) {
            $dec = json_decode((string) $ligne, true);
            if (is_array($dec)) {
                $entrees[] = $dec;
            }
        }

        return $entrees;
    }

    /**
     * Routage propre d'une entrée réussie : les appels d'outils en
     * HTTP 200 uniquement ({outil, params←arguments}), ≤ 4, revalidés
     * par AgentQuestions::routageValide (whitelist + params scalaires
     * ou expressions relatives).
     *
     * @param array<string,mixed> $entree
     *
     * @return list<array{outil:string,params:array<string,mixed>}}>|null
     */
    private static function routageDeSucces(array $entree): ?array
    {
        $appels = $entree['appels_outils'] ?? null;
        if (!is_array($appels) || $appels === []) {
            return null;
        }

        $routage = [];
        foreach ($appels as $appel) {
            if (!is_array($appel) || (int) ($appel['statut_http'] ?? 0) !== 200) {
                continue;
            }
            $params = [];
            foreach (is_array($appel['arguments'] ?? null) ? $appel['arguments'] : [] as $cle => $valeur) {
                if (is_string($cle) && (is_string($valeur) || is_numeric($valeur) || is_bool($valeur))) {
                    $params[$cle] = (string) $valeur;
                }
            }
            $routage[] = ['outil' => (string) ($appel['outil'] ?? ''), 'params' => $params];
        }
        if ($routage === []) {
            return null;
        }

        return AgentQuestions::routageValide((string) json_encode($routage, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Résultats d'outil (JSON décodé) des appels en HTTP 200 d'une entrée
     * du journal — matière première de l'auto-génération du gabarit.
     *
     * @param array<string,mixed> $entree
     *
     * @return list<array<string,mixed>>
     */
    private static function resultatsDecodes(array $entree): array
    {
        $resultats = [];
        foreach (is_array($entree['appels_outils'] ?? null) ? $entree['appels_outils'] : [] as $appel) {
            if (!is_array($appel) || (int) ($appel['statut_http'] ?? 0) !== 200) {
                continue;
            }
            $dec = json_decode((string) ($appel['resultat'] ?? ''), true);
            if (is_array($dec)) {
                $resultats[] = $dec;
            }
        }

        return $resultats;
    }

    /** @return array<string,mixed>|null */
    private function trouverPreparee(int $id): ?array
    {
        foreach (AssistantQuestionPreparee::toutes() as $p) {
            if ((int) ($p['id'] ?? 0) === $id) {
                return $p;
            }
        }

        return null;
    }
}
