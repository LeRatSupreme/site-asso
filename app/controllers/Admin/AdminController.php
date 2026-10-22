<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\Dashboard\Data;
use App\Core\Permissions;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;

/**
 * Accueil de l'espace d'administration, journal d'audit et recherche
 * globale.
 *
 * L'accueil (/admin) adapte son contenu au rôle :
 *  - rôles comptables (module compta) : « À faire aujourd'hui » avec le
 *    payload Dashboard\Data — le MÊME point d'assemblage que /admin/compta
 *    (règle AGENTS.md : la page ne recalcule rien) ;
 *  - BENEVOL : page outil minimaliste (trois tuiles-liens, aucun chiffre,
 *    aucun montant) ;
 *  - autres rôles du bureau : accueil non financier (compteurs existants +
 *    liens rapides vers leurs sections de menu).
 *
 * Le journal d'audit (/admin/audit) garde la garde de l'ancienne carte du
 * tableau de bord (Fondateur/ADMIN seulement) : filtres, pagination et
 * export CSV à la place des 10 dernières lignes.
 */
final class AdminController extends AdminBaseController
{
    /** Nombre d'entrées par page du journal d'audit. */
    private const AUDIT_PAR_PAGE = 50;

    /** Limite de résultats par groupe de recherche. */
    private const RECHERCHE_MAX = 10;

    public function index(): void
    {
        $user = $this->guardAdminArea();
        $role = (string) ($user['role'] ?? '');

        // ── Bénévole de caisse : page outil minimaliste. Aucun chiffre,
        //    aucun montant, aucun compteur — seulement les liens vers ses
        //    deux outils de comptage et l'aide.
        if ($role === Auth::ROLE_BENEVOL) {
            $this->renderAdmin('admin/dashboard', [
                'title'    => 'À faire aujourd\'hui',
                'user'     => $user,
                'variante' => 'benevol',
            ]);

            return;
        }

        // ── Rôles comptables (Fondateur, Administrateur, Trésorerie,
        //    Président) : le même « À faire aujourd'hui » que le dashboard
        //    compta, depuis le même payload (fenêtre par défaut : semaine
        //    en cours).
        if (Permissions::allows($role, Permissions::MODULE_COMPTA)) {
            $this->renderAdmin('admin/dashboard', array_merge([
                'title'         => 'À faire aujourd\'hui',
                'user'          => $user,
                'variante'      => 'compta',
                // Le layout charge compta.css pour /compta seulement :
                // l'accueil réutilise ses composants (cartes KPI, à faire).
                'loadComptaCss' => true,
            ], Data::construire()));

            return;
        }

        // ── Autres rôles du bureau : accueil NON financier (aucun €).
        //    Compteurs déjà calculés côté modèle + liens rapides filtrés
        //    par les modules du rôle. Brouillons d'événements : simple
        //    lecture de Event::allForAdmin() (aucun moteur inventé).
        $draftEvents = [];
        if (Permissions::allows($role, Permissions::MODULE_EVENTS)) {
            foreach (Event::allForAdmin() as $ev) {
                if ((int) ($ev['is_published'] ?? 0) === 1) {
                    continue;
                }
                $draftEvents[] = $ev;
                if (count($draftEvents) >= 5) {
                    break;
                }
            }
        }

        $this->renderAdmin('admin/dashboard', [
            'title'        => 'À faire aujourd\'hui',
            'user'         => $user,
            'variante'     => 'bureau',
            'usersCount'   => User::countActive(),
            'eventsCount'  => Event::count(),
            'draftEvents'  => $draftEvents,
            'liensRapides' => self::liensRapides($role),
        ]);
    }

    public function wiki(): void
    {
        $this->guardAdminArea();

        $this->renderAdmin('admin/wiki/index', [
            'title' => 'Wiki — Guide de l\'admin',
            'user'  => Auth::user(),
        ]);
    }

    // -----------------------------------------------------------------
    //  Journal d'audit
    // -----------------------------------------------------------------

    /**
     * Page « Journal d'audit » : garde identique à l'ancienne carte du
     * tableau de bord (Fondateur/ADMIN), filtres GET (utilisateur, action,
     * période), pagination de 50 entrées, export CSV.
     *
     * Nom d'action « auditPage » (et non « audit ») : la méthode protégée
     * audit() du contrôleur de base est déjà prise (journalisation).
     */
    public function auditPage(): void
    {
        $user = $this->guard();

        $filters = [
            'user_id' => trim((string) ($_GET['user_id'] ?? '')),
            'action'  => trim((string) ($_GET['action'] ?? '')),
            'from'    => trim((string) ($_GET['from'] ?? '')),
            'to'      => trim((string) ($_GET['to'] ?? '')),
        ];

        if (($_GET['export'] ?? '') === 'csv') {
            self::exportAuditCsv($filters);

            return;
        }

        $total = AuditLog::countFilters($filters);
        $pages = max(1, (int) ceil($total / self::AUDIT_PAR_PAGE));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);

        $this->renderAdmin('admin/audit', [
            'title'   => 'Journal d\'audit',
            'user'    => $user,
            'rows'    => AuditLog::searchFilters($filters, self::AUDIT_PAR_PAGE, ($page - 1) * self::AUDIT_PAR_PAGE),
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'filters' => $filters,
            // Menu « Qui » : les comptes du bureau (la garde de la page
            // limite déjà la consultation au Fondateur/ADMIN).
            'users'   => User::allForAdmin(),
            'actions' => AuditLog::distinctActions(),
        ]);
    }

    /**
     * Export CSV du journal filtré (même pattern que l'export du journal
     * des ventes : BOM UTF-8 pour Excel + neutralisation des formules).
     *
     * @param array<string,mixed> $filters
     */
    private static function exportAuditCsv(array $filters): void
    {
        // Borné volontairement : un journal est consulté par pages, l'export
        // couvre la sélection courante (100 000 entrées = marge large).
        $rows = AuditLog::searchFilters($filters, 100000, 0);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_aeic.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($out, ['Date (Paris)', 'Qui', 'Action', 'Entité', 'Détails', 'IP']);
        foreach ($rows as $r) {
            fputcsv($out, [
                formatDateTime((string) $r['created_at']),
                self::auditQui($r),
                self::csvSafe((string) ($r['action'] ?? '')),
                self::csvSafe(trim((string) ($r['entity_type'] ?? '') . (isset($r['entity_id']) && $r['entity_id'] !== null && $r['entity_id'] !== '' ? ' #' . $r['entity_id'] : ''))),
                self::csvSafe(self::auditDetails($r)),
                self::csvSafe((string) ($r['ip_address'] ?? '')),
            ]);
        }
        fclose($out);
        exit;
    }

    /**
     * Nom affiché de l'auteur d'une entrée : « kiosque » quand l'action
     * vient d'un poste à jeton (user_id null), le nom du compte sinon
     * (ou « Compte supprimé » si la ligne d'utilisateur n'existe plus).
     *
     * @param array<string,mixed> $row
     */
    private static function auditQui(array $row): string
    {
        $userId = $row['user_id'] ?? null;
        if ($userId === null || (string) $userId === '') {
            return 'kiosque';
        }

        $nom = trim((string) ($row['prenom'] ?? '') . ' ' . (string) ($row['nom'] ?? ''));

        return $nom !== '' ? $nom : 'Compte supprimé';
    }

    /**
     * Résumé lisible des détails d'une entrée (JSON décodé « clé : valeur »,
     * texte brut sinon) + élément concerné.
     *
     * @param array<string,mixed> $row
     */
    private static function auditDetails(array $row): string
    {
        $parts = [];

        $type = trim((string) ($row['entity_type'] ?? ''));
        $id = trim((string) ($row['entity_id'] ?? ''));
        if ($type !== '') {
            $parts[] = $type . ($id !== '' ? ' #' . $id : '');
        }

        $raw = trim((string) ($row['details'] ?? ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    if (is_scalar($v)) {
                        $parts[] = $k . ' : ' . (string) $v;
                    }
                }
            } else {
                $parts[] = $raw;
            }
        }

        return implode(' — ', $parts);
    }

    /**
     * Neutralise l'injection de formules dans les exports CSV/Excel (« CSV
     * injection ») : même règle que AdminComptaController::csvSafe().
     */
    private static function csvSafe(?string $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        $trimmed = ltrim($v);
        $first = $trimmed === '' ? '' : $trimmed[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r", "\n"], true)
            || in_array($v[0], ["\t", "\r", "\n"], true)
        ) {
            return "'" . $v;
        }

        return $v;
    }

    // -----------------------------------------------------------------
    //  Recherche globale
    // -----------------------------------------------------------------

    /**
     * Recherche globale (/admin/search?q=) : produits pour tout le bureau ;
     * ventes seulement pour les rôles compta ; membres seulement pour ceux
     * qui voient déjà /admin/users (groupe Système ou page « users »
     * attribuée). Chaque groupe est plafonné, requêtes LIKE paramétrées.
     */
    public function search(): void
    {
        $user = $this->guardAdminArea();
        $role = (string) ($user['role'] ?? '');

        $q = trim((string) ($_GET['q'] ?? ''));

        $peutCompta = Permissions::allows($role, Permissions::MODULE_COMPTA);
        $peutCafeteria = Permissions::allows($role, Permissions::MODULE_CAFETERIA);
        // Mêmes critères que la page Utilisateurs (groupe Système ou
        // attribution individuelle de la page « users »).
        $voirMembres = Permissions::isSystemAdmin() || Permissions::userHasExtraPage('users');

        $produits = $q !== '' ? self::searchProducts($q, $peutCompta, $peutCafeteria) : [];
        $ventes = ($q !== '' && $peutCompta) ? self::searchSales($q) : [];
        $membres = ($q !== '' && $voirMembres) ? self::searchUsers($q) : [];

        $this->renderAdmin('admin/search', [
            'title'       => 'Recherche',
            'user'        => $user,
            'q'           => $q,
            'produits'    => $produits,
            'ventes'      => $ventes,
            'membres'     => $membres,
            'voirVentes'  => $peutCompta,
            'voirMembres' => $voirMembres,
        ]);
    }

    /**
     * Produits correspondant à la recherche : catalogue cafétéria, libellés
     * distincts des ventes (clé/description) et alias de mapping — dédoublés
     * (comparaison insensible à la casse), plafonnés. Le lien pointe vers
     * la page produits compta (rôle compta) ou le catalogue cafétéria.
     *
     * @return list<array{label:string, detail:string, lien:?string}>
     */
    private static function searchProducts(string $q, bool $peutCompta, bool $peutCafeteria): array
    {
        $like = self::likePattern($q);
        $limit = self::RECHERCHE_MAX;
        $out = [];

        // Catalogue cafétéria (nom des produits).
        try {
            $stmt = db()->prepare('SELECT name FROM products WHERE name LIKE ? ORDER BY name LIMIT ' . $limit);
            $stmt->execute([$like]);
            foreach ($stmt->fetchAll() as $r) {
                $out[] = ['label' => (string) $r['name'], 'detail' => '', 'lien' => null];
            }
        } catch (\Throwable) {
            // Panne de base : groupe simplement vide (pattern des modèles).
        }

        // Libellés des ventes importées (clé + description distincts).
        try {
            $stmt = db()->prepare(
                'SELECT DISTINCT product_key, description FROM sales
                 WHERE product_key LIKE ? OR description LIKE ?
                 ORDER BY description LIMIT ' . $limit
            );
            $stmt->execute([$like, $like]);
            foreach ($stmt->fetchAll() as $r) {
                $out[] = ['label' => (string) ($r['description'] ?? $r['product_key']), 'detail' => '', 'lien' => null];
            }
        } catch (\Throwable) {
            // Idem.
        }

        // Mapping libellés (alias SumUp -> produit canonique).
        try {
            $stmt = db()->prepare(
                'SELECT raw_description, product_key FROM product_aliases
                 WHERE raw_description LIKE ? OR product_key LIKE ?
                 ORDER BY raw_description LIMIT ' . $limit
            );
            $stmt->execute([$like, $like]);
            foreach ($stmt->fetchAll() as $r) {
                $out[] = [
                    'label'  => (string) $r['raw_description'],
                    'detail' => '→ ' . (string) $r['product_key'],
                    'lien'   => null,
                ];
            }
        } catch (\Throwable) {
            // Idem.
        }

        // Dédoublonnage insensible à la casse + plafonnement.
        $seen = [];
        $rows = [];
        foreach ($out as $row) {
            $cle = mb_strtolower(trim($row['label']));
            if ($cle === '' || isset($seen[$cle])) {
                continue;
            }
            $seen[$cle] = true;
            $row['lien'] = self::produitsLien($peutCompta, $peutCafeteria, $row['label']);
            $rows[] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Lien « Voir » d'un produit : page compta des produits avec le filtre
     * de recherche (rôle compta), catalogue cafétéria sinon (rôle cafétéria),
     * aucun lien si ni l'un ni l'autre.
     */
    private static function produitsLien(bool $peutCompta, bool $peutCafeteria, string $label): ?string
    {
        if ($peutCompta) {
            return url('/admin/compta/produits?q=') . rawurlencode($label);
        }
        if ($peutCafeteria) {
            return url('/admin/cafeteria');
        }

        return null;
    }

    /**
     * Ventes récentes correspondant à la recherche (description ou
     * référence de transaction), de la plus récente à la plus ancienne.
     *
     * @return list<array<string,mixed>>
     */
    private static function searchSales(string $q): array
    {
        $like = self::likePattern($q);

        try {
            $stmt = db()->prepare(
                'SELECT description, transaction_ref, sold_at, price_ttc
                 FROM sales
                 WHERE description LIKE ? OR transaction_ref LIKE ?
                 ORDER BY sold_at DESC
                 LIMIT ' . self::RECHERCHE_MAX
            );
            $stmt->execute([$like, $like]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        // Chaque vente pointe vers sa fiche du journal des ventes
        // (filtre produit existant de la page).
        foreach ($rows as $i => $r) {
            $rows[$i]['lien'] = url('/admin/compta/ventes?product=') . rawurlencode((string) ($r['description'] ?? ''));
        }

        return $rows;
    }

    /**
     * Membres correspondant à la recherche (prénom, nom, e-mail) —
     * groupe réservé aux lecteurs de la page Utilisateurs.
     *
     * @return list<array<string,mixed>>
     */
    private static function searchUsers(string $q): array
    {
        $like = self::likePattern($q);

        try {
            $stmt = db()->prepare(
                'SELECT prenom, nom, email, role FROM users
                 WHERE prenom LIKE ? OR nom LIKE ? OR email LIKE ?
                 ORDER BY created_at DESC
                 LIMIT ' . self::RECHERCHE_MAX
            );
            $stmt->execute([$like, $like, $like]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as $i => $r) {
            $rows[$i]['nom'] = trim((string) ($r['prenom'] ?? '') . ' ' . (string) ($r['nom'] ?? ''));
            $rows[$i]['lien'] = url('/admin/users');
        }

        return $rows;
    }

    /**
     * Échappe les caractères joker de LIKE (% _ \) dans la saisie
     * utilisateur : la recherche reste une recherche littérale.
     */
    private static function likePattern(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }

    // -----------------------------------------------------------------
    //  Liens rapides (accueil non financier)
    // -----------------------------------------------------------------

    /**
     * Liens rapides de l'accueil « bureau » : uniquement des pages que le
     * rôle peut déjà ouvrir (jamais de lien interdit), libellés français
     * courants.
     *
     * @return list<array{label:string, url:string}>
     */
    private static function liensRapides(string $role): array
    {
        $liens = [];

        if (Permissions::allows($role, Permissions::MODULE_EVENTS)) {
            $liens[] = ['label' => 'Événements', 'url' => '/admin/events'];
        }
        if (Permissions::allows($role, Permissions::MODULE_CONTENT)) {
            $liens[] = ['label' => 'Pages du site', 'url' => '/admin/pages'];
            $liens[] = ['label' => 'Sondages', 'url' => '/admin/sondages'];
        }
        if (Permissions::allows($role, Permissions::MODULE_CAFETERIA)) {
            $liens[] = ['label' => 'Produits cafétéria', 'url' => '/admin/cafeteria'];
            $liens[] = ['label' => 'Commandes élèves', 'url' => '/admin/cafeteria/commandes'];
        }
        if (Permissions::allows($role, Permissions::MODULE_GAMES)) {
            $liens[] = ['label' => 'Jeux', 'url' => '/admin/jeux'];
        }

        return $liens;
    }

    /**
     * Page de démonstration du design system (document HTML autonome,
     * sans layout admin). Accessible par URL directe uniquement : aucune
     * entrée de menu tant que la navigation n'a pas été refaite (lot 2).
     */
    public function designSystem(): void
    {
        $this->guardAdminArea();

        echo $this->renderBare('admin/design_system');
    }
}
