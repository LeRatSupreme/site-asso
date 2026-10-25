<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\Kiosk;
use App\Core\Compta\KioskPages;
use App\Core\Auth;
use App\Core\Csrf;
use App\Models\Kiosque;
use App\Models\Setting;

/**
 * Page « Kiosques » (groupe Système) : tous les liens kiosque au même
 * endroit — hub tout-en-un, comptages, liste de courses, récap du jour,
 * réappro — avec copie en un clic et régénération du jeton (qui révoque
 * TOUS les liens d'un coup).
 *
 * S'y ajoutent les KIOSQUES PERSONNALISÉS (table `kiosques`) : l'admin
 * crée autant de kiosques que de types de personnes (trésorier, bar,
 * membres…), coche les tuiles autorisées (catalogue KioskPages) et
 * partage le lien révélé. Création, édition, suppression, régénération
 * individuelle du jeton — avec audit systématique.
 *
 * Les jetons ne sont JAMAIS envoyés dans le HTML initial (mission §10 :
 * aucune capture ne doit suffire) : les liens sont masqués par défaut et
 * révélés à la demande via POST /admin/kiosques/reveal (CSRF + garde de
 * la page + audit « kiosques.reveal »).
 */
final class AdminKiosquesController extends AdminBaseController
{
    /**
     * Définitions des pages MEMBRES (jeton reappro_kiosk_token) : saisie
     * et suivi, sans données financières sensibles (le récap membres
     * n'affiche que le CA). Aucune URL ici — elle n'est construite qu'à
     * la révélation.
     *
     * @return list<array{emoji:string,label:string,desc:string,path:string,main?:bool,scope:string}>
     */
    private function memberPages(): array
    {
        return [
            [
                'emoji' => '🏠',
                'label' => 'Hub kiosque — tout-en-un',
                'desc'  => "LA page à partager aux membres : comptage caisse, comptage inventaire, liste de courses et ventes du jour. L'identité (prénom, nom, rôle) y est obligatoire et tracée.",
                'path'  => '/kiosque/comptage/',
                'main'  => true,
                'scope' => 'membres',
            ],
            [
                'emoji' => '💵',
                'label' => 'Comptage caisse',
                'desc'  => 'Raccourci direct : compter le liquide présent dans la caisse.',
                'path'  => '/kiosque/comptage/caisse/',
                'scope' => 'membres',
            ],
            [
                'emoji' => '📦',
                'label' => 'Comptage inventaire',
                'desc'  => "Comptage à l'aveugle des produits, pause/reprise dynamique.",
                'path'  => '/kiosque/comptage/inventaire/',
                'scope' => 'membres',
            ],
            [
                'emoji' => '🛒',
                'label' => 'Liste de courses',
                'desc'  => "Ce qu'il faut racheter — cases cochées partagées en temps réel entre tous.",
                'path'  => '/kiosque/liste/',
                'scope' => 'membres',
            ],
            [
                'emoji' => '📊',
                'label' => 'Ventes du jour (membres)',
                'desc'  => 'Récap limité : CA du jour et produits vendus, sans bénéfice ni paiements.',
                'path'  => '/kiosque/comptage/jour-membre/',
                'scope' => 'membres',
            ],
        ];
    }

    /**
     * Définitions des cartes ADMINS. Les liens kiosque (jeton dédié
     * admin, détaché du jeton membres, sans connexion) portent un path +
     * un scope ; les pages /admin/* classiques gardent leur URL directe
     * (connexion admin requise, aucun jeton dedans).
     *
     * @return list<array{emoji:string,label:string,desc:string,main?:bool,path?:string,scope?:string,url?:string,kiosk?:bool}>
     */
    private function adminPages(): array
    {
        return [
            [
                'emoji' => '🖥️',
                'label' => 'Hub admin kiosque',
                'desc'  => "LE menu admin sur ton téléphone : bandeau jour/semaine/mois + tuiles vers toutes les pages financières. Lien kiosque dédié, sans connexion.",
                'path'  => '/kiosque/admin/',
                'scope' => 'admins',
                'kiosk' => true,
                'main'  => true,
            ],
            [
                'emoji' => '📊',
                'label' => 'Récap du jour — complet',
                'desc'  => 'CA, bénéfice, liquide/carte, top produits du jour. Lien kiosque, sans connexion.',
                'path'  => '/kiosque/comptage/jour/',
                'scope' => 'admins',
                'kiosk' => true,
            ],
            [
                'emoji' => '📈',
                'label' => 'Analytique (kiosque)',
                'desc'  => "Les mêmes tableaux de bord que sur PC : CA, bénéfice, top produits, heatmap des heures, détail par produit — sur ton téléphone. Lien kiosque, sans connexion.",
                'path'  => '/kiosque/admin/analytics/',
                'scope' => 'admins',
                'kiosk' => true,
            ],
            [
                'emoji' => '💡',
                'label' => 'Idées du moment',
                'desc'  => 'Recommandations commerciales du moteur : appliquer, reporter ou ignorer — un humain décide, rien n\'est automatique. Lien kiosque, sans connexion.',
                'path'  => '/kiosque/admin/reco/',
                'scope' => 'admins',
                'kiosk' => true,
            ],
            [
                'emoji' => '🧾',
                'label' => 'Livre comptable',
                'desc'  => 'Date | Objet | Débit | Crédit avec tickets et équilibrage — et saisie express des dépenses en 30 s depuis le téléphone. Lien kiosque, sans connexion.',
                'path'  => '/kiosque/admin/ledger/',
                'scope' => 'admins',
                'kiosk' => true,
            ],
            [
                'emoji' => '📈',
                'label' => 'Analytics',
                'desc'  => 'Tableaux de bord analytiques complets (CA, marges, heures, produits). Connexion admin requise.',
                'url'   => '/admin/analytics',
            ],
            [
                'emoji' => '🧮',
                'label' => 'Dashboard compta',
                'desc'  => "Vue d'ensemble de la période : CA, bénéfice, alertes, derniers imports. Connexion admin requise.",
                'url'   => '/admin/compta',
            ],
            [
                'emoji' => '📜',
                'label' => 'Journal des ventes',
                'desc'  => 'Toutes les ventes ligne par ligne, filtrables. Connexion admin requise.',
                'url'   => '/admin/compta/ventes',
            ],
            [
                'emoji' => '📅',
                'label' => 'Bilan annuel',
                'desc'  => 'Bilan mois par mois (CA, bénéfice, TVA). Connexion admin requise.',
                'url'   => '/admin/compta/annuel',
            ],
            [
                'emoji' => '📈',
                'label' => 'Réappro (téléphone)',
                'desc'  => 'Réapprovisionnement complet (coûts, packs, fournisseurs). Lien kiosque, sans connexion (jeton membres).',
                'path'  => '/kiosque/reappro/',
                'scope' => 'membres',
                'kiosk' => true,
            ],
        ];
    }

    public function index(): void
    {
        $this->guardSystemOrPage('kiosques');

        // Page à jeton : jamais mémorisable (les liens y sont manipulés).
        Kiosk::sendNoStore();

        $this->renderAdmin('admin/kiosques/index', [
            'title'          => 'Kiosques',
            'user'           => Auth::user(),
            'memberPages'    => $this->memberPages(),
            'adminPages'     => $this->adminPages(),
            'customKiosques' => Kiosque::all(),
            'tileCatalog'    => KioskPages::all(),
            'optionCatalog'  => KioskPages::optionCatalog(),
        ]);
    }

    /**
     * Normalise les tuiles cochées d'un formulaire de kiosque : seuls les
     * ids présents dans le catalogue KioskPages sont conservés, dédou-
     * blonnés, puis RÉORDONNÉS selon l'ordre du catalogue (l'ordre du hub
     * ne dépend jamais de l'ordre de coche).
     *
     * @return list<string>
     */
    private function normalizePages(): array
    {
        $posted = $_POST['pages'] ?? [];
        if (!is_array($posted)) {
            return [];
        }

        $valid = array_fill_keys(KioskPages::validIds(), true);
        $picked = [];
        foreach ($posted as $id) {
            $id = (string) $id;
            if (isset($valid[$id])) {
                $picked[$id] = true;
            }
        }

        $pages = [];
        foreach (KioskPages::validIds() as $id) {
            if (isset($picked[$id])) {
                $pages[] = $id;
            }
        }

        return $pages;
    }

    /**
     * Nom saisi pour un kiosque (create/save) : trimé ; vide ou > 120
     * caractères = invalide.
     */
    private function postedName(): string
    {
        return trim((string) ($_POST['name'] ?? ''));
    }

    /**
     * Normalise les OPTIONS cochées d'un formulaire de kiosque : seuls
     * les ids du catalogue KioskPages sont conservés (méthode pure du
     * catalogue, réordonnée, dédoublonnée).
     *
     * @return list<string>
     */
    private function normalizeOptions(): array
    {
        return KioskPages::normalizeOptions($_POST['options'] ?? null);
    }

    /**
     * Crée un kiosque personnalisé : nom + tuiles cochées + options de
     * visibilité. Le jeton est généré par le modèle et n'est JAMAIS
     * renvoyé au HTML — l'admin le découvre via « Afficher le lien »
     * (reveal audité).
     */
    public function create(): void
    {
        $this->guardSystemOrPage('kiosques');

        $name = $this->postedName();
        if ($name === '' || mb_strlen($name) > 120) {
            $this->setFlash('error', 'Nom du kiosque requis (120 caractères max).');
            redirect(url('/admin/kiosques'));
        }

        $pages = $this->normalizePages();
        if ($pages === []) {
            $this->setFlash('error', 'Choisis au moins une page pour ce kiosque.');
            redirect(url('/admin/kiosques'));
        }

        $options = $this->normalizeOptions();

        $id = Kiosque::create($name, $pages, (string) Auth::user()['email'], $options);

        $this->audit('kiosques.create', 'kiosque', $id, ['name' => $name, 'pages' => $pages, 'options' => $options]);
        $this->setFlash('success', 'Kiosque « ' . $name . ' » créé — révèle son lien pour l\'envoyer aux personnes concernées.');

        redirect(url('/admin/kiosques'));
    }

    /**
     * Met à jour un kiosque existant (nom + tuiles) : mêmes validations
     * que la création. Le jeton et le lien restent inchangés.
     */
    public function save(string $id): void
    {
        $this->guardSystemOrPage('kiosques');

        $k = Kiosque::find($id);
        if ($k === null) {
            $this->setFlash('error', 'Kiosque introuvable.');
            redirect(url('/admin/kiosques'));
        }

        $name = $this->postedName();
        if ($name === '' || mb_strlen($name) > 120) {
            $this->setFlash('error', 'Nom du kiosque requis (120 caractères max).');
            redirect(url('/admin/kiosques'));
        }

        $pages = $this->normalizePages();
        if ($pages === []) {
            $this->setFlash('error', 'Choisis au moins une page pour ce kiosque.');
            redirect(url('/admin/kiosques'));
        }

        $options = $this->normalizeOptions();

        Kiosque::update($id, $name, $pages, $options);

        $this->audit('kiosques.save', 'kiosque', $id, ['name' => $name, 'pages' => $pages, 'options' => $options]);
        $this->setFlash('success', 'Kiosque « ' . $name . ' » mis à jour.');

        redirect(url('/admin/kiosques'));
    }

    /**
     * Supprime définitivement un kiosque : son lien (jeton) cesse de
     * fonctionner immédiatement.
     */
    public function delete(string $id): void
    {
        $this->guardSystemOrPage('kiosques');

        $k = Kiosque::find($id);
        if ($k === null) {
            $this->setFlash('error', 'Kiosque introuvable.');
            redirect(url('/admin/kiosques'));
        }

        Kiosque::delete($id);

        $this->audit('kiosques.delete', 'kiosque', $id, ['name' => $k['name']]);
        $this->setFlash('success', 'Kiosque « ' . $k['name'] . ' » supprimé — son lien ne fonctionne plus.');

        redirect(url('/admin/kiosques'));
    }

    /**
     * Révèle un lien kiosque à la demande (POST, CSRF, garde identique à
     * la page) : renvoie l'URL complète en JSON pour le JS de la page.
     * Le HTML initial ne contient aucun jeton ; chaque révélation est
     * audité (« kiosques.reveal ») SANS la valeur du jeton.
     */
    public function reveal(): void
    {
        $this->guardSystemOrPage('kiosques');

        // CSRF : le routeur vérifie déjà tout POST hors /kiosque/ ; la
        // vérification explicite ici garantit le refus au niveau du
        // contrôleur (et la rend testable de bout en bout).
        if (!Csrf::verify(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            $this->json(['ok' => false, 'error' => 'Session expirée — recharge la page.'], 403);
        }

        // Kiosque personnalisé : la portée « custom » désigne un kiosque
        // de la table `kiosques` (par son id), pas un couple scope/path
        // legacy. L'URL hub n'est construite qu'ici — jamais dans le HTML.
        if (($_POST['scope'] ?? '') === 'custom') {
            $k = Kiosque::find(trim((string) ($_POST['id'] ?? '')));
            if ($k === null) {
                $this->json(['ok' => false, 'error' => 'Lien kiosque inconnu.'], 404);
            }

            $url = url('/kiosque/hub/' . $k['token']);
            $this->audit('kiosques.reveal', 'kiosque', $k['id'], ['scope' => 'custom', 'page' => 'hub']);
            $this->json(['ok' => true, 'scope' => 'custom', 'id' => $k['id'], 'url' => $url]);
        }

        $scope = ($_POST['scope'] ?? '') === 'admins' ? 'admins' : 'membres';
        $path  = trim((string) ($_POST['path'] ?? ''));

        // Liste blanche : seul un chemin kiosque réellement proposé par
        // la page peut être révélé, avec SA portée exacte.
        $all = array_merge($this->memberPages(), $this->adminPages());
        $page = null;
        foreach ($all as $p) {
            if (isset($p['scope']) && $p['scope'] === $scope && ($p['path'] ?? '') === $path) {
                $page = $p;
                break;
            }
        }

        if ($page === null) {
            $this->json(['ok' => false, 'error' => 'Lien kiosque inconnu.'], 404);
        }

        $url = $scope === 'admins' ? Kiosk::adminUrl((string) $page['path']) : Kiosk::url((string) $page['path']);

        $this->audit('kiosques.reveal', 'setting', $scope === 'admins' ? 'admin_kiosk_token' : 'reappro_kiosk_token', [
            'scope' => $scope,
            'page'  => (string) $page['path'],
        ]);

        $this->json(['ok' => true, 'scope' => $scope, 'path' => (string) $page['path'], 'url' => $url]);
    }

    /**
     * Régénère un jeton kiosque : scope = « membres » (reappro_kiosk_token)
     * ou « admins » (admin_kiosk_token). Les deux sont indépendants :
     * régénérer l'un n'affecte pas l'autre.
     *
     * Un champ `id` non vide désigne un KIOSQUE PERSONNALISÉ : seul SON
     * jeton est régénéré (l'ancien lien du kiosque meurt, les autres
     * kiosques et les liens legacy restent valides). La valeur du jeton
     * n'est JAMAIS audité ni affichée.
     */
    public function regenerate(): void
    {
        $this->guardSystemOrPage('kiosques');

        // Branche kiosque personnalisé : régénération individuelle.
        $customId = trim((string) ($_POST['id'] ?? ''));
        if ($customId !== '') {
            $k = Kiosque::find($customId);
            if ($k === null) {
                $this->setFlash('error', 'Kiosque introuvable.');
                redirect(url('/admin/kiosques'));
            }

            Kiosque::regenerateToken($customId);
            $this->audit('kiosque.token.regenerate', 'kiosque', $customId, ['scope' => 'custom', 'name' => $k['name']]);
            $this->setFlash('success', 'Lien du kiosque « ' . $k['name'] . ' » régénéré — l\'ancien lien ne fonctionne plus.');

            redirect(url('/admin/kiosques'));
        }

        $scope = ($_POST['scope'] ?? '') === 'admins' ? 'admins' : 'membres';
        $key   = $scope === 'admins' ? 'admin_kiosk_token' : 'reappro_kiosk_token';

        Setting::set($key, bin2hex(random_bytes(20)));
        $this->audit('kiosque.token.regenerate', 'setting', $key, ['scope' => $scope]);
        $this->setFlash(
            'success',
            $scope === 'admins'
                ? 'Lien ADMIN régénéré — tous les anciens liens financiers ne fonctionnent plus.'
                : 'Lien MEMBRES régénéré — tous les anciens liens membres ne fonctionnent plus.'
        );

        redirect(url('/admin/kiosques'));
    }
}
