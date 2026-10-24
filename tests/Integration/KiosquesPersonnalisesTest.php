<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Kiosque;
use App\Models\Model;

/**
 * Kiosques personnalisés (vague 3 — durcissement + tests) :
 *
 *  - création depuis la page admin (POST /admin/kiosques/create) : flash
 *    de succès, ligne en base avec les tuiles triées selon le catalogue,
 *    et AUCUN jeton (ni custom ni legacy) dans le HTML de la page ;
 *  - hub personnalisé GET /kiosque/hub/{token} : rend uniquement les
 *    tuiles choisies, jeton inconnu → 403 ;
 *  - un jeton personnalisé n'ouvre QUE les pages cochées (et l'édition
 *    du kiosque élargit/restreint l'accès à chaud) ;
 *  - les jetons legacy (membres/admin) conservent exactement leurs accès
 *    (verrouille l'ordre membres → admin → custom de KioskPages) ;
 *  - reveal scope=custom : CSRF obligatoire, URL hub révélée, audit sans
 *    la valeur du jeton ;
 *  - régénération individuelle (l'ancien lien meurt) et suppression ;
 *  - validations : nom vide ou tuile inconnue refusés, rien en base.
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class KiosquesPersonnalisesTest extends IntegrationTestCase
{
    private string $rootId       = 'u_kqpers_root';
    private string $tokenMembres = 'jetonkqp-membres';
    private string $tokenAdmin   = 'jetonkqp-admin';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->reset([
            'kiosques',
            'users',
            'audit_logs',
            'sales',
            'inventory_counts',
            'product_costs',
            'product_discontinued',
            'product_infinite',
            'product_packs',
        ]);
        $this->seedUser($this->rootId, 'kqpers-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Jetons kiosque « membres » et « admin » legacy posés en settings.
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (id, `key`, value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute(['set_kqpers_m', 'reappro_kiosk_token', $this->tokenMembres]);
        $stmt->execute(['set_kqpers_a', 'admin_kiosk_token', $this->tokenAdmin]);

        // Les kiosques sont créés/modifiés en process (Kiosque::create/update) :
        // le modèle est branché sur la même base que les requêtes HTTP.
        Model::setTestPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        parent::tearDown();
    }

    /**
     * Récupère le CSRF de la page admin Kiosques (même session que le POST
     * qui suivra, grâce au chaînage de session du runner).
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
     * Nombre de kiosques personnalisés en base.
     */
    private function kiosquesCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM kiosques')->fetchColumn();
    }

    /**
     * Détecte les marqueurs d'erreur dans un corps de page rendu.
     */
    private function bodyError(string $body): ?string
    {
        $marqueurs = ['Erreur 500', 'Fatal error', 'Parse error', 'Warning:', 'Deprecated:', 'Undefined variable', 'Undefined array key'];

        foreach ($marqueurs as $marqueur) {
            if (str_contains($body, $marqueur)) {
                return $marqueur;
            }
        }

        return null;
    }

    /**
     * Création d'un kiosque depuis la page admin : flash de succès, une
     * ligne en base avec les tuiles triées selon le catalogue, et la page
     * admin n'affiche AUCUN jeton (ni custom, ni legacy).
     */
    public function test_creation_kiosque_depuis_la_page_admin(): void
    {
        $csrf = $this->csrfFromAdminPage();

        $r = $this->request('POST', '/admin/kiosques/create', [
            '_csrf' => $csrf,
            'name'  => 'Trésorier',
            'pages' => ['jour', 'ledger', 'depot'],
        ], [], $this->rootId);

        $flash = $this->lastFlash($r);
        self::assertNotNull($flash, 'La création doit produire un flash.');
        self::assertSame('success', $flash['type'], 'La création doit produire un flash de succès.');
        self::assertStringContainsString('Trésorier', (string) $flash['message'], 'Le flash de succès cite le nom du kiosque.');

        // Une seule ligne en base, tuiles triées selon l'ordre du catalogue.
        self::assertSame(1, $this->kiosquesCount(), 'La création doit insérer exactement une ligne dans kiosques.');
        $row = $this->pdo->query('SELECT id, name, token, pages FROM kiosques')->fetch();
        self::assertIsArray($row);
        self::assertSame('Trésorier', (string) $row['name'], 'Le nom est stocké tel que saisi.');
        self::assertSame(
            ['jour', 'ledger', 'depot'],
            json_decode((string) $row['pages'], true),
            'Les tuiles sont stockées dans l\'ordre du catalogue.'
        );

        // La page admin liste le kiosque sans jamais exposer de jeton.
        $page = $this->request('GET', '/admin/kiosques', [], [], $this->rootId);
        $body = (string) ($page['body'] ?? '');
        self::assertSame(200, (int) ($page['code'] ?? 0), 'La page admin Kiosques doit répondre après création.');
        self::assertStringContainsString('Kiosques personnalisés', $body, 'La section des kiosques personnalisés est rendue.');
        self::assertStringContainsString('Trésorier', $body, 'Le kiosque créé apparaît dans la page.');
        self::assertStringNotContainsString((string) $row['token'], $body, 'Le jeton du kiosque ne doit JAMAIS apparaître dans le HTML.');
        self::assertStringNotContainsString($this->tokenMembres, $body, 'Le jeton legacy membres ne doit pas apparaître dans le HTML.');
        self::assertStringNotContainsString($this->tokenAdmin, $body, 'Le jeton legacy admin ne doit pas apparaître dans le HTML.');
    }

    /**
     * Le hub personnalisé rend uniquement les tuiles choisies ; un jeton
     * inconnu reçoit 403 ; aucune page kiosque ne fuit d'erreur PHP.
     */
    public function test_hub_personnalise_rend_les_tuiles_choisies(): void
    {
        $id = Kiosque::create('Bar du kiosque', ['jour', 'liste']);
        $k  = Kiosque::find($id);
        self::assertIsArray($k, 'Le kiosque créé doit être relu en base.');

        $r    = $this->request('GET', '/kiosque/hub/' . $k['token']);
        $body = (string) ($r['body'] ?? '');
        self::assertSame(200, (int) ($r['code'] ?? 0), 'Le hub personnalisé doit répondre 200.');
        self::assertStringContainsString('Bar du kiosque', $body, 'Le hub affiche le nom du kiosque.');
        self::assertStringContainsString('Récap du jour', $body, 'La tuile « jour » cochée est rendue.');
        self::assertStringContainsString('Liste de courses', $body, 'La tuile « liste » cochée est rendue.');
        self::assertStringNotContainsString('Livre comptable', $body, 'La tuile « ledger » non cochée ne doit pas être rendue.');
        self::assertNull($this->bodyError($body), 'Le hub doit se rendre sans erreur PHP.');

        $r403 = $this->request('GET', '/kiosque/hub/jeton-inconnu');
        self::assertSame(403, (int) ($r403['code'] ?? 0), 'Un jeton inconnu doit recevoir 403 sur le hub personnalisé.');
    }

    /**
     * Variantes « lite » vs « complètes » des tuiles Réappro/Liste :
     * un kiosque limité aux ids « reappro-lite » / « liste-lite » ouvre
     * la MÊME URL que sa page mère (même garde tokenAllows, via
     * accessId) mais en variante allégée — AUCUN chiffre financier —
     * tandis que les ids pleins (« reappro » / « liste ») affichent les
     * coûts. La garde reste par page : un kiosque « reappro(-lite) »
     * n'ouvre PAS /kiosque/liste/, et inversement.
     *
     * Marqueurs repris tels quels de KiosqueAffichageTest : côté allégé,
     * ni « € » ni « Coût » ; côté complet, « € », « Coût ligne » et
     * « Coût estimé du panier » (réappro) / « € » (liste).
     */
    public function test_variantes_reappro_et_liste_selon_la_tuile_choisie(): void
    {
        // Un produit commandable avec un coût connu (même mécanique que
        // KiosqueAffichageTest) : les variantes complètes ont un montant
        // à afficher, les variantes lite doivent le masquer.
        for ($i = 1; $i <= 3; $i++) {
            $this->pdo->prepare(
                'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, payment_raw, quantity, description, price_ttc, is_custom_amount, category)
                 VALUES (?,?,?,?,?,1,?,?,0,?)'
            )->execute([
                's_kqpers_var_' . $i,
                'TKQPVAR0' . $i,
                date('Y-m-d ') . sprintf('09:0%d:00', $i),
                'CARTE',
                'Visa - Débit',
                'colakqpersvar',
                '1.50',
                'Boissons',
            ]);
        }
        $this->pdo->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute(['inv_kqpers_var', '2026-01-01 08:00:00', 'colakqpersvar', 0, 0, 0, 'test']);
        $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)'
        )->execute(['pc_kqpers_var', 'colakqpersvar', '1.500', '2026-01-01']);
        $this->pdo->prepare(
            'INSERT INTO product_packs (product_key, pack_size, updated_by) VALUES (?,?,?)'
        )->execute(['colakqpersvar', 12, 'test']);

        // Quatre kiosques : variantes lite et complètes de Réappro/Liste.
        // Les jetons sont relus en base via find() (comme dans les autres
        // tests de cette classe).
        $kA = Kiosque::find(Kiosque::create('Réappro lite', ['reappro-lite'], 'test'));
        $kB = Kiosque::find(Kiosque::create('Réappro full', ['reappro'], 'test'));
        $kC = Kiosque::find(Kiosque::create('Liste lite', ['liste-lite'], 'test'));
        $kD = Kiosque::find(Kiosque::create('Liste full', ['liste'], 'test'));
        self::assertIsArray($kA, 'Le kiosque « Réappro lite » doit être relu en base.');
        self::assertIsArray($kB, 'Le kiosque « Réappro full » doit être relu en base.');
        self::assertIsArray($kC, 'Le kiosque « Liste lite » doit être relu en base.');
        self::assertIsArray($kD, 'Le kiosque « Liste full » doit être relu en base.');
        $tokenA = $kA['token'];
        $tokenB = $kB['token'];
        $tokenC = $kC['token'];
        $tokenD = $kD['token'];

        // A — « reappro-lite » : 200 sur /kiosque/reappro/, lignes produits
        // visibles, mais AUCUN marqueur de coût.
        $rA    = $this->request('GET', '/kiosque/reappro/' . $tokenA);
        $bodyA = (string) ($rA['body'] ?? '');
        self::assertSame(200, (int) ($rA['code'] ?? 0), 'La variante « reappro-lite » doit répondre 200 sur /kiosque/reappro/.');
        self::assertStringContainsString('data-name="colakqpersvar"', $bodyA, 'La variante lite affiche quand même les lignes produits.');
        foreach (['€', 'Coût'] as $interdit) {
            self::assertStringNotContainsString($interdit, $bodyA, sprintf('Réappro lite : « %s » ne doit pas apparaître.', $interdit));
        }
        self::assertNull($this->bodyError($bodyA), 'La variante « reappro-lite » doit se rendre sans erreur PHP.');

        // B — « reappro » : 200 sur /kiosque/reappro/ AVEC les coûts.
        $rB    = $this->request('GET', '/kiosque/reappro/' . $tokenB);
        $bodyB = (string) ($rB['body'] ?? '');
        self::assertSame(200, (int) ($rB['code'] ?? 0), 'La variante « reappro » complète doit répondre 200.');
        self::assertStringContainsString('€', $bodyB, 'Réappro complet : les coûts sont visibles.');
        self::assertStringContainsString('Coût ligne', $bodyB, 'Réappro complet : la colonne « Coût ligne » est visible.');
        self::assertStringContainsString('Coût estimé du panier', $bodyB, 'Réappro complet : le KPI panier est visible.');
        self::assertNull($this->bodyError($bodyB), 'La variante « reappro » complète doit se rendre sans erreur PHP.');

        // C — « liste-lite » : 200 sur /kiosque/liste/, tuile produit
        // visible, AUCUN marqueur de coût.
        $rC    = $this->request('GET', '/kiosque/liste/' . $tokenC);
        $bodyC = (string) ($rC['body'] ?? '');
        self::assertSame(200, (int) ($rC['code'] ?? 0), 'La variante « liste-lite » doit répondre 200 sur /kiosque/liste/.');
        self::assertStringContainsString('data-key="colakqpersvar"', $bodyC, 'La variante lite affiche quand même la tuile produit.');
        foreach (['€', 'Coût'] as $interdit) {
            self::assertStringNotContainsString($interdit, $bodyC, sprintf('Liste lite : « %s » ne doit pas apparaître.', $interdit));
        }
        self::assertNull($this->bodyError($bodyC), 'La variante « liste-lite » doit se rendre sans erreur PHP.');

        // D — « liste » : 200 sur /kiosque/liste/ AVEC les coûts.
        $rD    = $this->request('GET', '/kiosque/liste/' . $tokenD);
        $bodyD = (string) ($rD['body'] ?? '');
        self::assertSame(200, (int) ($rD['code'] ?? 0), 'La variante « liste » complète doit répondre 200.');
        self::assertStringContainsString('€', $bodyD, 'Liste complète : le coût estimé du panier est visible.');
        self::assertNull($this->bodyError($bodyD), 'La variante « liste » complète doit se rendre sans erreur PHP.');

        // Garde par page : la variante lite n'ouvre que SON URL, jamais
        // celle de la page sœur.
        $rGardeListe = $this->request('GET', '/kiosque/liste/' . $tokenA);
        self::assertSame(403, (int) ($rGardeListe['code'] ?? 0), 'Un kiosque limité à « reappro-lite » n\'ouvre PAS /kiosque/liste/.');
        $rGardeReappro = $this->request('GET', '/kiosque/reappro/' . $tokenC);
        self::assertSame(403, (int) ($rGardeReappro['code'] ?? 0), 'Un kiosque limité à « liste-lite » n\'ouvre PAS /kiosque/reappro/.');
    }

    /**
     * Un jeton personnalisé n'ouvre que les pages choisies : l'édition du
     * kiosque (update) élargit/restreint l'accès à chaud.
     */
    public function test_jeton_personnalise_n_ouvre_que_les_pages_choisies(): void
    {
        $id    = Kiosque::create('Kiosque minimal', ['jour']);
        $k     = Kiosque::find($id);
        self::assertIsArray($k);
        $token = $k['token'];

        $r1    = $this->request('GET', '/kiosque/comptage/jour/' . $token);
        $body1 = (string) ($r1['body'] ?? '');
        self::assertSame(200, (int) ($r1['code'] ?? 0), 'La tuile cochée « jour » doit être accessible au jeton du kiosque.');
        self::assertStringContainsString('Récap du jour', $body1, 'La page « jour » est rendue pour le kiosque.');
        self::assertNull($this->bodyError($body1), 'La page « jour » doit se rendre sans erreur PHP.');

        $r2 = $this->request('GET', '/kiosque/admin/ledger/' . $token);
        self::assertSame(403, (int) ($r2['code'] ?? 0), 'La tuile non cochée « ledger » doit être refusée (403).');

        $r3 = $this->request('GET', '/kiosque/admin/analytics/' . $token);
        self::assertSame(403, (int) ($r3['code'] ?? 0), 'La tuile non cochée « analytics » doit être refusée (403).');

        // Élargissement : le même jeton ouvre désormais le livre comptable,
        // mais JAMAIS l'analytique (toujours hors liste).
        self::assertTrue(
            Kiosque::update($id, 'Kiosque minimal', ['jour', 'ledger']),
            'La mise à jour des tuiles du kiosque doit réussir.'
        );

        $r4    = $this->request('GET', '/kiosque/admin/ledger/' . $token);
        $body4 = (string) ($r4['body'] ?? '');
        self::assertSame(200, (int) ($r4['code'] ?? 0), 'Après édition, la tuile « ledger » devient accessible.');
        self::assertNull($this->bodyError($body4), 'Le livre comptable kiosque doit se rendre sans erreur PHP.');

        $r5 = $this->request('GET', '/kiosque/admin/analytics/' . $token);
        self::assertSame(403, (int) ($r5['code'] ?? 0), 'La tuile « analytics » reste refusée après édition.');
    }

    /**
     * Les jetons legacy conservent exactement leurs accès (verrouille
     * l'ordre membres → admin → custom de KioskPages::tokenAllows) :
     * le jeton admin ouvre les pages financières, le jeton membres les
     * pages sans chiffre sensible, et jamais l'inverse.
     */
    public function test_les_jetons_legacy_conservent_leurs_acces(): void
    {
        $r1 = $this->request('GET', '/kiosque/admin/ledger/' . $this->tokenAdmin);
        self::assertSame(200, (int) ($r1['code'] ?? 0), 'Le jeton ADMIN legacy doit garder accès au livre comptable.');

        $r2 = $this->request('GET', '/kiosque/comptage/jour-membre/' . $this->tokenMembres);
        self::assertSame(200, (int) ($r2['code'] ?? 0), 'Le jeton MEMBRES legacy doit garder accès aux ventes du jour.');

        $r3 = $this->request('GET', '/kiosque/admin/ledger/' . $this->tokenMembres);
        self::assertSame(403, (int) ($r3['code'] ?? 0), 'Le jeton MEMBRES n\'ouvre PAS le livre comptable.');

        $r4 = $this->request('GET', '/kiosque/comptage/jour-membre/' . $this->tokenAdmin);
        self::assertSame(403, (int) ($r4['code'] ?? 0), 'Le jeton ADMIN n\'ouvre PAS la page membres.');
    }

    /**
     * Reveal d'un lien kiosque personnalisé : CSRF obligatoire, URL hub
     * construite à la demande (jamais dans le HTML), audit SANS la valeur
     * du jeton ; id inconnu → 404.
     */
    public function test_reveal_personnalise_avec_csrf_et_refus_sans(): void
    {
        $id    = Kiosque::create('Kiosque révélé', ['jour']);
        $k     = Kiosque::find($id);
        self::assertIsArray($k);
        $token = $k['token'];

        $csrf = $this->csrfFromAdminPage();

        // 1. Reveal avec CSRF : URL hub complète, contenant le jeton en base.
        $r1   = $this->request('POST', '/admin/kiosques/reveal', [
            '_csrf' => $csrf,
            'scope' => 'custom',
            'id'    => $id,
        ], [], $this->rootId);
        $json = json_decode((string) ($r1['body'] ?? ''), true);

        self::assertSame(200, (int) ($r1['code'] ?? 0), 'Le reveal custom avec CSRF doit réussir.');
        self::assertIsArray($json);
        self::assertTrue((bool) ($json['ok'] ?? false), 'Le reveal custom doit répondre ok:true.');
        $url = (string) ($json['url'] ?? '');
        self::assertStringContainsString('/kiosque/hub/', $url, 'L\'URL révélée pointe sur le hub personnalisé.');
        self::assertStringContainsString($token, $url, 'L\'URL révélée contient le jeton en base.');

        // 2. Sans CSRF : refus 403 (vérification explicite du contrôleur).
        $r2 = $this->request('POST', '/admin/kiosques/reveal', [
            'scope' => 'custom',
            'id'    => $id,
        ], [], $this->rootId);
        self::assertSame(403, (int) ($r2['code'] ?? 0), 'Le reveal custom sans CSRF doit être refusé.');
        self::assertStringNotContainsString($token, (string) ($r2['body'] ?? ''), 'Aucun jeton dans un refus de reveal.');

        // 3. Id inconnu avec CSRF valide : 404.
        $r3 = $this->request('POST', '/admin/kiosques/reveal', [
            '_csrf' => $csrf,
            'scope' => 'custom',
            'id'    => 'kq_inconnu',
        ], [], $this->rootId);
        self::assertSame(404, (int) ($r3['code'] ?? 0), 'Un id de kiosque inconnu doit recevoir 404.');

        // 4. Audit « kiosques.reveal » présent, SANS la valeur du jeton.
        $stmt = $this->pdo->query("SELECT details FROM audit_logs WHERE action = 'kiosques.reveal'");
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll();
        self::assertCount(1, $rows, 'Seule la révélation réussie doit être auditée.');
        $details = (string) $rows[0]['details'];
        self::assertStringNotContainsString($token, $details, 'L\'audit ne doit JAMAIS contenir la valeur du jeton.');
        self::assertStringContainsString('hub', $details, 'L\'audit trace la page révélée.');
    }

    /**
     * Régénération individuelle du jeton (l'ancien lien meurt, le nouveau
     * fonctionne), puis suppression (le lien régénéré meurt aussi, le
     * kiosque quitte la page admin).
     */
    public function test_regenerer_et_supprimer_un_kiosque(): void
    {
        $id       = Kiosque::create('Kiosque éphémère', ['jour']);
        $k        = Kiosque::find($id);
        self::assertIsArray($k);
        $oldToken = $k['token'];

        $csrf = $this->csrfFromAdminPage();

        // 1. Régénération : flash de succès, nouveau jeton ≠ ancien.
        $r1 = $this->request('POST', '/admin/kiosques/regenerate', [
            '_csrf' => $csrf,
            'id'    => $id,
        ], [], $this->rootId);
        $flash1 = $this->lastFlash($r1);
        self::assertNotNull($flash1, 'La régénération doit produire un flash.');
        self::assertSame('success', $flash1['type'], 'La régénération doit produire un flash de succès.');

        $newTokenRow = $this->pdo->prepare('SELECT token FROM kiosques WHERE id = ?');
        $newTokenRow->execute([$id]);
        $newToken = (string) $newTokenRow->fetchColumn();
        self::assertNotSame('', $newToken, 'Le kiosque existe toujours après régénération.');
        self::assertNotSame($oldToken, $newToken, 'La régénération doit produire un NOUVEAU jeton.');

        // 2. L'ancien lien est mort, le nouveau fonctionne.
        $rOld = $this->request('GET', '/kiosque/hub/' . $oldToken);
        self::assertSame(403, (int) ($rOld['code'] ?? 0), 'L\'ancien jeton ne doit plus donner accès au hub.');

        $rNew = $this->request('GET', '/kiosque/hub/' . $newToken);
        self::assertSame(200, (int) ($rNew['code'] ?? 0), 'Le nouveau jeton doit donner accès au hub.');

        // 3. Suppression : flash de succès, le lien régénéré meurt à son tour.
        $r2 = $this->request('POST', '/admin/kiosques/' . rawurlencode($id) . '/delete', [
            '_csrf' => $csrf,
        ], [], $this->rootId);
        $flash2 = $this->lastFlash($r2);
        self::assertNotNull($flash2, 'La suppression doit produire un flash.');
        self::assertSame('success', $flash2['type'], 'La suppression doit produire un flash de succès.');

        $rGone = $this->request('GET', '/kiosque/hub/' . $newToken);
        self::assertSame(403, (int) ($rGone['code'] ?? 0), 'Après suppression, le lien du kiosque ne fonctionne plus.');

        // 4. Le nom quitte la page admin. La PREMIÈRE relecture consomme le
        //    flash de suppression (qui cite le nom) : l'assertion porte sur
        //    la page rendue UNE FOIS le flash consommé.
        $this->request('GET', '/admin/kiosques', [], [], $this->rootId);
        $page = $this->request('GET', '/admin/kiosques', [], [], $this->rootId);
        $body = (string) ($page['body'] ?? '');
        self::assertSame(200, (int) ($page['code'] ?? 0));
        self::assertStringNotContainsString('Kiosque éphémère', $body, 'Le kiosque supprimé ne doit plus apparaître dans la page admin.');
    }

    /**
     * Validations à la création : sans nom ou avec une tuile inconnue,
     * refus (flash error) et AUCUNE ligne en base.
     */
    public function test_nom_et_pages_invalides_refuses(): void
    {
        $csrf = $this->csrfFromAdminPage();

        // 1. Sans nom : refus, rien en base.
        $r1 = $this->request('POST', '/admin/kiosques/create', [
            '_csrf' => $csrf,
            'pages' => ['jour'],
        ], [], $this->rootId);
        $flash1 = $this->lastFlash($r1);
        self::assertNotNull($flash1, 'Un POST sans nom doit produire un flash.');
        self::assertSame('error', $flash1['type'], 'Un kiosque sans nom doit être refusé.');
        self::assertSame(0, $this->kiosquesCount(), 'Aucun kiosque ne doit être créé sans nom.');

        // 2. Tuile inexistante : refus, rien en base.
        $r2 = $this->request('POST', '/admin/kiosques/create', [
            '_csrf' => $csrf,
            'name'  => 'Sans tuile valide',
            'pages' => ['page-inexistante'],
        ], [], $this->rootId);
        $flash2 = $this->lastFlash($r2);
        self::assertNotNull($flash2, 'Un POST avec tuile inconnue doit produire un flash.');
        self::assertSame('error', $flash2['type'], 'Un kiosque sans tuile du catalogue doit être refusé.');
        self::assertSame(0, $this->kiosquesCount(), 'Aucun kiosque ne doit être créé avec une tuile inconnue.');
    }
}
