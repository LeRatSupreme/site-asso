<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Page « Journal d'audit » (/admin/audit) — remplace la carte de
 * l'ancien tableau de bord :
 *   - garde identique (Fondateur/ADMIN ; les autres rôles sont refoulés) ;
 *   - filtres GET (utilisateur, action, période) ;
 *   - pagination 50 entrées (liens précédent/suivant) ;
 *   - export CSV (pattern du journal des ventes).
 *
 * Les entrées sont seedées avec des DÉTAILS JSON à valeurs distinctives
 * : la vue décode le JSON et affiche les valeurs (jamais le JSON brut),
 * l'export CSV les restitue en « clé : valeur ».
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class AuditPageTest extends IntegrationTestCase
{
    private string $rootId = 'u_audit_root';
    private string $tresorId = 'u_audit_tresor';
    private string $otherId = 'u_audit_other';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset(['audit_logs', 'users']);
        $this->seedUser($this->rootId, 'audit-root@exemple.fr', 'Password123456', 'SUPERADMIN');
        $this->seedUser($this->tresorId, 'audit-tresor@exemple.fr', 'Password123456', 'TRESORERIE');
        $this->seedUser($this->otherId, 'audit-other@exemple.fr', 'Password123456', 'ADMIN');
    }

    /**
     * Insère une entrée d'audit avec horodatage contrôlé (l'ordre
     * d'affichage est created_at DESC).
     */
    private function insertAudit(string $id, ?string $userId, string $action, string $createdAt, string $details = ''): void
    {
        $this->pdo->prepare(
            'INSERT INTO audit_logs (id, user_id, action, details, created_at) VALUES (?,?,?,?,?)'
        )->execute([$id, $userId, $action, $details !== '' ? $details : null, $createdAt]);
    }

    public function test_page_accesible_au_fondateur(): void
    {
        $this->insertAudit('aud_p1', $this->otherId, 'user.role', '2026-10-20 10:00:00', '{"vers":"role-vers-admin"}');
        $this->insertAudit('aud_p2', null, 'cash.count', '2026-10-21 09:00:00');

        $r = $this->request('GET', '/admin/audit', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0), 'La page d\'audit doit répondre au Fondateur.');
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('Journal d', $body, 'Le titre « Journal d\'audit » est absent.');
        self::assertStringContainsString('<th>Date</th>', $body, 'La colonne Date est absente.');
        self::assertStringContainsString('<th>Qui</th>', $body, 'La colonne Qui est absente.');
        self::assertStringContainsString('<th>Action</th>', $body, 'La colonne Action est absente.');
        self::assertStringContainsString('<th>Détails</th>', $body, 'La colonne Détails est absente.');

        // Entrée liée à un compte + entrée de kiosque (user_id null).
        self::assertStringContainsString('role-vers-admin', $body, 'La valeur des détails (JSON décodé) est absente.');
        self::assertStringContainsString('kiosque', $body, 'Les entrées sans compte doivent afficher « kiosque ».');

        // Dates affichées en heure Europe/Paris (UTC+2 en octobre : 10:00 UTC → 12:00).
        self::assertStringContainsString('20/10/2026 12:00', $body, 'La date doit être convertie en heure de Paris.');
    }

    public function test_autres_roles_refoules(): void
    {
        // Garde de l'ancienne carte : Fondateur/ADMIN seulement. Un rôle
        // connecté non autorisé est redirigé (jamais de 500 ni de fuite).
        $r = $this->request('GET', '/admin/audit', [], [], $this->tresorId);
        self::assertSame(
            302,
            (int) ($r['code'] ?? 0),
            'Un rôle hors Fondateur/ADMIN doit être refoulé (redirection).'
        );
    }

    public function test_filtre_par_action(): void
    {
        $this->insertAudit('aud_f1', $this->otherId, 'user.role', '2026-10-20 10:00:00', '{"marque":"action-un"}');
        $this->insertAudit('aud_f2', $this->otherId, 'user.role', '2026-10-20 11:00:00', '{"marque":"action-deux"}');
        $this->insertAudit('aud_f3', $this->otherId, 'cash.count', '2026-10-20 12:00:00', '{"marque":"action-trois"}');

        $r = $this->request('GET', '/admin/audit?action=user.role', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('action-un', $body, 'La première entrée filtrée est absente.');
        self::assertStringContainsString('action-deux', $body, 'La seconde entrée filtrée est absente.');
        self::assertStringNotContainsString('action-trois', $body, 'Une entrée hors filtre apparaît à tort.');
    }

    public function test_filtre_par_utilisateur(): void
    {
        $this->insertAudit('aud_u1', $this->otherId, 'user.role', '2026-10-20 10:00:00', '{"marque":"compte-un"}');
        $this->insertAudit('aud_u2', $this->rootId, 'user.role', '2026-10-20 11:00:00', '{"marque":"compte-deux"}');

        $r = $this->request('GET', '/admin/audit?user_id=' . $this->otherId, [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('compte-un', $body, 'L\'entrée du compte filtré est absente.');
        self::assertStringNotContainsString('compte-deux', $body, 'Une entrée d\'un autre compte apparaît à tort.');
    }

    public function test_filtre_par_periode(): void
    {
        $this->insertAudit('aud_d1', $this->otherId, 'user.role', '2026-10-01 10:00:00', '{"marque":"periode-hors"}');
        $this->insertAudit('aud_d2', $this->otherId, 'user.role', '2026-10-20 10:00:00', '{"marque":"periode-dans"}');

        $r = $this->request('GET', '/admin/audit?from=2026-10-15&to=2026-10-25', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('periode-dans', $body, 'L\'entrée dans la période est absente.');
        self::assertStringNotContainsString('periode-hors', $body, 'Une entrée hors période apparaît à tort.');
    }

    public function test_pagination_50_par_page(): void
    {
        // 55 entrées, horodatées croissantes : la page 1 porte les 50 plus
        // récentes (p055 → p006), la page 2 les 5 plus anciennes (p005 → p001).
        for ($i = 1; $i <= 55; $i++) {
            $this->insertAudit(
                'aud_pg' . $i,
                $this->otherId,
                'user.role',
                date('Y-m-d H:i:s', strtotime('2026-10-20 08:00:00 +' . $i . ' minutes')),
                '{"marque":"p' . sprintf('%03d', $i) . '"}'
            );
        }

        $r1 = $this->request('GET', '/admin/audit', [], [], $this->rootId);
        self::assertSame(200, (int) ($r1['code'] ?? 0));
        $b1 = (string) ($r1['body'] ?? '');

        self::assertSame(50, substr_count($b1, '>p0'), 'La page 1 doit afficher exactement 50 entrées.');
        self::assertStringContainsString('Page suivante', $b1, 'Le lien « Page suivante » est absent.');
        self::assertStringNotContainsString('Page précédente', $b1, 'La page 1 ne doit pas proposer de page précédente.');
        self::assertStringNotContainsString('>p001<', $b1, 'La plus ancienne entrée ne doit pas être sur la page 1.');

        $r2 = $this->request('GET', '/admin/audit?page=2', [], [], $this->rootId);
        self::assertSame(200, (int) ($r2['code'] ?? 0));
        $b2 = (string) ($r2['body'] ?? '');

        self::assertSame(5, substr_count($b2, '>p0'), 'La page 2 doit afficher les 5 entrées restantes.');
        self::assertStringContainsString('>p001<', $b2, 'La plus ancienne entrée doit être sur la page 2.');
        self::assertStringContainsString('Page précédente', $b2, 'Le lien « Page précédente » est absent.');
        self::assertStringNotContainsString('Page suivante', $b2, 'La page 2 ne doit pas proposer de page suivante.');
    }

    public function test_export_csv(): void
    {
        $this->insertAudit('aud_c1', $this->otherId, 'user.role', '2026-10-20 10:00:00', '{"vers":"role-vers-admin"}');
        $this->insertAudit('aud_c2', null, 'cash.count', '2026-10-21 09:00:00');

        $r = $this->request('GET', '/admin/audit?export=csv', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        // Contenu CSV (pas une page HTML) : en-têtes puis lignes.
        // (fputcsv encadre certaines cellules : on asserte champ par champ.)
        self::assertStringContainsString('Date (Paris)', $body, 'L\'en-tête du CSV est absent.');
        self::assertStringContainsString(',Qui,Action,', $body, 'L\'en-tête du CSV est absent.');
        self::assertStringContainsString('user.role', $body, 'L\'action attendue est absente du CSV.');
        self::assertStringContainsString('vers : role-vers-admin', $body, 'Les détails JSON doivent être restitués « clé : valeur ».');
        self::assertStringContainsString('kiosque', $body, 'La mention « kiosque » est absente du CSV.');
        self::assertStringNotContainsString('<html', $body, 'La réponse doit être un CSV, pas une page HTML.');
    }

    public function test_export_csv_applique_les_filtres(): void
    {
        $this->insertAudit('aud_cf1', $this->otherId, 'user.role', '2026-10-20 10:00:00', '{"marque":"csv-hors"}');
        $this->insertAudit('aud_cf2', $this->otherId, 'cash.count', '2026-10-20 11:00:00', '{"marque":"csv-dans"}');

        $r = $this->request('GET', '/admin/audit?action=cash.count&export=csv', [], [], $this->rootId);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        $body = (string) ($r['body'] ?? '');

        self::assertStringContainsString('cash.count', $body, 'La ligne filtrée est absente du CSV.');
        self::assertStringContainsString('csv-dans', $body, 'La valeur de la ligne filtrée est absente du CSV.');
        self::assertStringNotContainsString('user.role', $body, 'Une ligne hors filtre apparaît dans le CSV.');
        self::assertStringNotContainsString('csv-hors', $body, 'Une valeur hors filtre apparaît dans le CSV.');
    }
}
