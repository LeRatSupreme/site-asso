<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Page Paramètres : une clé connue absente de la table `settings` doit
 * quand même être affichée avec sa valeur par défaut et pouvoir être
 * enregistrée (sinon impossible de l'activer via l'interface — cas du
 * mode maintenance sur une base installée avant l'ajout de la clé).
 */
final class SettingsPageTest extends IntegrationTestCase
{
    public function test_parametre_absent_de_la_base_est_affiche_et_enregistrable(): void
    {
        $pdo = $this->requireDatabase();
        $this->reset(['users', 'settings']);
        $this->seedUser('u_set_root', 'settings-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // La clé maintenance_mode n'existe pas en base…
        self::assertSame(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE `key` = 'maintenance_mode'")->fetchColumn()
        );

        // … la page Paramètres affiche quand même le champ…
        $r = $this->request('GET', '/admin/settings', [], [], 'u_set_root');
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');
        self::assertStringContainsString('Mode maintenance', $body, 'Le champ Mode maintenance doit être affiché même sans ligne en base.');

        // … et l'enregistrement crée la ligne avec la valeur choisie.
        $this->request('POST', '/admin/settings/save', [
            'settings' => ['maintenance_mode' => '1'],
        ], [], 'u_set_root');
        self::assertSame(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE `key` = 'maintenance_mode' AND value = '1'")->fetchColumn(),
            'L\'enregistrement doit créer la ligne maintenance_mode.'
        );
    }
}
