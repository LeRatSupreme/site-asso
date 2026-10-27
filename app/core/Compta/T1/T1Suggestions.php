<?php

declare(strict_types=1);

namespace App\Core\Compta\T1;

use App\Core\Compta\StockPublic;
use Throwable;

/**
 * Assemblage des « Suggestions de rapprochement » de l'inventaire
 * (spec T1 §6, lot C) : paires issues du moteur de similarité
 * (data/eval_t1/candidats_fusion.json) et résiduels à qualifier
 * (data/eval_t1/residuels_a_qualifier.csv).
 *
 * L'humain reste décisionnaire : cette classe ne fusionne RIEN, elle
 * prépare uniquement les lignes affichées (clés vivantes, paires non
 * encore décidées, plafond d'affichage) et calcule l'identifiant
 * stable d'une paire pour le journal des décisions (boucle P3).
 *
 * Classe PURE (aucun accès base, aucun fichier obligatoire) : toute
 * entrée absente/corrompue/vide dégrade silencieusement — jamais
 * d'exception, la page Inventaire doit rester fonctionnelle.
 *
 * Règle anti-régression projet : l'identité d'une clé produit passe
 * exclusivement par StockPublic::normalizeKey() (même repliement que
 * ProductKeyMerge::canMerge) — jamais de strtolower ad hoc (AGENTS.md).
 */
final class T1Suggestions
{
    /** Action d'audit journalisant une décision humaine sur une paire. */
    public const ACTION_FEEDBACK = 't1.pair_feedback';

    /** Type d'entité du journal pour une paire de clés produits. */
    public const ENTITY_TYPE = 'product_pair';

    public const DECISION_FUSION = 'fusion';
    public const DECISION_IGNORER = 'ignorer';

    public const ORIGINE_MODELE = 'modele';
    public const ORIGINE_RESIDUEL = 'residuel';

    /** Plafond d'affichage par section (spec §6 : ~20 lignes). */
    public const MAX_PAR_SECTION = 20;

    /** Taille max d'une clé produit (colonne product_key VARCHAR(255)). */
    private const TAILLE_CLE_MAX = 255;

    /**
     * Identifiant stable d'une paire de libellés : sha1 des deux formes
     * normalisées triées (symétrique : (a,b) et (b,a) donnent le même id,
     * la casse/les accents ne changent rien). Toujours 40 hex — très en
     * dessous de la colonne entity_id (VARCHAR 255).
     */
    public static function pairEntityId(string $a, string $b): string
    {
        $fa = StockPublic::normalizeKey($a);
        $fb = StockPublic::normalizeKey($b);

        return sha1(min($fa, $fb) . '|' . max($fa, $fb));
    }

    /**
     * Lecture défensive de candidats_fusion.json (contrat lot B) :
     * liste triée par score décroissant. Fichier absent, illisible,
     * vide, JSON invalide, clé « candidats » manquante ou entrées
     * inexploitables → liste vide (dégradation gracieuse, jamais
     * d'exception).
     *
     * @return list<array{a:string,b:string,score:float}> score borné [0,1].
     */
    public static function parseCandidats(string $chemin): array
    {
        $contenu = self::lireFichier($chemin);
        if ($contenu === null) {
            return [];
        }

        try {
            $data = json_decode($contenu, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        if (!is_array($data) || !is_array($data['candidats'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($data['candidats'] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $a = is_string($c['a'] ?? null) ? trim($c['a']) : '';
            $b = is_string($c['b'] ?? null) ? trim($c['b']) : '';
            if ($a === '' || $b === '' || $a === $b) {
                continue;
            }
            if (mb_strlen($a) > self::TAILLE_CLE_MAX || mb_strlen($b) > self::TAILLE_CLE_MAX) {
                continue;
            }
            $score = is_numeric($c['score'] ?? null) ? (float) $c['score'] : 0.0;
            $out[] = ['a' => $a, 'b' => $b, 'score' => min(1.0, max(0.0, $score))];
        }

        // Score décroissant, puis ordre lexicographique : déterministe.
        usort($out, static function (array $x, array $y): int {
            if ($x['score'] !== $y['score']) {
                return $y['score'] <=> $x['score'];
            }

            return $x['a'] === $y['a']
                ? strcmp($x['b'], $y['b'])
                : strcmp($x['a'], $y['a']);
        });

        return $out;
    }

    /**
     * Lecture défensive de residuels_a_qualifier.csv (spec §1.3) :
     * colonnes libelle_a, libelle_b, motif, statut (statut ignoré —
     * seules les décisions humaines, via la liste noire d'audit,
     * retirent une paire de l'écran). L'en-tête est sauté ; toute
     * ligne sans deux libellés exploitables est ignorée. Fichier
     * absent/vide/corrompu → liste vide, jamais d'exception.
     *
     * @return list<array{a:string,b:string,motif:string}>
     */
    public static function parseResiduels(string $chemin): array
    {
        if (!is_file($chemin) || !is_readable($chemin)) {
            return [];
        }

        $handle = @fopen($chemin, 'r');
        if ($handle === false) {
            return [];
        }

        $out = [];
        try {
            // En-tête (libelle_a,libelle_b,motif,statut) : ignoré.
            if (fgetcsv($handle, 0, ',', '"', '\\') === false) {
                return [];
            }
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if (count($row) < 2) {
                    continue;
                }
                $a = trim((string) ($row[0] ?? ''));
                $b = trim((string) ($row[1] ?? ''));
                if ($a === '' || $b === '' || $a === $b) {
                    continue;
                }
                if (mb_strlen($a) > self::TAILLE_CLE_MAX || mb_strlen($b) > self::TAILLE_CLE_MAX) {
                    continue;
                }
                $out[] = ['a' => $a, 'b' => $b, 'motif' => trim((string) ($row[2] ?? ''))];
            }
        } finally {
            fclose($handle);
        }

        return $out;
    }

    /**
     * Assemblage UNIQUE des données de la carte « Suggestions de
     * rapprochement » (appelé par AdminStockController::inventory) :
     *
     *  - lecture défensive des deux livrables ;
     *  - les DEUX libellés doivent encore exister (clés vivantes de
     *    ProductKeyMerge::keyStats(), appariement par forme normalisée
     *    — une fusion fait disparaître la clé absorbée) ;
     *  - paires déjà décidées exclues (liste noire des entity_id
     *    journalisés par ACTION_FEEDBACK), paires dédoublonnées par
     *    identifiant (une paire présente dans les deux livrables
     *    n'apparaît qu'une fois, priorité au modèle) ;
     *  - paires non fusionnables (formes normalisées identiques, que
     *    canMerge refuserait) exclues ;
     *  - côté conservé = la clé des ventes SumUp si un seul côté en a,
     *    sinon la première par ordre alphabétique (même heuristique
     *    que la carte « Fusionner des clés produits ») ;
     *  - plafond d'affichage par section (~20, MAX_PAR_SECTION).
     *
     * Les libellés affichés/soumis sont les clés VIVANTES résolues (la
     * candidature peut différer de la clé réelle par la casse) : le
     * POST repart donc toujours vers des clés qui existent.
     *
     * @param array<string,array<string,mixed>> $keyStats     Stats par clé vivante (ProductKeyMerge::keyStats()).
     * @param list<string>                      $dejaDecidees Entity_id de paires déjà traitées (audit_logs).
     *
     * @return array{dispo:bool,total:int,modele:list<array{source:string,target:string,score:float,ressemblance:int}>,residuels:list<array{source:string,target:string,motif:string}>}
     *         dispo = au moins un livrable lisible et non vide (avant filtrage) ;
     *         total = paires affichables restantes (après filtrage, avant plafond).
     */
    public static function pourInventaire(
        string $cheminCandidats,
        string $cheminResiduels,
        array $keyStats,
        array $dejaDecidees
    ): array {
        $vide = ['dispo' => false, 'total' => 0, 'modele' => [], 'residuels' => []];

        try {
            $noires = [];
            foreach ($dejaDecidees as $id) {
                $id = (string) $id;
                if ($id !== '') {
                    $noires[$id] = true;
                }
            }

            [$representants, $ventesParForme] = self::indexClesVivantes($keyStats);

            $candidats = self::parseCandidats($cheminCandidats);
            $residuels = self::parseResiduels($cheminResiduels);

            $vues = [];
            $modele = [];
            foreach ($candidats as $c) {
                $paire = self::habille($c['a'], $c['b'], $representants, $ventesParForme, $noires, $vues);
                if ($paire === null) {
                    continue;
                }
                $modele[] = [
                    'source'       => $paire['source'],
                    'target'       => $paire['target'],
                    'score'        => $c['score'],
                    'ressemblance' => (int) round($c['score'] * 100),
                ];
            }

            $sortieResiduels = [];
            foreach ($residuels as $r) {
                $paire = self::habille($r['a'], $r['b'], $representants, $ventesParForme, $noires, $vues);
                if ($paire === null) {
                    continue;
                }
                $sortieResiduels[] = [
                    'source' => $paire['source'],
                    'target' => $paire['target'],
                    'motif'  => $r['motif'],
                ];
            }

            return [
                'dispo'     => $candidats !== [] || $residuels !== [],
                'total'     => count($modele) + count($sortieResiduels),
                'modele'    => array_slice($modele, 0, self::MAX_PAR_SECTION),
                'residuels' => array_slice($sortieResiduels, 0, self::MAX_PAR_SECTION),
            ];
        } catch (Throwable) {
            // Dégradation gracieuse totale : la carte affiche son message
            // « aucune suggestion », le reste de la page est intact.
            return $vide;
        }
    }

    // ── Internes ───────────────────────────────────────────────────────────

    /**
     * Lit un fichier texte entier, null si absent/illisible/vide.
     */
    private static function lireFichier(string $chemin): ?string
    {
        if (!is_file($chemin) || !is_readable($chemin)) {
            return null;
        }
        $contenu = @file_get_contents($chemin);

        return is_string($contenu) && $contenu !== '' ? $contenu : null;
    }

    /**
     * Index des clés vivantes par forme normalisée :
     * représentant = la clé des ventes SumUp si la forme en possède une,
     * sinon la première par ordre alphabétique (déterministe) ; ventes
     * par forme = max des ventes des clés de la forme.
     *
     * @param array<string,array<string,mixed>> $keyStats
     *
     * @return array{0:array<string,string>,1:array<string,int>}
     */
    private static function indexClesVivantes(array $keyStats): array
    {
        $cles = [];
        foreach ($keyStats as $cleBrute => $stat) {
            $cle = trim((string) $cleBrute);
            if ($cle === '') {
                continue;
            }
            $cles[] = [$cle, is_array($stat) ? (int) ($stat['sales'] ?? 0) : 0];
        }
        usort($cles, static fn (array $x, array $y): int => strcmp($x[0], $y[0]));

        $representants = [];
        $repVentes = [];
        $ventesParForme = [];
        foreach ($cles as [$cle, $ventes]) {
            $forme = StockPublic::normalizeKey($cle);
            if ($forme === '') {
                continue;
            }
            $ventesParForme[$forme] = max($ventesParForme[$forme] ?? 0, $ventes);
            if (!isset($representants[$forme]) || ($ventes > 0 && $repVentes[$forme] === 0)) {
                $representants[$forme] = $cle;
                $repVentes[$forme] = $ventes;
            }
        }

        return [$representants, $ventesParForme];
    }

    /**
     * Valide et habille une paire candidate : les DEUX libellés doivent
     * résoudre vers une clé vivante, les formes doivent être distinctes
     * (canMerge) et la paire ne doit être ni déjà décidée ni déjà vue.
     *
     * @param array<string,string> $representants  Forme normalisée → clé vivante représentante.
     * @param array<string,int>    $ventesParForme Ventes SumUp par forme normalisée.
     * @param array<string,bool>   $noires         Entity_id de paires déjà décidées.
     * @param array<string,bool>   $vues           (mutée) dédoublonnage inter-livrables.
     *
     * @return array{source:string,target:string,id:string}|null
     */
    private static function habille(
        string $a,
        string $b,
        array $representants,
        array $ventesParForme,
        array $noires,
        array &$vues
    ): ?array {
        $fa = StockPublic::normalizeKey($a);
        $fb = StockPublic::normalizeKey($b);
        if ($fa === '' || $fb === '' || $fa === $fb) {
            return null; // paire non fusionnable (canMerge refuserait).
        }
        $cleA = $representants[$fa] ?? null;
        $cleB = $representants[$fb] ?? null;
        if ($cleA === null || $cleB === null) {
            return null; // clé morte : la paire n'est plus d'actualité.
        }

        $id = self::pairEntityId($cleA, $cleB);
        if (isset($noires[$id]) || isset($vues[$id])) {
            return null;
        }
        $vues[$id] = true;

        // Côté conservé (cible) : la clé des ventes SumUp si un seul côté
        // en a, sinon la première forme par ordre alphabétique — même
        // heuristique que la carte « Fusionner des clés produits ».
        $va = $ventesParForme[$fa] ?? 0;
        $vb = $ventesParForme[$fb] ?? 0;
        if ($vb > 0 && $va === 0) {
            $source = $cleA;
            $target = $cleB;
        } elseif ($va > 0 && $vb === 0) {
            $source = $cleB;
            $target = $cleA;
        } elseif (strcmp($fa, $fb) <= 0) {
            $source = $cleB;
            $target = $cleA;
        } else {
            $source = $cleA;
            $target = $cleB;
        }

        return ['source' => $source, 'target' => $target, 'id' => $id];
    }
}
