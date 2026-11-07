<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Test d'intégration du renommage du « Libellé d'origine » sur la
 * route RÉELLE POST /admin/compta/aliases/bulk (grille « Alias
 * existants » de /admin/compta/aliases) :
 *
 *  - a. alias_label[id] en casse pure → simple renommage, cible
 *    conservée, catégorie (bulk_cat) appliquée dans le même envoi ;
 *  - b. libellé de la forme normalisée d'un autre alias de MÊME
 *    produit → fusion (ligne existante conservée, ligne renommée
 *    supprimée, trace « alias.fusion ») ;
 *  - c. libellé d'un produit DIFFÉRENT (alias d'autre cible, ou clé
 *    canonique d'une fiche carte) → ligne REFUSÉE : flash d'erreur
 *    explicite, AUCUNE écriture pour elle — mais sa catégorie et le
 *    reste du save s'appliquent normalement (SB3).
 *
 * Le login compta est forcé (APP_TESTING) pour isoler du flux
 * d'authentification, déjà couvert par ailleurs.
 */
final class AliasRenameRouteTest extends IntegrationTestCase
{
    private string $adminId = 'u_admin_ren';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset([
            'product_aliases', 'sales', 'products', 'product_categories',
            'audit_logs', 'scan_labels', 'users',
        ]);

        $this->seedUser($this->adminId, 'admin-ren@exemple.fr', 'Password123456', 'ADMIN');

        // Fiche carte d'un AUTRE produit (garde c2 : clé canonique).
        $this->pdo->exec("INSERT INTO products (id, name, price) VALUES ('p_schweppes', 'Schweppes', 1.00)");

        // Grille d'alias : orthographes choisies pour que les collisions
        // soient NORMALISÉES (espace vs underscore), pas exactes.
        $this->addAlias('al_x', 'oasis tropical slim', 'Oasis Tropical', null);
        $this->addAlias('al_c', 'RB ice', 'Redbull', null);
        $this->addAlias('al_d', 'redbull_ice', 'Redbull', 'Boissons');
        $this->addAlias('al_e', 'monster bleu', 'Monster', null);
        $this->addAlias('al_f', 'Monster Blue', 'Redbull', null);
        $this->addAlias('al_g', 'produit generique', 'Monster', null);
    }

    /**
     * Le trio a/b/c en UN seul envoi, avec les catégories en parallèle :
     * tout ce qui est valide s'applique, chaque refu est rapporté.
     */
    public function test_bulk_renommage_trio_renommage_fusion_refus(): void
    {
        $r = $this->request('POST', '/admin/compta/aliases/bulk', [
            // Catégories (contrat historique, inchangé).
            'bulk_raw' => ['oasis tropical slim', 'monster bleu'],
            'bulk_cat' => ['Boissons', 'Energie'],
            // Libellés modifiés (nouveau contrat, clé = id de ligne).
            'alias_label' => [
                'al_x' => 'Oasis Tropical Slim', // a : casse pure
                'al_c' => 'RedBull Ice',         // b : fusion normalisée vers al_d
                'al_e' => 'Monster Blue',        // c1 : refus (produit différent)
                'al_g' => 'Schweppes',           // c2 : refus (clé canonique d'un autre produit)
            ],
        ], [], $this->adminId);

        self::assertStringEndsWith('/admin/compta/aliases', $this->location($r), 'Retour à la liste des alias.');

        // --- a. Renommage simple : libellé ET catégorie appliqués. ---
        $x = $this->alias('al_x');
        self::assertSame('Oasis Tropical Slim', $x['raw_description']);
        self::assertSame('Oasis Tropical', $x['product_key'], 'Cible conservée.');
        self::assertSame('Boissons', $x['category'], 'La catégorie du même envoi est appliquée.');

        // --- b. Fusion : la ligne existante fait foi, l'autre disparaît. ---
        self::assertNull($this->alias('al_c'), 'La ligne renommée disparaît.');
        $d = $this->alias('al_d');
        self::assertSame('redbull_ice', $d['raw_description'], 'L\'orthographe de la ligne existante fait foi.');
        self::assertSame('Redbull', $d['product_key'], 'Cible conservée : celle de l\'existant.');
        self::assertSame('Boissons', $d['category']);
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'alias.fusion'"
        )->fetchColumn(), 'La fusion est tracée « alias.fusion ».');

        // --- c1. Refus produit différent : ligne INTACTE, catégorie OK. ---
        $e = $this->alias('al_e');
        self::assertSame('monster bleu', $e['raw_description'], 'Aucune écriture de renommage pour la ligne refusée.');
        self::assertSame('Monster', $e['product_key']);
        self::assertSame('Energie', $e['category'], 'La catégorie s\'applique indépendamment du refu.');
        $f = $this->alias('al_f');
        self::assertSame('Redbull', $f['product_key'], 'La cible de l\'alias existant n\'est pas écrasée.');

        // --- c2. Refus clé canonique d'un autre produit. ---
        $g = $this->alias('al_g');
        self::assertSame('produit generique', $g['raw_description']);
        self::assertSame('Monster', $g['product_key']);

        // --- Audits du renommage. ---
        self::assertSame(2, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'compta.alias.rename'"
        )->fetchColumn(), 'Un audit par ligne renommée/fusionnée (renamed + fused).');

        // --- Flashes : succès (catégories + renommages) et erreurs nominatives. ---
        $flashes = array_map(
            static fn (array $f): string => ($f['type'] ?? '') . '|' . (string) ($f['message'] ?? ''),
            $r['session']['_flash'] ?? []
        );

        self::assertCount(4, $flashes, '2 succès (catégories, renommages) + 2 refus.');
        self::assertContains(
            'success|2 catégorie(s) mise(s) à jour — 0 vente(s) synchronisée(s).',
            $flashes,
            'Le flash historique des catégories est inchangé.'
        );
        self::assertContains(
            "success|1 libellé(s) renommé(s) et 1 fusion(s) d'orthographe effectuée(s).",
            $flashes
        );
        self::assertContains(
            'error|Renommage refusé : « monster bleu » → « Monster Blue » — « Monster Blue » est déjà le libellé du produit « Redbull » (cette ligne vise « Monster »).',
            $flashes,
            'Refus c1 : message explicite rapporté à l\'UI (SB3).'
        );
        self::assertContains(
            'error|Renommage refusé : « produit generique » → « Schweppes » — « Schweppes » est le nom d\'un autre produit (« Schweppes »), pas un simple libellé.',
            $flashes,
            'Refus c2 : le nom canonique d\'un autre produit est refusé.'
        );
    }

    /**
     * Anti-régression : le formulaire historique (catégories seules,
     * sans alias_label) garde exactement son comportement d'origine.
     */
    public function test_bulk_categories_seules_comportement_inchange(): void
    {
        $r = $this->request('POST', '/admin/compta/aliases/bulk', [
            'bulk_raw' => ['monster bleu'],
            'bulk_cat' => ['Energie'],
        ], [], $this->adminId);

        self::assertStringEndsWith('/admin/compta/aliases', $this->location($r));

        $e = $this->alias('al_e');
        self::assertSame('monster bleu', $e['raw_description'], 'Aucun libellé modifié sans alias_label.');
        self::assertSame('Energie', $e['category']);

        $flashes = array_map(
            static fn (array $f): string => ($f['type'] ?? '') . '|' . (string) ($f['message'] ?? ''),
            $r['session']['_flash'] ?? []
        );
        self::assertContains(
            'success|1 catégorie(s) mise(s) à jour — 0 vente(s) synchronisée(s).',
            $flashes,
            'Un seul flash : celui des catégories.'
        );
        self::assertCount(1, $flashes);
    }

    /** Envoi vide : le flash « aucun changement » historique reste. */
    public function test_bulk_vide_aucun_changement(): void
    {
        $r = $this->request('POST', '/admin/compta/aliases/bulk', [], [], $this->adminId);

        self::assertStringEndsWith('/admin/compta/aliases', $this->location($r));
        $flashes = $r['session']['_flash'] ?? [];
        self::assertNotFalse(array_search(['type' => 'info', 'message' => 'Aucun changement à enregistrer.'], $flashes, true));
    }

    // -----------------------------------------------------------------
    //  Aides
    // -----------------------------------------------------------------

    /** Ligne d'alias par id, telle qu'en base (null si absente). */
    private function alias(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_aliases WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function addAlias(string $id, string $raw, string $target, ?string $category): void
    {
        $this->pdo->prepare(
            "INSERT INTO product_aliases (id, raw_description, product_key, category, kind)
             VALUES (?,?,?,?,'label')"
        )->execute([$id, $raw, $target, $category]);
    }
}
