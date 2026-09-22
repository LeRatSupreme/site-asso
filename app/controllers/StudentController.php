<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Mailer;
use App\Core\Middleware;
use App\Core\Validator;
use App\Models\CafeteriaOrder;
use App\Models\Cart;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Registration;
use App\Models\Setting;
use App\Models\User;
use App\Models\VerificationToken;

/**
 * Espace élève : tableau de bord, profil, inscriptions, commandes et cafétéria.
 *
 * Toutes les routes requièrent un utilisateur connecté (ELEVE, TRESORERIE ou ADMIN).
 */
final class StudentController extends Controller
{
    /**
     * Tableau de bord élève.
     */
    public function index(): void
    {
        $this->guard();

        $user = Auth::user();
        $userId = (string) $user['id'];

        $this->render('student/dashboard', [
            'title'        => 'Mon espace — AEIC',
            'description'  => 'Tableau de bord de l\'espace membre AEIC.',
            'user'         => $user,
            'upcoming'     => Event::upcoming(3),
            'myUpcoming'   => Registration::upcomingForUser($userId, 3),
            'recentOrders' => CafeteriaOrder::forUser($userId, 5),
        ], 'student');
    }

    // -----------------------------------------------------------------
    //  Profil
    // -----------------------------------------------------------------

    /**
     * Formulaire d'édition du profil.
     */
    public function profile(): void
    {
        $this->guard();

        $this->render('student/profile', [
            'title'       => 'Mon profil — AEIC',
            'description' => 'Modifier mes informations personnelles.',
            'user'        => Auth::user(),
        ], 'student');
    }

    /**
     * Mise à jour du profil (infos + changement de mot de passe optionnel).
     */
    public function updateProfile(): void
    {
        $this->guard();

        $user = Auth::user();
        $userId = (string) $user['id'];

        $prenom = trim((string) ($_POST['prenom'] ?? ''));
        $nom = trim((string) ($_POST['nom'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $oldPassword = (string) ($_POST['old_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirmation'] ?? '');

        $errors = [];

        if ($prenom === '') {
            $errors[] = 'Le prénom est obligatoire.';
        }
        if ($nom === '') {
            $errors[] = 'Le nom est obligatoire.';
        }
        if (!Validator::isValidEmail($email)) {
            $errors[] = 'L\'adresse e-mail n\'est pas valide.';
        }

        // E-mail déjà utilisé par un autre compte ?
        $existing = User::findByEmail($email);
        if ($existing !== null && (string) $existing['id'] !== $userId) {
            $errors[] = 'Cette adresse e-mail est déjà utilisée.';
        }

        // Changement de mot de passe (optionnel).
        $passwordChanged = $newPassword !== '';
        if ($passwordChanged) {
            if ($user['password'] === null || !password_verify($oldPassword, (string) $user['password'])) {
                $errors[] = 'L\'ancien mot de passe est incorrect.';
            } elseif (mb_strlen($newPassword) < Validator::PASSWORD_MIN) {
                $errors[] = sprintf('Le nouveau mot de passe doit contenir au moins %d caractères.', Validator::PASSWORD_MIN);
            } elseif (!Validator::isStrongEnough($newPassword)) {
                $errors[] = 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
            } elseif ($newPassword !== $confirm) {
                $errors[] = 'La confirmation du mot de passe ne correspond pas.';
            }
        }

        if ($errors !== []) {
            $this->setFlash('error', implode(' ', $errors));
            redirect(url('/eleve/profile'));
        }

        // E-mail réellement modifié (et pas re-soumis à l'identique) ?
        // (l'e-mail stocké est normalisé : comparaison sur la même forme)
        $emailChanged = User::normalizeEmail($email) !== (string) $user['email'];

        User::updateProfile($userId, [
            'prenom' => $prenom,
            'nom'    => $nom,
            'email'  => $email,
        ]);

        if ($passwordChanged) {
            User::changePassword($userId, $newPassword);
        }

        // Nouvelle adresse : la vérification doit être refaite (l'ancienne
        // preuve ne porte plus). L'utilisateur reste connecté mais sera
        // bloqué à sa prochaine connexion tant que l'e-mail n'est pas confirmé.
        if ($emailChanged) {
            $stmt = db()->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ?');
            $stmt->execute([$userId]);

            if (Mailer::isSmtpConfigured()) {
                $token = VerificationToken::createToken($userId);

                try {
                    Mailer::send('verify_email', User::normalizeEmail($email), 'Confirme ton adresse e-mail — AEIC', [
                        'prenom'    => $prenom,
                        'verifyUrl' => APP_URL . url('/verify-email?token=' . $token),
                        'expiresIn' => VerificationToken::EXPIRES_HOURS,
                    ]);
                } catch (\Throwable) {
                    // L'échec d'envoi ne doit pas bloquer la mise à jour.
                }
            }

            $this->setFlash('info', 'Profil mis à jour. Ton adresse e-mail a changé : confirme ta nouvelle adresse via l\'e-mail qui vient de t\'être envoyé (elle sera exigée à ta prochaine connexion).');
            redirect(url('/eleve/profile'));
        }

        $this->setFlash('success', 'Profil mis à jour.');
        redirect(url('/eleve/profile'));
    }

    // -----------------------------------------------------------------
    //  Mes inscriptions
    // -----------------------------------------------------------------

    /**
     * Liste des inscriptions de l'utilisateur.
     */
    public function inscriptions(): void
    {
        $this->guard();

        $user = Auth::user();

        $this->render('student/inscriptions', [
            'title'         => 'Mes inscriptions — AEIC',
            'description'   => 'Les événements auxquels je suis inscrit.',
            'registrations' => Registration::forUser((string) $user['id']),
        ], 'student');
    }

    // -----------------------------------------------------------------
    //  Mes commandes
    // -----------------------------------------------------------------

    /**
     * Historique des commandes cafétéria.
     */
    public function commandes(): void
    {
        $this->guard();

        $user = Auth::user();
        $orders = CafeteriaOrder::forUser((string) $user['id']);

        // Détail des lignes pour chaque commande.
        $itemsByOrder = [];
        foreach ($orders as $order) {
            $itemsByOrder[(string) $order['id']] = CafeteriaOrder::items((string) $order['id']);
        }

        $this->render('student/commandes', [
            'title'        => 'Mes commandes — AEIC',
            'description'  => 'Historique de mes commandes cafétéria.',
            'orders'       => $orders,
            'itemsByOrder' => $itemsByOrder,
        ], 'student');
    }

    // -----------------------------------------------------------------
    //  Cafétéria
    // -----------------------------------------------------------------

    /**
     * Catalogue de la cafétéria + panier courant.
     */
    public function cafeteria(): void
    {
        $this->guard();

        $ordersEnabled = Setting::getBool('orders_enabled', true);

        // Disponibles d'abord dans chaque catégorie, épuisés en fin de
        // groupe pour mettre en avant ce qui est commandable.
        $products = self::orderAvailableFirst(Product::available());

        $this->render('student/cafeteria', [
            'title'         => 'Commander à la cafétéria — AEIC',
            'description'   => 'Catalogue des produits de la cafétéria AEIC.',
            'categories'    => ProductCategory::active(),
            'products'      => $products,
            'cart'          => $this->cart(),
            'ordersEnabled' => $ordersEnabled,
            'sumupLink'     => sumup_enabled() ? sumup_link() : null,
        ], 'student');
    }

    /**
     * Ajoute un produit au panier (session).
     */
    public function cartAdd(): void
    {
        $this->guard();

        $productId = (string) ($_POST['product_id'] ?? '');
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

        $product = Product::find($productId);
        if ($product === null || (int) $product['is_active'] !== 1 || (int) $product['is_available'] !== 1) {
            $this->setFlash('error', 'Produit introuvable ou indisponible.');
            redirect(url('/eleve/cafeteria'));
        }

        $cart = $this->cart();
        $cart->add($product, $quantity);
        $this->saveCart($cart);

        $this->setFlash('success', sprintf('« %s » ajouté au panier.', e($product['name'])));
        redirect(url('/eleve/cafeteria'));
    }

    /**
     * Retire un produit du panier.
     */
    public function cartRemove(): void
    {
        $this->guard();

        $productId = (string) ($_POST['product_id'] ?? '');

        $cart = $this->cart();
        $cart->remove($productId);
        $this->saveCart($cart);

        redirect(url('/eleve/cafeteria'));
    }

    /**
     * Vide le panier.
     */
    public function cartClear(): void
    {
        $this->guard();

        $this->saveCart(new Cart());

        redirect(url('/eleve/cafeteria'));
    }

    /**
     * Valide la commande : recalcule le total serveur, décrémente les stocks,
     * crée la commande en transaction.
     */
    public function checkout(): void
    {
        $this->guard();

        if (!Setting::getBool('orders_enabled', true)) {
            $this->setFlash('error', 'Les commandes sont actuellement désactivées.');
            redirect(url('/eleve/cafeteria'));
        }

        $cart = $this->cart();
        if ($cart->isEmpty()) {
            $this->setFlash('error', 'Votre panier est vide.');
            redirect(url('/eleve/cafeteria'));
        }

        $user = Auth::user();

        try {
            CafeteriaOrder::create((string) $user['id'], $cart->items(), (string) ($_POST['notes'] ?? ''));
        } catch (\Throwable $e) {
            $this->setFlash('error', $e->getMessage());
            redirect(url('/eleve/cafeteria'));
        }

        // Commande validée : on vide le panier.
        $this->saveCart(new Cart());

        $this->setFlash('success', 'Commande passée ! Vous pouvez suivre son statut dans « Mes commandes ».');
        redirect(url('/eleve/commandes'));
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Garde d'accès : connexion requise (ELEVE/TRESORERIE/ADMIN).
     */
    private function guard(): void
    {
        Middleware::requireLogin();
    }

    /**
     * Charge le panier depuis la session.
     */
    private function cart(): Cart
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $raw = $_SESSION['cart'] ?? [];

        $items = [];
        if (is_array($raw)) {
            foreach ($raw as $id => $entry) {
                if (is_array($entry) && isset($entry['product'], $entry['quantity'])) {
                    $items[(string) $id] = [
                        'product'   => $entry['product'],
                        'quantity'  => (int) $entry['quantity'],
                    ];
                }
            }
        }

        return new Cart($items);
    }

    /**
     * Sauvegarde le panier en session.
     */
    private function saveCart(Cart $cart): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SESSION['cart'] = $cart->items();
    }

    /**
     * Réordonne la liste des produits (déjà triée par catégorie, ordre,
     * nom) pour faire passer les produits disponibles avant les épuisés,
     * à l'intérieur de chaque catégorie. L'ordre des catégories et l'ordre
     * d'affichage interne (ordre produit puis nom) restent inchangés.
     *
     * @param list<array<string,mixed>> $products
     * @return list<array<string,mixed>>
     */
    private static function orderAvailableFirst(array $products): array
    {
        $catRank = [];
        $indexed = [];
        foreach ($products as $i => $p) {
            $cat = (string) ($p['category_name'] ?? '');
            if (!array_key_exists($cat, $catRank)) {
                $catRank[$cat] = count($catRank);
            }
            // products.stock est toujours présent : <= 0 = épuisé (en dernier).
            $soldOut = (int) ($p['stock'] ?? 0) <= 0 ? 1 : 0;
            $indexed[] = [$catRank[$cat], $soldOut, $i, $p];
        }

        usort($indexed, static function (array $a, array $b): int {
            return $a[0] <=> $b[0]
                ?: $a[1] <=> $b[1]
                ?: $a[2] <=> $b[2];
        });

        return array_map(static function (array $row): array {
            return $row[3];
        }, $indexed);
    }
}
