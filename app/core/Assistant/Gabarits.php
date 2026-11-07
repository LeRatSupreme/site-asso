<?php

declare(strict_types=1);

namespace App\Core\Assistant;

/**
 * GABARITS DE RÉPONSE des questions préparées (steer V1+) : phrases
 * PRÉ-ÉCRITES à placeholders {nom}, remplies au fast-path avec le
 * résultat LIVE de l'outil — réponse complète instantanée, zéro LLM.
 *
 *  generer()  : auto-génération à la commande « Préparer » — templatyse
 *               la dernière réponse ok du journal (les valeurs issues du
 *               résultat d'outil qui apparaissent dans le texte — chiffres
 *               y compris formatés à la française, dates, noms de produits
 *               — deviennent {placeholder} mappées à leur chemin JSON) ;
 *  remplir()  : remplissage au moment du fast-path — un placeholder
 *               inconnu, un chemin introuvable ou un token résiduel
 *               renvoie null : JAMAIS de gabarit troué affiché (repli
 *               voie LLM).
 *
 * Classe PURE : testée unitairement (tests/Unit/GabaritsTest).
 */
final class Gabarits
{
    /** Clés de feuilles toujours templatysables (noms/catégories produits). */
    private const CLES_NOMS = ['nom_demande', 'cle_canonique', 'produit', 'source', 'target', 'categorie'];

    /** Priorité entre clés de noms partageant une même variante (la clé
     *  canonique du produit l'emporte sur la saisie d'origine). */
    private const PRIORITE_CLES = ['cle_canonique' => 0, 'produit' => 1, 'source' => 2, 'target' => 3, 'nom_demande' => 4, 'categorie' => 5];

    /** Placeholders maximum par gabarit (garde-fou de lisibilité). */
    private const PLACEHOLDERS_MAX = 12;

    /**
     * AUTO-GÉNÉRATION : templatyse la réponse du dernier succès à partir
     * du résultat d'outil qui l'a nourrie. Valeurs remplacées de la plus
     * longue à la plus courte (aucun remplacement imbriqué). Renvoie
     * null si rien n'a pu être templatysé (gabarit NULL en base : le
     * formateur générique du fast-path prendra le relais).
     *
     * @param array<string,mixed> $resultat Résultat d'outil (JSON décodé) du dernier succès.
     *
     * @return array{gabarit:string,mapping:array<string,string>}|null
     */
    public static function generer(string $reponse, array $resultat): ?array
    {
        if (trim($reponse) === '') {
            return null;
        }

        $feuilles = [];
        self::collecterFeuilles($resultat, '', $feuilles);

        $candidats = [];
        $priorites = self::PRIORITE_CLES;
        foreach ($feuilles as $chemin => $valeur) {
            $cle = self::cleDe((string) $chemin);
            if (!self::estTemporisable($valeur, $cle)) {
                continue;
            }
            foreach (self::variantes($valeur) as $variante) {
                if ($variante !== '' && str_contains($reponse, $variante)) {
                    // Une seule entrée par variante : si plusieurs feuilles
                    // la fournissent (ex. nom_demande ET cle_canonique), la
                    // clé la plus canonique gagne.
                    $prio = $priorites[$cle] ?? 99;
                    if (!isset($candidats[$variante]) || $prio < $candidats[$variante]['prio']) {
                        $candidats[$variante] = ['variante' => $variante, 'cle' => $cle, 'chemin' => (string) $chemin, 'prio' => $prio];
                    }
                    break; // une variante suffit par feuille
                }
            }
        }
        $candidats = array_values($candidats);
        if ($candidats === []) {
            return null;
        }

        usort($candidats, static fn (array $a, array $b): int => mb_strlen($b['variante']) <=> mb_strlen($a['variante']));

        $gabarit = $reponse;
        $mapping = [];
        $vus = [];
        foreach (array_slice($candidats, 0, self::PLACEHOLDERS_MAX) as $c) {
            if (!str_contains($gabarit, $c['variante'])) {
                continue; // déjà recouvert par un remplacement plus long
            }
            $nom = $c['cle'];
            $vus[$nom] = ($vus[$nom] ?? 0) + 1;
            if ($vus[$nom] > 1) {
                $nom .= '_' . $vus[$nom];
            }
            $gabarit = str_replace($c['variante'], '{' . $nom . '}', $gabarit);
            $mapping[$nom] = $c['chemin'];
        }

        return $mapping === [] ? null : ['gabarit' => $gabarit, 'mapping' => $mapping];
    }

    /**
     * REMPLISSAGE au fast-path : chaque {placeholder} est résolu sur le
     * résultat LIVE des outils (premier résultat contenant le chemin).
     *
     * @param array<string,string>      $mapping   placeholder → chemin JSON pointé.
     * @param list<array{donnees:array<string,mixed>}> $resultats résultats décodés des outils rejoués.
     */
    public static function remplir(string $gabarit, array $mapping, array $resultats): ?string
    {
        if (trim($gabarit) === '' || $mapping === []) {
            return null;
        }
        // Détection ÉLARGIE à tout token {…} : une forme exotique
        // ({Stock}, {a b}…) non exactement couverte par le mapping tombe
        // dans le return null ci-dessous → repli LLM, jamais verbatim.
        $nb = preg_match_all('/\{([^}]+)\}/', $gabarit, $m);
        if ($nb === false || $nb === 0 || ($m[0] ?? []) === []) {
            return null; // gabarit sans placeholder : rien à remplir
        }

        $remplace = $gabarit;
        foreach ($m[1] as $nom) {
            if (!isset($mapping[$nom]) || !is_string($mapping[$nom]) || $mapping[$nom] === '') {
                return null; // placeholder inconnu du mapping : gabarit troué interdit
            }
            $valeur = null;
            foreach ($resultats as $r) {
                $valeur = self::lireChemin(is_array($r['donnees'] ?? null) ? $r['donnees'] : [], $mapping[$nom]);
                if ($valeur !== null) {
                    break;
                }
            }
            if ($valeur === null || (is_string($valeur) && $valeur === '')) {
                return null; // chemin introuvable dans le résultat live : repli LLM
            }
            $remplace = str_replace('{' . $nom . '}', self::rendre($valeur), $remplace);
        }

        if (preg_match('/\{[^}]+\}/', $remplace) === 1) {
            return null; // token résiduel (forme exotique comprise) : gabarit troué interdit
        }

        return $remplace;
    }

    // -----------------------------------------------------------------

    /** Feuille templatysable : chiffre, date (ISO en tête), ou nom produit. */
    private static function estTemporisable(mixed $valeur, string $cle): bool
    {
        if (is_int($valeur) || is_float($valeur)) {
            return true;
        }
        if (is_string($valeur) && preg_match('/^\d{4}-\d{2}-\d{2}/', $valeur) === 1) {
            return true;
        }

        return is_string($valeur) && in_array($cle, self::CLES_NOMS, true);
    }

    /**
     * Variantes textuelles d'une valeur (le LLM écrit « 379,05 € » pour
     * 379.05, « le 2026-10-13 » pour « 2026-10-13 17:21:35 »…).
     *
     * @return list<string>
     */
    private static function variantes(mixed $valeur): array
    {
        if (is_int($valeur)) {
            return [(string) $valeur];
        }
        if (is_float($valeur)) {
            $variantes = [(string) $valeur];
            if ($valeur == (int) $valeur) {
                $variantes[] = (string) (int) $valeur;
            }
            $variantes[] = number_format($valeur, 2, ',', ' ');
            $variantes[] = number_format($valeur, 2, '.', ' ');

            return array_values(array_unique($variantes));
        }
        if (is_string($valeur)) {
            return strlen($valeur) > 10 && preg_match('/^\d{4}-\d{2}-\d{2}/', $valeur) === 1
                ? [$valeur, substr($valeur, 0, 10)]
                : [$valeur];
        }

        return [];
    }

    /** @param array<string,mixed> $feuilles chemin pointé → valeur scalaire */
    private static function collecterFeuilles(mixed $noeud, string $prefixe, array &$feuilles): void
    {
        if (is_array($noeud)) {
            foreach ($noeud as $cle => $valeur) {
                self::collecterFeuilles($valeur, $prefixe === '' ? (string) $cle : $prefixe . '.' . $cle, $feuilles);
            }

            return;
        }
        if (is_scalar($noeud) && !is_bool($noeud) && $prefixe !== '') {
            $feuilles[$prefixe] = $noeud;
        }
    }

    /** Lecture d'un chemin pointé dans un tableau décodé (null si absent). */
    private static function lireChemin(array $donnees, string $chemin): mixed
    {
        $courant = $donnees;
        foreach (explode('.', $chemin) as $segment) {
            if (!is_array($courant) || !array_key_exists($segment, $courant)) {
                return null;
            }
            $courant = $courant[$segment];
        }

        return is_scalar($courant) && !is_bool($courant) ? $courant : null;
    }

    /** Rendu d'une valeur live : entiers sans décimales, décimaux à la française, chaînes telles quelles. */
    private static function rendre(mixed $valeur): string
    {
        if (is_int($valeur)) {
            return (string) $valeur;
        }
        if (is_float($valeur)) {
            return $valeur == (int) $valeur ? (string) (int) $valeur : number_format($valeur, 2, ',', ' ');
        }

        return (string) $valeur;
    }

    /** Dernier segment d'un chemin pointé (clé de feuille). */
    private static function cleDe(string $chemin): string
    {
        $segments = explode('.', $chemin);

        return (string) end($segments);
    }
}
