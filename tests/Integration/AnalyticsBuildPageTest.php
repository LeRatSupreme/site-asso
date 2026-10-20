<?php

declare(strict_types=1);

namespace Tests\Integration;

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
 *  - la probabilité AFFICHÉE dans la carte Objectif = prob × 100.
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

        // ---- F4 : sous-titre meilleur jour = UN seul chiffre ----
        self::assertStringContainsString('(modèle, fenêtre ', $body, 'Le sous-titre meilleur jour doit citer la fenêtre.');
        self::assertStringNotContainsString('/jour en moyenne', $body, 'Ancien libellé à deux chiffres encore présent.');
        self::assertStringNotContainsString('Moyenne/jour : ', $body, 'La moyenne/jour ne doit plus vivre dans le sous-titre.');

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
        self::assertStringContainsString('/jour (modèle, fenêtre ', $body, 'Le sous-titre kiosque du meilleur jour doit citer la fenêtre.');

        // Mauvais jeton : 403.
        self::assertSame(
            403,
            (int) ($this->request('GET', '/kiosque/admin/analytics/mauvais-jeton')['code'] ?? 0),
            'Un jeton invalide doit être refusé.'
        );
    }

    /**
     * Extrait le payload JSON injecté dans la vue (« var data = {…}; »).
     *
     * @return array<string,mixed>
     */
    private function extractPayload(string $body): array
    {
        self::assertSame(1, preg_match('/var data = (\{.*?\});\r?\n/s', $body, $m), 'Payload JSON introuvable dans la page.');
        $data = json_decode((string) $m[1], true);
        self::assertIsArray($data, 'Le payload JSON de la page est invalide.');

        return $data;
    }
}
