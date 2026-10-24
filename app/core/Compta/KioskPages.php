<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\Kiosque;
use App\Models\Setting;

/**
 * Catalogue unique des tuiles kiosque et garde centralisée des jetons.
 *
 * D'un côté, les 11 pages accessibles par lien à jeton (Récap du jour,
 * Livre comptable, Réappro…) : le catalogue fait foi pour l'admin qui
 * coche les tuiles d'un kiosque personnalisé.
 * De l'autre, LA porte d'entrée unique pour décider si un jeton donné
 * autorise une page : jeton membres legacy, jeton admin legacy, puis
 * kiosques personnalisés. Les contrôleurs seront refactorés pour
 * n'utiliser plus que cette classe.
 */
final class KioskPages
{
    /**
     * Tuiles autorisées par le jeton MEMBRES legacy
     * (settings.reappro_kiosk_token, généré par Kiosk::token()).
     *
     * @var list<string>
     */
    public const LEGACY_MEMBER_PAGES = ['caisse', 'inventaire', 'liste', 'reappro', 'jour-membre'];

    /**
     * Tuiles autorisées par le jeton ADMIN legacy
     * (settings.admin_kiosk_token, généré par Kiosk::adminToken()) :
     * données financières complètes.
     *
     * @var list<string>
     */
    public const LEGACY_ADMIN_PAGES = ['jour', 'ledger', 'analytics', 'reco', 'periode', 'depot', 'caisse', 'inventaire', 'liste', 'reappro'];

    /**
     * Catalogue des 11 tuiles kiosque, dans l'ordre d'affichage.
     *
     * @var list<array{id:string,emoji:string,label:string,desc:string,path:string}>
     */
    private const TILES = [
        ['id' => 'jour', 'emoji' => '📊', 'label' => 'Récap du jour', 'desc' => 'CA, bénéfice, liquide/carte, top produits du jour.', 'path' => '/kiosque/comptage/jour/'],
        ['id' => 'ledger', 'emoji' => '🧾', 'label' => 'Livre comptable', 'desc' => 'Date | Objet | Débit | Crédit + saisie express des dépenses.', 'path' => '/kiosque/admin/ledger/'],
        ['id' => 'analytics', 'emoji' => '📈', 'label' => 'Analytique', 'desc' => 'Tableaux de bord : CA, bénéfice, heures, produits.', 'path' => '/kiosque/admin/analytics/'],
        ['id' => 'reco', 'emoji' => '💡', 'label' => 'Idées du moment', 'desc' => 'Recommandations commerciales du moteur.', 'path' => '/kiosque/admin/reco/'],
        ['id' => 'periode', 'emoji' => '📅', 'label' => 'Période analysée', 'desc' => 'Semaine, 14/30 jours ou mois : CA, bénéfice, top produits.', 'path' => '/kiosque/admin/periode/'],
        ['id' => 'depot', 'emoji' => '🏦', 'label' => 'Dépôt banque', 'desc' => 'Sortie de liquide vers la banque (+ frais bancaires automatiques).', 'path' => '/kiosque/admin/caisse/depot/'],
        ['id' => 'caisse', 'emoji' => '💵', 'label' => 'Comptage caisse', 'desc' => 'Liquide présent dans la caisse, comptage à l\'aveugle.', 'path' => '/kiosque/comptage/caisse/'],
        ['id' => 'inventaire', 'emoji' => '📦', 'label' => 'Comptage inventaire', 'desc' => 'Produits en stock, comptage à l\'aveugle.', 'path' => '/kiosque/comptage/inventaire/'],
        ['id' => 'liste', 'emoji' => '🛒', 'label' => 'Liste de courses', 'desc' => 'Ce qu\'il faut racheter, cases cochées partagées.', 'path' => '/kiosque/liste/'],
        ['id' => 'reappro', 'emoji' => '📈', 'label' => 'Réappro', 'desc' => 'Analyse de réapprovisionnement (stock, packs, coûts).', 'path' => '/kiosque/reappro/'],
        ['id' => 'jour-membre', 'emoji' => '📊', 'label' => 'Ventes du jour (membres)', 'desc' => 'CA du jour et produits vendus, sans chiffre financier.', 'path' => '/kiosque/comptage/jour-membre/'],
    ];

    /**
     * Catalogue complet des tuiles, dans l'ordre du catalogue.
     *
     * @return list<array{id:string,emoji:string,label:string,desc:string,path:string}>
     */
    public static function all(): array
    {
        return self::TILES;
    }

    /**
     * Une tuile par son id (null si inconnue).
     *
     * @return array{id:string,emoji:string,label:string,desc:string,path:string}|null
     */
    public static function get(string $id): ?array
    {
        foreach (self::TILES as $tile) {
            if ($tile['id'] === $id) {
                return $tile;
            }
        }

        return null;
    }

    /**
     * Liste des ids de tuiles, dans l'ordre du catalogue.
     *
     * @return list<string>
     */
    public static function validIds(): array
    {
        return array_column(self::TILES, 'id');
    }

    /**
     * LA garde centrale : ce jeton autorise-t-il cette page ?
     *
     * Ordre OBLIGATOIRE membres → admin → kiosques personnalisés : un
     * jeton custom ne doit JAMAIS être consulté avant les jetons legacy
     * (les réponses membres/admin ne dépendent que des settings, jamais
     * d'une table modifiable en admin). Vide = refus ; chaque jeton legacy
     * présent est comparé en hash_equals et limite aux pages de sa liste.
     */
    public static function tokenAllows(string $token, string $pageId): bool
    {
        $given = trim($token);
        if ($given === '' || $pageId === '') {
            return false;
        }

        $member = trim((string) Setting::get('reappro_kiosk_token', ''));
        if ($member !== '' && hash_equals($member, $given)) {
            return in_array($pageId, self::LEGACY_MEMBER_PAGES, true);
        }

        $admin = trim((string) Setting::get('admin_kiosk_token', ''));
        if ($admin !== '' && hash_equals($admin, $given)) {
            return in_array($pageId, self::LEGACY_ADMIN_PAGES, true);
        }

        try {
            $k = Kiosque::findByToken($given);
        } catch (\Throwable) {
            // Table kiosques absente (migration non appliquée) : les kiosques personnalisés sont simplement inactifs.
            $k = null;
        }

        return $k !== null && in_array($pageId, $k['pages'], true);
    }

    /**
     * Variante « données complètes » (coûts visibles sur les pages
     * Réappro/Liste) : vrai pour le jeton ADMIN legacy ou le jeton d'un
     * kiosque personnalisé ; faux pour le jeton MEMBRES legacy.
     */
    public static function tokenIsFullData(string $token): bool
    {
        $given = trim($token);
        if ($given === '') {
            return false;
        }

        $admin = trim((string) Setting::get('admin_kiosk_token', ''));
        if ($admin !== '' && hash_equals($admin, $given)) {
            return true;
        }

        $member = trim((string) Setting::get('reappro_kiosk_token', ''));
        if ($member !== '' && hash_equals($member, $given)) {
            return false;
        }

        return Kiosque::findByToken($given) !== null;
    }
}
