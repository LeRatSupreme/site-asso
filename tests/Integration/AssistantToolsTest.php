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

    /** Lundi Paris de la semaine courante ou de la prochaine. */
    private function lundiParis(bool $prochaine = false): string
    {
        $modif = $prochaine ? 'monday next week' : 'monday this week';

        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))
            ->modify($modif)
            ->format('Y-m-d');
    }

    /** Vide le cache EDT de test (APP_TESTING détourne CalendarContext/EdtCalendar
     * vers tests/cache/edt avec fetch null : jamais de réseau, jamais de cache réel). */
    private function viderCacheEdt(): void
    {
        foreach (glob(AEIC_ROOT . '/tests/cache/edt/iut-*.json') ?: [] as $fichier) {
            @unlink($fichier);
        }
    }

    /** Écrit un cache EDT de test (format EdtCalendar v1, FRAIS : servi tel
     * quel, aucun appel réseau — un cours « CM » par jour donné, midi UTC
     * = même jour local Paris). */
    private function ecrireCacheEdt(string $deptIut, array $jours): void
    {
        $dir = AEIC_ROOT . '/tests/cache/edt';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $events = [];
        foreach (array_values($jours) as $i => $jour) {
            $events[] = [
                'uid'    => 'edt-test-' . $deptIut . '-' . $i,
                'start'  => $jour . 'T12:00:00.000Z',
                'end'    => $jour . 'T14:00:00.000Z',
                'title'  => 'Test CM',
                'subject' => 'Test CM',
                'kind'   => 'CM',
                'room'   => 'S1',
                'groups' => ['BUT'],
            ];
        }
        file_put_contents(
            $dir . '/' . $deptIut . '.json',
            json_encode([
                'version'    => 1,
                'fetched_at' => time(),
                'roots'      => [['id' => 1, 'name' => 'BUT']],
                'events'     => $events,
            ], JSON_UNESCAPED_UNICODE)
        );
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
    //  Outil 4 — marge_produit (T4-P1)
    // -----------------------------------------------------------------

    public function test_marge_produit_sans_jeton_refuse_403_generique(): void
    {
        $this->entetes = [];
        $r = $this->request(
            'GET',
            '/internal/assistant/marge_produit?nom=Coca&debut=2026-10-20&fin=2026-10-21'
        );

        self::assertSame(403, (int) ($r['code'] ?? 0));
        self::assertSame(['erreur' => 'Accès refusé.'], $this->corps($r));
        self::assertTrue($this->aEntete($r, 'Cache-Control: no-store, max-age=0'), 'La réponse doit être no-store.');
    }

    public function test_marge_produit_parametres_invalides_refuses_en_400(): void
    {
        // Nom absent.
        $r = $this->request('GET', '/internal/assistant/marge_produit?debut=2026-10-20&fin=2026-10-21');
        self::assertSame(400, (int) ($r['code'] ?? 0));
        self::assertStringContainsString('nom', (string) ($this->corps($r)['erreur'] ?? ''));

        // Nom trop long (> 255 caractères).
        $r = $this->request(
            'GET',
            '/internal/assistant/marge_produit?nom=' . str_repeat('a', 256) . '&debut=2026-10-20&fin=2026-10-21'
        );
        self::assertSame(400, (int) ($r['code'] ?? 0));

        // Dates invalides (non ISO, 13e mois) et bornes inversées.
        foreach (
            [
                '?nom=Coca&debut=octobre&fin=2026-10-21',
                '?nom=Coca&debut=2026-13-01&fin=2026-10-21',
                '?nom=Coca&debut=2026-10-25&fin=2026-10-20',
            ] as $query
        ) {
            $r = $this->request('GET', '/internal/assistant/marge_produit' . $query);
            self::assertSame(400, (int) ($r['code'] ?? 0), "Requête « {$query} » doit être refusée en 400.");
        }
    }

    public function test_marge_produit_valeurs_conformes_a_la_regle_compta(): void
    {
        // Lot de coût 1,00 € depuis longtemps (as-of chaque vente) ;
        // 2 ventes : 2×2,50 € puis 1×2,50 € → CA 7,50 €, coût 3,00 €,
        // marge 4,50 € soit 60,00 % (MÊME règle que le bénéfice par
        // produit d'AdminComptaController::products : profit/ca×100).
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)'
        );
        $stmt->execute(['pc1', 'Coca cola', 1.000, '2026-01-01']);
        $this->seedVente('s1', 'T1', '2026-10-20 12:00:00', 'Coca cola', 'Boissons', 2, 5.00);
        $this->seedVente('s2', 'T2', '2026-10-21 12:00:00', 'Coca cola', 'Boissons', 1, 2.50);
        $this->seedComptage('c1', 'Coca cola', '2026-10-19 10:00:00', 4);

        $r = $this->request(
            'GET',
            '/internal/assistant/marge_produit?nom=' . rawurlencode('Coca cola')
                . '&debut=2026-10-20&fin=2026-10-21'
        );
        $c = $this->corps($r);

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertSame('marge_produit', $c['outil'] ?? '');
        self::assertTrue((bool) ($c['trouve'] ?? false));
        self::assertSame('Coca cola', $c['cle_ventes'] ?? '');
        self::assertSame(['debut' => '2026-10-20', 'fin' => '2026-10-21'], $c['periode'] ?? null);
        self::assertSame(3, (int) ($c['quantite_vendue'] ?? -1));
        self::assertEqualsWithDelta(7.50, (float) ($c['ca_eur'] ?? -1), 0.001);
        self::assertEqualsWithDelta(3.00, (float) ($c['cout_eur'] ?? -1), 0.001);
        self::assertEqualsWithDelta(4.50, (float) ($c['marge_eur'] ?? -1), 0.001);

        // Même règle que la page Bénéfice par produit (ComptaCalc partagée).
        self::assertEqualsWithDelta(
            \App\Core\Compta\ComptaCalc::marginPercent(4.50, 7.50),
            (float) ($c['marge_pct'] ?? -1),
            0.0001,
            'marge_pct doit suivre ComptaCalc::marginPercent (profit/ca×100).'
        );
        self::assertEqualsWithDelta(60.0, (float) ($c['marge_pct'] ?? -1), 0.001);
        self::assertEqualsWithDelta(1.000, (float) ($c['cout_lot_actuel_eur'] ?? -1), 0.001, 'Lot en cours as-of.');
    }

    public function test_marge_produit_somme_les_graphies_de_casse_differente(): void
    {
        // Régression bloquée (règle anti-rapprochement) : « Coca cola » et
        // « coca cola » sont UN produit — une demande en casse différente
        // « COCA COLA » doit renvoyer la marge des DEUX lignes (la collation
        // CI de MySQL les groupe d'ailleurs en une seule ligne SQL : 2×2,50 €
        // + 1×2,00 € = CA 7,00 €, coût 3×1,00 €).
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)'
        );
        $stmt->execute(['pc1', 'Coca cola', 1.000, '2026-01-01']);
        $this->seedVente('s1', 'T1', '2026-10-20 12:00:00', 'Coca cola', 'Boissons', 2, 5.00);
        $this->seedVente('s2', 'T2', '2026-10-20 12:30:00', 'coca cola', 'Boissons', 1, 2.00);
        $this->seedComptage('c1', 'Coca cola', '2026-10-19 10:00:00', 4);

        $r = $this->request(
            'GET',
            '/internal/assistant/marge_produit?nom=' . rawurlencode('COCA COLA')
                . '&debut=2026-10-20&fin=2026-10-20'
        );
        $c = $this->corps($r);

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertTrue((bool) ($c['trouve'] ?? false), 'Casse différente : le produit DOIT être trouvé (résolveur partagé).');
        self::assertSame(3, (int) ($c['quantite_vendue'] ?? -1), 'Les deux graphies sont sommées.');
        self::assertEqualsWithDelta(7.00, (float) ($c['ca_eur'] ?? -1), 0.001);
        self::assertEqualsWithDelta(3.00, (float) ($c['cout_eur'] ?? -1), 0.001);
        self::assertEqualsWithDelta(4.00, (float) ($c['marge_eur'] ?? -1), 0.001);
    }

    public function test_marge_produit_introuvable_repond_false_avec_candidats(): void
    {
        $this->seedComptage('c1', 'Monster Blanche', '2026-10-25 10:00:00', 13);
        $this->seedVente('s1', 'T1', '2026-10-20 12:00:00', 'Monster Blanche', 'Monster', 1, 1.80);

        $r = $this->request(
            'GET',
            '/internal/assistant/marge_produit?nom=Monst&debut=2026-10-20&fin=2026-10-21'
        );
        $c = $this->corps($r);

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertFalse((bool) ($c['trouve'] ?? true));
        self::assertArrayNotHasKey('ca_eur', $c, 'Aucun chiffre quand le produit est introuvable.');
        self::assertSame(
            [['cle' => 'Monster Blanche', 'stock' => 13]],
            $c['candidats'] ?? null,
            'Candidats du livre de stock, comme stock_produit.'
        );
    }

    public function test_marge_produit_exclut_les_ventes_hors_periode(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from) VALUES (?,?,?,?)'
        );
        $stmt->execute(['pc1', 'Coca cola', 1.000, '2026-01-01']);
        $this->seedComptage('c1', 'Coca cola', '2026-10-19 10:00:00', 4);
        // Dans la période [j-6 ; j-3] : 1 vente. Hors période : avant (j-8)
        // et après (j-1) → exclues, bornes incluses.
        $this->seedVente('s1', 'T1', $this->jourParis(5) . ' 12:00:00', 'Coca cola', 'Boissons', 1, 2.00);
        $this->seedVente('s2', 'T2', $this->jourParis(8) . ' 12:00:00', 'Coca cola', 'Boissons', 5, 10.00);
        $this->seedVente('s3', 'T3', $this->jourParis(1) . ' 12:00:00', 'Coca cola', 'Boissons', 3, 6.00);

        $r = $this->request(
            'GET',
            '/internal/assistant/marge_produit?nom=' . rawurlencode('coca cola')
                . '&debut=' . $this->jourParis(6) . '&fin=' . $this->jourParis(3)
        );
        $c = $this->corps($r);

        self::assertTrue((bool) ($c['trouve'] ?? false));
        self::assertSame(1, (int) ($c['quantite_vendue'] ?? -1), 'Seule la vente dans la période compte.');
        self::assertEqualsWithDelta(2.00, (float) ($c['ca_eur'] ?? -1), 0.001);
        self::assertEqualsWithDelta(1.00, (float) ($c['cout_eur'] ?? -1), 0.001);
    }

    // -----------------------------------------------------------------
    //  Outil 5 — doublons_en_attente (T4-P1)
    // -----------------------------------------------------------------

    public function test_doublons_en_attente_sans_jeton_refuse_403_generique(): void
    {
        $this->entetes = [];
        $r = $this->request('GET', '/internal/assistant/doublons_en_attente');

        self::assertSame(403, (int) ($r['code'] ?? 0));
        self::assertSame(['erreur' => 'Accès refusé.'], $this->corps($r));
        self::assertTrue($this->aEntete($r, 'Cache-Control: no-store, max-age=0'), 'La réponse doit être no-store.');
    }

    public function test_doublons_en_attente_expose_les_paires_vivantes_sans_decider(): void
    {
        // La paire « Minute Maid » / « Minute Maid Pomme » existe dans le
        // livrable data/eval_t1/candidats_fusion.json : les DEUX clés seedées
        // vivantes → la paire est exposée telle quelle. Le score du livrable
        // est recalculé à chaque modèle de rapprochement (89 % avec le modèle
        // P2 v1, 85 % après la vague 2) : l'attendu est relu dans le livrable
        // pour ne pas figer le score d'un modèle dans le test — même
        // convention que T1DecideTest.
        $this->seedVente('s1', 'T1', $this->jourParis(2) . ' 12:00:00', 'Minute Maid', 'Boissons', 2, 3.00);
        $this->seedVente('s2', 'T2', $this->jourParis(2) . ' 12:10:00', 'Minute Maid Pomme', 'Boissons', 1, 1.80);

        $r = $this->request('GET', '/internal/assistant/doublons_en_attente');
        $c = $this->corps($r);

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertSame('doublons_en_attente', $c['outil'] ?? '');
        self::assertTrue((bool) ($c['dispo'] ?? false), 'Livrables data/eval_t1 présents.');
        self::assertGreaterThanOrEqual(1, (int) ($c['total_suggestions'] ?? 0));

        $paires = $c['suggestions_modele'] ?? [];
        $attendue = null;
        foreach ($paires as $p) {
            $cote = [($p['source'] ?? ''), ($p['target'] ?? '')];
            sort($cote);
            if ($cote === ['Minute Maid', 'Minute Maid Pomme']) {
                $attendue = $p;
            }
        }
        self::assertNotNull($attendue, 'La paire vivante du livrable doit être exposée (côtés dans un ordre quelconque — habille choisit le côté conservé).');

        // Ressemblance attendue = score du livrable arrondi (T1Suggestions :
        // (int) round(score * 100)), relu dans candidats_fusion.json.
        $livrable = json_decode((string) file_get_contents(AEIC_ROOT . '/data/eval_t1/candidats_fusion.json'), true);
        self::assertIsArray($livrable, 'candidats_fusion.json doit être un JSON lisible.');
        $scoreAttendu = null;
        foreach (($livrable['candidats'] ?? []) as $cand) {
            $cote = [(string) ($cand['a'] ?? ''), (string) ($cand['b'] ?? '')];
            sort($cote);
            if ($cote === ['Minute Maid', 'Minute Maid Pomme']) {
                $scoreAttendu = (int) round(100 * (float) ($cand['score'] ?? 0));
            }
        }
        self::assertNotNull($scoreAttendu, 'La paire seedée doit exister dans le livrable candidats_fusion.json.');
        self::assertSame($scoreAttendu, (int) ($attendue['ressemblance'] ?? -1), 'La ressemblance exposée doit reprendre le score du livrable (arrondi), tel quel.');

        // L'assistant liste, il ne fusionne jamais : mention explicite.
        self::assertStringContainsString('humain', (string) ($c['a_decider_par'] ?? ''));

        // Structure compacte : sections quarantaine toujours présentes.
        self::assertIsArray($c['quarantaine']['en_attente'] ?? null);
        self::assertIsArray($c['quarantaine']['mures'] ?? null);
    }

    public function test_doublons_en_attente_quarantaine_compacte_et_mures(): void
    {
        // Quarantaine REDIRIGÉE (tests/bootstrap.php → tests/cache/t15/):
        // fixture écrite puis supprimée — jamais la vraie.
        $chemin = dirname(__DIR__, 2) . '/tests/cache/t15/quarantaine.json';
        @mkdir(dirname($chemin), 0777, true);
        $paris = new \DateTimeZone('Europe/Paris');
        $maintenant = new \DateTimeImmutable('now', $paris);
        $fixture = [
            'version' => 1,
            'paires' => [
                [
                    'id' => str_repeat('a', 40),
                    'source' => 'Foo',
                    'target' => 'foo',
                    'score' => 0.9123,
                    'motif' => 'casse',
                    'niveau' => 2,
                    'created_at' => $maintenant->modify('-40 hours')->format(DATE_ATOM),
                    'statut' => 'en_attente',
                    'applied_at' => null,
                ],
                [
                    'id' => str_repeat('b', 40),
                    'source' => 'Bar',
                    'target' => 'bar',
                    'score' => 0.9,
                    'motif' => 'casse',
                    'niveau' => 2,
                    'created_at' => $maintenant->modify('-60 hours')->format(DATE_ATOM),
                    'statut' => 'en_attente',
                    'applied_at' => null,
                ],
                [
                    'id' => str_repeat('c', 40),
                    'source' => 'Baz',
                    'target' => 'baz',
                    'score' => 0.95,
                    'motif' => 'casse',
                    'niveau' => 2,
                    'created_at' => $maintenant->modify('-30 hours')->format(DATE_ATOM),
                    'statut' => 'annulee',
                    'applied_at' => null,
                ],
            ],
        ];
        file_put_contents($chemin, json_encode($fixture, JSON_UNESCAPED_UNICODE));

        try {
            $r = $this->request('GET', '/internal/assistant/doublons_en_attente');
            $c = $this->corps($r);

            self::assertSame(200, (int) ($r['code'] ?? 0));
            $q = $c['quarantaine'] ?? [];
            self::assertSame(1, (int) ($q['nb_en_attente'] ?? -1), 'La paire annulée ne compte pas.');
            self::assertSame(1, (int) ($q['nb_mures'] ?? -1), 'Créée il y a 60 h > 48 h : mûre.');

            $attente = $q['en_attente'][0] ?? [];
            self::assertSame(str_repeat('a', 40), $attente['id'] ?? '');
            self::assertSame('Foo', $attente['source'] ?? '');
            self::assertSame(91, (int) ($attente['score_pct'] ?? -1), 'Score 0,9123 → 91 %.');
            self::assertGreaterThanOrEqual(7, (int) ($attente['reste_heures'] ?? -1));
            self::assertLessThanOrEqual(8, (int) ($attente['reste_heures'] ?? -1), '40 h écoulées → ~8 h restantes.');

            self::assertSame(str_repeat('b', 40), ($q['mures'][0] ?? [])['id'] ?? '');
        } finally {
            @unlink($chemin);
        }
    }

    // -----------------------------------------------------------------
    //  Outil 6 — catalogue_anomalies (veille qualité catalogue)
    // -----------------------------------------------------------------

    public function test_catalogue_anomalies_sans_jeton_refuse_403_generique(): void
    {
        $this->entetes = [];
        $r = $this->request('GET', '/internal/assistant/catalogue_anomalies');

        self::assertSame(403, (int) ($r['code'] ?? 0));
        self::assertSame(['erreur' => 'Accès refusé.'], $this->corps($r));
        self::assertTrue($this->aEntete($r, 'Cache-Control: no-store, max-age=0'), 'La réponse doit être no-store.');
    }

    /** Liste blanche (200, pas le 404 « Outil inconnu ») + JSON champs français des 6 familles. */
    public function test_catalogue_anomalies_liste_blanche_et_champs_francais(): void
    {
        // CatalogueQc lit aussi products/product_categories : reset dédié
        // (absent du setUp() commun — les autres outils n'y touchent pas).
        $this->reset(['products', 'product_categories']);
        $this->pdo->exec("INSERT INTO product_categories (id, name, `order`, is_active) VALUES ('cat_qc', 'Boissons', 0, 1)");
        $this->pdo->exec("INSERT INTO products (id, name, price, category_id, is_active) VALUES ('p_qc', 'Orangina ancien', 2.50, 'cat_qc', 1)");

        $r = $this->request('GET', '/internal/assistant/catalogue_anomalies');
        $c = $this->corps($r);

        self::assertSame(200, (int) ($r['code'] ?? 0), 'catalogue_anomalies doit être dans la liste blanche du contrôleur (sinon 404 « Outil inconnu. »).');
        self::assertSame('catalogue_anomalies', $c['outil'] ?? '');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) ($c['genere_le'] ?? ''), 'Horodatage Paris compact.');

        // Les SIX familles : comptes exacts + listes compactes, clés stables.
        $familles = [
            'fiches_jamais_vendues', 'cles_sans_fiche', 'categories_vides',
            'quasi_doublons_categories', 'prix_suspects', 'fiches_dormantes',
        ];
        foreach ($familles as $famille) {
            self::assertArrayHasKey($famille, $c['totaux'] ?? [], "La famille $famille doit avoir son compte exact.");
            self::assertIsArray($c[$famille] ?? null, "La famille $famille doit avoir sa liste compacte.");
        }

        // La fixture (fiche active jamais vendue) sort avec ses champs français.
        self::assertSame(1, (int) ($c['totaux']['fiches_jamais_vendues'] ?? -1));
        $item = $c['fiches_jamais_vendues'][0] ?? [];
        self::assertSame('Orangina ancien', $item['produit'] ?? '');
        self::assertSame('Boissons', $item['categorie'] ?? '');
        self::assertSame(2.5, $item['prix_eur'] ?? -1);
        self::assertArrayHasKey('jamais_achetee', $item, 'Champ français « jamais_achetee » attendu (candidat [SÛR]).');
    }

    public function test_catalogue_anomalies_ne_modifie_aucune_table(): void
    {
        $this->reset(['products', 'product_categories']);
        $this->seedVente('s1', 'T1', $this->jourParis(2) . ' 12:00:00', 'Coca cola', 'Boissons', 3, 3.00);
        $this->seedComptage('c1', 'Coca cola', $this->jourParis(1) . ' 10:00:00', 4);
        $this->pdo->exec("INSERT INTO product_categories (id, name, `order`, is_active) VALUES ('cat_qc', 'Boissons', 0, 1)");
        $this->pdo->exec("INSERT INTO products (id, name, price, category_id, is_active) VALUES ('p_qc', 'Fantome', 2.50, 'cat_qc', 1)");

        $avant = $this->comptagesTables();

        $r = $this->request('GET', '/internal/assistant/catalogue_anomalies');

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertSame($avant, $this->comptagesTables(), 'catalogue_anomalies est strictement LECTURE SEULE : l\'assistant signale, il ne corrige jamais.');
    }

    // -----------------------------------------------------------------
    //  Outil 7 — edt_iut (emploi du temps IUT ULCO, mission E3)
    // -----------------------------------------------------------------

    public function test_edt_iut_sans_jeton_refuse_403_generique(): void
    {
        $this->entetes = [];
        $r = $this->request('GET', '/internal/assistant/edt_iut');

        self::assertSame(403, (int) ($r['code'] ?? 0));
        self::assertSame(['erreur' => 'Accès refusé.'], $this->corps($r));
        self::assertTrue($this->aEntete($r, 'Cache-Control: no-store, max-age=0'), 'La réponse doit être no-store.');
    }

    public function test_edt_iut_parametres_invalides_refuses_en_400(): void
    {
        $this->viderCacheEdt();

        $r = $this->request('GET', '/internal/assistant/edt_iut?dept=meca');
        self::assertSame(400, (int) ($r['code'] ?? 0));
        self::assertStringContainsString('dept', (string) ($this->corps($r)['erreur'] ?? ''));

        $r = $this->request('GET', '/internal/assistant/edt_iut?semaine=hier');
        self::assertSame(400, (int) ($r['code'] ?? 0));
        self::assertStringContainsString('semaine', (string) ($this->corps($r)['erreur'] ?? ''));
    }

    /** Fail-closed honnête : calendrier EDT totalement vide (API down, aucun
     * cache) → 503 « Données EDT indisponibles. », JAMAIS un 500 ni une
     * « semaine sans cours » inventée sur du vide. */
    public function test_edt_iut_calendrier_indisponible_fail_closed_honnete(): void
    {
        $this->viderCacheEdt();

        $r = $this->request('GET', '/internal/assistant/edt_iut?semaine=courante');

        self::assertSame(503, (int) ($r['code'] ?? 0), 'Panne calendrier : refus honnête 503, pas de 500.');
        self::assertSame('Données EDT indisponibles.', $this->corps($r)['erreur'] ?? '');
        self::assertTrue($this->aEntete($r, 'Cache-Control: no-store, max-age=0'));
    }

    /** Contrat JSON champs français (semaine, départements, source citée) :
     * fixtures déterministes — INFO a UN cours le lundi courant et UN le
     * lundi prochain, GEA/GEII vides (lundi prochain est TOUJOURS strictement
     * postérieur à aujourd'hui, quel que soit le jour d'exécution). */
    public function test_edt_iut_contrat_json_semaine_courante(): void
    {
        $this->viderCacheEdt();
        $lundi = $this->lundiParis();
        $lundiProchaine = $this->lundiParis(true);
        $this->ecrireCacheEdt('iut-info', [$lundi, $lundiProchaine]);

        $avant = $this->comptagesTables();

        $r = $this->request('GET', '/internal/assistant/edt_iut?semaine=courante');
        $c = $this->corps($r);

        self::assertSame(200, (int) ($r['code'] ?? 0), 'edt_iut doit être dans la liste blanche (sinon 404 « Outil inconnu. »).');
        self::assertSame('edt_iut', $c['outil'] ?? '');
        self::assertSame(['info', 'gea', 'geii'], $c['depts'] ?? null, 'dept=tous → les trois départements.');
        self::assertSame('courante', $c['semaine'] ?? '');
        self::assertSame($lundi, $c['lundi'] ?? '');
        self::assertSame((new \DateTimeImmutable($lundi))->modify('+4 days')->format('Y-m-d'), $c['vendredi'] ?? '');
        self::assertSame(1, (int) ($c['creneaux'] ?? -1), 'Un seul créneau INFO sur la semaine (cours du lundi).');
        self::assertSame(1, (int) ($c['jours_actifs'] ?? -1));
        self::assertTrue((bool) ($c['avec_cours'] ?? false));
        self::assertFalse((bool) ($c['hors_horizon'] ?? true), 'Semaine dans l’horizon publié (lundi ≤ dernier jour connu).');
        self::assertSame('Semaine de cours', $c['label'] ?? '');
        self::assertSame($lundiProchaine, $c['prochain_jour_cours'] ?? '', 'Prochain jour de cours strictement après aujourd’hui.');
        self::assertSame('edt-ulco.fr via EdtCalendar (cache 6h)', $c['source'] ?? '', 'La source edt-ulco.fr est citée (exigence coordinateur).');

        self::assertSame($avant, $this->comptagesTables(), 'edt_iut est strictement LECTURE SEULE (aucune table touchée).');
    }

    /** Vue département : GEA sans cours cette semaine publiée → « Semaine
     * sans cours » (pas hors horizon : la semaine est publiée, il n’y a
     * simplement aucun cours GEA), prochain jour de cours GEA = null. */
    public function test_edt_iut_dept_sans_cours_et_horizon_honnete(): void
    {
        $this->viderCacheEdt();
        $lundiProchaine = $this->lundiParis(true);
        $this->ecrireCacheEdt('iut-info', [$this->lundiParis(), $lundiProchaine]);

        $r = $this->request('GET', '/internal/assistant/edt_iut?dept=gea&semaine=courante');
        $c = $this->corps($r);

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertSame(['gea'], $c['depts'] ?? null, 'dept=gea → seul GEA.');
        self::assertSame(0, (int) ($c['creneaux'] ?? -1));
        self::assertSame(0, (int) ($c['jours_actifs'] ?? -1));
        self::assertFalse((bool) ($c['avec_cours'] ?? true));
        self::assertFalse((bool) ($c['hors_horizon'] ?? true), 'INFO publie des cours : la semaine est publiée, GEA n’a juste pas de cours.');
        self::assertSame('Semaine sans cours (vacances ?)', $c['label'] ?? '');
        self::assertArrayHasKey('prochain_jour_cours', $c, 'Le champ est TOUJOURS présent (stable), null quand rien n’est publié.');
        self::assertNull($c['prochain_jour_cours'], 'Aucun cours GEA publié : null, pas une date inventée.');

        // La même semaine vue INFO : cours présents, label de cours.
        $r = $this->request('GET', '/internal/assistant/edt_iut?dept=info&semaine=prochaine');
        $c = $this->corps($r);
        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertSame(['info'], $c['depts'] ?? null);
        self::assertSame($lundiProchaine, $c['lundi'] ?? '');
        self::assertSame(1, (int) ($c['creneaux'] ?? -1));
        self::assertSame('Semaine de cours', $c['label'] ?? '');
        self::assertFalse((bool) ($c['hors_horizon'] ?? true));
    }

    /** Vue « aujourdhui » (défaut) : un cours GEA aujourd’hui → jour de cours,
     * prochain jour = le lundi prochain INFO (strictement après aujourd’hui,
     * déterministe quel que soit le jour d’exécution). */
    public function test_edt_iut_vue_aujourdhui_par_defaut(): void
    {
        $this->viderCacheEdt();
        $lundiProchaine = $this->lundiParis(true);
        $aujourdhui = $this->jourParis(0);
        // INFO ne publie QUE le lundi prochain : « aujourd’hui » ne peut
        // jamais le chevaucher, peu importe le jour d’exécution.
        $this->ecrireCacheEdt('iut-info', [$lundiProchaine]);
        $this->ecrireCacheEdt('iut-gea', [$aujourdhui]);

        $r = $this->request('GET', '/internal/assistant/edt_iut');
        $c = $this->corps($r);

        self::assertSame(200, (int) ($r['code'] ?? 0));
        self::assertSame('aujourdhui', $c['semaine'] ?? '', 'Défaut sans paramètre : aujourdhui.');
        self::assertSame($aujourdhui, $c['jour'] ?? '');
        self::assertSame(1, (int) ($c['creneaux'] ?? -1), 'Le cours GEA du jour compte.');
        self::assertSame(1, (int) ($c['jours_actifs'] ?? -1));
        self::assertTrue((bool) ($c['avec_cours'] ?? false));
        self::assertSame('Jour de cours', $c['label'] ?? '');
        self::assertSame($lundiProchaine, $c['prochain_jour_cours'] ?? '');
        self::assertArrayNotHasKey('lundi', $c, 'Vue jour : pas de champ semaine.');
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

    public function test_les_deux_nouveaux_outils_ne_modifient_aucune_table_ni_fichier(): void
    {
        $this->seedVente('s1', 'T1', $this->jourParis(2) . ' 12:00:00', 'Coca cola', 'Boissons', 3, 3.00);
        $this->seedComptage('c1', 'Coca cola', $this->jourParis(1) . ' 10:00:00', 4);

        $avant = $this->comptagesTables();
        $fichiers = [
            AEIC_ROOT . '/data/eval_t1/candidats_fusion.json',
            AEIC_ROOT . '/data/eval_t1/residuels_a_qualifier.csv',
            AEIC_ROOT . '/data/eval_t1/t15/quarantaine.json', // la VRAIE, jamais la redirection test.
        ];
        $empreintes = array_map(
            static fn (string $f): string => is_file($f) ? md5_file($f) : 'absent',
            $fichiers
        );

        $r1 = $this->request(
            'GET',
            '/internal/assistant/marge_produit?nom=' . rawurlencode('coca cola')
                . '&debut=' . $this->jourParis(7) . '&fin=' . $this->jourParis(0)
        );
        $r2 = $this->request('GET', '/internal/assistant/doublons_en_attente');

        self::assertSame(200, (int) ($r1['code'] ?? 0));
        self::assertSame(200, (int) ($r2['code'] ?? 0));

        self::assertSame($avant, $this->comptagesTables(), 'Aucune table modifiée par les 2 nouveaux outils.');
        self::assertSame(
            $empreintes,
            array_map(
                static fn (string $f): string => is_file($f) ? md5_file($f) : 'absent',
                $fichiers
            ),
            'Les livrables T1 et la vraie quarantaine doivent rester intacts (lecture seule).'
        );
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
