<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Saisie de packs dans les comptages d'inventaire (F1/F2/F3) : deux champs
 * additifs `count[clé]` (unités) + `count_packs[clé]` (packs), conversion
 * SERVEUR en un point (CountPacks + PackResolver), `inventory_counts`
 * reste en unités, la note trace la saisie d'origine (« 2×24+3 »).
 *
 * Scénarios :
 *   1. F1 page Inventaire — 2 packs de 24 + 3 unités = 51 comptés, écart
 *      calculé sur la valeur CONVERTIE, pack résolu alors que la clé de
 *      comptage (graphie différente) diffère de la clé du pack.
 *   2. F2 comptage à l'aveugle — même contrat POST.
 *   3. F3 kiosque téléphone — même contrat POST (l'auto-save envoie tout
 *      le FormData), trace au nom du membre.
 *   4. Sans pack défini : champ packs ignoré silencieusement (D4) —
 *      comportement identique à avant, note NULL.
 *
 * F4 (relevé tablette SumUp) : hors périmètre packs — couvert par
 * InventoryTabletTest (aucun champ pack, tablet_qty en unités).
 *
 * Base `aeic_test` requise (les tests sont sautés sinon).
 */
final class InventoryCountPacksTest extends IntegrationTestCase
{
    private string $rootId = 'u_pack_root';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset([
            'sale_adjustments',
            'sales',
            'inventory_counts',
            'product_costs',
            'product_discontinued',
            'product_zero_since',
            'product_infinite',
            'product_packs',
            'purchases',
            'losses',
            'product_aliases',
            'product_stocks',
            'users',
        ]);
        $this->seedUser($this->rootId, 'pack-root@exemple.fr', 'Password123456', 'SUPERADMIN');

        // Pack défini sous la graphie du Réappro ; les comptages sont saisis
        // sous la clé « redbull-ice » (casse/ponctuation différentes) —
        // seul le PackResolver peut rapprocher les deux.
        $this->insertPack('Redbull Ice', 24);
        $this->insertPack('Cola Carton', 6);
    }

    public function test_f1_save_count_convertit_packs_et_calcule_le_gap_sur_la_valeur_convertie(): void
    {
        // Comptage précédent : le théorique de « redbull-ice » vaut 30.
        $this->insertCount('inv_pack_prev', '2026-09-20 10:00:00', 'redbull-ice', 30);

        $this->request('POST', '/admin/compta/inventaire/save', [
            'count'      => ['redbull-ice' => '3', 'snack-solo' => '7', 'cola-carton' => ''],
            'count_packs' => ['redbull-ice' => '2', 'snack-solo' => '5', 'cola-carton' => '1'],
        ], [], $this->rootId);

        // 2 packs de 24 + 3 unités = 51, écart = 51 − 30 (théorique) = 21 :
        // le gap doit être calculé sur la valeur CONVERTIE, pas sur « 3 ».
        $row = $this->lastCount('redbull-ice');
        self::assertNotNull($row, 'Le comptage converti doit être enregistré.');
        self::assertSame(51, (int) $row['counted_qty'], '2×24+3 = 51 unités comptées.');
        self::assertSame(30, (int) $row['theoretical_qty']);
        self::assertSame(21, (int) $row['gap'], 'L\'écart est calculé sur la valeur convertie (51 − 30).');
        self::assertSame('2×24+3', (string) $row['note'], 'La note trace la saisie d\'origine.');

        // Sans pack défini : champ packs ignoré silencieusement (D4),
        // comportement identique à avant — pas de note parasite.
        $solo = $this->lastCount('snack-solo');
        self::assertNotNull($solo);
        self::assertSame(7, (int) $solo['counted_qty'], 'Sans pack, le champ packs est ignoré (7, pas 7+5).');
        self::assertNull($solo['note']);

        // Unités vides + packs saisis : la ligne compte quand même (1×6).
        $seul = $this->lastCount('cola-carton');
        self::assertNotNull($seul, 'Une ligne avec seulement des packs doit être comptée.');
        self::assertSame(6, (int) $seul['counted_qty']);
        self::assertSame('1×6', (string) $seul['note']);
    }

    public function test_f2_save_blind_count_meme_contrat_post(): void
    {
        $this->insertCount('inv_pack_prev2', '2026-09-20 10:00:00', 'redbull-ice', 30);

        $r = $this->request('POST', '/admin/compta/inventaire/comptage/save', [
            'count'      => ['redbull-ice' => '3'],
            'count_packs' => ['redbull-ice' => '2'],
        ], [], $this->rootId);

        self::assertStringContainsString(
            '/admin/compta/inventaire/comptage',
            $this->location($r),
            'Le comptage à l\'aveugle redirige vers sa page.'
        );

        $row = $this->lastCount('redbull-ice');
        self::assertNotNull($row);
        self::assertSame(51, (int) $row['counted_qty'], 'Même conversion que la page Inventaire complète.');
        self::assertSame(21, (int) $row['gap'], 'Écart calculé sur la valeur convertie.');
        self::assertSame('2×24+3', (string) $row['note']);
    }

    public function test_f3_kiosque_inventaire_save_accepte_count_packs(): void
    {
        $this->insertCount('inv_pack_prev3', '2026-09-20 10:00:00', 'redbull-ice', 30);

        // Jeton kiosque (même clé de réglages que les pages kiosque).
        $this->pdo->prepare(
            "INSERT INTO settings (id, `key`, value) VALUES ('set_pack_t', 'reappro_kiosk_token', 'jetonpacks123')
             ON DUPLICATE KEY UPDATE value = 'jetonpacks123'"
        )->execute();

        $this->request('POST', '/kiosque/comptage/inventaire/save/jetonpacks123', [
            'count'      => ['redbull-ice' => '3'],
            'count_packs' => ['redbull-ice' => '2'],
            'who_prenom' => 'Jean',
            'who_nom'    => 'Dupont',
            'who_alias'  => 'vice-trésorier',
        ]);

        $row = $this->lastCount('redbull-ice');
        self::assertNotNull($row, 'Le comptage kiosque doit être enregistré.');
        self::assertSame(51, (int) $row['counted_qty'], 'L\'auto-save envoie tout le FormData : le serveur convertit (D6).');
        self::assertSame(21, (int) $row['gap']);
        self::assertSame('2×24+3', (string) $row['note']);
        self::assertSame('Jean Dupont (vice-trésorier)', (string) $row['created_by'], 'Trace au nom du membre, comme avant.');
    }

    public function test_champ_packs_absent_du_post_comportement_inchange(): void
    {
        // Le POST d'avant les packs (sans count_packs du tout) doit passer
        // à l'identique : aucune dépendance au nouveau champ.
        $this->request('POST', '/admin/compta/inventaire/save', [
            'count' => ['redbull-ice' => '9'],
        ], [], $this->rootId);

        $row = $this->lastCount('redbull-ice');
        self::assertNotNull($row);
        self::assertSame(9, (int) $row['counted_qty']);
        self::assertNull($row['note'], 'Pas de note sans packs saisis.');
    }

    /**
     * Insère une taille de pack (table product_packs).
     */
    private function insertPack(string $productKey, int $size): void
    {
        $this->pdo->prepare(
            'INSERT INTO product_packs (product_key, pack_size, updated_by) VALUES (?,?,?)'
        )->execute([$productKey, $size, 'test']);
    }

    /**
     * Insère un comptage inventaire (théorique = compté, aucun mouvement).
     */
    private function insertCount(string $id, string $countedAt, string $productKey, int $qty): void
    {
        $this->pdo->prepare(
            'INSERT INTO inventory_counts (id, counted_at, product_key, counted_qty, theoretical_qty, gap, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$id, $countedAt, $productKey, $qty, $qty, 0, 'test']);
    }

    /**
     * Dernier comptage d'un produit (le plus récent).
     *
     * @return array<string,mixed>|null
     */
    private function lastCount(string $productKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM inventory_counts WHERE product_key = ? ORDER BY counted_at DESC, created_at DESC LIMIT 1'
        );
        $stmt->execute([$productKey]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
