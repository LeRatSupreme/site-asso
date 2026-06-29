<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Mailer;
use App\Models\CafeteriaOrder;
use App\Models\OrderWorkflow;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;

/**
 * Gestion de la cafétéria : produits, catégories, commandes, caisse (POS).
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

    // -----------------------------------------------------------------
    //  Commandes
    // -----------------------------------------------------------------

    public function orders(): void
    {
        $this->guard();

        $this->renderAdmin('admin/cafeteria/orders', [
            'title'        => 'Commandes',
            'orders'       => CafeteriaOrder::allForAdmin(),
            'itemsByOrder' => [],
            'statuses'     => OrderWorkflow::statuses(),
        ]);
    }

    public function changeStatus(string $id): void
    {
        $this->guard();

        $status = strtoupper((string) ($_POST['status'] ?? ''));

        try {
            CafeteriaOrder::changeStatus($id, $status);
            $this->audit('order.status_change', 'order', $id, ['status' => $status]);
            $this->setFlash('success', 'Statut mis à jour.');

            // Notification par e-mail quand la commande est prête (et associée à un compte).
            if ($status === OrderWorkflow::READY) {
                $order = CafeteriaOrder::find($id);
                if ($order !== null && !empty($order['user_id'])) {
                    $user = User::find((string) $order['user_id']);
                    if ($user !== null && !empty($user['email'])) {
                        try {
                            Mailer::send('order_ready', (string) $user['email'], 'Votre commande est prête', [
                                'prenom'  => $user['prenom'] ?? '',
                                'orderId' => (string) ($order['number'] ?? $id),
                                'total'   => formatPrice($order['total'] ?? 0),
                            ]);
                        } catch (\Throwable) {
                            // L'envoi d'e-mail ne doit pas bloquer le changement de statut.
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->setFlash('error', $e->getMessage());
        }

        redirect(url('/admin/cafeteria/commandes'));
    }

    // -----------------------------------------------------------------
    //  Caisse (POS)
    // -----------------------------------------------------------------

    public function pos(): void
    {
        $this->guard();

        $this->renderAdmin('admin/cafeteria/pos', [
            'title'      => 'Caisse (POS)',
            'categories' => ProductCategory::active(),
            'products'   => Product::available(),
            'sumupLink'  => sumup_enabled() ? sumup_link() : null,
        ]);
    }

    /**
     * Validation d'une vente au comptoir (paiement comptant ou SumUp).
     * Le panier est reçu sous forme de paires produit_id => quantité.
     */
    public function posCheckout(): void
    {
        $this->guard();

        $items = $_POST['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $cart = new \App\Models\Cart();
        foreach ($items as $productId => $qty) {
            $quantity = (int) $qty;
            if ($quantity <= 0) {
                continue;
            }
            $product = Product::find((string) $productId);
            if ($product !== null) {
                $cart->add($product, $quantity);
            }
        }

        if ($cart->isEmpty()) {
            $this->setFlash('error', 'Panier vide.');
            redirect(url('/admin/cafeteria/pos'));
        }

        try {
            $orderId = CafeteriaOrder::create(null, $cart->items(), 'Vente au comptoir');
            // Au comptoir, la commande passe directement en CONFIRMED.
            CafeteriaOrder::changeStatus($orderId, OrderWorkflow::CONFIRMED);
            $this->audit('pos.sale', 'order', $orderId);
            $this->setFlash('success', sprintf('Vente enregistrée : %s', formatPrice($cart->total())));
        } catch (\Throwable $e) {
            $this->setFlash('error', $e->getMessage());
        }

        redirect(url('/admin/cafeteria/pos'));
    }
}
