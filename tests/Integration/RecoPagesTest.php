<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Intégration de l'INTÉGRATION du moteur de recommandations (cœur pur
 * App\Core\Compta\Reco) au site : carte « Idées du moment » sur le
 * dashboard Analytics, page d'historique, seuils, décisions humaines
 * (Appliquer / Plus tard / Ignorer) et kiosque admin à jeton.
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
        $this->reset(['recommendations', 'users', 'settings']);
        $this->seedUser($this->rootId, 'reco-root@exemple.fr', 'Password123456', 'SUPERADMIN');
    }

    /**
     * Le dashboard Analytics rend la carte « Idées du moment » (même sur
     * une base vide : payload vide ou indisponible accepté).
     */
    public function test_analytics_affiche_la_carte_idees_du_moment(): void
    {
        $r = $this->request('GET', '/admin/analytics', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Analytics doit répondre 200 (pas de 500).');
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('Idées du moment', $body, 'La carte reco est absente du dashboard.');
        self::assertStringContainsString('Le site propose', $body, 'La mention « le site propose, tu décides » est absente.');
    }

    /**
     * La page d'historique (admin) répond 200 et affiche son titre.
     */
    public function test_historique_reco_repond_200(): void
    {
        $r = $this->request('GET', '/admin/reco/historique', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Historique reco doit répondre 200.');
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('Idées appliquées', $body, 'Titre de page absent.');
        self::assertStringContainsString('Seuils du moteur', $body, 'Formulaire des seuils absent.');
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
     * POST /admin/reco/decision (appliquer) : statut « appliquee », date
     * d'application posée, baseline écrite (photo de l'avant — vide sur
     * base sans données, mais jamais NULL après application).
     */
    public function test_decision_appliquer_ecrit_la_baseline(): void
    {
        $this->seedReco('fp_reco_apply', 'proposee');

        $r = $this->request('POST', '/admin/reco/decision', [
            'id'       => 'fp_reco_apply',
            'decision' => 'appliquer',
        ], [], $this->rootId);
        self::assertSame(302, (int) ($r['code'] ?? 0));

        $row = $this->fetchReco('fp_reco_apply');
        self::assertSame('appliquee', (string) $row['statut'], 'Le statut doit passer à appliquee.');
        self::assertNotNull($row['applied_at'], 'applied_at doit être daté.');
        self::assertNotNull($row['baseline_json'], 'La baseline doit être écrite à l\'application.');
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
     */
    private function seedReco(string $id, string $statut, string $regle = 'R2'): void
    {
        $this->pdo->prepare(
            'INSERT INTO recommendations
                (id, regle, titre, produits_json, payload_json, statut, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $id,
            $regle,
            'Idée test ' . $id,
            '[{"key":"cola","label":"Cola"}]',
            '{}',
            $statut,
        ]);
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
