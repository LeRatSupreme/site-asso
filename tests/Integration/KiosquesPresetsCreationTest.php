<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Admin\AdminKiosquesController;
use App\Core\Compta\KioskPages;
use App\Models\Model;

/**
 * Presets de création « en 1 clic » par rôle (page admin Kiosques) :
 *
 *  - la page rend les 3 cartes presets (Trésorier, Président, Resp.
 *    cafet) AU-DESSUS du formulaire de création existant, AVEC le
 *    formulaire identifié (cible du pré-remplissage JS) — et toujours
 *    AUCUN jeton dans le HTML initial ;
 *  - la création « en 1 clic » (ce que soumet le formulaire pré-rempli)
 *    passe par le POST ORDINAIRE /admin/kiosques/create : flash de
 *    succès, ligne en base avec exactement les tuiles/options du preset,
 *    options EFFECTIVES du jeton conformes (Président → bénéfice seul),
 *    audit « kiosques.create » systématique SANS la valeur du jeton.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class KiosquesPresetsCreationTest extends IntegrationTestCase
{
    private string $rootId = 'u_kqpresets_root';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset(['kiosques', 'users', 'audit_logs']);
        $this->seedUser($this->rootId, 'kqpresets-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // KioskPages::tokenOptions() lit la table kiosques en process :
        // modèle branché sur la même base que les requêtes HTTP.
        Model::setTestPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        parent::tearDown();
    }

    /**
     * CSRF de la page admin Kiosques (même session que le POST qui suit).
     */
    private function csrfFromAdminPage(): string
    {
        $page = $this->request('GET', '/admin/kiosques', [], [], $this->rootId);
        $html = (string) ($page['body'] ?? '');
        self::assertSame(200, (int) ($page['code'] ?? 0), 'La page admin Kiosques doit répondre.');
        self::assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $html, $m), 'CSRF absent de la page admin Kiosques.');

        return (string) $m[1];
    }

    /**
     * Dernier flash posé par la réponse (null si aucun).
     *
     * @param array<string,mixed> $r
     * @return array{type:string,message:string}|null
     */
    private function lastFlash(array $r): ?array
    {
        $flash = $r['session']['_flash'] ?? [];
        $last  = is_array($flash) && $flash !== [] ? end($flash) : null;

        return is_array($last) ? $last : null;
    }

    /**
     * La page rend les 3 presets avec leurs libellés humains, le
     * formulaire de création identifié (cible du pré-remplissage) et les
     * données de preset servies au JS — sans jamais exposer de jeton.
     */
    public function test_la_page_rend_les_trois_presets(): void
    {
        // Un kiosque existant : son jeton ne doit JAMAIS transiter dans le
        // HTML non plus (comportement existant, vérifié au passage).
        $this->pdo->prepare(
            "INSERT INTO kiosques (id, name, token, pages, created_by) VALUES ('kq_presets_seed', 'Kiosque témoin', ?, '[\"jour\"]', 'test')"
        )->execute([bin2hex(random_bytes(20))]);

        $page = $this->request('GET', '/admin/kiosques', [], [], $this->rootId);
        $body = (string) ($page['body'] ?? '');
        self::assertSame(200, (int) ($page['code'] ?? 0), 'La page admin Kiosques doit répondre.');

        // Les 3 cartes presets, identifiées par leur clé.
        foreach (['tresorier', 'president', 'cafet'] as $key) {
            self::assertStringContainsString('data-kq-preset="' . $key . '"', $body, 'La carte preset « ' . $key . ' » doit être rendue.');
        }
        self::assertStringContainsString('Pré-remplir', $body, 'Les cartes presets proposent le pré-remplissage.');
        self::assertStringContainsString('Pour le président', $body, 'Le preset Président porte sa ligne de description du rôle.');
        self::assertStringContainsString('Pour le responsable cafétéria', $body, 'Le preset Resp. cafet porte sa ligne de description du rôle.');

        // Le formulaire de création (cible du pré-remplissage JS) existe
        // et reste un POST vers l'action ORDINAIRE /admin/kiosques/create.
        self::assertStringContainsString('id="kq-create-form"', $body, 'Le formulaire de création est identifié pour le pré-remplissage.');
        self::assertStringContainsString(url('/admin/kiosques/create'), $body, 'Le formulaire pré-rempli soumet bien vers le POST existant.');

        // Données de preset servies au JS (même page, aucun jeton dedans).
        self::assertStringContainsString('var PRESETS =', $body, 'Les données de presets sont servies au script de pré-remplissage.');

        // Sécurité inchangée : aucun jeton dans le HTML initial.
        $stmt = $this->pdo->query('SELECT token FROM kiosques');
        foreach ($stmt->fetchAll() as $row) {
            self::assertStringNotContainsString((string) $row['token'], $body, 'Aucun jeton de kiosque ne doit apparaître dans le HTML.');
        }
    }

    /**
     * Création « Président » en 1 clic : le formulaire pré-rempli soumet
     * le POST existant → kiosque avec EXACTEMENT les tuiles et options
     * du preset, options effectives du jeton conformes, audit tracé.
     */
    public function test_creation_president_en_un_clic(): void
    {
        $preset = null;
        foreach (AdminKiosquesController::presets() as $p) {
            if ($p['key'] === 'president') {
                $preset = $p;
                break;
            }
        }
        self::assertNotNull($preset, 'Le preset « president » doit exister.');

        $csrf = $this->csrfFromAdminPage();

        // Ce que soumet le formulaire après un clic sur la carte Président
        // (nom + tuiles + options du preset) : le POST ORDINAIRE.
        $r = $this->request('POST', '/admin/kiosques/create', [
            '_csrf'   => $csrf,
            'name'    => $preset['label'],
            'pages'   => $preset['pages'],
            'options' => $preset['options'],
        ], [], $this->rootId);

        $flash = $this->lastFlash($r);
        self::assertNotNull($flash, 'La création doit produire un flash.');
        self::assertSame('success', $flash['type'], 'La création Président en 1 clic doit réussir.');
        self::assertStringContainsString('Président', (string) $flash['message']);

        // En base : exactement les tuiles/options du preset.
        $row = $this->pdo->query('SELECT id, name, token, pages, options FROM kiosques')->fetch();
        self::assertIsArray($row, 'La création doit insérer une ligne dans kiosques.');
        self::assertSame('Président', (string) $row['name']);
        self::assertSame(['jour-membre', 'president'], json_decode((string) $row['pages'], true), 'Tuiles stockées = preset exact.');
        self::assertSame(['benefice'], json_decode((string) $row['options'], true), 'Options stockées = preset exact.');

        // Options effectives du jeton : bénéfice SEUL (contrat P1-2).
        self::assertSame(
            ['benefice' => true, 'paiements' => false, 'couts' => false],
            KioskPages::tokenOptions((string) $row['token'])
        );
        self::assertTrue(KioskPages::tokenAllows((string) $row['token'], 'president'), 'La Vue Président s\'ouvre à ce jeton.');
        self::assertFalse(KioskPages::tokenAllows((string) $row['token'], 'ledger'), 'Le livre comptable reste refusé.');

        // Audit systématique conservé (création via le flux ordinaire),
        // SANS la valeur du jeton.
        $stmt = $this->pdo->prepare('SELECT details FROM audit_logs WHERE action = ? AND entity_type = ? AND entity_id = ?');
        $stmt->execute(['kiosques.create', 'kiosque', (string) $row['id']]);
        $details = $stmt->fetchColumn();
        self::assertNotFalse($details, 'La création en 1 clic doit être auditée comme toute création.');
        self::assertStringNotContainsString((string) $row['token'], (string) $details, 'L\'audit ne doit jamais contenir le jeton.');
        self::assertStringContainsString('president', (string) $details, 'L\'audit trace les tuiles créées.');
    }

    /**
     * Les 3 presets produisent chacun un kiosque valide via le POST
     * ordinaire (au moins une tuile, options exactes du preset).
     */
    public function test_les_trois_presets_produisent_des_kiosques_valides(): void
    {
        $csrf = $this->csrfFromAdminPage();

        foreach (AdminKiosquesController::presets() as $preset) {
            $r = $this->request('POST', '/admin/kiosques/create', [
                '_csrf'   => $csrf,
                'name'    => $preset['label'],
                'pages'   => $preset['pages'],
                'options' => $preset['options'],
            ], [], $this->rootId);

            $flash = $this->lastFlash($r);
            self::assertNotNull($flash, 'Preset ' . $preset['key'] . ' : la création doit produire un flash.');
            self::assertSame('success', $flash['type'], 'Preset ' . $preset['key'] . ' : la création doit réussir.');
        }

        $rows = $this->pdo->query('SELECT name, pages, options FROM kiosques ORDER BY name')->fetchAll();
        self::assertCount(3, $rows, 'Les 3 presets créent chacun leur kiosque.');

        $byName = [];
        foreach ($rows as $row) {
            $byName[(string) $row['name']] = $row;
        }
        foreach (AdminKiosquesController::presets() as $preset) {
            self::assertArrayHasKey($preset['label'], $byName, 'Kiosque « ' . $preset['label'] . ' » créé.');
            self::assertSame(
                $preset['pages'],
                json_decode((string) $byName[$preset['label']]['pages'], true),
                'Tuiles du kiosque « ' . $preset['label'] . ' » = preset exact.'
            );
            self::assertSame(
                $preset['options'],
                json_decode((string) $byName[$preset['label']]['options'], true),
                'Options du kiosque « ' . $preset['label'] . ' » = preset exact.'
            );
        }
    }
}
