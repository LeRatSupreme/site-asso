<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Ordre persistant de la sidebar admin (drag & drop).
 *
 * Point d'assemblage UNIQUE de l'ordre du menu admin : le layout
 * (views/layouts/admin.php) construit l'arbre filtré par permissions,
 * puis appelle SidebarOrder::apply($sections) en dernier — jamais de
 * réordonnage ad hoc ailleurs (même règle que StockBook/PackResolver
 * pour les lookups produits).
 *
 * Contrat du JSON stocké (Setting « admin_sidebar_order », borné à
 * MAX_JSON_BYTES, '{}' = ordre par défaut) :
 *
 *   {
 *     "sections":  ["Stock", "Accueil", ...],              // titres
 *     "items":     {"Stock": ["Inventaire", "Produits"], ...},
 *     "subgroups": {"Stock|Inventaire": ["Comptage à l'aveugle", ...], ...}
 *   }
 *
 * Sémantique d'apply() : tri stable — seules les clés présentes À LA FOIS
 * dans le JSON et dans l'arbre bougent (devant, dans l'ordre du JSON) ;
 * les clés absentes du JSON gardent leur ordre par défaut APRÈS les clés
 * connues ; toute clé du JSON inconnue est ignorée ; AUCUN lien n'est
 * jamais ajouté (l'arbre filtré reste la seule source de vérité, le
 * filtrage permissions reste la responsabilité du layout).
 */
final class SidebarOrder
{
    /** Clé du setting clé/valeur qui porte l'ordre (cf. Setting::get/set). */
    public const SETTING_KEY = 'admin_sidebar_order';

    /** Taille maximale du JSON stocké (après sanitize) : 8 Ko. */
    public const MAX_JSON_BYTES = 8192;

    /**
     * Réordonne l'arbre de sections selon l'ordre enregistré.
     *
     * Un setting absent, vide ('{}'), trop gros ou corrompu retombe
     * SILENCIEUSEMENT sur l'ordre par défaut : la sidebar s'affiche
     * toujours, elle n'est jamais cassée par une donnée invalide.
     *
     * @param array<string, array<string, string|array<string, string>>> $sections
     *        Arbre filtré du layout : titre de section => entrées, une
     *        entrée étant 'label' => '/chemin' (lien) ou
     *        'sousGroupe' => ['label' => '/chemin', ...].
     *
     * @return array<string, array<string, string|array<string, string>>>
     */
    public static function apply(array $sections): array
    {
        $stocke = Setting::get(self::SETTING_KEY, '');
        if ($stocke === '' || $stocke === '{}') {
            return $sections; // Aucun ordre enregistré : défaut du layout.
        }

        if (strlen($stocke) > self::MAX_JSON_BYTES) {
            return $sections; // Valeur gonflée : ignorée, pas de calcul fou.
        }

        $ordre = json_decode($stocke, true);
        if (!is_array($ordre)) {
            return $sections; // JSON invalide : fallback silencieux.
        }

        $items = is_array($ordre['items'] ?? null) ? $ordre['items'] : [];
        $subgroups = is_array($ordre['subgroups'] ?? null) ? $ordre['subgroups'] : [];

        // 1. Ordre des sections (titres).
        $sections = self::reordonner($sections, $ordre['sections'] ?? []);

        // 2. Ordre des entrées de chaque section (liens plats ET
        //    sous-groupes mêlés, ex. Association) puis, 3. ordre des
        //    liens à l'intérieur de chaque sous-groupe (clé composée
        //    « Section|SousGroupe »).
        foreach ($sections as $titre => $entrees) {
            if (!is_array($entrees)) {
                continue;
            }
            $sections[$titre] = self::reordonner($entrees, $items[$titre] ?? []);
            foreach ($sections[$titre] as $sousGroupe => $entree) {
                if (is_array($entree)) {
                    $cle = $titre . '|' . $sousGroupe;
                    $sections[$titre][$sousGroupe] = self::reordonner($entree, $subgroups[$cle] ?? []);
                }
            }
        }

        return $sections;
    }

    /**
     * Valide/normalise un payload d'ordre reçu du front en 3 champs :
     *  - 'sections'  : liste de titres de sections ;
     *  - 'items'     : 'Titre de section' => liste de labels (liens plats
     *                  et sous-groupes mêlés) ;
     *  - 'subgroups' : 'Section|SousGroupe' => liste de labels.
     *
     * Chaînes non vides uniquement (trimmées), dédoublonnées (première
     * occurrence conservée). Tout le reste (types inattendus, valeurs
     * vides, listes vides) est rejeté proprement. Payload sans rien
     * d'utilisable => [] (= ordre par défaut ; save([]) stocke '{}').
     *
     * @return array<string, mixed> Sous-ensemble non vide des 3 champs.
     */
    public static function sanitize(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $propre = [];

        $sections = self::sanitizeListe($data['sections'] ?? null);
        if ($sections !== []) {
            $propre['sections'] = $sections;
        }

        $items = self::sanitizeMap($data['items'] ?? null);
        if ($items !== []) {
            $propre['items'] = $items;
        }

        $subgroups = self::sanitizeMap($data['subgroups'] ?? null);
        if ($subgroups !== []) {
            $propre['subgroups'] = $subgroups;
        }

        return $propre;
    }

    /**
     * Enregistre l'ordre (déjà passé par sanitize()) : []/'{}' signifie
     * « ordre par défaut ». Refuse un JSON de plus de MAX_JSON_BYTES.
     *
     * @param array<string, mixed> $order
     */
    public static function save(array $order): void
    {
        if ($order === []) {
            Setting::set(self::SETTING_KEY, '{}');

            return;
        }

        $json = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > self::MAX_JSON_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Ordre de la sidebar trop volumineux (max %d octets).',
                self::MAX_JSON_BYTES
            ));
        }

        Setting::set(self::SETTING_KEY, $json);
    }

    /**
     * Tri stable de $arbre selon $connus : les clés présentes des deux
     * côtés passent devant (dans l'ordre de $connus), les autres gardent
     * leur ordre par défaut derrière ; clés de $connus inconnues ignorées,
     * doublons ignorés, aucune clé ajoutée. $connus invalide => $arbre
     * inchangé.
     *
     * @param array<int|string, mixed> $arbre
     *
     * @return array<int|string, mixed>
     */
    private static function reordonner(array $arbre, mixed $connus): array
    {
        if (!is_array($connus)) {
            return $arbre;
        }

        $tries = [];
        foreach ($connus as $cle) {
            if (!is_string($cle) && !is_int($cle)) {
                continue; // Chaînes uniquement : le reste est ignoré.
            }
            $cle = (string) $cle;
            if ($cle === '' || !array_key_exists($cle, $arbre) || array_key_exists($cle, $tries)) {
                continue;
            }
            $tries[$cle] = $arbre[$cle];
        }

        if ($tries === []) {
            return $arbre; // Rien de connu : ordre par défaut intact.
        }

        foreach ($arbre as $cle => $valeur) {
            if (!array_key_exists((string) $cle, $tries)) {
                $tries[(string) $cle] = $valeur; // Absent du JSON : APRÈS.
            }
        }

        return $tries;
    }

    /**
     * @return list<string>
     */
    private static function sanitizeListe(mixed $valeur): array
    {
        if (!is_array($valeur)) {
            return [];
        }

        $liste = [];
        foreach ($valeur as $element) {
            if (!is_string($element)) {
                continue;
            }
            $element = trim($element);
            if ($element === '' || in_array($element, $liste, true)) {
                continue;
            }
            $liste[] = $element;
        }

        return $liste;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function sanitizeMap(mixed $valeur): array
    {
        if (!is_array($valeur)) {
            return [];
        }

        $map = [];
        foreach ($valeur as $cle => $liste) {
            if (!is_string($cle) && !is_int($cle)) {
                continue;
            }
            $cle = trim((string) $cle);
            if ($cle === '') {
                continue;
            }
            $nettoyee = self::sanitizeListe($liste);
            if ($nettoyee === []) {
                continue;
            }
            $map[$cle] = $nettoyee;
        }

        return $map;
    }
}
