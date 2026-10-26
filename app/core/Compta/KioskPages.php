<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\Kiosque;
use App\Models\Setting;

/**
 * Catalogue unique des tuiles kiosque et garde centralisée des jetons.
 *
 * D'un côté, les 14 tuiles accessibles par lien à jeton (Vue Président,
 * Récap du jour, Livre comptable, Réappro/Liste en variantes avec ou sans
 * coûts…) : le catalogue fait foi pour l'admin qui coche les tuiles d'un
 * kiosque personnalisé.
 * De l'autre, LA porte d'entrée unique pour décider si un jeton donné
 * autorise une page : jeton membres legacy, jeton admin legacy, puis
 * kiosques personnalisés. Les contrôleurs seront refactorés pour
 * n'utiliser plus que cette classe.
 */
final class KioskPages
{
    /**
     * Catalogue des OPTIONS de visibilité par kiosque : ce que chaque
     * personne a le droit de VOIR, indépendamment des pages cochées.
     * Un kiosque dont les options sont définies (colonne non NULL)
     * n'affiche QUE ce qui est coché ; NULL (kiosque antérieur) garde
     * le comportement historique.
     *
     * @var list<array{id:string,emoji:string,label:string,desc:string}>
     */
    private const OPTIONS = [
        ['id' => 'benefice', 'emoji' => '💰', 'label' => 'Bénéfices & marges', 'desc' => 'Bénéfice, marge et bénéfice net : récap du jour, période, analytique, livre comptable.'],
        ['id' => 'paiements', 'emoji' => '💳', 'label' => 'Détail des paiements', 'desc' => 'Répartition liquide / carte (récap du jour, période).'],
        ['id' => 'couts', 'emoji' => '🧮', 'label' => 'Coûts d’achat', 'desc' => 'Prix d’achat et coûts de revient : réapprovisionnement et liste de courses.'],
    ];

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
    public const LEGACY_ADMIN_PAGES = ['jour', 'ledger', 'analytics', 'reco', 'periode', 'depot', 'caisse', 'inventaire', 'liste', 'reappro', 'president'];

    /**
     * Catalogue des 14 tuiles kiosque, dans l'ordre d'affichage. Les
     * variantes « lite » (« liste-lite », « reappro-lite ») partagent
     * l'URL de leur page mère : seule la variante de rendu (coûts
     * visibles ou masqués) change. La tuile « president » (ajoutée en
     * dernier, purement additive) affiche UNE page de 3 chiffres —
     * ventes et bénéfice du mois, stock restant — sans aucun autre
     * chiffre : elle exige en plus l'option « benefice » du kiosque.
     *
     * @var list<array{id:string,emoji:string,label:string,desc:string,path:string}>
     */
    private const TILES = [
        ['id' => 'jour', 'emoji' => '📊', 'label' => 'Récap du jour — complet', 'desc' => 'CA, bénéfice, liquide/carte, top produits du jour.', 'path' => '/kiosque/comptage/jour/'],
        ['id' => 'jour-membre', 'emoji' => '📊', 'label' => 'Récap du jour — membres', 'desc' => 'CA du jour et produits vendus, sans bénéfice ni paiements.', 'path' => '/kiosque/comptage/jour-membre/'],
        ['id' => 'ledger', 'emoji' => '🧾', 'label' => 'Livre comptable', 'desc' => 'Date | Objet | Débit | Crédit + saisie express des dépenses.', 'path' => '/kiosque/admin/ledger/'],
        ['id' => 'analytics', 'emoji' => '📈', 'label' => 'Analytique', 'desc' => 'Tableaux de bord : CA, bénéfice, heures, produits.', 'path' => '/kiosque/admin/analytics/'],
        ['id' => 'reco', 'emoji' => '💡', 'label' => 'Idées du moment', 'desc' => 'Recommandations commerciales du moteur.', 'path' => '/kiosque/admin/reco/'],
        ['id' => 'periode', 'emoji' => '📅', 'label' => 'Période analysée', 'desc' => 'Semaine, 14/30 jours ou mois : CA, bénéfice, top produits.', 'path' => '/kiosque/admin/periode/'],
        ['id' => 'depot', 'emoji' => '🏦', 'label' => 'Dépôt banque', 'desc' => 'Sortie de liquide vers la banque (+ frais bancaires automatiques).', 'path' => '/kiosque/admin/caisse/depot/'],
        ['id' => 'caisse', 'emoji' => '💵', 'label' => 'Comptage caisse', 'desc' => 'Liquide présent dans la caisse, comptage à l\'aveugle.', 'path' => '/kiosque/comptage/caisse/'],
        ['id' => 'inventaire', 'emoji' => '📦', 'label' => 'Comptage inventaire', 'desc' => 'Produits en stock, comptage à l\'aveugle.', 'path' => '/kiosque/comptage/inventaire/'],
        ['id' => 'liste', 'emoji' => '🛒', 'label' => 'Liste de courses — avec coûts', 'desc' => 'Ce qu\'il faut racheter, coûts visibles : pour trésorier/président.', 'path' => '/kiosque/liste/'],
        ['id' => 'liste-lite', 'emoji' => '🛒', 'label' => 'Liste de courses — sans coûts', 'desc' => 'Ce qu\'il faut racheter, sans chiffre financier : pour les membres.', 'path' => '/kiosque/liste/'],
        ['id' => 'reappro', 'emoji' => '📈', 'label' => 'Réappro — avec coûts', 'desc' => 'Stock, packs et coûts : pour trésorier/président.', 'path' => '/kiosque/reappro/'],
        ['id' => 'reappro-lite', 'emoji' => '📈', 'label' => 'Réappro — sans coûts', 'desc' => 'Stock et packs, sans chiffre financier : pour les membres.', 'path' => '/kiosque/reappro/'],
        ['id' => 'president', 'emoji' => '🏛️', 'label' => 'Vue Président', 'desc' => 'Trois chiffres simples : ventes et bénéfice du mois, stock restant.', 'path' => '/kiosque/admin/president/'],
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
     * Catalogue des options de visibilité, dans l'ordre du catalogue.
     *
     * @return list<array{id:string,emoji:string,label:string,desc:string}>
     */
    public static function optionCatalog(): array
    {
        return self::OPTIONS;
    }

    /**
     * Liste des ids d'options, dans l'ordre du catalogue.
     *
     * @return list<string>
     */
    public static function optionIds(): array
    {
        return array_column(self::OPTIONS, 'id');
    }

    /**
     * Normalise les options cochées d'un formulaire de kiosque
     * (méthode PURE, testée sans base) : seuls les ids du catalogue
     * sont conservés, dédoublonnés puis réordonnés selon le catalogue
     * — exactement le contrat de normalizePages côté contrôleur.
     *
     * @return list<string>
     */
    public static function normalizeOptions(mixed $posted): array
    {
        if (!is_array($posted)) {
            return [];
        }

        $valid = array_fill_keys(self::optionIds(), true);
        $picked = [];
        foreach ($posted as $opt) {
            $opt = (string) $opt;
            if (isset($valid[$opt])) {
                $picked[$opt] = true;
            }
        }

        $options = [];
        foreach (self::optionIds() as $id) {
            if (isset($picked[$id])) {
                $options[] = $id;
            }
        }

        return $options;
    }

    /**
     * Options EFFECTIVES d'un jeton (LA porte unique pour savoir ce
     * qu'un kiosque a le droit d'afficher) :
     *
     *  - jeton ADMIN legacy : tout visible (données financières
     *    complètes, comportement historique) ;
     *  - jeton MEMBRES legacy (bénévole) : rien de sensible ;
     *  - kiosque personnalisé avec options définies (colonne non
     *    NULL) : EXACTEMENT les options cochées ;
     *  - kiosque personnalisé antérieur (options NULL) : bénéfice et
     *    paiements visibles (comme avant), coûts selon la tuile pleine
     *    « avec coûts » (reprise du comportement tokenIsFullData).
     *
     * Jeton vide/inconnu : tout à false (refus par défaut).
     *
     * @return array{benefice:bool,paiements:bool,couts:bool}
     */
    public static function tokenOptions(string $token): array
    {
        $given = trim($token);
        if ($given === '') {
            return ['benefice' => false, 'paiements' => false, 'couts' => false];
        }

        $admin = trim((string) Setting::get('admin_kiosk_token', ''));
        if ($admin !== '' && hash_equals($admin, $given)) {
            return ['benefice' => true, 'paiements' => true, 'couts' => true];
        }

        $member = trim((string) Setting::get('reappro_kiosk_token', ''));
        if ($member !== '' && hash_equals($member, $given)) {
            return ['benefice' => false, 'paiements' => false, 'couts' => false];
        }

        try {
            $k = Kiosque::findByToken($given);
        } catch (\Throwable) {
            $k = null;
        }

        if ($k === null) {
            return ['benefice' => false, 'paiements' => false, 'couts' => false];
        }

        if ($k['options'] !== null) {
            return [
                'benefice' => in_array('benefice', $k['options'], true),
                'paiements' => in_array('paiements', $k['options'], true),
                'couts'    => in_array('couts', $k['options'], true),
            ];
        }

        // Kiosque antérieur : comportement historique.
        return [
            'benefice' => true,
            'paiements' => true,
            'couts'    => in_array('reappro', $k['pages'], true) || in_array('liste', $k['pages'], true),
        ];
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
     * Identifiant d'ACCÈS d'une tuile : les variantes « lite » partagent
     * l'accès de leur page mère (« liste-lite » → « liste », « reappro-lite »
     * → « reappro » — mêmes URL, même garde tokenAllows) ; tout autre
     * identifiant est renvoyé inchangé.
     */
    public static function accessId(string $pageId): string
    {
        return match ($pageId) {
            'liste-lite' => 'liste',
            'reappro-lite' => 'reappro',
            default => $pageId,
        };
    }

    /**
     * LA garde centrale : ce jeton autorise-t-il cette page ?
     *
     * Ordre OBLIGATOIRE membres → admin → kiosques personnalisés : un
     * jeton custom ne doit JAMAIS être consulté avant les jetons legacy
     * (les réponses membres/admin ne dépendent que des settings, jamais
     * d'une table modifiable en admin). Vide = refus ; chaque jeton legacy
     * présent est comparé en hash_equals et limite aux pages de sa liste.
     * En branche custom, la page est testée par identifiant d'ACCÈS
     * (accessId) : les variantes lite partagées avec la page mère passent
     * la même garde (« reappro-lite » → garde « reappro »).
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

        if ($k === null) {
            return false;
        }

        // Comparaison par identifiant d'ACCÈS des deux côtés : la variante
        // lite cochée seule ouvre la page mère (et elle seule).
        $acc = self::accessId($pageId);
        foreach ($k['pages'] as $page) {
            if (self::accessId((string) $page) === $acc) {
                return true;
            }
        }

        return false;
    }

    /**
     * Variante « données complètes » (coûts visibles sur les pages
     * Réappro/Liste) : vrai pour le jeton ADMIN legacy ; faux pour le
     * jeton MEMBRES legacy (bénévole) ; pour un kiosque personnalisé
     * PILOTÉ PAR SES OPTIONS (colonne non NULL), vrai SSI l'option
     * « couts » est cochée — la tuile pleine/lite ne décide plus rien ;
     * pour un kiosque antérieur (options NULL), vrai seulement si
     * l'admin avait coché la tuile pleine ($pageId) — comportement
     * historique conservé.
     */
    public static function tokenIsFullData(string $token, string $pageId = 'reappro'): bool
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

        try {
            $k = Kiosque::findByToken($given);
        } catch (\Throwable) {
            // Table kiosques absente (migration non appliquée) : les kiosques personnalisés sont simplement inactifs.
            return false;
        }

        if ($k === null) {
            return false;
        }

        // Kiosque piloté par ses options : SEULE l'option « couts » décide.
        if ($k['options'] !== null) {
            return in_array('couts', $k['options'], true);
        }

        return in_array($pageId, $k['pages'], true);
    }
}
