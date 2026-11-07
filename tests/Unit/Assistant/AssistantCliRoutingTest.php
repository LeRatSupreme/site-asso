<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use PHPUnit\Framework\TestCase;

/**
 * ROUTAGE CLI du pont scripts/assistant.py (review 2e vague, réserve
 * MINEURE n°2) : épinglage DÉTERMINISTE des gabarits 0-tour-LLM via le
 * mode --dry-run du pont — aucun tour LLM, aucun appel d'outil, aucun
 * jeton, aucun réseau par construction (le dry-run n'exécute QUE la
 * détection regex des chemins : fast_path_stock > gabarit_benefice_
 * global > rachats > anomalies catalogue > EDT > prix > voie LLM).
 *
 * Cible : « bénéfice par catégorie ? ». Avant la 2e vague, « categorie »
 * n'était PAS dans MOTS_NON_PRODUITS côté CLI : le mot était traité
 * comme un nom de produit → marge_produit introuvable → le modèle
 * demandait de préciser un produit. Depuis : « benefice » ∈
 * MOTS_BENEFICE, « categorie » ∈ MOTS_NON_PRODUITS, « par » ∈
 * MOTS_FLOUS_BENEFICE → gabarit bénéfice GLOBAL honnête, 0 tour LLM.
 * Le même routage est épinglé côté portage PHP par PublicGabaritsTest
 * (invariant MOTS_NON_PRODUITS ⊇ CLI + gabarits publics).
 *
 * Pont PHPUnit → interpréteur externe, même convention que
 * PurchaseGridJsTest/InventaireTabletJsTest (Node) : exec + skip propre
 * si Python est indisponible. assistant.py n'utilise que la stdlib —
 * le venv du runbook s'il est présent, sinon le python3 du système.
 * Suite Unit (pas Integration) : le routage dry-run ne touche NI la
 * base, NI le HTTP — aucune dépendance de test.
 */
final class AssistantCliRoutingTest extends TestCase
{
    /**
     * « bénéfice par catégorie ? » (et sa formulation naturelle
     * « c'est quoi le… ») → gabarit bénéfice GLOBAL, et le fast-path
     * stock ne capte PAS la question (detecter_question_stock → None :
     * « categorie » ∈ MOTS_NON_PRODUITS côté CLI). Verrouille la
     * priorité d'un routage qui doit rester instantané, sans LLM.
     */
    public function test_benefice_par_categorie_route_au_gabarit_benefice_global(): void
    {
        foreach (['bénéfice par catégorie ?', 'c\'est quoi le bénéfice par catégorie ?'] as $question) {
            $r = $this->dryRun($question);

            self::assertSame($question, $r['question'] ?? null, 'la question évaluée est celle posée.');
            self::assertSame(
                'gabarit_benefice_global',
                $r['chemin'] ?? null,
                "{$question} → gabarit bénéfice global (0 tour LLM), pas la voie LLM/marge_produit."
            );
            // Piège « ?? null » : un null JSON décodé est indiscernable
            // d'une clé absente via ?? — assertArrayHasKey + assertNull
            // (même convention que les tests réappro EDT).
            self::assertArrayHasKey('fast_path_produit', $r);
            self::assertNull(
                $r['fast_path_produit'],
                "{$question} : detecter_question_stock → None (aucun produit extrait)."
            );
        }
    }

    /**
     * Garde de calibration (docstring de question_benefice_global) : le
     * gabarit global ne doit capter NI la marge d'un PRODUIT cité
     * (« marge des Monster Blanche ? » → voie LLM normale, aujourd'hui
     * llm_outils), NI les questions de stock pures — le fast-path stock
     * reste prioritaire et son extraction produit intacte (« Bueno »
     * n'est pas filtré par MOTS_NON_PRODUITS : Kinder Bueno/coca
     * restent fastpath par construction, cf. AdminAgentFastPathTest).
     */
    public function test_le_gabarit_global_ne_capte_pas_marge_produit_ni_le_fast_path_stock(): void
    {
        $marge = $this->dryRun('marge des Monster Blanche ?');
        self::assertNotSame('gabarit_benefice_global', $marge['chemin'] ?? null, 'produit cité : jamais le gabarit global.');
        self::assertSame('llm_outils', $marge['chemin'] ?? null, 'marge_produit : voie LLM normale, outils au choix du modèle.');

        $stock = $this->dryRun('il me reste combien de Bueno ?');
        self::assertSame('fast_path_stock', $stock['chemin'] ?? null, 'le fast-path stock reste DEVANT le gabarit bénéfice.');
        self::assertSame('Bueno', $stock['fast_path_produit'] ?? null, 'produit extrait intact : la garde MOTS_NON_PRODUITS ne désamorce pas le fast-path.');
    }

    /**
     * Exécute le pont en --dry-run et décode sa sortie JSON.
     *
     * @return array<string,mixed> tableau décodé (dry_run, question, chemin, fast_path_produit, …)
     */
    private function dryRun(string $question): array
    {
        $python = self::pythonBinary();
        if ($python === null) {
            self::markTestSkipped('Python indisponible : routage CLI du pont assistant.py non testable ici.');
        }

        $lignes = [];
        $code = 1;
        exec(
            escapeshellcmd($python) . ' ' . escapeshellarg(AEIC_ROOT . '/scripts/assistant.py')
                . ' ' . escapeshellarg($question) . ' --dry-run 2>&1',
            $lignes,
            $code
        );
        $sortie = implode("\n", $lignes);
        self::assertSame(0, $code, "le pont doit s'exécuter en dry-run (code {$code}) :\n{$sortie}");

        $r = json_decode($sortie, true);
        self::assertIsArray($r, 'dry-run imprime du JSON en sortie (extrait : ' . mb_substr($sortie, 0, 300) . ').');
        self::assertTrue((bool) ($r['dry_run'] ?? false), 'la sortie doit être celle du mode dry-run (aucun LLM consommé).');

        return $r;
    }

    /** Interpréteur Python du pont (stdlib uniquement) : venv du runbook
     *  s'il est présent, sinon python3/python du système, sinon null. */
    private static function pythonBinary(): ?string
    {
        $venv = AEIC_ROOT . '/.venv-assistant/bin/python3';
        if (is_executable($venv)) {
            return $venv;
        }

        $devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        foreach (['python3', 'python'] as $bin) {
            $out = [];
            $code = 1;
            exec(escapeshellcmd($bin) . ' -V 2>' . $devNull, $out, $code);
            if ($code === 0) {
                return $bin;
            }
        }

        return null;
    }
}
