<?php

declare(strict_types=1);

namespace App\Core\Compta\T1;

use App\Core\Compta\AliasSuggester;
use App\Core\Compta\StockPublic;
use RuntimeException;

/**
 * Construction déterministe du jeu d'évaluation T1 (rapprochement produits).
 *
 * Spec : docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md (§3) — jeu figé :
 *  - paires positives sémantiques (fusions auditées + alias actuels), hors
 *    variantes de casse pure (déjà résolues par la normalisation) ;
 *  - négatifs « durs » intra-famille (Redbull Bleu vs Redbull Blanche…) ;
 *  - négatifs inter-familles échantillonnés avec graine fixe ;
 *  - split PAR PRODUIT (union-find des paires positives), sans fuite
 *    train/test, ≥ 20 paires sémantiques côté test ;
 *  - résiduels §1.3 (douteux non qualifiés) JAMAIS dans le jeu.
 *
 * Cette classe est PURE (aucun accès base) : le script CLI
 * scripts/t1_export_eval.php n'est qu'un wrapper (SELECT + écritures).
 *
 * Règle anti-régression projet : toute normalisation de libellé passe par
 * StockPublic::normalizeKey() / AliasSuggester::normalizeKey() — jamais de
 * strtolower ad hoc (AGENTS.md).
 */
final class EvalDataset
{
    /** Graine par défaut (reproductibilité des tirages). */
    public const SEED_DEFAUT = 42;

    /** Part cible d'identités côté test pour le split (§3.4 : ~30 %). */
    public const RATIO_TEST_DEFAUT = 0.30;

    /** Contrainte minimale de paires sémantiques côté test (§3.4). */
    public const MIN_PAIRES_SEMANTIQUES_TEST = 20;

    /** Distance de Levenshtein max pour les near-miss résiduels auto-détectés. */
    public const NEAR_MISS_DISTANCE_MAX = 2;

    /** Priorité de représentant lors du dédoublonnage des paires positives (0 = prioritaire). */
    private const PRIORITE_ORIGINE = [
        'product_aliases'          => 0, // vérité vivante (table actuelle)
        'compta.repair.merge_cle'  => 1,
        'compta.product.merge'     => 2,
        'compta.aliases.merge'     => 3,
        'inventory.merge'          => 4,
    ];

    // ── Normalisation (délégation aux résolveurs du projet) ─────────────────

    /**
     * Forme normalisée canonique d'un libellé (casse, accents, séparateurs
     * et espaces supprimés) — délègue à StockPublic::normalizeKey().
     */
    public static function forme(string $libelle): string
    {
        return StockPublic::normalizeKey($libelle);
    }

    /**
     * Répare le double encodage UTF-8→latin1→UTF-8 (« Ã© » → « é ») observé
     * dans 1 événement d'audit (ex. « Oasis ThÃ©mangue Passion »).
     *
     * Déterministe et prudent : ne s'applique que si la chaîne contient un
     * « Ã » multioctet (jamais légitime en français), et seulement si le
     * résultat de la conversion est une chaîne UTF-8 valide sans « Ã ».
     */
    public static function repairMojibake(string $libelle): string
    {
        if ($libelle === '' || preg_match('/Ã./u', $libelle) !== 1) {
            return $libelle;
        }

        $repare = @mb_convert_encoding($libelle, 'ISO-8859-1', 'UTF-8');
        if (
            !is_string($repare)
            || $repare === ''
            || preg_match('/Ã./u', $repare) === 1
            || preg_match('//u', $repare) !== 1
        ) {
            return $libelle;
        }

        return $repare;
    }

    /**
     * Famille d'un libellé : premier token de la forme normalisée à espaces
     * (AliasSuggester::normalizeKey) — « Redbull Bleu » → « redbull »,
     * « Fanta Citron » → « fanta ». La clé parasite « . » donne « ».
     */
    public static function famille(string $libelle): string
    {
        $norm = AliasSuggester::normalizeKey($libelle);

        return explode(' ', $norm)[0] ?? '';
    }

    /**
     * Clé de paire (non ordonnée) de deux formes normalisées, pour
     * dédoublonner / interdire des paires. Forme vide → '' (paire invalide).
     */
    public static function paireFormes(string $formeA, string $formeB): string
    {
        if ($formeA === '' || $formeB === '') {
            return '';
        }

        return $formeA < $formeB ? $formeA . '|' . $formeB : $formeB . '|' . $formeA;
    }

    /** Clé de paire de deux libellés bruts (normalisés via forme()). */
    public static function paire(string $libelleA, string $libelleB): string
    {
        return self::paireFormes(self::forme($libelleA), self::forme($libelleB));
    }

    /** Variante de casse pure : même forme normalisée, libellés bruts distincts. */
    public static function estCassePure(string $libelleA, string $libelleB): bool
    {
        return $libelleA !== $libelleB
            && self::forme($libelleA) !== ''
            && self::forme($libelleA) === self::forme($libelleB);
    }

    /**
     * Libellé représentant d'une identité parmi ses libellés bruts.
     * Règle déterministe : plus grand nombre de tables sources, puis plus
     * grand nombre d'occurrences (ventes + achats), puis ordre lexicographique.
     *
     * @param list<array{libelle:string,sources:int,occurrences:int}> $candidats
     */
    public static function representant(array $candidats): ?string
    {
        $meilleur = null;
        $cleMeilleur = null;
        foreach ($candidats as $c) {
            $cle = [-1 * (int) $c['sources'], -1 * (int) $c['occurrences'], (string) $c['libelle']];
            if ($cleMeilleur === null || $cle < $cleMeilleur) {
                $cleMeilleur = $cle;
                $meilleur = (string) $c['libelle'];
            }
        }

        return $meilleur;
    }

    // ── Paires positives (fusions auditées + alias) ─────────────────────────

    /**
     * Déduplonne et classe les paires positives.
     *
     * Entrée : événements ['brut','cible','origine','action','kind','ref','ts']
     * (origine = product_aliases|audit_logs ; action vide pour les alias ;
     * kind = label|appellation pour les alias).
     *
     * Sortie : ['semantique' => lignes, 'casse' => lignes, 'exclues' => lignes,
     * 'anomalies' => list<string>] ; une ligne =
     * ['brut','cible','origine','action','kind','remarque','paire'].
     *
     * - casse pure (forme(a) === forme(b)) → fichier de transparence ;
     * - sémantique → dédoublonné par paire de formes (les variantes de casse
     *   d'une même fusion sémantique fusionnent en UNE ligne) ;
     * - paire listée dans $pairesExclues (résiduels §1.3) → sorties du jeu
     *   (remarque explicite), à qualification humaine ;
     * - libellé vide / auto-alias (brut === cible) → ignoré + anomalie.
     *
     * @param list<array{brut:string,cible:string,origine:string,action:string,kind:string,ref:string,ts:string}> $evenements
     * @param list<string> $pairesExclues clés paireFormes() à écarter
     *
     * @return array{semantique:list<array<string,string>>,casse:list<array<string,string>>,exclues:list<array<string,string>>,anomalies:list<string>}
     */
    public static function construirePositifs(array $evenements, array $pairesExclues): array
    {
        $exclues = array_fill_keys(array_values($pairesExclues), true);

        $semantiques = [];
        $cases = [];
        $anomalies = [];

        foreach ($evenements as $ev) {
            $brut = self::repairMojibake(trim((string) ($ev['brut'] ?? '')));
            $cible = self::repairMojibake(trim((string) ($ev['cible'] ?? '')));
            $origine = (string) ($ev['origine'] ?? '');
            $action = (string) ($ev['action'] ?? '');
            $kind = (string) ($ev['kind'] ?? '');
            $ref = (string) ($ev['ref'] ?? '');
            $ts = (string) ($ev['ts'] ?? '');

            if ($brut === '' || $cible === '') {
                $anomalies[] = "événement ignoré (libellé vide) : origine={$origine} action={$action} ref={$ref}";
                continue;
            }
            if ($brut === $cible) {
                $anomalies[] = "auto-alias ignoré (brut === cible) : « {$brut} » ({$origine} {$action} {$ref})";
                continue;
            }

            $formeBrut = self::forme($brut);
            $formeCible = self::forme($cible);
            if ($formeBrut === '' || $formeCible === '') {
                $anomalies[] = "événement ignoré (forme vide après normalisation) : « {$brut} » / « {$cible} » ({$origine} {$action} {$ref})";
                continue;
            }

            $prio = self::PRIORITE_ORIGINE[$origine === 'product_aliases' ? 'product_aliases' : $action] ?? 99;
            $cleTri = [$prio, $ts, $ref];
            $source = $origine === 'product_aliases'
                ? 'product_aliases' . ($kind !== '' ? '/' . $kind : '')
                : 'audit_logs/' . $action;

            // Deux clés de dédoublonnage distinctes :
            //  - casse pure : paire de libellés BRUTS (toutes les variantes
            //    restent visibles, fichier de transparence) ;
            //  - sémantique : paire de FORMES (les variantes de casse d'une
            //    même fusion sémantique fusionnent en une seule ligne).
            if (self::estCassePure($brut, $cible)) {
                $cle = $brut < $cible ? $brut . "\x00" . $cible : $cible . "\x00" . $brut;
                if (isset($cases[$cle])) {
                    $cases[$cle]['sources'][] = $source;
                    continue;
                }
                $cases[$cle] = [
                    'brut' => $brut, 'cible' => $cible,
                    'origine' => $origine, 'action' => $action, 'kind' => $kind,
                    'sources' => [$source], 'cleTri' => $cleTri, 'paire' => $formeBrut,
                ];
                continue;
            }

            $cle = self::paireFormes($formeBrut, $formeCible);
            if (isset($semantiques[$cle])) {
                $semantiques[$cle]['sources'][] = $source;
                if ($cleTri < $semantiques[$cle]['cleTri']) {
                    $semantiques[$cle] = array_merge($semantiques[$cle], [
                        'brut' => $brut, 'cible' => $cible, 'origine' => $origine,
                        'action' => $action, 'kind' => $kind, 'cleTri' => $cleTri,
                    ]);
                }
                continue;
            }
            $semantiques[$cle] = [
                'brut' => $brut, 'cible' => $cible,
                'origine' => $origine, 'action' => $action, 'kind' => $kind,
                'sources' => [$source], 'cleTri' => $cleTri, 'paire' => $cle,
            ];
        }

        $ligne = static function (array $p, string $remarque): array {
            return [
                'libelle_brut'    => $p['brut'],
                'libelle_cible'   => $p['cible'],
                'origine'         => $p['origine'],
                'action_origine'  => $p['action'],
                'kind'            => $p['kind'],
                'remarque'        => $remarque,
                'paire'           => $p['paire'],
            ];
        };

        $sem = [];
        $excl = [];
        foreach ($semantiques as $cle => $p) {
            $remarques = [];
            $sources = array_values(array_unique($p['sources']));
            if (count($sources) > 1) {
                $remarques[] = 'doublons : ' . count($sources) . ' événements (' . implode(' ; ', $sources) . ')';
            }
            if (isset($exclues[$cle])) {
                $remarques[] = '§1.3 résiduel → residuels_a_qualifier.csv (sortie du jeu)';
                $excl[] = $ligne($p, implode(' — ', $remarques));
                continue;
            }
            $sem[] = $ligne($p, implode(' — ', $remarques));
        }
        usort($sem, static fn (array $a, array $b): int => [$a['libelle_brut'], $a['libelle_cible']] <=> [$b['libelle_brut'], $b['libelle_cible']]);
        usort($excl, static fn (array $a, array $b): int => [$a['libelle_brut'], $a['libelle_cible']] <=> [$b['libelle_brut'], $b['libelle_cible']]);

        $cas = [];
        foreach ($cases as $p) {
            $sources = array_values(array_unique($p['sources']));
            $remarque = count($sources) > 1 ? 'doublons : ' . count($sources) . ' événements' : '';
            $cas[] = $ligne($p, $remarque);
        }
        usort($cas, static fn (array $a, array $b): int => [$a['libelle_brut'], $a['libelle_cible']] <=> [$b['libelle_brut'], $b['libelle_cible']]);

        return ['semantique' => $sem, 'casse' => $cas, 'exclues' => $excl, 'anomalies' => $anomalies];
    }

    // ── Groupes de split (union-find sur les paires positives) ──────────────

    /**
     * Groupes de produits : union-find sur les paires positives sémantiques
     * (les deux formes d'une paire sont dans le MÊME groupe). Identifiant de
     * groupe = plus petite forme du composant (déterministe).
     *
     * @param list<array{0:string,1:string}> $paires liste [formeA, formeB]
     *
     * @return array<string,string> forme → groupe
     */
    public static function groupesDepuisPaires(array $paires): array
    {
        $parent = [];
        foreach ($paires as [$a, $b]) {
            if ($a !== '') {
                $parent[$a] ??= $a;
            }
            if ($b !== '') {
                $parent[$b] ??= $b;
            }
        }

        $racine = static function (string $n) use (&$parent): string {
            while ($parent[$n] !== $n) {
                $parent[$n] = $parent[$parent[$n]];
                $n = $parent[$n];
            }

            return $n;
        };
        $union = static function (string $a, string $b) use (&$parent, $racine): void {
            if ($a === '' || $b === '') {
                return;
            }
            $ra = $racine($a);
            $rb = $racine($b);
            if ($ra === $rb) {
                return;
            }
            // Racine déterministe : la plus petite forme du composant.
            if ($rb < $ra) {
                [$ra, $rb] = [$rb, $ra];
            }
            $parent[$rb] = $ra;
        };

        foreach ($paires as [$a, $b]) {
            $union($a, $b);
        }

        $groupes = [];
        foreach (array_keys($parent) as $forme) {
            $groupes[$forme] = $racine($forme);
        }
        ksort($groupes, SORT_STRING);

        return $groupes;
    }

    /** Groupe d'une forme : son groupe d'union-find, sinon elle-même. */
    public static function groupeDe(array $groupes, string $forme): string
    {
        return $groupes[$forme] ?? $forme;
    }

    /**
     * Split train/test PAR GROUPE (§3.4), déterministe (graine).
     *
     * 1. Vérifie l'absence de fuite : les deux formes de chaque paire
     *    sémantique appartiennent au même groupe (sinon exception).
     * 2. Mélange Fisher-Yates seedé de la liste des groupes (triée au
     *    préalable), puis remplit le test jusqu'à ~ratio des identités.
     * 3. Si le minimum de paires sémantiques côté test n'est pas atteint,
     *    complète avec les groupes restants les plus riches en paires
     *    (déterministe), quitte à dépasser légèrement le ratio.
     *
     * @param array<string,string>               $groupes          forme → groupe (groupesDepuisPaires)
     * @param array<string,int>                  $poidsGroupes     groupe → nb d'identités de l'univers
     * @param list<array{0:string,1:string}>     $pairesSemantiques formes des paires du jeu
     *
     * @return array{mapping:array<string,string>,stats:array<string,int|float>}
     */
    public static function splitParGroupe(
        array $groupes,
        array $poidsGroupes,
        array $pairesSemantiques,
        int $seed,
        float $ratio = self::RATIO_TEST_DEFAUT,
        int $minPairesTest = self::MIN_PAIRES_SEMANTIQUES_TEST,
    ): array {
        $pairesParGroupe = [];
        foreach ($pairesSemantiques as [$a, $b]) {
            $ga = self::groupeDe($groupes, $a);
            $gb = self::groupeDe($groupes, $b);
            if ($ga !== $gb) {
                throw new RuntimeException("fuite de split : « {$a} » ({$ga}) et « {$b} » ({$gb}) dans des groupes différents");
            }
            $pairesParGroupe[$ga] = ($pairesParGroupe[$ga] ?? 0) + 1;
        }

        $tousGroupes = array_keys($poidsGroupes);
        sort($tousGroupes, SORT_STRING);
        $mapping = array_fill_keys($tousGroupes, 'train');

        $cible = (int) round($ratio * (float) array_sum($poidsGroupes));
        $ordre = self::melangeSeede($tousGroupes, $seed);

        $poidsTest = 0;
        $pairesTest = 0;
        foreach ($ordre as $g) {
            $w = $poidsGroupes[$g];
            if ($poidsTest + $w <= $cible) {
                $mapping[$g] = 'test';
                $poidsTest += $w;
                $pairesTest += $pairesParGroupe[$g] ?? 0;
            }
        }

        if ($pairesTest < $minPairesTest) {
            $restants = array_values(array_filter($ordre, static fn (string $g): bool => $mapping[$g] === 'train'));
            usort($restants, static fn (string $x, string $y): int =>
                [($pairesParGroupe[$y] ?? 0), $x] <=> [($pairesParGroupe[$x] ?? 0), $y]);
            foreach ($restants as $g) {
                if ($pairesTest >= $minPairesTest) {
                    break;
                }
                $mapping[$g] = 'test';
                $poidsTest += $poidsGroupes[$g];
                $pairesTest += $pairesParGroupe[$g] ?? 0;
            }
        }

        if ($pairesTest < $minPairesTest) {
            throw new RuntimeException("impossible d'atteindre {$minPairesTest} paires sémantiques en test ({$pairesTest} obtenues) : jeu trop petit");
        }

        // Garde-fou final : chaque paire sémantique est entièrement d'un côté.
        $identitesTest = 0;
        foreach ($tousGroupes as $g) {
            if ($mapping[$g] === 'test') {
                $identitesTest += $poidsGroupes[$g];
            }
        }
        foreach ($pairesSemantiques as [$a, $b]) {
            $ga = self::groupeDe($groupes, $a);
            if ($mapping[$ga] !== $mapping[self::groupeDe($groupes, $b)]) {
                throw new RuntimeException("fuite de split : paire « {$a} »/« {$b} » à cheval sur train et test");
            }
        }

        ksort($mapping, SORT_STRING);
        $totalIdentites = (int) array_sum($poidsGroupes);

        return [
            'mapping' => $mapping,
            'stats'   => [
                'groupes_total'              => count($tousGroupes),
                'groupes_train'              => count(array_filter($mapping, static fn (string $s): bool => $s === 'train')),
                'groupes_test'               => count(array_filter($mapping, static fn (string $s): bool => $s === 'test')),
                'identites_total'            => $totalIdentites,
                'identites_train'            => $totalIdentites - $identitesTest,
                'identites_test'             => $identitesTest,
                'ratio_test'                 => $totalIdentites > 0 ? round($identitesTest / $totalIdentites, 4) : 0.0,
                'paires_semantiques_total'   => count($pairesSemantiques),
                'paires_semantiques_train'   => count($pairesSemantiques) - $pairesTest,
                'paires_semantiques_test'    => $pairesTest,
            ],
        ];
    }

    // ── Négatifs ────────────────────────────────────────────────────────────

    /**
     * Négatifs DURS intra-famille, EXHAUSTIFS : toutes les paires
     * d'identités de même famille, ni positives, ni résiduelles, ni
     * identiques après normalisation (§3.2 — critère A4).
     *
     * @param array<string,array{libelle:string,famille:string}> $identites forme → attributs
     * @param array<string,true>                                 $pairesInterdites clés paireFormes()
     *
     * @return list<array{libelle_a:string,libelle_b:string,forme_a:string,forme_b:string,famille:string}>
     */
    public static function negatifsDurs(array $identites, array $pairesInterdites): array
    {
        $formes = array_keys($identites);
        sort($formes, SORT_STRING);

        $lignes = [];
        $n = count($formes);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $fa = $formes[$i];
                $fb = $formes[$j];
                $famA = $identites[$fa]['famille'];
                $famB = $identites[$fb]['famille'];
                if ($famA === '' || $famA !== $famB) {
                    continue;
                }
                if (isset($pairesInterdites[self::paireFormes($fa, $fb)])) {
                    continue;
                }
                $lignes[] = [
                    'libelle_a' => $identites[$fa]['libelle'],
                    'libelle_b' => $identites[$fb]['libelle'],
                    'forme_a'   => $fa,
                    'forme_b'   => $fb,
                    'famille'   => $famA,
                ];
            }
        }
        usort($lignes, static fn (array $a, array $b): int => [$a['famille'], $a['libelle_a'], $a['libelle_b']] <=> [$b['famille'], $b['libelle_a'], $b['libelle_b']]);

        return $lignes;
    }

    /**
     * Négatifs inter-familles échantillonnés (graine fixe), jamais positives
     * ni résiduelles. Énumération déterministe des candidats puis
     * Fisher-Yates seedé.
     *
     * @param array<string,array{libelle:string,famille:string}> $identites
     * @param array<string,true>                                 $pairesInterdites
     *
     * @return list<array{libelle_a:string,libelle_b:string,forme_a:string,forme_b:string,famille_a:string,famille_b:string}>
     */
    public static function echantillonnerNegatifs(array $identites, array $pairesInterdites, int $nb, int $seed): array
    {
        $formes = array_keys($identites);
        sort($formes, SORT_STRING);

        $candidats = [];
        $n = count($formes);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $fa = $formes[$i];
                $fb = $formes[$j];
                if ($identites[$fa]['famille'] === $identites[$fb]['famille']) {
                    continue;
                }
                if (isset($pairesInterdites[self::paireFormes($fa, $fb)])) {
                    continue;
                }
                $candidats[] = [$fa, $fb];
            }
        }

        $tirage = self::melangeSeede($candidats, $seed);
        $retenus = array_slice($tirage, 0, max(0, $nb));

        $lignes = array_map(static fn (array $p): array => [
            'libelle_a' => $identites[$p[0]]['libelle'],
            'libelle_b' => $identites[$p[1]]['libelle'],
            'forme_a'   => $p[0],
            'forme_b'   => $p[1],
            'famille_a' => $identites[$p[0]]['famille'],
            'famille_b' => $identites[$p[1]]['famille'],
        ], $retenus);
        usort($lignes, static fn (array $a, array $b): int => [$a['libelle_a'], $a['libelle_b']] <=> [$b['libelle_a'], $b['libelle_b']]);

        return $lignes;
    }

    // ── Résiduels (near-miss non fusionnés) ─────────────────────────────────

    /**
     * Paires d'identités vivantes distantes de 1 à $distanceMax édition(s)
     * de Levenshtein sur les formes normalisées (distance calculée octet par
     * octet : les formes normalisées sont quasi ASCII ; comportement
     * déterministe documenté), NON déjà connues (positives ou résiduelles
     * listées) et NON déjà liées entre elles par une chaîne de paires
     * positives (même groupe d'union-find : produit déjà identifié).
     *
     * Variante numérotée exclue — même principe que
     * AliasSuggester::isNearMiss (« chiffres exclus : “ Sucette 1 ” vs
     * “ Sucette 3 ” est une variante numérotée, pas une faute de frappe ») :
     * si la suppression des chiffres rend les formes égales, la paire n'est
     * pas un suspect.
     *
     * Ces paires sont des DOUBLONS SUSPECTS à qualifier par un humain (D2)
     * — jamais des négatifs.
     *
     * @param array<string,array{libelle:string,famille:string}> $identites
     * @param array<string,true>                                 $pairesConnues
     * @param array<string,string>                               $groupes    forme → groupe (union-find des positives)
     *
     * @return list<array{libelle_a:string,libelle_b:string,forme_a:string,forme_b:string,distance:int}>
     */
    public static function nearMiss(array $identites, array $pairesConnues, int $distanceMax = self::NEAR_MISS_DISTANCE_MAX, array $groupes = []): array
    {
        $formes = array_keys($identites);
        sort($formes, SORT_STRING);

        $lignes = [];
        $n = count($formes);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $fa = $formes[$i];
                $fb = $formes[$j];
                if (isset($pairesConnues[self::paireFormes($fa, $fb)])) {
                    continue;
                }
                $d = \levenshtein($fa, $fb);
                if ($d < 1 || $d > $distanceMax) {
                    continue;
                }
                if ($groupes !== [] && self::groupeDe($groupes, $fa) === self::groupeDe($groupes, $fb)) {
                    continue; // déjà reliées par une chaîne de fusions auditées
                }
                $sansChiffresA = (string) preg_replace('/[0-9]/', '', $fa);
                $sansChiffresB = (string) preg_replace('/[0-9]/', '', $fb);
                if ($sansChiffresA === $sansChiffresB) {
                    continue; // variante numérotée (règle projet AliasSuggester)
                }
                $lignes[] = [
                    'libelle_a' => $identites[$fa]['libelle'],
                    'libelle_b' => $identites[$fb]['libelle'],
                    'forme_a'   => $fa,
                    'forme_b'   => $fb,
                    'distance'  => $d,
                ];
            }
        }
        usort($lignes, static fn (array $a, array $b): int => [$a['libelle_a'], $a['libelle_b']] <=> [$b['libelle_a'], $b['libelle_b']]);

        return $lignes;
    }

    // ── Anonymisation (export Kaggle, protocole D3) ─────────────────────────

    /**
     * Masque les prénoms probables dans un libellé (protocole D3).
     *
     * Cas connu : « Décoration halloween | les creation de ju… » — si la
     * partie après « | » contient « creation(s) de … » (insensible à la
     * casse/accents), elle est remplacée par « [PRENOM] ». Aucun autre
     * champ n'est exporté côté Kaggle (libellés seuls, sans prix, dates,
     * quantités, fournisseurs, références, user_id ni IP).
     */
    public static function masquerPrenom(string $libelle): string
    {
        $pos = mb_strpos($libelle, '|');
        if ($pos === false) {
            return $libelle;
        }

        $tete = rtrim(mb_substr($libelle, 0, $pos));
        $queue = trim(mb_substr($libelle, $pos + 1));
        if ($queue === '' || preg_match('/\bcreations? de\b/', AliasSuggester::normalizeKey($queue)) !== 1) {
            return $libelle;
        }

        return $tete . ' | [PRENOM]';
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    /**
     * Mélange Fisher-Yates reproductible : mt_srand($seed) une seule fois,
     * implémentation maison (indépendante des évolutions internes de
     * shuffle(), qui ne garantit pas la stabilité inter-versions PHP).
     *
     * @template T
     *
     * @param list<T> $elements
     *
     * @return list<T>
     */
    public static function melangeSeede(array $elements, int $seed): array
    {
        mt_srand($seed);
        $n = count($elements);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$elements[$i], $elements[$j]] = [$elements[$j], $elements[$i]];
        }
        mt_srand();

        return $elements;
    }
}
