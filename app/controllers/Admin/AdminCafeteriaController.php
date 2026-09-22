<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\ProductAutoSync;
use App\Core\Permissions;
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

        $this->renderAdmin('admin/cafeteria/products', [
            'title'    => 'Produits',
            'products' => Product::allForAdmin(),
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

        $this->renderAdmin('admin/cafeteria/product_form', [
            'title'       => isset($product['id']) ? 'Modifier le produit' : 'Nouveau produit',
            'product'     => $product,
            'categories'  => ProductCategory::allForAdmin(),
        ]);
    }

    public function saveProduct(): void
    {
        $this->guardModule(Permissions::MODULE_CAFETERIA);

        $data = $_POST;
        $isNew = empty($data['id']);
        $data['price'] = parseFrenchFloat((string) ($data['price'] ?? '0'));

        $id = Product::save($data);

        $this->audit($isNew ? 'product.create' : 'product.update', 'product', $id);

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
