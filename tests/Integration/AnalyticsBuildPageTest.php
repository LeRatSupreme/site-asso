<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Admin\AdminAnalyticsController;
use App\Models\Model;
use ReflectionMethod;

/**
 * Filet anti-500 du dashboard Analytics : la page complète (contrôleur +
 * moteur de prévision + vues admin et kiosque) doit se construire sans
 * exception sur un jeu de ventes réaliste, et le contrat du payload doit
 * être respecté.
 *
 * C'est LE test qui aurait attrapé le TypeError fatal en production
 * (comparateur usort typé bool dans AnalyticsForecastData::productStats(),
 * invisible localement sans MySQL) : toute régression du pipeline
 * buildPage — extraction, moteur, vues — fait échouer ce test en CI.
 *
 * Contrats vérifiés :
 *  - clés de buildPage présentes (forecast, day_track, weekday, hourly…) ;
 *  - forecast.semaine : objectif > 0 et prob_atteinte FRACTION dans [0, 1]
 *    (les vues font le ×100 — régression du bug « 0 % ») ;
 *  - forecast.jours : 7 entrées, ca ≥ 0 ;
 *  - produits / produits_reappro cohérents (flux courant : Événement et
 *    ligne 0 € exclus) ;
 *  - n_jours ≥ 10 → confiance ≠ « insuffisant » ;
 *  - la probabilité AFFICHÉE dans la carte Objectif = prob × 100 ;
 *  - LOT 4 : agrégats d'affichage calculés côté contrôleur (totaux
 *    tendance produit, parts catégorie/paiement, totaux tableau produit,
 *    méta heatmap, prob en %, bande fourchette jour de semaine) et
 *    cohérence de ces valeurs avec les séries du payload.
 *
 * AJUSTEMENTS LOT 4 (extraction JS/CSS imposée par la mission — la page
 * ne contient plus le JS inline) :
 *  - le payload JSON est extrait de l'îlot <script type="application/json"
 *    id="analytics-data"> / id="analytics-kiosk-data"> (l'ancien inline
 *    « var data = {…}; » a disparu de la page) ;
 *  - les étiquettes de séries Chart.js (« Observé », « Modèle (prochaine
 *    occurrence) ») vivent dans public/assets/js/analytics.js et
 *    analytics-kiosk.js : les assertions correspondantes portent sur le
 *    CONTENU DE CES FICHIERS et non sur le HTML rendu.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class AnalyticsBuildPageTest extends IntegrationTestCase
{
    private string $rootId = 'u_analytics_root';

    private const KIOSK_TOKEN = 'jeton-analytics-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset([
            'sale_adjustments',
            'sales',
            'import_batches',
            'expenses',
            'cash_movements',
            'cash_counts',
            'inventory_counts',
            'product_costs',
            'product_discontinued',
            'product_zero_since',
            'product_infinite',
            'product_packs',
            'purchases',
            'losses',
            'product_aliases',
            'product_stocks',
            'users',
            'settings',
        ]);
        $this->seedUser($this->rootId, 'analytics-root@exemple.fr', 'Password123456', 'SUPERADMIN');
        $this->seedVentesRealistes();
    }

    /**
     * Fenêtre d'apprentissage remplie : 14 jours consécutifs finissant
     * aujourd'hui (tous « ouverts », ~140 €/jour → semaine attendue très
     * au-dessus de l'objectif par défaut de 500 €), 3 produits du flux
     * courant (Boisson/Nourriture), un coût fiable sur 2 produits, une
     * vente Événement exclue et une ligne à 0 € (audit).
     */
    private function seedVentesRealistes(): void
    {
        $ins = $this->pdo->prepare(
            'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, product_key, category, price_ttc, is_custom_amount)
             VALUES (?,?,?,?,?,?,?,?,?,?,0)'
        );
        $id = 0;
        for ($d = 13; $d >= 0; $d--) {
            $day = date('Y-m-d', time() - $d * 86400);
            $ins->execute(['a_p' . (++$id), 'TRX' . $id . 'A', $day . ' 10:15:00', 'CARTE', 'Visa - Débit', 20, 'Cola', 'cola', 'Boisson', '50.00']);
            $ins->execute(['a_p' . (++$id), 'TRX' . $id . 'B', $day . ' 12:30:00', 'CARTE', 'Visa - Débit', 10, 'Jus de fruits', 'jus', 'Boisson', '30.00']);
            $ins->execute(['a_p' . (++$id), 'TRX' . $id . 'C', $day . ' 14:00:00', 'LIQUIDE', 'Espèces', 15, 'Sandwich', 'sandwich', 'Nourriture', '60.00']);
        }
        // Exclusions du flux courant : catégorie Événement et ligne à 0 €.
        $hier = date('Y-m-d', time() - 86400);
        $ins->execute(['a_ev1', 'TEV1', $hier . ' 20:00:00', 'CARTE', 'Visa - Débit', 1, 'Entrée soirée', 'soiree', 'Événement', '80.00']);
        $ins->execute(['a_z1', 'TZ1', $hier . ' 20:05:00', 'CARTE', 'Visa - Débit', 1, 'Test gratuit', 'test', null, '0.00']);

        // Coûts fiables : cola (1 €) et sandwich (2 €) ; « jus » sans coût.
        $cout = $this->pdo->prepare('INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)');
        $cout->execute(['pc_an_1', 'cola', '1.000', '2026-01-01']);
        $cout->execute(['pc_an_2', 'sandwich', '2.000', '2026-01-01']);
    }

    public function test_page_analytics_se_construit_sans_erreur_avec_contrat_payload(): void
    {
        $r = $this->request('GET', '/admin/analytics', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Analytics doit répondre 200 (pas de 500).');
        $body = (string) ($r['body'] ?? '');

        // La vue est rendue : carte Objectif présente.
        self::assertStringContainsString("Probabilité d'atteindre l'objectif hebdo", $body, 'La carte Objectif est absente.');

        // ---- LOT 4 : onglets de l'espace Ventes (partiel partagé) ----
        self::assertStringContainsString('class="tabs-ventes"', $body, 'La nav « Espace ventes » est absente.');
        self::assertStringContainsString('aria-label="Espace ventes"', $body, 'La nav « Espace ventes » doit être labellisée.');
        self::assertMatchesRegularExpression(
            '#/admin/analytics" class="is-active" aria-current="page">Analyses<#',
            $body,
            'L\'onglet « Analyses » doit être actif sur /admin/analytics.'
        );

        $payload = $this->extractPayload($body);
        foreach (['forecast', 'day_track', 'weekday', 'hourly', 'semaine', 'mois30', 'goal', 'meta'] as $key) {
            self::assertArrayHasKey($key, $payload, "Clé « $key » absente du payload buildPage.");
        }

        // Objectif par défaut (500 €) et prob_atteinte = FRACTION [0, 1].
        $semaine = $payload['semaine'];
        self::assertGreaterThan(0.0, (float) $semaine['objectif'], 'L\'objectif doit être strictement positif.');
        self::assertGreaterThanOrEqual(0.0, (float) $semaine['prob_atteinte'], 'prob_atteinte ∈ [0,1] (fraction, pas 0-100).');
        self::assertLessThanOrEqual(1.0, (float) $semaine['prob_atteinte'], 'prob_atteinte ∈ [0,1] (fraction, pas 0-100).');

        // 7 jours prévus, CA jamais négatif.
        $jours = $payload['forecast']['jours'];
        self::assertCount(7, $jours, 'Le moteur prévoit exactement 7 jours.');
        foreach ($jours as $j) {
            self::assertGreaterThanOrEqual(0.0, (float) $j['ca'], 'CA prévu jamais négatif.');
        }

        // Produits : flux courant uniquement (Événement et 0 € exclus),
        // cohérence forecast.produits ↔ payload.produits_reappro.
        $produits = $payload['forecast']['produits'];
        self::assertCount(3, $produits, '3 produits du flux courant (cola, jus, sandwich).');
        self::assertCount(count($produits), $payload['produits_reappro'], 'produits_reappro reflète forecast.produits.');
        foreach ($produits as $p) {
            self::assertNotSame('soiree', (string) $p['key'], 'La vente Événement est exclue du flux.');
            self::assertNotSame('test', (string) $p['key'], 'La ligne à 0 € est exclue du flux.');
        }
        self::assertSame(1, (int) $payload['anomalies']['ventes_zero'], 'La ligne à 0 € est comptée dans l\'audit.');

        // Assez de données : confiance jamais « insuffisant ».
        self::assertGreaterThanOrEqual(10, (int) $payload['meta']['n_jours'], '14 jours ouverts seedés → n_jours ≥ 10.');
        self::assertNotSame('insuffisant', (string) $payload['meta']['confiance'], 'n_jours ≥ 10 → confiance exploitable.');

        // RÉGRESSION « 0 % » : la carte affiche prob × 100 (et non la
        // fraction 0-1 brute, qui affichait toujours « 0 % » ou « 1 % »).
        $pct   = max(0.0, min(100.0, (float) $semaine['prob_atteinte'] * 100.0));
        $fmt   = number_format($pct, $pct < 10 ? 1 : 0, ',', ' ');
        self::assertStringContainsString(
            'goal-prob-value">' . $fmt . ' %</span>',
            $body,
            'La probabilité affichée doit être prob_atteinte × 100.'
        );

        // ---- F1 : les deux fourchettes sont étiquetées sans ambiguïté ----
        // « Attendu à Hh » = fourchette à l'heure courante (day_track) ;
        // la fourchette JOURNÉE 80 % vit dans la carte Prévisions.
        self::assertStringContainsString('Attendu à ', $body, 'La chip « Attendu à Hh » (estimation) est absente.');
        self::assertStringContainsString('fourchette journée 80 %', $body, 'La fourchette journée de la carte Prévisions doit être étiquetée.');
        self::assertArrayHasKey('expected_so_far', $payload['day_track']);
        self::assertArrayHasKey('intervalle_h', $payload['day_track']);

        // ---- F4 : sous-titre meilleur jour = significatif UNIQUEMENT ----
        // Soit un meilleur jour désigné (avec n = occurrences observées),
        // soit la mention honnête « Pas de différence significative ».
        // Deux séries étiquetées : Observé (barres) + Modèle (points).
        $wd = $payload['weekday'];
        foreach (['labels', 'ca', 'obs', 'se', 'next_date', 'days_count', 'best'] as $wdKey) {
            self::assertArrayHasKey($wdKey, $wd, "Clé « $wdKey » absente du payload weekday.");
        }
        self::assertCount(7, $wd['ca'], 'weekday.ca : 7 entrées (une par dow).');
        self::assertCount(7, $wd['obs'], 'weekday.obs : 7 entrées (une par dow).');
        if (is_array($wd['best']) && $wd['best'] !== []) {
            self::assertStringContainsString('Meilleur jour : ', $body, 'Le meilleur jour désigné doit être affiché.');
            self::assertStringContainsString(
                '/jour attendu (modèle, n=' . (int) ($wd['best']['n'] ?? -1) . ')',
                $body,
                'Le sous-titre meilleur jour doit citer n (occurrences observées).'
            );
        } else {
            self::assertNull($wd['best'] ?? null);
            self::assertStringContainsString(
                'Pas de différence significative entre les jours',
                $body,
                'Sans meilleur jour significatif, le sous-titre doit le dire honnêtement.'
            );
        }
        self::assertStringNotContainsString('/jour en moyenne', $body, 'Ancien libellé à deux chiffres encore présent.');
        self::assertStringNotContainsString('Moyenne/jour : ', $body, 'La moyenne/jour ne doit plus vivre dans le sous-titre.');
        self::assertStringNotContainsString('CA moyen (€)', $body, 'L\'étiquette trompeuse « CA moyen » doit avoir disparu.');

        // ---- LOT 4 : étiquettes de séries déplacées dans le JS extrait ----
        // (extraction imposée par la mission : le JS inline n'est plus dans
        // la page — l'assertion porte sur les fichiers extraits).
        $jsDesktop = $this->jsSource('analytics.js');
        $jsKiosk = $this->jsSource('analytics-kiosk.js');
        self::assertStringContainsString('Modèle (prochaine occurrence)', $jsDesktop, 'La série modèle doit être étiquetée (JS extrait desktop).');
        self::assertStringContainsString("'Observé'", $jsDesktop, 'La série observée doit être étiquetée (JS extrait desktop).');
        self::assertStringContainsString('Modèle (prochaine occurrence)', $jsKiosk, 'La série modèle doit être étiquetée (JS extrait kiosque).');
        self::assertStringContainsString("'Observé'", $jsKiosk, 'La série observée doit être étiquetée (JS extrait kiosque).');

        // ---- LOT 4 : agrégats calculés côté contrôleur (ex-calculs vue/JS) ----
        // Tendance produit : totaux = sommes des colonnes du payload.
        $pw = $payload['product_weekly'];
        self::assertArrayHasKey('total_ca', $pw, 'product_weekly.total_ca manquant (ex-array_sum de vue).');
        self::assertArrayHasKey('total_qty', $pw, 'product_weekly.total_qty manquant (ex-array_sum de vue).');
        self::assertEqualsWithDelta(
            array_sum(array_map('floatval', $pw['ca'])),
            (float) $pw['total_ca'],
            0.01,
            'total_ca doit être la somme des CA hebdo (calcul remonté côté contrôleur).'
        );
        self::assertSame(
            array_sum(array_map('intval', $pw['qty'])),
            (int) $pw['total_qty'],
            'total_qty doit être la somme des quantités hebdo.'
        );

        // Donut catégorie : total et part de la 1re catégorie cohérents.
        $byCat = $payload['byCategory'];
        self::assertEqualsWithDelta(
            array_sum(array_map('floatval', $byCat['ca'])),
            (float) $byCat['total'],
            0.01,
            'byCategory.total = somme des CA par catégorie (ex-reduce JS).'
        );
        self::assertArrayHasKey('category_share', $payload, 'category_share manquant (titre-conclusion du donut).');
        if ((float) $byCat['total'] > 0.0) {
            self::assertNotNull($payload['category_share']['label'], 'Part catégorie désignée dès qu\'il y a des ventes.');
            $maxCa = max(array_map('floatval', $byCat['ca']));
            self::assertEqualsWithDelta(
                round($maxCa / (float) $byCat['total'] * 100, 1),
                (float) $payload['category_share']['pct'],
                0.01,
                'category_share.pct = poids de la catégorie la plus vendue.'
            );
        }

        // Donut paiements : total, part carte et libellés pré-composés.
        $pay = $payload['payments'];
        $sumPay = (float) ($pay['by_ca']['CARTE'] ?? 0) + (float) ($pay['by_ca']['LIQUIDE'] ?? 0);
        self::assertEqualsWithDelta($sumPay, (float) $pay['total'], 0.01, 'payments.total = somme carte + liquide.');
        $pctCarteAttendu = $sumPay > 0 ? (int) round((float) ($pay['by_ca']['CARTE'] ?? 0) / $sumPay * 100) : null;
        self::assertSame($pctCarteAttendu, $pay['pct_carte'] !== null ? (int) $pay['pct_carte'] : null, 'pct_carte = % carte (ex-Math.round JS).');
        if ($pctCarteAttendu !== null) {
            self::assertCount(2, $pay['labels'], 'Deux libellés de légende pré-composés.');
            self::assertStringContainsString((string) $pctCarteAttendu . ' %', (string) $pay['labels'][0], 'Le libellé Carte porte la part calculée côté contrôleur.');
        }

        // Tableau produit : totaux de la ligne Total (ex-calculs JS).
        $tt = $payload['table_totals'];
        $sumQty = array_sum(array_map(static fn ($r) => (int) $r['qty'], $payload['table']));
        $sumCa = array_sum(array_map(static fn ($r) => (float) $r['ca'], $payload['table']));
        $sumProfit = array_sum(array_map(static fn ($r) => (float) $r['profit'], $payload['table']));
        self::assertSame($sumQty, (int) $tt['qty'], 'table_totals.qty = somme des quantités.');
        self::assertEqualsWithDelta($sumCa, (float) $tt['ca'], 0.01, 'table_totals.ca = somme des CA.');
        self::assertEqualsWithDelta($sumProfit, (float) $tt['profit'], 0.01, 'table_totals.profit = somme des bénéfices.');
        if ($sumCa > 0.0) {
            self::assertEqualsWithDelta(round($sumProfit / $sumCa * 100, 1), (float) $tt['margin_pct'], 0.01, 'table_totals.margin_pct = marge observée globale.');
        }
        self::assertArrayHasKey('table_categories', $payload, 'table_categories manquant (sous-totaux kiosque).');

        // Heatmap : échelle (max) et totaux par jour (ex-calculs JS).
        $hm = $payload['heatmap_meta'];
        $maxAttendu = 0.0;
        $jour0 = 0.0;
        foreach ($payload['heatmap'] as $d => $row) {
            foreach ($row as $h => $v) {
                $v = (float) $v;
                $maxAttendu = max($maxAttendu, $v);
                if ((int) $d === 0) {
                    $jour0 += $v;
                }
            }
        }
        self::assertEqualsWithDelta($maxAttendu, (float) $hm['max'], 0.001, 'heatmap_meta.max = max de la matrice (échelle de couleur).');
        self::assertCount(7, $hm['day_totals'], 'Un total par jour de semaine.');
        self::assertEqualsWithDelta($jour0, (float) $hm['day_totals'][0], 0.01, 'day_totals[0] = somme du lundi.');

        // Jour de semaine : bande de fourchette ~80 % autour du modèle.
        $wd4 = $payload['weekday'];
        foreach (['lo', 'hi'] as $bandKey) {
            self::assertArrayHasKey($bandKey, $wd4, "weekday.$bandKey manquant (bande de fourchette, lot 4).");
            self::assertCount(7, $wd4[$bandKey], "weekday.$bandKey : 7 entrées.");
        }
        foreach ($wd4['ca'] as $i => $caVal) {
            if ($caVal === null) {
                continue;
            }
            self::assertGreaterThanOrEqual(0.0, (float) $wd4['lo'][$i], 'Borne basse jamais négative.');
            self::assertGreaterThanOrEqual((float) $caVal - 0.001, (float) $wd4['hi'][$i], 'Borne haute ≥ attendu (bande autour du modèle).');
        }

        // Probabilité d'atteinte pré-échelonnée (×100 borné, contrôleur).
        self::assertArrayHasKey('goal_prob_pct', $payload, 'goal_prob_pct manquant (ex-×100 en vue).');
        self::assertNotNull($payload['goal_prob_pct'], 'Semaine chargée → probabilité affichable.');
        self::assertGreaterThanOrEqual(0.0, (float) $payload['goal_prob_pct']);
        self::assertLessThanOrEqual(100.0, (float) $payload['goal_prob_pct']);

        // ---- F6 : note bénéfice en régimes (≥ 5 % ici, produits nommés) ----
        self::assertStringContainsString(
            'sans coût fiable (Jus de fruits)',
            $body,
            'La note bénéfice doit nommer le produit suspect.'
        );
        self::assertStringContainsString('bénéfice sous-estimé ou surestimé', $body);
        $pctNc = (float) $payload['benefice']['pct_ca_sans_cout_fiable'];
        self::assertGreaterThan(5.0, $pctNc, 'Le seed doit mettre le dashboard en régime ≥ 5 % (note entier).');

        // ---- F7 : marge prévisionnelle vs marge observée étiquetées ----
        self::assertStringContainsString('Marge prévisionnelle (prix médian fenêtre − coût actuel)', $body);
        self::assertStringContainsString('Marge observée (coût à la date de vente)', $body);

        // ---- F8 : contrat des nouvelles clés d'audit (bandeau qualité) ----
        foreach (['cles_fusionnees', 'produits_sans_stock', 'n_produits_sans_stock'] as $key) {
            self::assertArrayHasKey($key, $payload['anomalies'], "Clé « $key » absente des anomalies.");
        }
    }

    public function test_kiosque_analytics_affiche_la_probabilite_echellee(): void
    {
        $this->pdo->prepare(
            "INSERT INTO settings (id, `key`, value) VALUES ('set_an_t', 'admin_kiosk_token', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        )->execute([self::KIOSK_TOKEN]);

        $r = $this->request('GET', '/kiosque/admin/analytics/' . self::KIOSK_TOKEN);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Le kiosque Analytics doit répondre 200.');
        $body = (string) ($r['body'] ?? '');

        // ---- LOT 4 : onglets Ventes en version compacte kiosque ----
        self::assertStringContainsString('tabs-ventes--compact', $body, 'Kiosque : version compacte des onglets Ventes absente.');
        self::assertStringContainsString('aria-current="page"', $body, 'Kiosque : l\'onglet actif doit porter aria-current.');

        $payload = $this->extractPayload($body);
        $pct = max(0.0, min(100.0, (float) $payload['semaine']['prob_atteinte'] * 100.0));
        $fmt = number_format($pct, $pct < 10 ? 1 : 0, ',', ' ');
        self::assertStringContainsString(
            "Probabilité d'atteinte : " . $fmt . ' %',
            $body,
            'La chip kiosque doit afficher prob_atteinte × 100.'
        );

        // F1/F4 kiosque : libellés compacts attendus.
        self::assertStringContainsString('Attendu à ', $body, 'La chip kiosque « Attendu à Hh » est absente.');
        self::assertStringContainsString('fourchette journée 80 %', $body, 'La fourchette journée doit être étiquetée dans le kiosque.');
        $wdK = $payload['weekday']['best'] ?? null;
        if (is_array($wdK) && $wdK !== []) {
            self::assertStringContainsString('/jour attendu (modèle, n=', $body, 'Le sous-titre kiosque du meilleur jour doit citer n.');
        } else {
            self::assertStringContainsString('Pas de différence significative entre les jours', $body, 'Kiosque : sous-titre honnête sans meilleur jour significatif.');
        }
        self::assertStringNotContainsString('CA moyen (€)', $body, 'Kiosque : étiquette « CA moyen » supprimée.');

        // Mauvais jeton : 403.
        self::assertSame(
            403,
            (int) ($this->request('GET', '/kiosque/admin/analytics/mauvais-jeton')['code'] ?? 0),
            'Un jeton invalide doit être refusé.'
        );
    }

    /**
     * Extraction du payload JSON injecté dans la vue.
     *
     * AJUSTEMENT LOT 4 (extraction JS) : le payload vit désormais dans un
     * îlot <script type="application/json" id="analytics-data"> (vue
     * desktop) ou id="analytics-kiosk-data" (kiosque) — l'ancien inline
     * « var data = {…}; » a été déplacé vers public/assets/js/.
     *
     * @return array<string,mixed>
     */
    private function extractPayload(string $body): array
    {
        self::assertSame(
            1,
            preg_match('/<script type="application\/json" id="analytics(?:-kiosk)?-data">(\{.*?\})<\/script>/s', $body, $m),
            'Îlot payload JSON introuvable dans la page.'
        );
        $data = json_decode((string) $m[1], true);
        self::assertIsArray($data, 'Le payload JSON de la page est invalide.');

        return $data;
    }

    /**
     * Contenu d'un JS extrait (lot 4) — les étiquettes de séries Chart.js
     * vivent dans ces fichiers depuis l'extraction du JS inline.
     */
    private function jsSource(string $file): string
    {
        $path = AEIC_PUBLIC . '/assets/js/' . $file;
        self::assertFileExists($path, 'JS extrait manquant : ' . $file);

        return (string) file_get_contents($path);
    }

    /**
     * REND le fichier de vue avec les variables de buildPage (mécanisme
     * identique au contrôleur : extract + include), sans layout.
     *
     * @param array<string,mixed> $vars
     */
    private function renderView(string $file, array $vars): string
    {
        $_SESSION = $_SESSION ?? [];
        extract($vars, EXTR_SKIP);
        ob_start();

        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * RÉGRESSION « Attendu aujourd'hui : 0,00 € (fourchette 0,00 €–0,00 €) »
     * (incident du mercredi ~08:11, jour ouvré sans vente encore) : à 8h le
     * profil horaire couvre < 10 % de la journée (trop_tot), l'attendu
     * HORAIRE est masqué (expected_so_far / intervalle_h null) — mais le
     * sous-titre de la carte « Par heure » doit afficher l'attendu
     * JOURNÉE (prevision_jour + intervalle_80), côté admin COMME kiosque,
     * et JAMAIS « 0,00 € » de null.
     */
    public function test_sous_titre_par_heure_affiche_attendu_journee_a_8h_trop_tot(): void
    {
        // 08:00 Europe/Paris : identique au fuseau du contrôleur, jamais
        // à cheval sur deux jours UTC (06:00/07:00 UTC le même jour).
        $now = new \DateTimeImmutable('today 08:00', new \DateTimeZone('Europe/Paris'));

        // Incident exact : aujourd'hui est ABSENT du dataset (aucune vente
        // importée à 8h) — on retire les ventes du jour seedées.
        $this->pdo->prepare('DELETE FROM sales WHERE DATE(sold_at) = ?')->execute([$now->format('Y-m-d')]);

        // Le contrôleur lit la base via Model::pdo() : on branche la base
        // de test DANS le processus phpunit (Modèle prévu pour ça), puis
        // buildPage('week', $now) via réflexion (méthode privée).
        Model::setTestPdo($this->pdo);

        try {
            $ctrl  = new AdminAnalyticsController();
            $build = new ReflectionMethod($ctrl, 'buildPage');
            $build->setAccessible(true);
            $page = $build->invoke($ctrl, 'week', $now);
        } finally {
            Model::setTestPdo(null);
        }

        // Contrat moteur à 8h sans vente : attendu JOURNÉE non null et > 0,
        // fourchette journée présente, trop_tot (profil < 10 %), intervalle
        // horaire masqué (null — jamais 0–0). expected_so_far reste un
        // float du modèle (S(h) lissé, non null) : la VUE choisit
        // « — (trop tôt) » via trop_tot, jamais « 0,00 € » de null.
        $prev = $page['forecast']['prevision_jour'] ?? null;
        self::assertNotNull($prev, 'prevision_jour doit être calculé même sans vente du jour (fix 4265b21).');
        self::assertGreaterThan(0.0, (float) $prev, 'L\'attendu JOURNÉE doit être strictement positif à 8h.');
        self::assertNotNull($page['forecast']['intervalle_80'] ?? null, 'intervalle_80 doit être calculé même sans vente du jour.');
        self::assertTrue((bool) ($page['day_track']['trop_tot'] ?? false), 'À 8h le profil horaire est < 10 % → trop_tot.');
        self::assertNull($page['day_track']['intervalle_h'] ?? null, 'Intervalle horaire masqué trop tôt (null, jamais 0–0).');

        $montant = formatPrice((float) $prev);
        // Le sous-titre passe dans e() : l'apostrophe est échappée
        // (aujourd&apos;hui) — les attendus sont comparés via e() également.
        $attenduJour = e("Attendu aujourd'hui : ") . $montant;
        $attenduJourZero = e("Attendu aujourd'hui : ") . '0,00';

        // ---- Vue ADMIN : sous-titre « Par heure » rendu serveur ----
        $htmlAdmin = $this->renderView(AEIC_VIEWS . '/admin/analytics/index.php', $page + ['title' => 'Analytics', 'kiosk' => false, 'token' => '']);
        self::assertStringContainsString(
            $attenduJour,
            $htmlAdmin,
            'Le sous-titre « Par heure » doit afficher l\'attendu JOURNÉE à 8h.'
        );
        self::assertStringContainsString('trop tôt', $htmlAdmin, 'La mention « trop tôt » (partie horaire) doit être présente.');
        self::assertStringContainsString('Attendu à 8h : — (trop tôt)', $htmlAdmin, 'La partie horaire doit être libellée « — (trop tôt) » à 8h.');
        self::assertStringNotContainsString($attenduJourZero, $htmlAdmin, 'JAMAIS « 0,00 € » de null dans le sous-titre.');
        self::assertStringNotContainsString('0,00 €–0,00 €', $htmlAdmin, 'JAMAIS de fourchette « 0,00 €–0,00 € » de null.');

        // ---- Vue KIOSQUE : même contrat ----
        $htmlKiosque = $this->renderView(AEIC_VIEWS . '/admin/analytics/kiosk.php', $page + ['title' => 'Analytique', 'kiosk' => true, 'token' => 'jeton-test']);
        self::assertStringContainsString(
            $attenduJour,
            $htmlKiosque,
            'Le sous-titre kiosque « Par heure » doit afficher l\'attendu JOURNÉE à 8h.'
        );
        self::assertStringContainsString('Attendu à 8h : — (trop tôt)', $htmlKiosque, 'Partie horaire kiosque libellée « — (trop tôt) ».');
        self::assertStringNotContainsString($attenduJourZero, $htmlKiosque, 'JAMAIS « 0,00 € » de null (kiosque).');
    }
}
