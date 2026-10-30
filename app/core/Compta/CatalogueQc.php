<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\AnalyticsForecastData;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductCost;
use App\Models\Sale;

/**
 * Contrôle qualité du catalogue cafétéria — point d'assemblage UNIQUE.
 *
 * Collecte en LECTURE SEULE les anomalies du catalogue, partagé par
 * l'outil assistant « catalogue_anomalies » (AssistantToolsController)
 * et la veille quotidienne scripts/catalogue_watchdog.php : une seule
 * définition de « ce qu'est une anomalie », jamais recalculée par page.
 *
 * Rapprochement des noms (règle anti-régression du projet) : aucune
 * jointure produit par strtolower ad hoc — l'appariement fiches ↔ clés
 * de vente passe par la primitive partagée StockPublic::normalizeKey
 * (casse/accents/ponctuation/espaces repliés), complétée de la passe
 * ALIAS de product_aliases (une vente sous un libellé alias compte pour
 * la fiche de sa clé canonique), même esprit que les passes
 * exacte → normalisée → alias de StockBook.
 *
 * Six familles d'anomalies :
 *  (a) fiches carte ACTIVES sans AUCUNE vente (même via alias) ;
 *  (b) clés de vente ACTIVES RÉCENTES (dernière vente < 30 j) sans
 *      fiche carte active ;
 *  (c) catégories VIDES (0 produit pointant dessus, 0 alias la citant —
 *      mêmes conditions que ProductCategory::purgeAutoCreatedEmpty,
 *      sans la partie audit : ici on SIGNALE, la purge re-vérifie) ;
 *  (d) QUASI-DOUBLONS de catégories (même forme normalisée, ou distance
 *      de Levenshtein <= 1 entre formes >= 4 caractères — exactement la
 *      règle d'absorption de ProductCategory::ensure) ;
 *  (e) PRIX SUSPECTS : prix <= 0 ; prix = 1,00 € avec coût unitaire
 *      courant > 1 € (défaut type Surfizz : prix unitaire resté à 1 €
 *      alors que le lot d'achat vaut 15,20 €) ; prix < coût unitaire
 *      courant (vente à perte) ;
 *  (f) fiches DORMANTES : dernière vente > 45 jours avec du stock
 *      théorique (> 0) qui dort ;
 *  (g) DOUBLONS D'APPELLATIONS : libellés d'alias qui normalisent
 *      identiquement (« Coca cherry » 80 occurrences et « Coca_cherry »
 *      6, cible « coca cherry ») alors qu'une seule entrée doit
 *      exister. Deux niveaux :
 *       - « simple » : tous les libellés pointent vers la MÊME clé —
 *         la fusion des compteurs/lignes d'alias suffit (garde
 *         ProductAlias::save / consolidateFor, tracée 'alias.fusion') ;
 *       - « lourde » [À DÉCIDER] : les doublons portent des clés
 *         DISTINCTES — tout doit fusionner par ProductKeyMerge
 *         (ventes, lots de coûts, stocks, comptages, fiches, alias),
 *         jamais automatiquement ; les données attachées aux clés
 *         concernées (fiche/ventes/coûts) sont signalées pour éclairer
 *         la décision.
 *
 * Le stock vient du livre UNIQUE (AnalyticsForecastData::stockBook() →
 * StockBook), jamais de products.stock ; les coûts du lot courant par
 * la règle « as-of » ProductCost::costAt(clé, aujourd'hui), la dernière
 * vente par clé par Sale::lastSoldByProduct — zéro logique dupliquée.
 *
 * Sortie COMPACTE et stable (champs français) : listes plafonnées à
 * LIMITE_PAR_TYPE items, libellés tronqués à LIBELLE_MAX caractères ;
 * « totaux » porte les comptes EXACTS (au-delà de la limite).
 */
final class CatalogueQc
{
    /** Items listés maximum par famille d'anomalie (les totaux restent exacts). */
    public const LIMITE_PAR_TYPE = 20;

    /** Troncature des libellés produits/catégories dans la sortie. */
    private const LIBELLE_MAX = 60;

    /** Une clé de vente est « active récente » si sa dernière vente date de moins de ce nombre de jours. */
    private const FENETRE_ACTIVE_JOURS = 30;

    /** Une fiche avec du stock est « dormante » si sa dernière vente date de plus de ce nombre de jours. */
    private const DORMANCE_JOURS = 45;

    /**
     * Familles d'anomalies : clé de sortie => libellé français (rendu du
     * watchdog). L'ORDRE est l'ordre d'affichage du rapport.
     */
    public const TYPES = [
        'fiches_jamais_vendues'    => 'Fiches actives jamais vendues',
        'cles_sans_fiche'          => 'Clés de vente récentes sans fiche carte',
        'categories_vides'         => 'Catégories vides (0 produit, 0 alias)',
        'quasi_doublons_categories' => 'Quasi-doublons de catégories',
        'prix_suspects'            => 'Prix suspects',
        'fiches_dormantes'         => 'Fiches dormantes (stock immobilisé)',
        'alias_doublons'           => 'Doublons d\'appellations (formes normalisées identiques)',
    ];

    private function __construct()
    {
        // Classe utilitaire statique : aucune instanciation.
    }

    /**
     * Collecte toutes les anomalies du catalogue (LECTURE SEULE).
     *
     * @return array<string,mixed> Structure compacte :
     *         'genere_le' (Y-m-d H:i, Paris), 'totaux' (comptes exacts par
     *         famille) puis une liste par famille (<= LIMITE_PAR_TYPE
     *         items, champs français stables).
     */
    public static function anomalies(): array
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $aujourdhui = new \DateTimeImmutable('today', $paris);

        // Sources UNIQUEMENT via les modèles/résolveurs existants.
        $fiches     = Product::allForAdmin();
        $categories = ProductCategory::allForAdmin();
        $ventes     = Sale::lastSoldByProduct();
        $book       = AnalyticsForecastData::stockBook();

        // Fiches par forme normalisée (les doublons orthographiques d'une
        // même fiche partagent une entrée — la première ligne lue l'emporte).
        $fichesNorm = [];
        foreach ($fiches as $fiche) {
            $norm = StockPublic::normalizeKey((string) ($fiche['name'] ?? ''));
            if ($norm === '' || isset($fichesNorm[$norm])) {
                continue;
            }
            $fichesNorm[$norm] = [
                'active' => (int) ($fiche['is_active'] ?? 0) === 1,
                'row'    => $fiche,
            ];
        }

        // Ventes agrégées par forme normalisée, ALIAS INCLUS : une vente
        // sous un libellé alias crédite aussi la clé canonique qu'il
        // pointe (passe alias des résolveurs partagés).
        $ventesNorm = [];
        foreach ($ventes as $cle => $stats) {
            self::agregerVente($ventesNorm, StockPublic::normalizeKey((string) $cle), (string) $cle, $stats);
        }
        $cibleParAliasNorm = [];
        foreach (ProductAlias::all() as $alias) {
            $rawNorm = StockPublic::normalizeKey((string) ($alias['raw_description'] ?? ''));
            $cible   = StockPublic::normalizeKey((string) ($alias['product_key'] ?? ''));
            if ($rawNorm === '' || $cible === '') {
                continue;
            }
            $cibleParAliasNorm[$rawNorm] = $cible;
            if (isset($ventesNorm[$rawNorm]) && $cible !== $rawNorm) {
                $brutes = $ventesNorm[$rawNorm];
                self::agregerVente(
                    $ventesNorm,
                    $cible,
                    $brutes['label'],
                    ['last' => $brutes['last'], 'qty' => $brutes['qty']]
                );
            }
        }

        $resultat = [
            'genere_le' => (new \DateTimeImmutable('now', $paris))->format('Y-m-d H:i'),
            'totaux'    => [],
        ];
        $listes = [
            'fiches_jamais_vendues'     => self::fichesJamaisVendues($fiches, $ventesNorm, $book, $aujourdhui),
            'cles_sans_fiche'           => self::clesSansFiche($ventesNorm, $fichesNorm, $cibleParAliasNorm, $aujourdhui),
            'categories_vides'          => self::categoriesVides($categories, $fiches),
            'quasi_doublons_categories' => self::quasiDoublonsCategories($categories),
            'prix_suspects'             => self::prixSuspects($fiches, $aujourdhui),
            'fiches_dormantes'          => self::fichesDormantes($fiches, $ventesNorm, $book, $aujourdhui),
            'alias_doublons'            => self::aliasDoublons($ventesNorm, $fichesNorm),
        ];
        foreach (self::TYPES as $type => $_libelle) {
            $liste = $listes[$type];
            $resultat['totaux'][$type] = count($liste);
            $resultat[$type] = array_values(array_slice($liste, 0, self::LIMITE_PAR_TYPE));
        }

        return $resultat;
    }

    // -----------------------------------------------------------------
    //  (a) Fiches actives jamais vendues
    // -----------------------------------------------------------------

    /**
     * Fiches is_active = 1 sans AUCUNE vente de leur forme normalisée,
     * même via leurs alias de saisie.
     *
     * « jours_depuis_premier_achat » : products n'a pas de colonne
     * created_at — proxy retenu = date du PREMIER lot de coût connu de
     * la clé (première entrée du produit dans le système), null si
     * aucun achat connu. « jamais_achetee » = vrai quand aucun lot :
     * avec un stock nul/inconnu, la fiche est candidate [SÛR] à la
     * désactivation (aucune marchandise, aucun historique).
     *
     * @param list<array<string,mixed>>              $fiches
     * @param array<string,array{label:string,last:?string,qty:int}> $ventesNorm
     *
     * @return list<array<string,mixed>>
     */
    private static function fichesJamaisVendues(array $fiches, array $ventesNorm, \App\Core\Compta\Reappro\StockBook $book, \DateTimeImmutable $aujourdhui): array
    {
        $anomalies = [];
        foreach ($fiches as $fiche) {
            if ((int) ($fiche['is_active'] ?? 0) !== 1) {
                continue;
            }
            $norm = StockPublic::normalizeKey((string) ($fiche['name'] ?? ''));
            if ($norm === '' || isset($ventesNorm[$norm])) {
                continue;
            }

            $premierLot = self::premierLot((string) ($fiche['name'] ?? ''));
            $resolu     = $book->resolveLoose((string) ($fiche['name'] ?? ''));

            $anomalies[] = [
                'produit'                 => self::tronque((string) ($fiche['name'] ?? '')),
                'categorie'               => self::tronque(self::categorieFiche($fiche)),
                'prix_eur'                => round((float) ($fiche['price'] ?? 0), 2),
                'stock'                   => $resolu['stock'] ?? null,
                'jamais_achetee'          => $premierLot === null,
                'jours_depuis_premier_achat' => $premierLot === null
                    ? null
                    : self::joursDepuis($aujourdhui, (string) $premierLot['valid_from']),
            ];
        }

        // Candidates [SÛR] en tête : jamais achetées d'abord, puis les
        // fiches les plus anciennement entrées.
        usort($anomalies, static function (array $a, array $b): int {
            if ($a['jamais_achetee'] !== $b['jamais_achetee']) {
                return $a['jamais_achetee'] ? -1 : 1;
            }
            return self::cmpJoursDesc($a['jours_depuis_premier_achat'], $b['jours_depuis_premier_achat']);
        });

        return $anomalies;
    }

    // -----------------------------------------------------------------
    //  (b) Clés de vente récentes sans fiche carte
    // -----------------------------------------------------------------

    /**
     * Clés vendues il y a moins de FENETRE_ACTIVE_JOURS jours dont
     * AUCUNE fiche carte ACTIVE ne porte la forme normalisée — ni
     * directement, ni via l'alias qui la mappe vers sa clé canonique.
     * « fiche_inactive » signale le cas particulier « la fiche existe
     * mais est désactivée » (à réactiver plutôt qu'à recréer).
     *
     * @param array<string,array{label:string,last:?string,qty:int}> $ventesNorm
     * @param array<string,array{active:bool,row:array<string,mixed>}> $fichesNorm
     * @param array<string,string> $cibleParAliasNorm
     *
     * @return list<array<string,mixed>>
     */
    private static function clesSansFiche(array $ventesNorm, array $fichesNorm, array $cibleParAliasNorm, \DateTimeImmutable $aujourdhui): array
    {
        $anomalies = [];
        foreach ($ventesNorm as $norm => $stats) {
            $derniere = $stats['last'];
            if ($derniere === null) {
                continue;
            }
            if (self::joursDepuis($aujourdhui, $derniere) >= self::FENETRE_ACTIVE_JOURS) {
                continue;
            }

            $cible = $cibleParAliasNorm[$norm] ?? null;
            $directeActive = $fichesNorm[$norm]['active'] ?? false;
            $cibleActive   = $cible !== null && ($fichesNorm[$cible]['active'] ?? false);
            if ($directeActive || $cibleActive) {
                continue;
            }

            $anomalies[] = [
                'cle'                        => self::tronque($stats['label']),
                'quantite_totale'            => (int) $stats['qty'],
                'derniere_vente'             => $derniere,
                'jours_depuis_derniere_vente' => self::joursDepuis($aujourdhui, $derniere),
                'fiche_inactive'             => isset($fichesNorm[$norm]) || ($cible !== null && isset($fichesNorm[$cible])),
            ];
        }

        // Les plus fraîchement actives d'abord (vente en cours → fiche
        // manquante la plus urgente), puis par volume vendu.
        usort($anomalies, static fn (array $a, array $b): int
            => [$a['jours_depuis_derniere_vente'], -$a['quantite_totale']]
            <=> [$b['jours_depuis_derniere_vente'], -$b['quantite_totale']]);

        return $anomalies;
    }

    // -----------------------------------------------------------------
    //  (c) Catégories vides
    // -----------------------------------------------------------------

    /**
     * Catégories sans AUCUN produit pointant dessus et sans AUCUN alias
     * la citant par nom (comparaison normalisée, comme
     * ProductCategory::purgeAutoCreatedEmpty — ici en simple signalement,
     * sans lire l'audit : la purge re-vérifiera ses propres conditions).
     *
     * @param list<array<string,mixed>> $categories
     * @param list<array<string,mixed>> $fiches
     *
     * @return list<array<string,mixed>>
     */
    private static function categoriesVides(array $categories, array $fiches): array
    {
        $produitsParCat = [];
        foreach ($fiches as $fiche) {
            $cat = (string) ($fiche['category_id'] ?? '');
            if ($cat !== '') {
                $produitsParCat[$cat] = ($produitsParCat[$cat] ?? 0) + 1;
            }
        }

        $aliasCatsNorm = [];
        foreach (ProductAlias::distinctCategories() as $catAlias) {
            $aliasCatsNorm[AliasSuggester::normalizeKey((string) $catAlias)] = true;
        }

        $anomalies = [];
        foreach ($categories as $categorie) {
            $id = (string) ($categorie['id'] ?? '');
            $nom = (string) ($categorie['name'] ?? '');
            if ($id === '' || ($produitsParCat[$id] ?? 0) > 0) {
                continue;
            }
            if (isset($aliasCatsNorm[AliasSuggester::normalizeKey($nom)])) {
                continue;
            }
            $anomalies[] = [
                'categorie' => self::tronque($nom),
                'active'    => (int) ($categorie['is_active'] ?? 0) === 1,
            ];
        }

        usort($anomalies, static fn (array $a, array $b): int => strcasecmp($a['categorie'], $b['categorie']));

        return $anomalies;
    }

    // -----------------------------------------------------------------
    //  (d) Quasi-doublons de catégories
    // -----------------------------------------------------------------

    /**
     * Paires de catégories que ProductCategory::ensure ABSORBERAIT
     * aujourd'hui : mêmes formes normalisées, ou distance de
     * Levenshtein <= 1 entre deux formes d'au moins 4 caractères
     * (constantes de ensure : FUZZY_MIN_LENGTH / FUZZY_MAX_DISTANCE) —
     * elles sont donc issues d'avant la mission E ou du formulaire
     * admin, et restent à fusionner à la main.
     *
     * @param list<array<string,mixed>> $categories
     *
     * @return list<array<string,mixed>>
     */
    private static function quasiDoublonsCategories(array $categories): array
    {
        $parNorm = [];
        foreach ($categories as $categorie) {
            $norm = AliasSuggester::normalizeKey((string) ($categorie['name'] ?? ''));
            if ($norm !== '') {
                $parNorm[] = ['norm' => $norm, 'nom' => (string) ($categorie['name'] ?? '')];
            }
        }

        $anomalies = [];
        $total = count($parNorm);
        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                $a = $parNorm[$i];
                $b = $parNorm[$j];
                $memeForme = $a['norm'] === $b['norm'];
                $faute = !$memeForme
                    && strlen($a['norm']) >= ProductCategory::FUZZY_MIN_LENGTH
                    && strlen($b['norm']) >= ProductCategory::FUZZY_MIN_LENGTH
                    && levenshtein($a['norm'], $b['norm']) <= ProductCategory::FUZZY_MAX_DISTANCE;
                if (!$memeForme && !$faute) {
                    continue;
                }
                $paire = [$a['nom'], $b['nom']];
                sort($paire, SORT_STRING);
                $anomalies[] = ['categories' => [
                    self::tronque($paire[0]),
                    self::tronque($paire[1]),
                ]];
            }
        }

        usort($anomalies, static fn (array $a, array $b): int => strcasecmp($a['categories'][0], $b['categories'][0]));

        return $anomalies;
    }

    // -----------------------------------------------------------------
    //  (e) Prix suspects
    // -----------------------------------------------------------------

    /**
     * Prix de vente suspects des fiches ACTIVES, au motif le plus grave :
     *  - prix_nul_ou_negatif : prix <= 0 ;
     *  - prix_1_eur_cout_superieur : prix = 1,00 € avec un coût unitaire
     *    courant > 1 € (défaut type Surfizz : prix resté à 1 € pendant
     *    que le lot d'achat vaut 15,20 €) ;
     *  - prix_sous_cout : prix < coût unitaire courant (vente à perte).
     *
     * Coût courant = ProductCost::costAt(clé, aujourd'hui), la même
     * règle « as-of » que la page Réappro et l'outil marge_produit ;
     * null = aucun lot connu (seul le motif prix <= 0 peut alors sortir).
     *
     * @param list<array<string,mixed>> $fiches
     *
     * @return list<array<string,mixed>>
     */
    private static function prixSuspects(array $fiches, \DateTimeImmutable $aujourdhui): array
    {
        $anomalies = [];
        foreach ($fiches as $fiche) {
            if ((int) ($fiche['is_active'] ?? 0) !== 1) {
                continue;
            }
            $nom  = (string) ($fiche['name'] ?? '');
            $prix = round((float) ($fiche['price'] ?? 0), 2);
            if (StockPublic::normalizeKey($nom) === '') {
                continue;
            }

            $cout = null;
            if ($prix > 0) {
                try {
                    $cout = ProductCost::costAt($nom, $aujourdhui->format('Y-m-d'));
                } catch (\Throwable) {
                    $cout = null;
                }
                if ($cout !== null) {
                    $cout = round((float) $cout, 3);
                }
            }

            if ($prix <= 0) {
                $motif = 'prix_nul_ou_negatif';
            } elseif ($prix === 1.0 && $cout !== null && $cout > 1.0) {
                $motif = 'prix_1_eur_cout_superieur';
            } elseif ($cout !== null && $prix < $cout) {
                $motif = 'prix_sous_cout';
            } else {
                continue;
            }

            $anomalies[] = [
                'produit'           => self::tronque($nom),
                'categorie'         => self::tronque(self::categorieFiche($fiche)),
                'prix_eur'          => $prix,
                'cout_unitaire_eur' => $cout,
                'motif'             => $motif,
            ];
        }

        usort($anomalies, static fn (array $a, array $b): int => [$a['prix_eur'], $a['produit']] <=> [$b['prix_eur'], $b['produit']]);

        return $anomalies;
    }

    // -----------------------------------------------------------------
    //  (f) Fiches dormantes avec stock
    // -----------------------------------------------------------------

    /**
     * Fiches ACTIVES dont la dernière vente (forme normalisée, alias
     * inclus) date de plus de DORMANCE_JOURS jours alors qu'elles ont
     * encore du stock THÉORIQUE > 0 (livre UNIQUE AnalyticsForecastData
     * ::stockBook) : marchandise immobilisée, à déstocker ou solder.
     * Stock inconnu (aucune donnée d'inventaire) = non signalé.
     *
     * @param list<array<string,mixed>>                          $fiches
     * @param array<string,array{label:string,last:?string,qty:int}> $ventesNorm
     *
     * @return list<array<string,mixed>>
     */
    private static function fichesDormantes(array $fiches, array $ventesNorm, \App\Core\Compta\Reappro\StockBook $book, \DateTimeImmutable $aujourdhui): array
    {
        $anomalies = [];
        foreach ($fiches as $fiche) {
            if ((int) ($fiche['is_active'] ?? 0) !== 1) {
                continue;
            }
            $nom  = (string) ($fiche['name'] ?? '');
            $norm = StockPublic::normalizeKey($nom);
            if ($norm === '' || !isset($ventesNorm[$norm])) {
                continue; // jamais vendue : déjà couvert par (a)
            }
            $derniere = $ventesNorm[$norm]['last'];
            if ($derniere === null) {
                continue;
            }
            $jours = self::joursDepuis($aujourdhui, $derniere);
            if ($jours <= self::DORMANCE_JOURS) {
                continue;
            }

            $resolu = $book->resolveLoose($nom);
            $stock = $resolu['stock'] ?? null;
            if ($stock === null || $stock <= 0) {
                continue;
            }

            $anomalies[] = [
                'produit'          => self::tronque($nom),
                'categorie'        => self::tronque(self::categorieFiche($fiche)),
                'stock'            => (int) $stock,
                'derniere_vente'   => $derniere,
                'jours_sans_vente' => $jours,
            ];
        }

        usort($anomalies, static fn (array $a, array $b): int => [$b['jours_sans_vente'], -$a['stock']] <=> [$a['jours_sans_vente'], -$b['stock']]);

        return $anomalies;
    }

    // -----------------------------------------------------------------
    //  (g) Doublons d'appellations (alias)
    // -----------------------------------------------------------------

    /**
     * Libellés d'alias qui NORMALISENT identiquement (StockPublic
     * ::normalizeKey — « Coca cherry » / « Coca_cherry ») alors qu'une
     * seule entrée doit exister (RÈGLE anti-doublons : la garde
     * ProductAlias::save empêche les nouveaux doublons, cette veille
     * signale les doublons HÉRITÉS d'avant-garde, et les comptages de
     * ventes par libellé montrent l'enjeu).
     *
     * Deux niveaux (décision utilisateur W4) :
     *  - « simple » (a_decider = false) : tous les libellés pointent
     *    vers la MÊME clé — la fusion des compteurs/lignes d'alias
     *    suffit (chemin ProductAlias::save / consolidateFor) ;
     *  - « lourde » (a_decider = true, [À DÉCIDER] dans le rapport) :
     *    les doublons portent des clés DISTINCTES — tout doit fusionner
     *    par ProductKeyMerge (ventes, lots de coûts, stocks, comptages,
     *    fiches, alias), jamais automatiquement. « donnees_attachees »
     *    liste les familles de données portées par les clés concernées
     *    (fiche/ventes/coûts) pour éclairer la décision.
     *
     * @param array<string,array{label:string,last:?string,qty:int}> $ventesNorm
     * @param array<string,array{active:bool,row:array<string,mixed>}> $fichesNorm
     *
     * @return list<array<string,mixed>>
     */
    private static function aliasDoublons(array $ventesNorm, array $fichesNorm): array
    {
        // Coûts par clé normalisée (une seule lecture, via l'API publique
        // du modèle — aucun accès SQL direct depuis la veille).
        $coutsNorm = [];
        try {
            foreach (ProductCost::all() as $lot) {
                $norm = StockPublic::normalizeKey((string) ($lot['product_key'] ?? ''));
                if ($norm !== '') {
                    $coutsNorm[$norm] = true;
                }
            }
        } catch (\Throwable) {
            $coutsNorm = [];
        }

        // Groupes de libellés par forme normalisée (occurrences de ventes
        // par libellé brut — Sale::allDescriptions, appariement ci).
        $occurrencesBrutes = [];
        foreach (self::occurrencesParLibelle() as $desc => $n) {
            $occurrencesBrutes[mb_strtolower($desc)] = (int) $n;
        }
        $parNorm = [];
        foreach (ProductAlias::all() as $alias) {
            $raw = trim((string) ($alias['raw_description'] ?? ''));
            $norm = StockPublic::normalizeKey($raw);
            $cibleNorm = StockPublic::normalizeKey((string) ($alias['product_key'] ?? ''));
            if ($norm === '' || $cibleNorm === '') {
                continue;
            }
            $parNorm[$norm][] = [
                'raw'         => $raw,
                'occurrences' => $occurrencesBrutes[mb_strtolower($raw)] ?? 0,
                'cible'       => (string) $alias['product_key'],
                'cible_norm'  => $cibleNorm,
            ];
        }

        $anomalies = [];
        foreach ($parNorm as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            // Libellés par occurrences décroissantes puis ordre alphabétique.
            usort($rows, static function (array $a, array $b): int {
                if ($a['occurrences'] !== $b['occurrences']) {
                    return $b['occurrences'] <=> $a['occurrences'];
                }

                return strcasecmp($a['raw'], $b['raw']);
            });

            $ciblesNorm = array_values(array_unique(array_column($rows, 'cible_norm')));
            $ciblesBrutes = array_values(array_unique(array_column($rows, 'cible')));
            $memeCle = count($ciblesNorm) === 1;

            // Données attachées aux clés DISTINCTES impliquées (fiche,
            // ventes, coûts) : à clé commune, rien à déplacer.
            $donneesAttachees = [];
            $commune = $memeCle ? $ciblesNorm[0] : null;
            foreach ($ciblesNorm as $cibleNorm) {
                if ($cibleNorm === $commune) {
                    continue;
                }
                if (isset($fichesNorm[$cibleNorm])) {
                    $donneesAttachees['fiche'] = true;
                }
                if (isset($ventesNorm[$cibleNorm])) {
                    $donneesAttachees['ventes'] = true;
                }
                if (isset($coutsNorm[$cibleNorm])) {
                    $donneesAttachees['couts'] = true;
                }
            }

            $lourde = !$memeCle;

            $anomalies[] = [
                'libelles'             => array_map(
                    static fn (array $r): string => self::tronque((string) $r['raw']),
                    $rows
                ),
                'cibles'               => array_map(
                    static fn (string $c): string => self::tronque($c),
                    $ciblesBrutes
                ),
                'occurrences_par_libelle' => array_combine(
                    array_map(static fn (array $r): string => self::tronque((string) $r['raw']), $rows),
                    array_map(static fn (array $r): int => (int) $r['occurrences'], $rows)
                ),
                'occurrences_total'    => array_sum(array_map(static fn (array $r): int => (int) $r['occurrences'], $rows)),
                'niveau'               => $lourde ? 'lourde' : 'simple',
                'a_decider'            => $lourde,
                'donnees_attachees'    => array_keys($donneesAttachees),
            ];
        }

        // Enjeu décroissant (occurrences totales), puis ordre stable.
        usort($anomalies, static function (array $a, array $b): int {
            if ($a['occurrences_total'] !== $b['occurrences_total']) {
                return $b['occurrences_total'] <=> $a['occurrences_total'];
            }

            return strcasecmp((string) $a['libelles'][0], (string) $b['libelles'][0]);
        });

        return $anomalies;
    }

    /**
     * Occurrences de ventes par libellé brut : descriptions distinctes
     * des ventes (Sale::allDescriptions — COUNT(*) par description,
     * point d'assemblage existant de l'auto-détection de doublons),
     * appariées aux libellés d'alias en comparaison insensible à la
     * casse (collation ci).
     *
     * @return array<string,int>
     */
    private static function occurrencesParLibelle(): array
    {
        $out = [];
        try {
            foreach (Sale::allDescriptions() as $row) {
                $desc = trim((string) ($row['description'] ?? ''));
                if ($desc !== '') {
                    $out[$desc] = (int) ($row['occurrences'] ?? 0);
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    // -----------------------------------------------------------------
    //  Petits utilitaires partagés
    // -----------------------------------------------------------------

    /**
     * Agrège une vente dans la carte normalisée : quantités sommées,
     * dernière vente la plus récente, libellé d'affichage = la clé la
     * plus vendue (pour cles_sans_fiche).
     *
     * @param array<string,array{label:string,last:?string,qty:int}> $ventesNorm
     * @param array{last:?string,qty:int} $stats
     */
    private static function agregerVente(array &$ventesNorm, string $norm, string $label, array $stats): void
    {
        if ($norm === '') {
            return;
        }
        if (!isset($ventesNorm[$norm])) {
            $ventesNorm[$norm] = ['label' => $label, 'last' => $stats['last'], 'qty' => (int) $stats['qty']];

            return;
        }
        $courant = $ventesNorm[$norm];
        $ventesNorm[$norm] = [
            'label' => (int) $stats['qty'] > $courant['qty'] ? $label : $courant['label'],
            'last'  => self::dateMax($stats['last'], $courant['last']),
            'qty'   => $courant['qty'] + (int) $stats['qty'],
        ];
    }

    /** Date la plus récente de deux dates AAAA-MM-JJ (null ignoré). */
    private static function dateMax(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        return $b === null || $a >= $b ? $a : $b;
    }

    /** Premier (plus ancien) lot de coût connu d'une clé, null si aucun. */
    private static function premierLot(string $nom): ?array
    {
        try {
            $lots = ProductCost::forProduct($nom);
        } catch (\Throwable) {
            return null;
        }
        if ($lots === []) {
            return null;
        }

        return $lots[count($lots) - 1]; // triés valid_from DESC → dernier = plus ancien
    }

    /** Catégorie d'affichage d'une fiche (jointure de Product::allForAdmin). */
    private static function categorieFiche(array $fiche): string
    {
        $nom = (string) ($fiche['category_name'] ?? '');

        return $nom === '' ? '—' : $nom;
    }

    /** Jours calendaires Paris écoulés depuis une date AAAA-MM-JJ. */
    private static function joursDepuis(\DateTimeImmutable $aujourdhui, string $jour): int
    {
        $date = new \DateTimeImmutable(substr($jour, 0, 10), $aujourdhui->getTimezone());

        return (int) $aujourdhui->diff($date)->days;
    }

    /** Comparaison de « jours depuis » décroissante (null = inconnu, en dernier). */
    private static function cmpJoursDesc(?int $a, ?int $b): int
    {
        $va = $a ?? PHP_INT_MIN;
        $vb = $b ?? PHP_INT_MIN;

        return $vb <=> $va;
    }

    /** Tronque un libellé à LIBELLE_MAX caractères (ellipsis si coupé). */
    private static function tronque(string $libelle): string
    {
        $libelle = trim($libelle);

        return mb_strlen($libelle) <= self::LIBELLE_MAX
            ? $libelle
            : rtrim(mb_substr($libelle, 0, self::LIBELLE_MAX - 1)) . '…';
    }
}
