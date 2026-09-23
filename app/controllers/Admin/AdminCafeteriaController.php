<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\ProductAutoSync;
use App\Core\Compta\StockPublic;
use App\Core\Permissions;
use App\Models\InventoryCount;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\Sale;

/**
 * Gestion de la cafétéria : produits et catégories.
 */
final class AdminCafeteriaController extends AdminBaseController
{
    // -----------------------------------------------------------------
    //  Produits
    // -----------------------------------------------------------------

    public function products(): void
    {
        $this->guardModule(Permissions::MODULE_CAFETERIA);

        // Colonne Stock = stock THÉORIQUE (dernier comptage + achats
        // − ventes − pertes) : il suit les ventes en temps réel, comme
        // la carte publique. `products.stock` n'est qu'un repli pour
        // les produits non suivis en inventaire.
        $stockMap = StockPublic::menuStockMap();
        $products = Product::allForAdmin();
        foreach ($products as $i => $p) {
            $products[$i]['theoretical_stock'] = StockPublic::stockForMenuProduct(
                (string) ($p['name'] ?? ''),
                $stockMap
            );
        }

        $this->renderAdmin('admin/cafeteria/products', [
            'title'    => 'Produits',
            'products' => $products,
        ]);
    }

    public function productForm(?string $id = null): void
    {
        $this->guardModule(Permissions::MODULE_CAFETERIA);

        $product = ['is_available' => 1, 'is_active' => 1, 'stock' => 0];
        if ($id !== null) {
            $found = Product::find($id);
            if ($found !== null) {
                $product = $found;
            }
        }

        // Le formulaire s'ouvre sur le stock THÉORIQUE (dynamique) :
        // la saisie reste une déclaration de stock physique.
        $theoretical = self::theoreticalStockFor((string) ($product['name'] ?? ''));
        if ($theoretical !== null) {
            $product['stock'] = $theoretical;
        }

        $this->renderAdmin('admin/cafeteria/product_form', [
            'title'       => isset($product['id']) ? 'Modifier le produit' : 'Nouveau produit',
            'product'     => $product,
            'categories'  => ProductCategory::allForAdmin(),
            'theoretical' => $theoretical,
        ]);
    }

    /**
     * Stock théorique (dynamique) d'un nom de produit de la carte, ou
     * null si le produit n'est suivi en inventaire. Jamais fatale : la
     * page produits doit s'afficher même si la compta est indisponible.
     */
    private static function theoreticalStockFor(string $name): ?int
    {
        if (trim($name) === '') {
            return null;
        }

        try {
            $key = StockPublic::resolveKey($name);

            return $key !== null ? InventoryCount::theoreticalStock($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Crée ou met à jour une fiche produit (création si id absent).
     *
     * Le stock saisi devient la référence d'inventaire (InventoryCount) pour la clé résolue.
     */
    public function saveProduct(): void
    {
        $this->guardModule(Permissions::MODULE_CAFETERIA);

        $data = $_POST;
        $isNew = empty($data['id']);
        $data['price'] = parseFrenchFloat((string) ($data['price'] ?? '0'));

        // Ancien stock capturé AVANT la sauvegarde : la synchronisation
        // inventaire n'a lieu que si le stock a réellement changé (une
        // mise à jour sans changement de stock ne doit pas polluer
        // l'historique des comptages).
        $existing = $isNew ? null : Product::find((string) $data['id']);
        $oldStock = $existing !== null ? (int) ($existing['stock'] ?? 0) : null;

        // Référence de comparaison = stock THÉORIQUE (celui prérempli
        // dans le formulaire) : products.stock est souvent en retard
        // sur les ventes, comparer à lui créerait un comptage fictif à
        // chaque sauvegarde (changement de prix, description…).
        $reference = self::theoreticalStockFor((string) ($data['name'] ?? '')) ?? $oldStock;

        $id = Product::save($data);

        $this->audit($isNew ? 'product.create' : 'product.update', 'product', $id);

        // La saisie du stock sur la fiche déclare le stock physique :
        // elle devient la nouvelle référence d'inventaire (comme un
        // comptage). Mêmes conventions que AdminStockController::saveCount :
        // record() sans try/catch, audit, puis invalidation du cache carte.
        $newStock = (int) ($data['stock'] ?? 0);
        if ($oldStock === null || $newStock !== $reference) {
            $stockKey = StockPublic::resolveKey((string) ($data['name'] ?? ''));
            if ($stockKey !== null) {
                InventoryCount::record($stockKey, max(0, $newStock), null, Auth::id());

                $this->audit('inventory.stock_sync', 'product', $id, [
                    'name' => (string) ($data['name'] ?? ''),
                    'old'  => $reference,
                    'new'  => $newStock,
                ]);
            }

            // Le stock de la fiche bouge : la carte publique suit.
            StockPublic::invalidate();
        }

        $this->setFlash('success', 'Produit enregistré.');
        redirect(url('/admin/cafeteria'));
    }

    public function deleteProduct(string $id): void
    {
        $this->guardModule(Permissions::MODULE_CAFETERIA);

        Product::deleteRow($id);
        $this->audit('product.delete', 'product', $id);
        $this->setFlash('success', 'Produit supprimé.');
        redirect(url('/admin/cafeteria'));
    }

    /**
     * Synchronisation manuelle de la carte (bouton de la page Produits) :
     * crée les fiches des nouveaux produits détectés dans les ventes,
     * achats, pertes et comptages. Le CSRF est vérifié globalement par le
     * routeur sur tous les POST ; un échec de synchro ne casse jamais la
     * page (flash de succès neutre).
     */
    public function syncProducts(): void
    {
        $this->guardModule(Permissions::MODULE_CAFETERIA);

        $created = [];
        try {
            $created = ProductAutoSync::sync()['created'];
        } catch (\Throwable) {
            $created = [];
        }

        $this->audit('product.autosync', 'product', null, ['created' => count($created)]);

        if ($created === []) {
            $this->setFlash('success', 'Carte déjà à jour.');
        } else {
            $this->setFlash('success', sprintf(
                'Carte synchronisée — %d nouveau(x) produit(s) : %s.',
                count($created),
                implode(', ', $created)
            ));
        }

        redirect(url('/admin/cafeteria'));
    }

    // -----------------------------------------------------------------
    //  Catégories
    // -----------------------------------------------------------------

    public function categories(): void
    {
        $this->guardModule(Permissions::MODULE_CAFETERIA);

        // Catégories du mapping des libellés absentes de la carte : la vue
        // propose leur création en un clic (section dédiée). Comparaison
        // insensible à la casse pour ne pas proposer un doublon qui ne
        // différerait que par la casse.
        $existing = array_map(
            static fn (array $c): string => mb_strtolower(trim((string) ($c['name'] ?? ''))),
            ProductCategory::allForAdmin()
        );
        $mappingMissing = [];
        foreach (ProductAlias::distinctCategories() as $name) {
            $name = trim($name);
            if ($name !== '' && !in_array(mb_strtolower($name), $existing, true)) {
                $mappingMissing[] = $name;
            }
        }

        $this->renderAdmin('admin/cafeteria/categories', [
            'title'          => 'Catégories',
            'categories'     => ProductCategory::allForAdmin(),
            'mappingMissing' => $mappingMissing,
        ]);
    }

    public function saveCategory(): void
    {
        $this->guardModule(Permissions::MODULE_CAFETERIA);

        // Renommage : le nouveau nom est propagé sur le mapping des libellés
        // (alias) et sur les ventes déjà catégorisées, pour que tout reste
        // synchronisé avec les catégories existantes.
        $renamed = 0;
        $id = (string) ($_POST['id'] ?? '');
        $newName = trim((string) ($_POST['name'] ?? ''));
        $existing = $id !== '' ? ProductCategory::find($id) : null;
        if ($existing !== null) {
            $oldName = trim((string) ($existing['name'] ?? ''));
            if ($oldName !== '' && $newName !== '' && $oldName !== $newName) {
                $renamed = ProductAlias::renameCategory($oldName, $newName)
                    + Sale::renameCategory($oldName, $newName);
            }
        }

        $newId = ProductCategory::save($_POST);

        $this->audit('category.save', 'product_category', $newId, ['renamed_refs' => $renamed]);
        if ($renamed > 0) {
            $this->setFlash('success', sprintf('Catégorie enregistrée — %d référence(s) (alias, ventes) renommée(s).', $renamed));
        } else {
            $this->setFlash('success', 'Catégorie enregistrée.');
        }
        redirect(url('/admin/cafeteria/categories'));
    }

    public function deleteCategory(string $id): void
    {
        $this->guardModule(Permissions::MODULE_CAFETERIA);

        ProductCategory::deleteRow($id);
        $this->audit('category.delete', 'product_category', $id);
        $this->setFlash('success', 'Catégorie supprimée.');
        redirect(url('/admin/cafeteria/categories'));
    }

    /**
     * Ajout d'une catégorie depuis la section « mapping des libellés » de
     * la page Catégories : création silencieuse si absente, flash adapté
     * si elle est déjà présente (création idempotente).
     */
    public function addMappingCategory(): void
    {
        $this->guardModule(Permissions::MODULE_CAFETERIA);

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $this->setFlash('error', 'Nom de catégorie requis (100 caractères maximum).');
            redirect(url('/admin/cafeteria/categories'));
        }

        $created = ProductCategory::ensure($name, Auth::id());
        $this->audit('category.ensure', 'product_category', null, ['name' => $name]);

        $this->setFlash(
            'success',
            $created
                ? sprintf('Catégorie « %s » ajoutée.', $name)
                : sprintf('La catégorie « %s » est déjà présente.', $name)
        );
        redirect(url('/admin/cafeteria/categories'));
    }

    /**
     * Synchronise les catégories du mapping des libellés : chaque catégorie
     * d'alias absente de la carte est créée (bouton « Tout ajouter »).
     */
    public function syncMappingCategories(): void
    {
        $this->guardModule(Permissions::MODULE_CAFETERIA);

        $created = 0;
        foreach (ProductAlias::distinctCategories() as $name) {
            if (ProductCategory::ensure(trim($name), Auth::id())) {
                $created++;
            }
        }

        $this->audit('category.sync_mapping', 'product_category', null, ['created' => $created]);
        $this->setFlash('success', sprintf('%d catégorie(s) ajoutée(s) depuis le mapping.', $created));
        redirect(url('/admin/cafeteria/categories'));
    }
}
