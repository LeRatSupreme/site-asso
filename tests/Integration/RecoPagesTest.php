<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Intégration de l'INTÉGRATION du moteur de recommandations (cœur pur
 * App\Core\Compta\Reco) au site : carte « Idées du moment » sur le
 * dashboard Analytics (DEUX BLOCS « À corriger » / « Idées pour vendre
 * plus », liens fiche), page d'historique (idées + corrections groupées
 * par bloc), seuils, décisions humaines (Appliquer / Plus tard /
 * Ignorer) et kiosque admin à jeton.
 *
 * RÈGLE DE MISSION vérifiée ici en base : le site PROPOSE, un humain
 * DÉCIDE — aucune écriture automatique de prix/stock (aucun test
 * n'en déclenche, et les POST ne touchent que recommendations/settings).
 *
 * La carte analytics doit se rendre MÊME SANS DONNÉES (payload vide ou
 * « indisponible » accepté) : filet anti-500 du pipeline reco complet
 * (extraction RecoData → moteur → vues).
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class RecoPagesTest extends IntegrationTestCase
{
    private string $rootId = 'u_reco_root';

    private const KIOSK_TOKEN = 'jeton-reco-test-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset(['recommendations', 'users', 'settings', 'sales', 'product_stocks', 'product_costs']);
        $this->seedUser($this->rootId, 'reco-root@exemple.fr', 'Password123456', 'SUPERADMIN');
    }

    /**
     * Le dashboard Analytics rend la carte « Idées du moment » avec SES
     * DEUX BLOCS (« À corriger » et « Idées pour vendre plus »). Quelques
     * ventes sont seedées pour que le moteur émette au moins une
     * correction R11 (coût inconnu) : les deux titres de blocs doivent
     * être présents même en mode restreint (historique court).
     */
    public function test_analytics_affiche_les_deux_blocs_de_la_carte(): void
    {
        $this->seedVente('vente-reco-carte', 'cola', 'Cola');

        $r = $this->request('GET', '/admin/analytics', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Analytics doit répondre 200 (pas de 500).');
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('Idées du moment', $body, 'La carte reco est absente du dashboard.');
        self::assertStringContainsString('Le site propose', $body, 'La mention « le site propose, tu décides » est absente.');
        self::assertStringContainsString('À corriger', $body, 'Le bloc « À corriger » est absent de la carte.');
        self::assertStringContainsString('Idées pour vendre plus', $body, 'Le bloc « Idées pour vendre plus » est absent de la carte.');
    }

    /**
     * Lien fiche d'une correction R11 « Renseigner le coût » : la carte
     * analytics doit pointer vers la fiche coûts du produit
     * (/admin/compta/couts?product_key=…).
     */
    public function test_carte_correction_r11_porte_le_lien_vers_la_fiche_couts(): void
    {
        $this->seedVente('vente-reco-r11', 'cola', 'Cola');

        $r = $this->request('GET', '/admin/analytics', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString(
            '/admin/compta/couts?product_key=',
            $body,
            'La correction R11 doit porter un lien vers la fiche coûts du produit.'
        );
    }

    /**
     * La page d'historique (admin) répond 200 et affiche son titre, les
     * deux sections d'historique et le formulaire des seuils.
     */
    public function test_historique_reco_repond_200(): void
    {
        $r = $this->request('GET', '/admin/reco/historique', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Historique reco doit répondre 200.');
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('Idées appliquées', $body, 'Titre de page absent.');
        self::assertStringContainsString('Corrections traitées', $body, 'Section corrections absente.');
        self::assertStringContainsString('Seuils du moteur', $body, 'Formulaire des seuils absent.');
    }

    /**
     * Les lignes appliquées sont groupées par bloc décodé du payload :
     * bloc 'idee' → section « Idées appliquées », bloc 'correction' →
     * section « Corrections traitées ».
     */
    public function test_historique_groupe_idees_et_corrections_par_bloc(): void
    {
        $this->seedReco('fp_hist_idee', 'appliquee', 'R1', ['bloc' => 'idee']);
        $this->seedReco('fp_hist_corr', 'appliquee', 'R11', ['bloc' => 'correction']);

        $r = $this->request('GET', '/admin/reco/historique', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('Idée test fp_hist_idee', $body, 'L\'idée appliquée doit apparaître dans l\'historique.');
        self::assertStringContainsString('Correction test fp_hist_corr', $body, 'La correction appliquée doit apparaître dans l\'historique.');
        self::assertStringContainsString('Corrections traitées', $body);
    }

    /**
     * POST /admin/reco/parametres : la ligne settings est mise à jour
     * (vérif PDO direct) puis la valeur est réaffichée par l'historique.
     */
    public function test_parametres_sauvegarde_et_reaffiche_le_seuil(): void
    {
        $r = $this->request('POST', '/admin/reco/parametres', ['reco_marge_min' => '0.2'], [], $this->rootId);
        self::assertSame(302, (int) ($r['code'] ?? 0), 'La sauvegarde doit rediriger.');

        $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE `key` = 'reco_marge_min' LIMIT 1");
        $stmt->execute();
        $valeur = $stmt->fetchColumn();
        self::assertNotFalse($valeur, 'La ligne settings doit exister après sauvegarde.');
        self::assertSame('0.2', (string) $valeur, 'reco_marge_min doit valoir 0.2 en base.');

        $r2 = $this->request('GET', '/admin/reco/historique', [], [], $this->rootId);
        self::assertSame(200, (int) ($r2['code'] ?? 0));
        self::assertStringContainsString(
            'value="0.2"',
            (string) ($r2['body'] ?? ''),
            'Le seuil sauvegardé doit être réaffiché dans le formulaire.'
        );
    }

    /**
     * POST /admin/reco/decision (ignorer) sur une ligne recommendations
     * seedée : statut « ignoree » en base + motif tracé.
     */
    public function test_decision_ignorer_enregistree_en_base(): void
    {
        $this->seedReco('fp_reco_ignore', 'proposee');

        $r = $this->request('POST', '/admin/reco/decision', [
            'id'       => 'fp_reco_ignore',
            'decision' => 'ignorer',
            'motif'    => 'non_pertinent',
        ], [], $this->rootId);
        self::assertSame(302, (int) ($r['code'] ?? 0), 'La décision doit rediriger.');

        $row = $this->fetchReco('fp_reco_ignore');
        self::assertSame('ignoree', (string) $row['statut'], 'Le statut doit passer à ignoree.');
        self::assertSame('non_pertinent', (string) $row['motif'], 'Le motif doit être tracé.');
        self::assertNotNull($row['decided_at'], 'decided_at doit être daté.');
        self::assertNull($row['applied_at'], 'Une idée ignorée n\'est jamais appliquée.');
    }

    /**
     * POST /admin/reco/decision (appliquer) sur une IDÉE : statut
     * « appliquee », date d'application posée, baseline écrite (photo de
     * l'avant — vide sur base sans données, mais jamais NULL après
     * application).
     */
    public function test_decision_appliquer_idee_ecrit_la_baseline(): void
    {
        $this->seedReco('fp_reco_apply', 'proposee', 'R1', ['bloc' => 'idee']);

        $r = $this->request('POST', '/admin/reco/decision', [
            'id'       => 'fp_reco_apply',
            'decision' => 'appliquer',
        ], [], $this->rootId);
        self::assertSame(302, (int) ($r['code'] ?? 0));

        $row = $this->fetchReco('fp_reco_apply');
        self::assertSame('appliquee', (string) $row['statut'], 'Le statut doit passer à appliquee.');
        self::assertNotNull($row['applied_at'], 'applied_at doit être daté.');
        self::assertNotNull($row['baseline_json'], 'La baseline doit être écrite à l\'application d\'une idée.');
    }

    /**
     * POST /admin/reco/decision (appliquer) sur une CORRECTION (bloc
     * 'correction') : statut « appliquee » SANS baseline — reconstruire
     * RecoData/ForecastEngine n'a de sens que pour une idée appliquée à
     * une offre datée.
     */
    public function test_decision_appliquer_correction_sans_baseline(): void
    {
        $this->seedReco('fp_reco_apply_corr', 'proposee', 'R11', ['bloc' => 'correction']);

        $r = $this->request('POST', '/admin/reco/decision', [
            'id'       => 'fp_reco_apply_corr',
            'decision' => 'appliquer',
        ], [], $this->rootId);
        self::assertSame(302, (int) ($r['code'] ?? 0));

        $row = $this->fetchReco('fp_reco_apply_corr');
        self::assertSame('appliquee', (string) $row['statut'], 'Le statut doit passer à appliquee.');
        self::assertNotNull($row['applied_at'], 'applied_at doit être daté.');
        self::assertNull($row['baseline_json'], 'Une correction est appliquée SANS baseline (rien à mesurer).');
    }

    /**
     * Kiosque admin reco : 200 avec le bon jeton (et l'historique court
     * présent), 403 avec un jeton invalide — même comportement que le
     * kiosque analytics existant.
     */
    public function test_kiosque_reco_jeton_valide_et_invalide(): void
    {
        $this->pdo->prepare(
            "INSERT INTO settings (id, `key`, value) VALUES ('set_reco_kt', 'admin_kiosk_token', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        )->execute([self::KIOSK_TOKEN]);

        // Une idée appliquée avec effet : visible dans l'historique court.
        $this->seedReco('fp_reco_kiosk', 'appliquee', 'R2');

        $r = $this->request('GET', '/kiosque/admin/reco/' . self::KIOSK_TOKEN);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Le kiosque reco doit répondre 200 avec le bon jeton.');
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('Idées du moment', $body);
        self::assertStringContainsString('Idées appliquées', $body, 'L\'historique compact est absent du kiosque.');

        self::assertSame(
            403,
            (int) ($this->request('GET', '/kiosque/admin/reco/mauvais-jeton')['code'] ?? 0),
            'Un jeton invalide doit être refusé.'
        );
    }

    // -----------------------------------------------------------------
    //  Helpers de seeding
    // -----------------------------------------------------------------

    /**
     * Insère une ligne recommendations directement en PDO.
     *
     * @param array<string,mixed>|null $payload Payload complet stocké (bloc, dates…).
     */
    private function seedReco(string $id, string $statut, string $regle = 'R2', ?array $payload = null): void
    {
        $payloadJson = $payload !== null
            ? (string) json_encode($payload, JSON_UNESCAPED_UNICODE)
            : '{}';
        $bloc = is_array($payload) ? (string) ($payload['bloc'] ?? 'idee') : 'idee';
        $titre = ($bloc === 'correction' ? 'Correction test ' : 'Idée test ') . $id;
        $this->pdo->prepare(
            'INSERT INTO recommendations
                (id, regle, titre, produits_json, payload_json, statut, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $id,
            $regle,
            $titre,
            '[{"key":"cola","label":"Cola"}]',
            $payloadJson,
            $statut,
        ]);
    }

    /**
     * Insère une vente du « flux courant » (même définition que le
     * moteur) : produit clé connu, montant > 0, hors « Événement ».
     * Sans lot de coût ni stock : le produit devient un candidat R11
     * « Renseigner le coût » pour la carte analytics.
     */
    private function seedVente(string $ref, string $key, string $label): void
    {
        $this->pdo->prepare(
            "INSERT INTO sales
                (id, transaction_ref, sold_at, payment_method, quantity, description,
                 product_key, category, price_ttc, is_custom_amount)
             VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 DAY), 'CARTE', 2, ?, ?, 'Boissons', 4.00, 0)"
        )->execute([$ref, $ref, $label, $key]);
    }

    /**
     * Relit une ligne recommendations (PDO direct).
     *
     * @return array<string,mixed>
     */
    private function fetchReco(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recommendations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        self::assertIsArray($row, 'La ligne recommendations doit exister.');

        return $row;
    }
}
