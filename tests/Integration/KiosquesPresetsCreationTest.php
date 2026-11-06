<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Admin\AdminKiosquesController;
use App\Core\Compta\KioskPages;
use App\Models\Model;

/**
 * Page « Créer un kiosque » (admin Kiosques) APRÈS le retrait des
 * presets de rôle (commit 76310b9) :
 *
 *  - la page ne rend PLUS les cartes presets (Trésorier, Président,
 *    Resp. cafet) : ni boutons « Pré-remplir », ni données de preset
 *    servies au JS — le formulaire de création ORDINAIRE reste le seul
 *    chemin, intact (identifié, POST /admin/kiosques/create, tuiles et
 *    options du catalogue cochables), et toujours AUCUN jeton dans le
 *    HTML initial ;
 *  - le contrôleur conserve les jeux de tuiles/options par rôle
 *    (AdminKiosquesController::presets()) : soumis au POST ORDINAIRE
 *    /admin/kiosques/create, ils produisent toujours des kiosques
 *    exacts — flash de succès, ligne en base avec exactement les
 *    tuiles/options du preset, options EFFECTIVES du jeton conformes
 *    (Président → bénéfice seul), audit « kiosques.create »
 *    systématique SANS la valeur du jeton.
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
     * La page ne rend PLUS les presets de rôle (retirés en 76310b9) :
     * aucune carte, aucun bouton « Pré-remplir », aucune donnée servie
     * au JS — le formulaire de création ORDINAIRE reste intact (cible,
     * action, tuiles/options cochables, bouton de soumission) et
     * toujours AUCUN jeton dans le HTML initial.
     */
    public function test_la_page_ne_rend_plus_les_presets(): void
    {
        // Un kiosque existant : son jeton ne doit JAMAIS transiter dans le
        // HTML non plus (comportement existant, vérifié au passage).
        $this->pdo->prepare(
            "INSERT INTO kiosques (id, name, token, pages, created_by) VALUES ('kq_presets_seed', 'Kiosque témoin', ?, '[\"jour\"]', 'test')"
        )->execute([bin2hex(random_bytes(20))]);

        $page = $this->request('GET', '/admin/kiosques', [], [], $this->rootId);
        $body = (string) ($page['body'] ?? '');
        self::assertSame(200, (int) ($page['code'] ?? 0), 'La page admin Kiosques doit répondre.');

        // Plus aucune carte preset, identifiée ou non.
        foreach (['tresorier', 'president', 'cafet'] as $key) {
            self::assertStringNotContainsString('data-kq-preset="' . $key . '"', $body, 'La carte preset « ' . $key . ' » ne doit plus être rendue.');
        }
        self::assertStringNotContainsString('kq-preset', $body, 'Ni le bloc .kq-presets ni une carte .kq-preset résiduelle ne doivent rester (CSS ou HTML).');
        self::assertStringNotContainsString('Pré-remplir', $body, 'Les boutons de pré-remplissage ont disparu avec les presets.');
        self::assertStringNotContainsString('var PRESETS =', $body, 'Les données de presets ne sont plus servies au script de la page.');
        self::assertStringNotContainsString('Pour le président', $body, 'Les descriptions de rôle des presets ne sont plus rendues.');
        self::assertStringNotContainsString('Pour le responsable cafétéria', $body, 'Les descriptions de rôle des presets ne sont plus rendues.');
        self::assertStringNotContainsString('ex. Trésorier', $body, 'Le champ nom n\'invite plus à nommer le kiosque d\'après un rôle de preset.');

        // Le formulaire de création ORDINAIRE reste le seul chemin, intact.
        self::assertStringContainsString('id="kq-create-form"', $body, 'Le formulaire de création reste identifié.');
        self::assertStringContainsString(url('/admin/kiosques/create'), $body, 'Le formulaire soumet bien vers le POST existant.');
        self::assertStringContainsString('id="kq-new-name"', $body, 'Le champ nom du formulaire de création est présent.');
        self::assertStringContainsString('name="pages[]"', $body, 'Les tuiles du catalogue restent cochables à la création.');
        self::assertStringContainsString('name="options[]"', $body, 'Les options du catalogue restent cochables à la création.');
        self::assertStringContainsString('Créer le kiosque', $body, 'Le bouton de soumission du formulaire reste présent.');

        // Sécurité inchangée : aucun jeton dans le HTML initial.
        $stmt = $this->pdo->query('SELECT token FROM kiosques');
        foreach ($stmt->fetchAll() as $row) {
            self::assertStringNotContainsString((string) $row['token'], $body, 'Aucun jeton de kiosque ne doit apparaître dans le HTML.');
        }
    }

    /**
     * Jeu « Président » (pages+options toujours définis par le
     * contrôleur, mais plus pré-remplis par la page) : le POST
     * ORDINAIRE accepte toujours ce payload → kiosque avec EXACTEMENT
     * les tuiles et options du preset, options effectives du jeton
     * conformes, audit tracé.
     */
    public function test_creation_du_jeu_president_via_le_post_ordinaire(): void
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

        // Payload du jeu Président (nom + tuiles + options définis par le
        // contrôleur), soumis au POST ORDINAIRE — le seul chemin d'écriture.
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
     * Les 3 jeux de preset produisent chacun un kiosque valide via le
     * POST ordinaire (au moins une tuile, options exactes du preset) —
     * la page ne les pré-remplit plus, le contrôleur les définit
     * toujours et le POST ordinaire les accepte toujours.
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
