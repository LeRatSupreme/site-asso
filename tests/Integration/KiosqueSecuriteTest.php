<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Mission §10 — sécurité des jetons de kiosque :
 *
 *  - TOUTES les pages à jeton (/kiosque/* — HTML, JSON et refus 403 —
 *    ainsi que /admin/kiosques et les pages admin qui affichent un lien
 *    à copier) répondent « Cache-Control: no-store, max-age=0 » +
 *    « Pragma: no-cache » (réponse jamais mémorisable) ;
 *  - /admin/kiosques ne contient AUCUN jeton dans le HTML initial :
 *    liens masqués (« •••••••• »), révélation à la demande ;
 *  - POST /admin/kiosques/reveal (CSRF + garde Système) renvoie l'URL
 *    complète et audite « kiosques.reveal » SANS la valeur du jeton ;
 *  - sans CSRF (ou avec un CSRF invalide) → refus 403, jeton absent.
 *
 * Jetons posés directement en settings (même mécanique que
 * KiosqueAffichageTest / ComptaConsistencyTest). Base `aeic_test`
 * requise (les tests sont sautés sinon).
 */
final class KiosqueSecuriteTest extends IntegrationTestCase
{
    private string $rootId       = 'u_kiosq_sec_root';
    private string $tokenMembres = 'jetonsec-membres-2026';
    private string $tokenAdmin   = 'jetonsec-admin-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset([
            'users',
            'sales',
            'inventory_counts',
            'audit_logs',
        ]);
        $this->seedUser($this->rootId, 'kiosq-sec-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Jetons kiosque « membres » et « admin » posés en settings.
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (id, `key`, value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute(['set_kiosq_sec_m', 'reappro_kiosk_token', $this->tokenMembres]);
        $stmt->execute(['set_kiosq_sec_a', 'admin_kiosk_token', $this->tokenAdmin]);
    }

    /**
     * L'en-tête attendu est-il présent dans la réponse ?
     *
     * @param array<string,mixed> $response
     */
    private function hasHeader(array $response, string $header): bool
    {
        foreach ($response['headers'] ?? [] as $h) {
            if (strcasecmp((string) $h, $header) === 0) {
                return true;
            }
        }

        return false;
    }

    public function test_les_pages_a_jeton_portent_no_store(): void
    {
        $cc = 'Cache-Control: no-store, max-age=0';

        $pages = [
            'kiosque hub comptage'      => ['/kiosque/comptage/' . $this->tokenMembres, '', 200],
            'kiosque liste'             => ['/kiosque/liste/' . $this->tokenMembres, '', 200],
            'kiosque hub admin'         => ['/kiosque/admin/' . $this->tokenAdmin, '', 200],
            'kiosque réappro (admin)'   => ['/kiosque/reappro/' . $this->tokenAdmin, '', 200],
            'kiosque JSON jour'         => ['/kiosque/comptage/jour-membre/data/' . $this->tokenMembres, '', 200],
            'kiosque mauvais jeton'     => ['/kiosque/comptage/mauvais-jeton', '', 403],
            'admin kiosques'            => ['/admin/kiosques', $this->rootId, 200],
            'admin réappro (copie lien)' => ['/admin/compta/reappro', $this->rootId, 200],
            'admin liste (copie lien)'  => ['/admin/compta/liste', $this->rootId, 200],
        ];

        foreach ($pages as $nom => [$path, $userId, $codeAttendu]) {
            $r = $this->request('GET', $path, [], [], $userId);

            self::assertSame(
                $codeAttendu,
                (int) ($r['code'] ?? 0),
                sprintf('%s : code HTTP inattendu.', $nom)
            );
            self::assertTrue(
                $this->hasHeader($r, $cc),
                sprintf('%s : « Cache-Control: no-store, max-age=0 » absent.', $nom)
            );
            self::assertTrue(
                $this->hasHeader($r, 'Pragma: no-cache'),
                sprintf('%s : « Pragma: no-cache » absent.', $nom)
            );
        }
    }

    public function test_admin_kiosques_ne_contient_pas_les_jetons_en_clair(): void
    {
        $r    = $this->request('GET', '/admin/kiosques', [], [], $this->rootId);
        $body = (string) ($r['body'] ?? '');

        self::assertSame(200, (int) ($r['code'] ?? 0));

        // Aucun jeton en clair ni URL à jeton dans le HTML initial.
        self::assertStringNotContainsString($this->tokenMembres, $body, 'Le jeton membres ne doit pas apparaître dans le HTML.');
        self::assertStringNotContainsString($this->tokenAdmin, $body, 'Le jeton admin ne doit pas apparaître dans le HTML.');
        self::assertStringNotContainsString('/kiosque/comptage/' . $this->tokenMembres, $body, 'Aucune URL à jeton en clair.');
        self::assertStringNotContainsString('/kiosque/admin/' . $this->tokenAdmin, $body, 'Aucune URL à jeton admin en clair.');

        // Liens masqués + révélation à la demande en place.
        self::assertStringContainsString('••••••••', $body, 'Les liens doivent être masqués par défaut.');
        self::assertStringContainsString('Afficher le lien', $body, 'Le bouton de révélation doit être présent.');
        self::assertStringContainsString('kiosques/reveal', $body, 'Le JS de révélation doit viser l\'endpoint reveal.');
        self::assertStringContainsString('data-kq-path="/kiosque/comptage/"', $body, 'Les cartes kiosque portent leur chemin (pas le jeton).');

        // La copie reste possible (usage légitime de l'admin) et la
        // régénération garde sa confirmation.
        self::assertStringContainsString('Copier', $body, 'Le bouton Copier doit rester.');
        self::assertStringContainsString('data-confirm', $body, 'La confirmation de régénération doit rester.');
    }

    /**
     * Lot suivi (docs/refonte-limites.md §16) : les encarts « accès
     * téléphone » des pages Réappro, Liste, Caisse et Comptage
     * inventaire utilisent le même masquage que /admin/kiosques — le
     * HTML initial ne contient plus AUCUN jeton, la révélation passe
     * par le POST CSRF audité (partial _kiosk_link.php).
     */
    public function test_encarts_kiosque_des_pages_admin_masques(): void
    {
        $pages = [
            'admin réappro'            => ['/admin/compta/reappro', '/kiosque/reappro/'],
            'admin liste'              => ['/admin/compta/liste', '/kiosque/liste/'],
            'admin caisse'             => ['/admin/compta/caisse', '/kiosque/comptage/caisse/'],
            'admin comptage inventaire' => ['/admin/compta/inventaire/comptage', '/kiosque/comptage/inventaire/'],
        ];

        foreach ($pages as $nom => [$path, $kioskPath]) {
            $r    = $this->request('GET', $path, [], [], $this->rootId);
            $body = (string) ($r['body'] ?? '');

            self::assertSame(200, (int) ($r['code'] ?? 0), sprintf('%s : code HTTP inattendu.', $nom));
            self::assertStringNotContainsString($this->tokenMembres, $body, sprintf('%s : le jeton membres ne doit pas apparaître dans le HTML.', $nom));
            self::assertStringNotContainsString($this->tokenAdmin, $body, sprintf('%s : le jeton admin ne doit pas apparaître dans le HTML.', $nom));
            self::assertStringContainsString('••••••••', $body, sprintf('%s : le lien kiosque doit être masqué.', $nom));
            self::assertStringContainsString('Afficher le lien', $body, sprintf('%s : le bouton de révélation doit être présent.', $nom));
            self::assertStringContainsString('data-kl-path="' . $kioskPath . '"', $body, sprintf('%s : l\'encart porte le chemin kiosque attendu (pas le jeton).', $nom));
        }
    }

    public function test_reveal_renvoie_l_url_complete_avec_csrf_valide(): void
    {
        // 1. Récupère le CSRF de la page (même session).
        $page = $this->request('GET', '/admin/kiosques', [], [], $this->rootId);
        $html = (string) ($page['body'] ?? '');
        self::assertSame(200, (int) ($page['code'] ?? 0));
        self::assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $html, $m), 'CSRF absent de la page.');
        $csrf = (string) $m[1];

        // 2. Révélation du lien membres (hub comptage).
        $r1    = $this->request('POST', '/admin/kiosques/reveal', [
            '_csrf' => $csrf,
            'scope' => 'membres',
            'path'  => '/kiosque/comptage/',
        ], [], $this->rootId);
        $json1 = json_decode((string) ($r1['body'] ?? ''), true);

        self::assertSame(200, (int) ($r1['code'] ?? 0), 'La révélation avec CSRF valide doit réussir.');
        self::assertIsArray($json1);
        self::assertTrue((bool) ($json1['ok'] ?? false), 'La réponse reveal doit être ok.');
        $url1 = (string) ($json1['url'] ?? '');
        self::assertStringContainsString('/kiosque/comptage/', $url1, 'L\'URL révélée doit pointer sur la page demandée.');
        self::assertStringContainsString($this->tokenMembres, $url1, 'L\'URL révélée doit contenir le jeton membres.');

        // 3. Révélation du lien admin (hub admin).
        $r2    = $this->request('POST', '/admin/kiosques/reveal', [
            '_csrf' => $csrf,
            'scope' => 'admins',
            'path'  => '/kiosque/admin/',
        ], [], $this->rootId);
        $json2 = json_decode((string) ($r2['body'] ?? ''), true);

        self::assertSame(200, (int) ($r2['code'] ?? 0));
        self::assertIsArray($json2);
        self::assertTrue((bool) ($json2['ok'] ?? false));
        self::assertStringContainsString('/kiosque/admin/', (string) ($json2['url'] ?? ''));
        self::assertStringContainsString($this->tokenAdmin, (string) ($json2['url'] ?? ''), 'Le hub admin doit révéler le jeton admin.');

        // 4. Chemin hors liste blanche : refus.
        $r3    = $this->request('POST', '/admin/kiosques/reveal', [
            '_csrf' => $csrf,
            'scope' => 'membres',
            'path'  => '/kiosque/admin/',
        ], [], $this->rootId);
        self::assertSame(404, (int) ($r3['code'] ?? 0), 'Un couple scope/path incohérent doit être refusé.');

        // 5. Audit « kiosques.reveal » présent, SANS la valeur des jetons.
        $stmt = $this->pdo->query("SELECT details FROM audit_logs WHERE action = 'kiosques.reveal'");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll();
        self::assertCount(2, $rows, 'Chaque révélation réussie doit être auditée.');
        foreach ($rows as $row) {
            $details = (string) $row['details'];
            self::assertStringNotContainsString($this->tokenMembres, $details, 'L\'audit ne doit jamais contenir le jeton.');
            self::assertStringNotContainsString($this->tokenAdmin, $details, 'L\'audit ne doit jamais contenir le jeton.');
            self::assertStringContainsString('page', $details, 'L\'audit doit tracer la page révélée.');
        }
    }

    public function test_reveal_refuse_sans_csrf_ou_avec_csrf_invalide(): void
    {
        // Sans CSRF : refus 403 (vérification explicite du contrôleur —
        // le court-circuit APP_TESTING du routeur ne s'applique pas ici).
        $r1    = $this->request('POST', '/admin/kiosques/reveal', [
            'scope' => 'membres',
            'path'  => '/kiosque/comptage/',
        ], [], $this->rootId);
        $body1 = (string) ($r1['body'] ?? '');

        self::assertSame(403, (int) ($r1['code'] ?? 0), 'La révélation sans CSRF doit être refusée.');
        self::assertStringNotContainsString($this->tokenMembres, $body1, 'Aucun jeton dans un refus.');
        self::assertStringNotContainsString('/kiosque/comptage/' . $this->tokenMembres, $body1, 'Aucune URL à jeton dans un refus.');

        // Avec un CSRF invalide : refus 403 également.
        $r2 = $this->request('POST', '/admin/kiosques/reveal', [
            '_csrf' => 'jeton-csrf-fabrique',
            'scope' => 'admins',
            'path'  => '/kiosque/admin/',
        ], [], $this->rootId);

        self::assertSame(403, (int) ($r2['code'] ?? 0), 'La révélation avec un CSRF invalide doit être refusée.');
        self::assertStringNotContainsString($this->tokenAdmin, (string) ($r2['body'] ?? ''), 'Aucun jeton dans un refus.');

        // Rien n'a été audité comme révélation.
        $stmt = $this->pdo->query("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'kiosques.reveal'");
        self::assertNotFalse($stmt);
        $row = $stmt->fetch();
        self::assertSame(0, (int) ($row['n'] ?? -1), 'Un refus ne doit pas être audité comme une révélation.');
    }
}
