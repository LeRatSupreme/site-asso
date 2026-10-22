<?php

declare(strict_types=1);

/**
 * AEIC — Dump comptable répondant aux 2 questions du dashboard (CLI, LECTURE SEULE).
 *
 * Objet : imprimer, sur une fenêtre de jours, les chiffres qui répondent à :
 *   Q1 — « le résultat net compte-t-il les produits deux fois ? »
 *        (profit vs dépenses vs achats de stock) ;
 *   Q2 — « sur quoi portent les frais SumUp ? » (base CARTE seulement,
 *        pas le CA total : 35,21 € vs 32,05 €).
 *
 * Usage :
 *   php scripts/compta_dump.php                     # 30 derniers jours, texte
 *   php scripts/compta_dump.php --json              # même contenu en JSON
 *   php scripts/compta_dump.php --jours=14          # fenêtre de N jours (défaut 30)
 *   php scripts/compta_dump.php --from=2026-09-01 --to=2026-09-30  # fenêtre custom
 *
 * Garanties LECTURE SEULE :
 *   - AUCUN INSERT / UPDATE / DELETE n'est émis : uniquement des SELECT
 *     via les modèles existants (Sale, Expense, Purchase, Setting) et
 *     les utilitaires purs (CardFees) ;
 *   - aucune écriture de settings, aucune importation, aucun appel
 *     aux contrôleurs ;
 *   - sans base joignable, le script échoue proprement (« base
 *     indisponible ») au lieu d'imprimer des zéros trompeurs.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Compta\CardFees;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;

// ── Options CLI ──────────────────────────────────────────────────────────────
$opts = getopt('', ['json', 'jours::', 'from::', 'to::']);
$asJson = array_key_exists('json', $opts);

$eur = static fn (float $v): string => number_format($v, 2, ',', '');
$pct = static fn (float $v, int $dec = 1): string => number_format($v, $dec, ',', '');

try {
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));

    // Sonde de connexion (simple PDO::__construct, AUCUNE requête).
    db();

    // ── Fenêtre analysée ─────────────────────────────────────────────────────
    $to = isset($opts['to']) ? (string) $opts['to'] : $now->format('Y-m-d');
    $toDay = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if ($toDay === false) {
        fwrite(STDERR, "ERREUR : --to doit être une date « YYYY-MM-DD ».\n");
        exit(1);
    }
    $jours = max(1, (int) ($opts['jours'] ?? 30));
    if (isset($opts['from'])) {
        $fromDay = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $opts['from']);
        if ($fromDay === false) {
            fwrite(STDERR, "ERREUR : --from doit être une date « YYYY-MM-DD ».\n");
            exit(1);
        }
    } else {
        $fromDay = $toDay->modify('-' . ($jours - 1) . ' days');
    }
    if ($fromDay > $toDay) {
        fwrite(STDERR, "ERREUR : --from est postérieur à --to.\n");
        exit(1);
    }
    $from = $fromDay->format('Y-m-d');
    $toFinal = $toDay->format('Y-m-d');
    $nbJours = (int) $fromDay->diff($toDay)->days + 1;

    // ── 2. CA : total + répartition CARTE / LIQUIDE ──────────────────────────
    $agg = Sale::aggregatesBetween($from, $toFinal);
    $split = Sale::paymentSplitBetween($from, $toFinal);
    $ca = (float) $agg['ca'];
    $caCarte = (float) ($split['CARTE'] ?? 0.0);
    $caLiquide = (float) ($split['LIQUIDE'] ?? 0.0);
    $caAutre = 0.0;
    foreach ($split as $moyen => $montant) {
        if ($moyen !== 'CARTE' && $moyen !== 'LIQUIDE') {
            $caAutre += (float) $montant;
        }
    }

    // ── 3. FRAIS SUMUP : estimation + rétro-calcul ───────────────────────────
    $tauxRaw = Setting::get('sumup_fee_rate', (string) CardFees::DEFAULT_RATE);
    $taux = CardFees::rate(); // % , borné [0 ; 100], défaut 1,75
    $frais = CardFees::estimatedTotal($from, $toFinal);
    $tauxEffectif = $caCarte > 0 ? $frais / $caCarte * 100.0 : 0.0;
    $frais17Total = $ca * 1.75 / 100.0; // « 1,75 % × CA total » (question récurrente)

    // ── 4. MARGE ─────────────────────────────────────────────────────────────
    $profit = (float) $agg['profit'];
    $caProduits = (float) $agg['ca_products'];
    $margePct = $caProduits > 0 ? $profit / $caProduits * 100.0 : 0.0;

    // ── 5. DÉPENSES : total + par catégorie ──────────────────────────────────
    $dep = Expense::aggregatesBetween($from, $toFinal);
    $depTtc = (float) $dep['ttc'];
    $depCount = (int) $dep['count'];
    $parCat = [];
    foreach (Expense::CATEGORIES as $cat) {
        $parCat[$cat] = 0.0;
    }
    foreach (Expense::byCategoryBetween($from, $toFinal) as $row) {
        $cat = (string) $row['category'];
        $parCat[$cat] = ($parCat[$cat] ?? 0.0) + (float) $row['ttc'];
    }

    // ── 6. ACHATS DE STOCK ───────────────────────────────────────────────────
    $achats = Purchase::statsBetween($from, $toFinal);
    $achatsTtc = (float) $achats['ttc'];

    // ── 7. RÉSULTAT NET + contre-vérifications ───────────────────────────────
    $resultatNet = $profit - $depTtc;
    $verifB = ($ca - $achatsTtc) - $depTtc;
    $matiere = $parCat['MATIERE'] ?? 0.0;

    // ── 8. TVA collectée par taux ────────────────────────────────────────────
    $tvaRows = Sale::vatByRateBetween($from, $toFinal);

    // ── Sortie ───────────────────────────────────────────────────────────────
    if ($asJson) {
        echo json_encode([
            'genere_le'      => $now->format('Y-m-d H:i') . ' Paris',
            'fenetre'        => ['from' => $from, 'to' => $toFinal, 'jours' => $nbJours],
            'ca'             => ['total' => $ca, 'carte' => $caCarte, 'liquide' => $caLiquide, 'autre' => $caAutre],
            'frais_sumup'    => [
                'taux_configure_pct' => $taux,
                'taux_brut_setting'  => $tauxRaw,
                'base_carte_ttc'     => $caCarte,
                'frais_estimes'      => $frais,
                'taux_effectif_pct'  => round($tauxEffectif, 4),
                'un_pct_75_du_ca_total' => round($frais17Total, 2),
                'explication'        => 'Les frais SumUp sont estimés sur les seules ventes CARTE de la période (taux configurable sumup_fee_rate, arrondi à la ligne) ; ils n\'entrent pas dans le résultat net.',
            ],
            'marge'          => ['profit' => $profit, 'ca_produits' => $caProduits, 'pct' => round($margePct, 1)],
            'depenses'       => ['total_ttc' => $depTtc, 'lignes' => $depCount, 'par_categorie' => $parCat],
            'achats_stock'   => ['total_ttc' => $achatsTtc, 'lignes' => (int) $achats['lines'], 'quantite' => (int) $achats['qty']],
            'resultat_net'   => [
                'profit_moins_depenses' => round($resultatNet, 2),
                'ca_moins_achats_moins_depenses' => round($verifB, 2),
                'explication' => 'Les achats de stock ne sont PAS dans les dépenses : le coût des produits est déjà déduit de la marge via product_costs. Le résultat net ne compte donc pas les produits deux fois — SAUF si une dépense est saisie en catégorie MATIERE pour une course déjà comptée dans les achats : MATIERE = ' . $eur($matiere) . ' € sur la période (risque de double comptage = ' . $eur($matiere) . ' € si elle double un achat).',
            ],
            'tva_collectee'  => array_map(static fn (array $r): array => [
                'taux' => $r['rate'],
                'ht'   => (float) $r['ht'],
                'vat'  => (float) $r['vat'],
                'ttc'  => (float) $r['ttc'],
            ], $tvaRows),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    }

    echo '=== Dump comptable — AEIC (généré le ' . $now->format('Y-m-d H:i') . ' Paris · LECTURE SEULE) ===', "\n";
    echo 'Fenêtre : ' . $from . ' → ' . $toFinal . ' (' . $nbJours . " jours)\n\n";

    echo "-- 2. CA --\n";
    echo 'total : ' . $eur($ca) . ' € · CARTE : ' . $eur($caCarte) . ' € · LIQUIDE : ' . $eur($caLiquide) . ' €'
        . ($caAutre > 0.0 ? ' · autre : ' . $eur($caAutre) . ' €' : '') . "\n\n";

    echo "-- 3. FRAIS SUMUP (estimation) --\n";
    echo 'taux configuré (sumup_fee_rate = « ' . $tauxRaw . ' ») : ' . $pct($taux, 2) . ' %'
        . ' · base = ventes CARTE TTC : ' . $eur($caCarte) . " €\n";
    echo 'frais estimés sur la période : ' . $eur($frais) . " €\n";
    echo 'rétro-calcul : taux_effectif = frais / base × 100 = ' . $pct($tauxEffectif, 4) . " %\n";
    echo 'pour comparaison : 1,75 % × CA total (' . $eur($ca) . ' €) = ' . $eur($frais17Total) . " €\n";
    echo "Les frais SumUp sont estimés sur les seules ventes CARTE de la période (taux configurable sumup_fee_rate, arrondi à la ligne) ; ils n'entrent pas dans le résultat net.\n\n";

    echo "-- 4. MARGE --\n";
    echo 'profit : ' . $eur($profit) . ' € · CA produits : ' . $eur($caProduits) . ' € · marge : ' . $pct($margePct, 1) . " %\n\n";

    echo "-- 5. DÉPENSES (TTC, table expenses) --\n";
    echo 'total : ' . $eur($depTtc) . ' € (' . $depCount . " ligne(s))\n";
    $cats = [];
    foreach (Expense::CATEGORIES as $cat) {
        $cats[] = $cat . ' ' . $eur($parCat[$cat]) . ' €';
    }
    echo 'par catégorie : ' . implode(' · ', $cats) . "\n\n";

    echo "-- 6. ACHATS DE STOCK (table purchases) --\n";
    echo 'total TTC : ' . $eur($achatsTtc) . ' € (' . (int) $achats['lines'] . ' ligne(s), quantité ' . (int) $achats['qty'] . ")\n\n";

    echo "-- 7. RÉSULTAT NET --\n";
    echo '(a) profit − dépenses = ' . $eur($profit) . ' − ' . $eur($depTtc) . ' = ' . $eur($resultatNet) . " €\n";
    echo '(b) (CA − achats) − dépenses = (' . $eur($ca) . ' − ' . $eur($achatsTtc) . ') − ' . $eur($depTtc) . ' = ' . $eur($verifB) . " €\n";
    echo '(c) Les achats de stock ne sont PAS dans les dépenses : le coût des produits est déjà déduit de la marge via product_costs. Le résultat net ne compte donc pas les produits deux fois — SAUF si une dépense est saisie en catégorie MATIERE pour une course déjà comptée dans les achats : MATIERE = ' . $eur($matiere) . ' € sur la période (risque de double comptage = ' . $eur($matiere) . " € si elle double un achat).\n\n";

    echo "-- 8. TVA collectée (par taux, y compris 0 % — masquage géré par la page) --\n";
    if ($tvaRows === []) {
        echo "— aucune vente sur la période.\n";
    }
    foreach ($tvaRows as $row) {
        echo 'taux ' . (string) $row['rate'] . ' % : HT ' . $eur((float) $row['ht']) . ' € · TVA ' . $eur((float) $row['vat']) . ' € · TTC ' . $eur((float) $row['ttc']) . " €\n";
    }

    exit(0);
} catch (PDOException $e) {
    fwrite(STDERR, 'ERREUR : base indisponible (config.env ou MySQL : ' . $e->getMessage() . ")\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERREUR : dump comptable impossible — ' . $e->getMessage() . "\n");
    exit(1);
}
