<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\RateLimiter;
use PDO;

/**
 * T4-E — endpoints OUTILS de l'assistant (/internal/assistant/*) :
 *
 *  - sans en-tête, mauvais jeton ou jeton non configuré → 403 générique
 *    {"erreur": "Accès refusé."} + réponse no-store ;
 *  - ventes_periode : agrégats, transactions et top produits conformes
 *    aux méthodes Sale::* sur les mêmes bornes (ventes hors période
 *    exclues), dates invalides → 400 ;
 *  - stock_produit : résolution par le livre de stock UNIQUE (exacte,
 *    normalisée, alias, variante couleur/goût) et candidats sinon ;
 *  - produits_a_commander : sortie = Data::analyseReappro sur la fenêtre
 *    par défaut de la page Réappro (14 derniers jours, horizon 7 j) ;
 *  - rate limit : 21e requête dans la minute → 429 ;
 *  - LECTURE SEULE : comptage de TOUTES les tables avant/après les 3
 *    outils strictement identique (aucune écriture base).
 *
 * Le jeton passe par ASSISTANT_TOKEN (env) et l'en-tête X-Assistant-Token
 * (support AEIC_TEST_HEADERS du runner) — jamais dans l'URL.
 */
final class AssistantToolsTest extends IntegrationTestCase
{
    private string $token = '3f9a1c7e5b2d4f6a8c0e2b4d6f8a1c3e5b7d9f1a2c4e';

    /** @var array<string,string> En-têtes HTTP de la prochaine requête. */
    private array $entetes = [];

    /** Valeur ASSISTANT_TOKEN exposée au sous-processus ('' = non configuré). */
    private string $jetonEnv = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireDatabase();
        $this->reset([
            'sales',
            'inventory_counts',
            'product_aliases',
            'purchases',
            'losses',
            'product_costs',
            'product_packs',
            'product_discontinued',
            'product_infinite',
        ]);

        $this->jetonEnv = $this->token;
        $this->entetes  = ['X-Assistant-Token' => $this->token];
    }

    protected function tearDown(): void
    {
        // Le compteur de rate limit vit dans cache/ratelimit — des fichiers
        // PARTAGÉS avec l'application live de ce même arbre : le pont de
        // l'assistant appelle les endpoints depuis 127.0.0.1, la même clé
        // que ce runner (REMOTE_ADDR forcé à 127.0.0.1). Sans ce nettoyage,
        // la suite laisse jusqu'à 21 tentatives fraîches qui font renvoyer
        // 429 aux VRAIES requêtes de l'assistant pendant 60 s — constaté en
        // E2E le 27/10/2026 (démo T4-I : 3 questions sur 5 en 429 juste
        // après un run --filter AssistantToolsTest). setUp() vide déjà tout
        // le répertoire avant CHAQUE test ; ici on efface après, uniquement
        // la clé assistant (jamais les compteurs d'autres clés live).
        (new RateLimiter())->clear('assistant:127.0.0.1');

        parent::tearDown();
    }

    protected function extraEnv(): array
    {
        return [
            'ASSISTANT_TOKEN'   => $this->jetonEnv,
            'AEIC_TEST_HEADERS' => $this->entetes === [] ? '' : json_encode($this->entetes, JSON_UNESCAPED_UNICODE),
        ];
    }

    // -----------------------------------------------------------------
    //  Aides de fixtures
    // -----------------------------------------------------------------

    private function seedVente(
        string $id,
        string $ref,
        string $quand,
        string $cle,
        string $categorie,
        int $qty,
        float $prix,
        string $methode = 'CARTE'
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, category, price_ttc)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$id, $ref, $quand, $methode, $qty, $cle, $cle, $categorie, $prix]);
    }

    private function seedComptage(string $id, string $cle, string $quand, int $qty): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap)
             VALUES (?,?,?,?,?,0)'
        );
        $stmt->execute([$id, $quand, $cle, $qty, $qty]);
    }

    /**
     * Corps JSON décodé de la réponse.
     *
     * @return array<string,mixed>
     */
    private function corps(array $response): array
    {
        $decode = json_decode((string) ($response['body'] ?? ''), true);

        return is_array($decode) ? $decode : [];
    }

    private function aEntete(array $response, string $entete): bool
    {
        foreach ($response['headers'] ?? [] as $h) {
            if (strcasecmp((string) $h, $entete) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Jour Paris « aujourd'hui − N jours », format AAAA-MM-JJ. */
    private function jourParis(int $moinsJours): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))
            ->modify(sprintf('-%d days', $moinsJours))
            ->format('Y-m-d');
    }

    // -----------------------------------------------------------------
    //  Authentification
    // -----------------------------------------------------------------

    public function test_sans_jeton_ou_mauvais_jeton_refus_403_generique_no_store(): void
    {
        // Sans en-tête du tout.
        $this->entetes = [];
        $r = $this->request('GET', '/internal/assistant/ventes_periode?debut=2026-10-20&fin=2026-10-21');

        self::assertSame(403, (int) ($r['code'] ?? 0));
        self::assertSame(['erreur' => 'Accès refusé.'], $this->corps($r));
        self::assertTrue($this->aEntete($r, 'Cache-Control: no-store, max-age=0'), 'La réponse doit être no-store.');
        self::assertTrue($this->aEntete($r, 'Pragma: no-cache'), 'La réponse doit porter Pragma: no-cache.');

        // Mauvais jeton : même refus générique, aucune distinction.
        $this->entetes = ['X-Assistant-Token' => 'faux-jeton-qui-ne-passe-pas'];
        $r = $this->request('GET', '/internal/assistant/stock_produit?nom=Monster');

        self::assertSame(403, (int) ($r['code'] ?? 0));
        self::assertSame(['erreur' => 'Accès refusé.'], $this->corps($r));
    }

    public function test_outil_inconnu_refuse_apres_la_garde(): void
    {
        // Sans jeton : 403 générique — aucune énumération des outils.
        $this->entetes = [];
        $r = $this->request('GET', '/internal/assistant/outil_factice');
        self::assertSame(403, (int) ($r['code'] ?? 0));

        // Avec le bon jeton : 404 « Outil inconnu. ».
        $this->entetes = ['X-Assistant-Token' => $this->token];
        $r = $this->request('GET', '/internal/assistant/outil_factice');
        self::assertSame(404, (int) ($r['code'] ?? 0));
        self::assertSame(['erreur' => 'Outil inconnu.'], $this->corps($r));
    }

    public function test_jeton_non_configure_fail_closed(): void
    {
        // ASSISTANT_TOKEN vide : même un en-tête « valide » est refusé.
        $this->jetonEnv = '';
        $r = $this->request('GET', '/internal/assistant/produits_a_commander');

        self::assertSame(403, (int) ($r['code'] ?? 0));
        self::assertSame(['erreur' => 'Accès refusé.'], $this->corps($r));
    }

    public function test_rate_limit_21e_requete_dans_la_minute_refusee(): void
    {
        // Pré-remplit le compteur partagé (clé IP du runner : 127.0.0.1).
        $limiter = new RateLimiter();
        for ($i = 0; $i < 20; $i++) {
            $limiter->hit('assistant:127.0.0.1');
        }

        $r = $this->request('GET', '/internal/assistant/produits_a_commander');

        self::assertSame(429, (int) ($r['code'] ?? 0));
        self::assertSame('Trop de requêtes. Réessayez dans une minute.', $this->corps($r)['erreur'] ?? '');
    }

    // -----------------------------------------------------------------
    //  Outil 1 — ventes_periode
    // -----------------------------------------------------------------

    public function test_ventes_periode_agregats_transactions_et_top_conformes(): void
    {
        $this->seedVente('s1', 'T1', '2026-10-20 12:00:00', 'Bueno', 'Douceurs', 1, 3.50, 'LIQUIDE');
        $this->seedVente('s2', 'T1', '2026-10-20 12:00:00', 'Monster', 'Boissons', 2, 4.00);
        $this->seedVente('s3', 'T2', '2026-10-21 15:00:00', 'Coca cola', 'Boissons', 1, 5.00);
        // Hors période demandée : ne doit PAS être comptée.
        $this->seedVente('s4', 'T3', '2026-10-22 10:00:00', 'Kitkat', 'Douceurs', 1, 9.99);

        $r = $this->request('GET', '/internal/assistant/ventes_periode?debut=2026-10-20&fin=2026-10-21');
        $c = $this->corps($r);

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertSame('ventes_periode', $c['outil'] ?? '');
        self::assertSame('2026-10-20', $c['debut'] ?? '');
        self::assertSame('2026-10-21', $c['fin'] ?? '');
        self::assertEqualsWithDelta(12.50, (float) ($c['total_ventes_eur'] ?? -1), 0.001);
        self::assertSame(2, (int) ($c['nb_transactions'] ?? -1), '2 transactions distinctes attendues.');
        self::assertSame(4, (int) ($c['quantite_vendue'] ?? -1), '1 + 2 + 1 unités attendues.');

        // Top trié par CA décroissant (même règle que Sale::topProductsBetween),
        // la vente hors période (Kitkat) absente.
        $top = $c['top_produits'] ?? [];
        self::assertEquals(
            [
                ['produit' => 'Coca cola', 'quantite' => 1, 'ca_eur' => 5.0],
                ['produit' => 'Monster', 'quantite' => 2, 'ca_eur' => 4.0],
                ['produit' => 'Bueno', 'quantite' => 1, 'ca_eur' => 3.5],
            ],
            $top
        );
        self::assertSame('Bueno', $top[2]['produit'] ?? '');
        self::assertSame(1, $top[2]['quantite'] ?? 0);
    }

    public function test_ventes_periode_dates_invalides_refusees_en_400(): void
    {
        // Paramètres absents.
        $r = $this->request('GET', '/internal/assistant/ventes_periode');
        self::assertSame(400, (int) ($r['code'] ?? 0));
        self::assertStringContainsString('debut', (string) ($this->corps($r)['erreur'] ?? ''));

        // Format non ISO.
        $r = $this->request('GET', '/internal/assistant/ventes_periode?debut=20/10/2026&fin=2026-10-21');
        self::assertSame(400, (int) ($r['code'] ?? 0));

        // Date impossible (31 novembre).
        $r = $this->request('GET', '/internal/assistant/ventes_periode?debut=2026-11-31&fin=2026-11-31');
        self::assertSame(400, (int) ($r['code'] ?? 0));

        // Bornes inversées.
        $r = $this->request('GET', '/internal/assistant/ventes_periode?debut=2026-10-25&fin=2026-10-20');
        self::assertSame(400, (int) ($r['code'] ?? 0));
    }

    // -----------------------------------------------------------------
    //  Outil 2 — stock_produit
    // -----------------------------------------------------------------

    public function test_stock_produit_resout_exact_normalise_alias_et_variante(): void
    {
        $this->seedComptage('c1', 'Monster Blanche', '2026-10-25 10:00:00', 13);
        $this->seedComptage('c2', 'Monster', '2026-10-26 09:00:00', 3);
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_aliases (id, raw_description, product_key, category) VALUES (?,?,?,NULL)'
        );
        $stmt->execute(['a1', 'Boisson energy', 'Monster Blanche']);

        // Passe exacte.
        $r = $this->request('GET', '/internal/assistant/stock_produit?nom=' . rawurlencode('Monster Blanche'));
        $c = $this->corps($r);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertTrue((bool) ($c['trouve'] ?? false));
        self::assertSame('Monster Blanche', $c['cle_canonique'] ?? '');
        self::assertSame(13, $c['stock'] ?? null);
        self::assertSame('2026-10-25 10:00:00', $c['dernier_comptage'] ?? '');
        self::assertSame('exact', $c['resolution'] ?? '');

        // Passe normalisée : casse/accents/espaces ignorés, clé CANONIQUE renvoyée.
        $r = $this->request('GET', '/internal/assistant/stock_produit?nom=' . rawurlencode('MONSTER  blanche'));
        $c = $this->corps($r);
        self::assertTrue((bool) ($c['trouve'] ?? false));
        self::assertSame('Monster Blanche', $c['cle_canonique'] ?? '', 'La clé canonique du livre doit être renvoyée, pas le libellé saisi.');
        self::assertSame(13, $c['stock'] ?? null);
        self::assertSame('normalise', $c['resolution'] ?? '');

        // Passe alias : « Boisson energy » → « Monster Blanche ».
        $r = $this->request('GET', '/internal/assistant/stock_produit?nom=' . rawurlencode('Boisson energy'));
        $c = $this->corps($r);
        self::assertTrue((bool) ($c['trouve'] ?? false));
        self::assertSame('Monster Blanche', $c['cle_canonique'] ?? '');
        self::assertSame('alias', $c['resolution'] ?? '');

        // Variante couleur/goût : « Monster verte » → famille « Monster ».
        $r = $this->request('GET', '/internal/assistant/stock_produit?nom=' . rawurlencode('Monster verte'));
        $c = $this->corps($r);
        self::assertTrue((bool) ($c['trouve'] ?? false));
        self::assertSame('Monster', $c['cle_canonique'] ?? '');
        self::assertSame(3, $c['stock'] ?? null);
        self::assertSame('variante', $c['resolution'] ?? '');
    }

    public function test_stock_produit_introuvable_propose_des_candidats(): void
    {
        $this->seedComptage('c1', 'Monster Blanche', '2026-10-25 10:00:00', 13);
        $this->seedComptage('c2', 'Monster', '2026-10-26 09:00:00', 3);

        // Préfixe « Monst » : les deux clés du livre sont suggérées (tri
        // alphabétique, max 5), sans être « trouvées ».
        $r = $this->request('GET', '/internal/assistant/stock_produit?nom=Monst');
        $c = $this->corps($r);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertFalse((bool) ($c['trouve'] ?? true));
        self::assertNull($c['cle_canonique']);
        self::assertNull($c['stock']);
        self::assertSame(
            [
                ['cle' => 'Monster', 'stock' => 3],
                ['cle' => 'Monster Blanche', 'stock' => 13],
            ],
            $c['candidats'] ?? []
        );

        // Nom vide → 400 (jamais de recherche « tout »).
        $r = $this->request('GET', '/internal/assistant/stock_produit?nom=');
        self::assertSame(400, (int) ($r['code'] ?? 0));

        // Produit vraiment inconnu : candidats vides, pas d'erreur.
        $r = $this->request('GET', '/internal/assistant/stock_produit?nom=' . rawurlencode('Pizza ananas'));
        $c = $this->corps($r);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertFalse((bool) ($c['trouve'] ?? true));
        self::assertSame([], $c['candidats'] ?? null);
    }

    // -----------------------------------------------------------------
    //  Outil 3 — produits_a_commander
    // -----------------------------------------------------------------

    public function test_produits_a_commander_reprend_la_fenetre_par_defaut_du_reappro(): void
    {
        // « Coca cola » : 14 vendus sur la fenêtre de 14 jours (avant le
        // dernier comptage), stock théorique 2 → besoin 7, a_commander 5,
        // autonomie 2 j (< 7) → état a_commander (alerte).
        // « Chips » : 7 vendus, stock 30 → a_commander 0, état ok.
        $j3 = $this->jourParis(3);
        $this->seedVente('s1', 'T1', $j3 . ' 12:00:00', 'Coca cola', 'Boissons', 14, 14.00);
        $this->seedVente('s2', 'T2', $this->jourParis(4) . ' 12:00:00', 'Chips', 'Snacks', 7, 7.00);
        $this->seedComptage('c1', 'Coca cola', $this->jourParis(1) . ' 10:00:00', 2);
        $this->seedComptage('c2', 'Chips', $this->jourParis(1) . ' 10:30:00', 30);

        $r = $this->request('GET', '/internal/assistant/produits_a_commander');
        $c = $this->corps($r);

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertSame('produits_a_commander', $c['outil'] ?? '');
        self::assertSame($this->jourParis(0), $c['genere_le'] ?? '');
        self::assertSame(
            ['debut' => $this->jourParis(13), 'fin' => $this->jourParis(0), 'jours' => 14],
            $c['periode_analyse'] ?? null,
            'Fenêtre par défaut de la page Réappro : 14 derniers jours.'
        );
        self::assertSame(7, (int) ($c['horizon_jours'] ?? -1));
        self::assertSame(1, (int) ($c['nb_alertes'] ?? -1), 'Seul « Coca cola » est en alerte.');

        $produits = $c['produits'] ?? [];
        self::assertCount(2, $produits);
        self::assertSame('Coca cola', $produits[0]['produit'] ?? '', 'Tri : quantité à commander décroissante.');

        $coca = $produits[0];
        self::assertSame(2, $coca['stock'] ?? null);
        self::assertSame(2, $coca['autonomie_jours'] ?? null);
        self::assertSame(5, $coca['a_commander'] ?? null);
        self::assertSame('a_commander', $coca['etat'] ?? '');

        $chips = $produits[1];
        self::assertSame(0, $chips['a_commander'] ?? null);
        self::assertSame('ok', $chips['etat'] ?? '');
    }

    // -----------------------------------------------------------------
    //  Lecture seule stricte
    // -----------------------------------------------------------------

    public function test_les_trois_outils_ne_modifient_aucune_table(): void
    {
        $this->seedVente('s1', 'T1', $this->jourParis(2) . ' 12:00:00', 'Coca cola', 'Boissons', 3, 3.00);
        $this->seedComptage('c1', 'Coca cola', $this->jourParis(1) . ' 10:00:00', 4);

        $avant = $this->comptagesTables();

        $r1 = $this->request('GET', '/internal/assistant/ventes_periode?debut=' . $this->jourParis(7) . '&fin=' . $this->jourParis(0));
        $r2 = $this->request('GET', '/internal/assistant/stock_produit?nom=' . rawurlencode('coca cola'));
        $r3 = $this->request('GET', '/internal/assistant/produits_a_commander');

        foreach ([$r1, $r2, $r3] as $r) {
            self::assertSame(200, (int) ($r['code'] ?? 0), 'Chaque outil doit répondre 200 (jeton et fixtures valides).');
        }

        self::assertSame($avant, $this->comptagesTables(), 'Aucune table ne doit être modifiée par les outils assistant.');
    }

    /**
     * Comptage de lignes de toutes les tables de base (hors vues).
     *
     * @return array<string,int>
     */
    private function comptagesTables(): array
    {
        $tables = $this->pdo
            ->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
            ->fetchAll(PDO::FETCH_NUM);

        $counts = [];
        foreach ($tables as $row) {
            $name = (string) $row[0];
            $counts[$name] = (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();
        }
        ksort($counts);

        return $counts;
    }
}
