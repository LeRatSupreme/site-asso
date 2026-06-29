<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Product;
use App\Models\ProductCategory;

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
        $this->guard();

        $this->renderAdmin('admin/cafeteria/products', [
            'title'    => 'Produits',
            'products' => Product::allForAdmin(),
        ]);
    }

    public function productForm(?string $id = null): void
    {
        $this->guard();

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
        $this->guard();

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
        $this->guard();

        Product::deleteRow($id);
        $this->audit('product.delete', 'product', $id);
        $this->setFlash('success', 'Produit supprimé.');
        redirect(url('/admin/cafeteria'));
    }

    // -----------------------------------------------------------------
    //  Catégories
    // -----------------------------------------------------------------

    public function categories(): void
    {
        $this->guard();

        $this->renderAdmin('admin/cafeteria/categories', [
            'title'      => 'Catégories',
            'categories' => ProductCategory::allForAdmin(),
        ]);
    }

    public function saveCategory(): void
    {
        $this->guard();

        $id = ProductCategory::save($_POST);

        $this->audit('category.save', 'product_category', $id);
        $this->setFlash('success', 'Catégorie enregistrée.');
        redirect(url('/admin/cafeteria/categories'));
    }

    public function deleteCategory(string $id): void
    {
        $this->guard();

        ProductCategory::deleteRow($id);
        $this->audit('category.delete', 'product_category', $id);
        $this->setFlash('success', 'Catégorie supprimée.');
        redirect(url('/admin/cafeteria/categories'));
    }
}
