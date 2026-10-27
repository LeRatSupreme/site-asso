<?php

declare(strict_types=1);

namespace App\Core\Compta\T1;

use App\Core\Compta\AliasSuggester;
use App\Core\Compta\StockPublic;

/**
 * Moteur d'autonomie graduée des fusions (T1.5, niveaux 1/2/3).
 *
 * Décisions utilisateur du 27/10 (T1.5_AUTONOMIE_NOTES) : les variantes
 * de goût/couleur (base ↔ variante : « Lion » / « Lion_Peanut »,
 * « Lipton » / « Lipton Ice Tea ») sont le MÊME produit, et l'initiative
 * automatique est voulue. Le risque d'erreur est couvert par :
 *  - un point de fonctionnement MESURÉ sur le jeu figé (étape 0,
 *    data/eval_t1/t15/analyse_seuil_auto.md) ;
 *  - une quarantaine de 48 h annulable (veto) pour le niveau 2 ;
 *  - un ARCHIVE pré-fusion systématique (T1MergeArchive) rendant toute
 *    fusion restaurable — ce qui autorise plus d'auto (seuil p90 des
 *    positifs au lieu de p95, décision cadrage).
 *
 * Niveaux :
 *  - 1 — auto immédiat : doublons de CASSE PURE (normalizeKey(a) ===
 *    normalizeKey(b), clés vivantes) — fusion sans délai (motif casse) ;
 *  - 2 — auto avec quarantaine 48 h : score ≥ SEUIL_AUTO ET (préfixe
 *    normalisé ≥ MIN_PREFIXE OU suffixe AliasSuggester) ET aucun chiffre
 *    (garde conditionnement : « Sucette 1 » / « Sucette 1 sucette »),
 *    clés vivantes, coûts de revient non divergents (> 15 % → niveau 3,
 *    garde dev-couts « zéro perte ») ;
 *  - 3 — suggestion humaine : tout le reste (écran T1 existant, inchangé).
 *
 * Classe PURE (aucune base, aucun fichier) : les entrées sont injectées
 * (candidats, keyStats vivantes, coûts) et l'horloge est passée en
 * paramètre ISO (Europe/Paris, décalage explicite — leçon #1 : le VPS
 * est en UTC). L'orchestration applicative (verrou flock, quarantaine,
 * fusions réelles, audits) vit dans passage() de cette même classe et
 * dans le contrôleur.
 *
 * Règle anti-régression projet : toute identité de clé passe par
 * StockPublic::normalizeKey()/AliasSuggester (jamais de strtolower ad
 * hoc, AGENTS.md).
 */
final class T1AutoFusion
{
    // ── Calibration (mesurée étape 0, analyse_seuil_auto.md) ─────────────

    /** p90 des 50 positifs sémantiques du jeu figé (modèle P1 actuel). */
    public const SEUIL_AUTO = 0.8936;

    /** Taille minimale de la base d'une paire préfixe (base ↔ variante). */
    public const MIN_PREFIXE = 4;

    /** Garde dev-couts : écart relatif maximal entre coûts unitaires connus. */
    public const ECART_COUTS_MAX = 0.15;

    /** Fenêtre de veto du niveau 2 (heures, heure locale Europe/Paris). */
    public const QUARANTAINE_HEURES = 48;

    /** Plafond de sécurité : fusions automatiques maximales par passage. */
    public const MAX_FUSIONS_PAR_PASSAGE = 20;

    /** Fuseau de TOUT calcul de fenêtre (fenêtre métier de l'association). */
    public const FUSEAU = 'Europe/Paris';

    // ── Niveaux & motifs ──────────────────────────────────────────────────

    public const NIVEAU_1 = 1;
    public const NIVEAU_2 = 2;
    public const NIVEAU_3 = 3;

    public const MOTIF_CASSE = 'casse';
    public const MOTIF_PREFIXE = 'prefixe';
    public const MOTIF_SUFFIXE = 'suffixe';
    public const MOTIF_SCORE = 'score';
    public const MOTIF_COUTS = 'couts_divergents';
    public const MOTIF_CHIFFRE = 'conditionnement';

    public const ORIGINE_AUTO = 'auto';

    /**
     * Doublon de CASSE PURE (niveau 1) : deux clés vivantes distinctes en
     * comparaison brute mais de même forme normalisée (casse, accents,
     * ponctuation, espaces — StockPublic::normalizeKey, le repliement
     * partagé du projet).
     */
    public static function estDoublonCasse(string $a, string $b): bool
    {
        $fa = StockPublic::normalizeKey($a);
        $fb = StockPublic::normalizeKey($b);

        return $fa !== '' && $fa === $fb;
    }

    /**
     * Base ↔ variante (décision utilisateur 27/10) : une forme normalisée
     * d'au moins MIN_PREFIXE caractères est PRÉFIXE de l'autre —
     * « lion »/« lionpeanut », « lipton »/« liptonicetea ». Ce n'est PAS
     * « un préfixe commun » : « monsterbleu » / « monsterverte » (deux
     * variantes sœurs, deux produits distincts) ne matchent pas.
     */
    public static function estVariantePrefixe(string $a, string $b): bool
    {
        $fa = StockPublic::normalizeKey($a);
        $fb = StockPublic::normalizeKey($b);
        if ($fa === $fb || $fa === '' || $fb === '') {
            return false;
        }

        return (mb_strlen($fa) >= self::MIN_PREFIXE && str_starts_with($fb, $fa))
            || (mb_strlen($fb) >= self::MIN_PREFIXE && str_starts_with($fa, $fb));
    }

    /**
     * Même radical après retrait des suffixes couleur/goût
     * (AliasSuggester::suggest) ET MÊME suffixe à flexion près
     * (genre/pluriel : « bleu » ≡ « bleue », « blanc » ≡ « blanches ») :
     * « monster bleu » / « monster bleue » (même produit, orthographe
     * du genre) — mais PAS « monster bleu » / « monster verte » (deux
     * variantes sœurs, deux produits : le dur négatif 0,9273 du jeu
     * figé). Suffixes différents (white/blanc…) → niveau 3, conservateur.
     */
    public static function estVarianteSuffixe(string $a, string $b): bool
    {
        $sa = AliasSuggester::suggest($a);
        $sb = AliasSuggester::suggest($b);
        if ($sa === '' || $sa !== $sb || self::estDoublonCasse($a, $b)) {
            return false;
        }

        return self::suffixesEgaux($a, $b);
    }

    /**
     * Les suffixes couleur/goût retirés par suggest() des deux libellés
     * sont-ils les MÊMES à flexion près ? Comparaison ensembles triés
     * (l'ordre des retraits ne compte pas) avec équivalence de teinte.
     */
    private static function suffixesEgaux(string $a, string $b): bool
    {
        $sufA = self::suffixesRetires($a);
        $sufB = self::suffixesRetires($b);
        if ($sufA === [] || count($sufA) !== count($sufB)) {
            return false;
        }
        sort($sufA, SORT_STRING);
        sort($sufB, SORT_STRING);

        foreach ($sufA as $i => $t) {
            if (!self::suffixeEquivalent($t, $sufB[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Suffixes couleur/goût retirés itérativement en fin de libellé
     * (miroir exact d'AliasSuggester::suggest, sur la forme ESPACÉE
     * normalisée — le repliement sans espaces fusionnerait les tokens).
     *
     * @return list<string>
     */
    private static function suffixesRetires(string $libelle): array
    {
        $tokens = explode(' ', AliasSuggester::normalizeKey($libelle));
        $retires = [];
        while ($tokens !== [] && in_array(end($tokens), AliasSuggester::SUFFIX_STOPWORDS, true)) {
            $retires[] = (string) array_pop($tokens);
        }

        return $retires;
    }

    /**
     * Deux suffixes couleur désignent-ils la MÊME teinte à flexion
     * française près ? Égalité, ou féminin en « e » (vert/verte,
     * bleu/bleue, noir/noire), éventuellement avec doublement de la
     * consonne finale (blanc/blanche) ; le pluriel « s » est replié
     * avant comparaison. Deux teintes distinctes (bleu/vert) ou deux
     * mots différents (white/blanc) : JAMAIS équivalents.
     */
    private static function suffixeEquivalent(string $x, string $y): bool
    {
        $replier = static fn (string $t): string => rtrim($t, 's');
        $x = $replier($x);
        $y = $replier($y);

        return $x === $y
            || $x === $y . 'e' || $y === $x . 'e'
            || $x === $y . 'he' || $y === $x . 'he';
    }

    /**
     * Au moins une des deux formes contient un chiffre : la différence
     * signale une taille/quantité/conditionnement (« Sucette 1 » vs
     * « Sucette 1 sucette », « Bte 33cl ») — jamais d'auto (règle projet
     * « 5 cannettes ≠ pack »).
     */
    public static function contientChiffre(string $a, string $b): bool
    {
        return preg_match('/\d/', $a . $b) === 1;
    }

    /**
     * Garde dev-couts (zéro perte) : deux coûts unitaires CONNUS qui
     * divergent de plus de ECART_COUTS_MAX (écart relatif au plus petit)
     * interdisent l'auto — le doute profite au contrôle humain. Un coût
     * inconnu d'un côté ne bloque pas (rien à opposer).
     *
     * @param float|null $ca Coût unitaire connu de a (null = inconnu).
     * @param float|null $cb Coût unitaire connu de b (null = inconnu).
     */
    public static function coutsDivergents(?float $ca, ?float $cb): bool
    {
        if ($ca === null || $cb === null || $ca <= 0.0 || $cb <= 0.0) {
            return false;
        }

        return abs($ca - $cb) / min($ca, $cb) > self::ECART_COUTS_MAX;
    }

    /**
     * Cible d'une fusion (clé conservée) : la clé des ventes SumUp si un
     * seul côté en a, sinon la première par ordre alphabétique — MÊME
     * heuristique que T1Suggestions (un seul point de règle).
     *
     * @return array{source:string,target:string}
     */
    public static function oriente(string $a, string $b, int $ventesA, int $ventesB): array
    {
        if ($ventesB > 0 && $ventesA === 0) {
            return ['source' => $a, 'target' => $b];
        }
        if ($ventesA > 0 && $ventesB === 0) {
            return ['source' => $b, 'target' => $a];
        }

        return strcmp($a, $b) <= 0
            ? ['source' => $b, 'target' => $a]
            : ['source' => $a, 'target' => $b];
    }

    /**
     * Paires de niveau 1 (casse pure) depuis les clés vivantes : groupes
     * de clés de même forme normalisée (jamais depuis les candidats — les
     * doublons de casse vivent dans la base, pas dans le livrable).
     * Orientation : ventes SumUp, sinon alphabétique. Une fusion étant
     * faite vers le représentant du groupe, les paires d'un même groupe
     * restent valides appliquées séquentiellement.
     *
     * @param array<string,array<string,mixed>> $keyStats Stats par clé vivante (ProductKeyMerge::keyStats()).
     *
     * @return list<array{source:string,target:string,id:string}>
     */
    public static function doublonsCasse(array $keyStats): array
    {
        $parForme = [];
        foreach ($keyStats as $cle => $stat) {
            $cle = trim((string) $cle);
            if ($cle === '') {
                continue;
            }
            $forme = StockPublic::normalizeKey($cle);
            if ($forme === '') {
                continue;
            }
            $parForme[$forme][] = [$cle, is_array($stat) ? (int) ($stat['sales'] ?? 0) : 0];
        }
        ksort($parForme, SORT_STRING);

        $paires = [];
        foreach ($parForme as $membres) {
            if (count($membres) < 2) {
                continue;
            }
            usort($membres, static fn (array $x, array $y): int => strcmp($x[0], $y[0]));
            // Représentant = la clé des ventes si la forme en possède une,
            // sinon la première alphabétique (déterministe).
            $rep = $membres[0];
            foreach ($membres as [$cle, $ventes]) {
                if ($ventes > 0 && $rep[1] === 0) {
                    $rep = [$cle, $ventes];
                }
            }
            foreach ($membres as [$cle]) {
                if ($cle === $rep[0]) {
                    continue;
                }
                $oriente = self::oriente($cle, $rep[0], 0, $rep[1]);
                $paires[] = [
                    'source' => $oriente['source'],
                    'target' => $oriente['target'],
                    'id'     => T1Suggestions::pairEntityId($cle, $rep[0]),
                ];
            }
        }

        return $paires;
    }

    /**
     * Classification des paires candidates (modèle de similarité) :
     * niveau 2 (auto avec quarantaine) ou niveau 3 (humain). Le niveau 1
     * ne vient PAS des candidats (voir doublonsCasse) : une paire de
     * casse pure y est ignorée — elle est traitée par la voie immédiate.
     *
     * Pré-conditions implicites : les deux clés vivantes (résolues par
     * l'appelant via keyStats — une clé morte n'est pas candidate) et
     * formes normalisées DISTINCTES (canMerge), garanties par la
     * résolution en clés vivantes de T1Suggestions.
     *
     * @param list<array{a:string,b:string,score:float}>            $paires   Candidats (T1Suggestions::parseCandidats).
     * @param array<string,array<string,mixed>>                      $keyStats Stats par clé vivante.
     * @param array<string,float|null>                                $couts    Coût unitaire connu par clé (garde dev-couts).
     *
     * @return array{niveau2:list<array{a:string,b:string,score:float,motif:string}>,niveau3:list<array{a:string,b:string,score:float,motif:string}>}
     */
    public static function classifie(array $paires, array $keyStats, array $couts): array
    {
        $niveau2 = [];
        $niveau3 = [];
        foreach ($paires as $p) {
            $a = $p['a'];
            $b = $p['b'];
            $score = (float) $p['score'];

            // Clés vivantes uniquement (l'appelant a déjà résolu, on
            // revérifie : une entrée forgée ne déclenche rien).
            if (!isset($keyStats[$a]) || !isset($keyStats[$b])) {
                continue;
            }
            if (self::estDoublonCasse($a, $b)) {
                continue; // voie niveau 1 (immédiate), pas la quarantaine.
            }
            if ($score < self::SEUIL_AUTO) {
                $niveau3[] = ['a' => $a, 'b' => $b, 'score' => $score, 'motif' => self::MOTIF_SCORE];
                continue;
            }
            $motif = self::estVariantePrefixe($a, $b) ? self::MOTIF_PREFIXE : null;
            $motif = $motif ?? (self::estVarianteSuffixe($a, $b) ? self::MOTIF_SUFFIXE : null);
            if ($motif === null) {
                $niveau3[] = ['a' => $a, 'b' => $b, 'score' => $score, 'motif' => self::MOTIF_SCORE];
                continue;
            }
            if (self::contientChiffre($a, $b)) {
                $niveau3[] = ['a' => $a, 'b' => $b, 'score' => $score, 'motif' => self::MOTIF_CHIFFRE];
                continue;
            }
            if (self::coutsDivergents($couts[$a] ?? null, $couts[$b] ?? null)) {
                $niveau3[] = ['a' => $a, 'b' => $b, 'score' => $score, 'motif' => self::MOTIF_COUTS];
                continue;
            }
            $niveau2[] = ['a' => $a, 'b' => $b, 'score' => $score, 'motif' => $motif];
        }

        return ['niveau2' => $niveau2, 'niveau3' => $niveau3];
    }

    // ── Fenêtre 48 h (Europe/Paris, leçon #1 : VPS en UTC) ────────────────

    /**
     * Instant ISO courant en Europe/Paris (décalage explicite dans la
     * chaîne — jamais une heure locale nue ambiguë à l'heure d'été).
     */
    public static function maintenantIso(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::FUSEAU)))->format('Y-m-d\TH:i:sP');
    }

    /**
     * Instant de maturité d'une quarantaine (created_at + 48 h de durée
     * ÉCOULÉE). Sémantique retenue : 48 heures révolues ENTRE INSTANTS
     * (PT48H, pas P2D mural) — mûrit exactement à 48 h écoulées même à
     * cheval sur un changement d'heure, et le critère de validation
     * « 47 h 59 → rien, 48 h 01 → fusion » tient à toute date. Les deux
     * instants restent horodatés Europe/Paris (leçon #1 : le VPS est en
     * UTC, tout affichage/comparaison passe par des ISO à décalage
     * explicite — jamais une heure locale nue ambiguë).
     *
     * Un created_at qui n'est pas un ISO à décalage explicite (format
     * strict) est INVALIDE : PHP parsait sinon « nimportequoi » en date
     * plausible — retour '' (jamais mûre, sécurité).
     */
    public static function maturiteIso(string $creeIso): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $creeIso) !== 1) {
            return '';
        }
        try {
            $cree = new \DateTimeImmutable($creeIso);
        } catch (\Throwable) {
            return '';
        }

        return $cree
            ->setTimezone(new \DateTimeZone(self::FUSEAU))
            ->add(new \DateInterval('PT' . self::QUARANTAINE_HEURES . 'H'))
            ->format('Y-m-d\TH:i:sP');
    }

    /**
     * Une quarantaine est-elle MÛRE (48 h révolues) ? Comparaison sur
     * les instants absolus des deux heures locales Paris (décalages
     * explicites). created_at illisible → jamais mûre (sécurité).
     *
     * @param string      $creeIso     Création de la quarantaine (ISO Paris).
     * @param string|null $maintenant  Instant de référence (tests), défaut : maintenant.
     */
    public static function estMure(string $creeIso, ?string $maintenant = null): bool
    {
        return self::secondesRestantes($creeIso, $maintenant) <= 0;
    }

    /**
     * Secondes restantes avant maturité (0 si mûre ; négatif jamais
     * renvoyé — l'écran affiche « mûre »). created_at illisible →
     * horizon infini (jamais mûre) — NB : PHP parse la chaîne vide en
     * « maintenant », d'où le court-circuit AVANT tout parsing.
     */
    public static function secondesRestantes(string $creeIso, ?string $maintenant = null): int
    {
        $maturiteIso = self::maturiteIso($creeIso);
        if ($maturiteIso === '') {
            return PHP_INT_MAX;
        }

        try {
            $maturite = new \DateTimeImmutable($maturiteIso);
            $ref = new \DateTimeImmutable($maintenant ?? self::maintenantIso());
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }

        return max(0, (int) ($maturite->getTimestamp() - $ref->getTimestamp()));
    }

    // ── Orchestration (IO : base + quarantaine, verrou flock) ───────────

    /** Chemin par défaut de la quarantaine (aucune table, décision cadrage). */
    public const CHEMIN_DEFAUT_QUARANTAINE = AEIC_ROOT . '/data/eval_t1/t15/quarantaine.json';

    /**
     * Chemin de la quarantaine : surchargeable par la constante
     * AEIC_T15_QUARANTAINE (tests bootstrap → tests/cache/, pour ne
     * JAMAIS écrire la vraie quarantaine depuis un jeu d'essai).
     */
    public static function cheminQuarantaine(): string
    {
        $surcharge = defined('AEIC_T15_QUARANTAINE') ? (string) constant('AEIC_T15_QUARANTAINE') : '';

        return $surcharge !== '' ? $surcharge : self::CHEMIN_DEFAUT_QUARANTAINE;
    }

    /**
     * UN passage d'autonomie (déclenché au chargement de la page
     * Inventaire — décision cadrage : PAS de cron) :
     *
     *  0. verrou flock NON BLOQUANT sur la quarantaine — deux
     *     chargements simultanés : le second passe son tour (idempotence,
     *     jamais d'attente sur une page admin) ;
     *  1. déclaration en quarantaine des paires niveau 2 fraîches
     *     (idempotent par paire, un veto n'est jamais contourné) ;
     *  2. fusions niveau 1 IMMÉDIATES (casse pure), audits complets
     *     (inventory.merge origine=auto + archive pré-fusion, puis
     *     t1.pair_feedback origine=auto) ;
     *  3. application des quarantaines MÛRES non annulées (48 h Paris,
     *     clés toujours vivantes et fusionnables) ; clé morte → expire ;
     *  4. plafond de sécurité : MAX_FUSIONS_PAR_PASSAGE fusions réelles
     *     au total, log dédié au-delà.
     *
     * Ne jette JAMAIS : toute erreur est journalisée et comptée en
     * « echecs » — la page Inventaire reste fonctionnelle.
     *
     * @param array<string,array<string,mixed>> $keyStats           Clés vivantes (ProductKeyMerge::keyStats).
     * @param string|null                       $cheminQuarantaine  Test : chemin dédié (défaut : CHEMIN_QUARANTAINE).
     * @param string|null                       $cheminCandidats    Test : candidats dédiés (défaut : livrable réel).
     * @param string|null                       $maintenant         Horloge injectable ISO Paris (maturité simulée).
     *
     * @return array{saute:bool,niveau1:int,declarees:int,appliquees:int,expirees:int,echecs:int,plafond:bool}
     */
    public static function passage(
        array $keyStats,
        ?string $cheminQuarantaine = null,
        ?string $cheminCandidats = null,
        ?string $maintenant = null
    ): array {
        $quarantaine = new T1Quarantaine($cheminQuarantaine ?? self::cheminQuarantaine());
        $maintenant ??= self::maintenantIso();
        $racine = AEIC_ROOT . '/data/eval_t1/';

        $resultat = $quarantaine->acquireNonBloquant(
            static function (array &$etat) use ($keyStats, $cheminCandidats, $racine, $maintenant, $quarantaine): array {
                $r = ['saute' => false, 'niveau1' => 0, 'declarees' => 0, 'appliquees' => 0, 'expirees' => 0, 'echecs' => 0, 'plafond' => false];

                try {
                    // 1. Candidats niveau 2 : même résolution que l'écran
                    //    (clés vivantes, orientation ventes/alphabet,
                    //    paires déjà décidées exclues) + liste noire de la
                    //    quarantaine (une paire connue n'y retourne pas).
                    $noires = array_merge(
                        \App\Models\AuditLog::entityIdsForAction(T1Suggestions::ACTION_FEEDBACK),
                        array_keys($quarantaine->idsStatuts())
                    );
                    $candidats = T1Suggestions::pourInventaire(
                        $cheminCandidats ?? $racine . 'candidats_fusion.json',
                        $racine . 'residuels_a_qualifier.csv',
                        $keyStats,
                        $noires
                    );

                    $paires = [];
                    foreach ($candidats['modele'] as $p) {
                        $paires[] = ['a' => $p['source'], 'b' => $p['target'], 'score' => $p['score']];
                    }

                    // Coûts unitaires des clés candidates (garde dev-couts).
                    $couts = [];
                    foreach ($paires as $p) {
                        foreach ([$p['a'], $p['b']] as $cle) {
                            if (!array_key_exists($cle, $couts)) {
                                $lot = \App\Models\ProductCost::current($cle);
                                $couts[$cle] = $lot === null ? null : (float) $lot['cost_price'];
                            }
                        }
                    }

                    $classes = self::classifie($paires, $keyStats, $couts);
                    if ($classes['niveau2'] !== []) {
                        $declarables = [];
                        foreach ($classes['niveau2'] as $p) {
                            $declarables[] = [
                                'source' => $p['a'],
                                'target' => $p['b'],
                                'score'  => $p['score'],
                                'motif'  => $p['motif'],
                            ];
                        }
                        // Mutateur PUR : le verrou est déjà détenu ici —
                        // jamais de re-verrouillage (flock est lié au
                        // descripteur, se bloquerait soi-même).
                        $r['declarees'] = T1Quarantaine::appliquerDeclaration($etat, $declarables, $maintenant);
                    }

                    // Budget de fusions réelles du passage.
                    $budget = self::MAX_FUSIONS_PAR_PASSAGE;

                    // 2. Niveau 1 — casse pure, immédiat.
                    foreach (self::doublonsCasse($keyStats) as $paire) {
                        if ($budget <= 0) {
                            $r['plafond'] = true;
                            break;
                        }
                        $res = self::fusionAuto(
                            $paire['source'],
                            $paire['target'],
                            $keyStats,
                            self::NIVEAU_1,
                            self::MOTIF_CASSE,
                            null
                        );
                        if ($res['statut'] === 'ok') {
                            $r['niveau1']++;
                            $budget--;
                        } elseif ($res['statut'] === 'erreur') {
                            $r['echecs']++;
                        }
                    }

                    // 3. Quarantaines mûres (48 h Paris révolues).
                    foreach ($etat['paires'] ?? [] as $e) {
                        if ($budget <= 0) {
                            $r['plafond'] = true;
                            break;
                        }
                        if (($e['statut'] ?? '') !== T1Quarantaine::STATUT_EN_ATTENTE) {
                            continue;
                        }
                        $id = (string) ($e['id'] ?? '');
                        $source = (string) ($e['source'] ?? '');
                        $target = (string) ($e['target'] ?? '');
                        if (!self::estMure((string) ($e['created_at'] ?? ''), $maintenant)) {
                            continue;
                        }

                        // Clés toujours vivantes et fusionnables ?
                        if (!isset($keyStats[$source]) || !isset($keyStats[$target])
                            || !\App\Models\ProductKeyMerge::canMerge($source, $target)) {
                            T1Quarantaine::appliquerExpiration($etat, $id, 'clé disparue ou non fusionnable avant maturité');
                            $r['expirees']++;
                            continue;
                        }

                        // Garde dev-couts RE-vérifiée à l'application (les
                        // coûts bougent en 48 h — revue sécurité T15-B,
                        // constat 1c) : vérifiée à la déclaration (classifie),
                        // elle doit l'être AUSSI au moment de fusionner, sinon
                        // des lots divergents saisis PENDANT la quarantaine
                        // seraient fusionnés malgré la règle « zéro perte ».
                        // Rétrogradation : la paire expire (jamais fusionnée
                        // automatiquement), le doute profite à l'humain.
                        $lotS = \App\Models\ProductCost::current($source);
                        $lotT = \App\Models\ProductCost::current($target);
                        if (self::coutsDivergents(
                            $lotS === null ? null : (float) $lotS['cost_price'],
                            $lotT === null ? null : (float) $lotT['cost_price']
                        )) {
                            T1Quarantaine::appliquerExpiration(
                                $etat,
                                $id,
                                'couts divergents (> 15 %) apparus pendant la quarantaine'
                            );
                            $r['expirees']++;
                            continue;
                        }

                        $res = self::fusionAuto(
                            $source,
                            $target,
                            $keyStats,
                            self::NIVEAU_2,
                            (string) ($e['motif'] ?? ''),
                            (float) ($e['score'] ?? 0)
                        );
                        if ($res['statut'] === 'ok') {
                            T1Quarantaine::appliquerApplication($etat, $id, $maintenant, $res['audit_id']);
                            $r['appliquees']++;
                            $budget--;
                        } elseif ($res['statut'] === 'erreur') {
                            $r['echecs']++;
                        }
                    }
                } catch (\Throwable $e) {
                    \App\Core\Logger::error('t1.auto: passage interrompu', ['msg' => $e->getMessage()]);
                    $r['echecs']++;
                }

                return $r;
            }
        );

        if ($resultat === null) {
            // Verrou occupé : un autre chargement applique déjà — on passe.
            return ['saute' => true, 'niveau1' => 0, 'declarees' => 0, 'appliquees' => 0, 'expirees' => 0, 'echecs' => 0, 'plafond' => false];
        }
        if ($resultat['plafond']) {
            \App\Core\Logger::warning('t1.auto: plafond de fusions automatiques atteint pour ce passage', [
                'max' => self::MAX_FUSIONS_PAR_PASSAGE,
            ]);
        }

        return $resultat;
    }

    /**
     * Fusion automatique réelle (niveau 1 ou quarantaine mûre) :
     * ProductKeyMerge::merge + audits COMPLETS à l'identique des fusions
     * humaines — inventory.merge (avec archive pré-fusion, origine auto,
     * niveau, motif) puis t1.pair_feedback (origine auto, décision fusion).
     *
     * @param array<string,array<string,mixed>> $keyStats Clés vivantes du passage.
     *
     * @return array{statut:'ok'|'invalide'|'erreur',audit_id:?string} audit_id = ligne inventory.merge (base de la restauration).
     */
    private static function fusionAuto(
        string $source,
        string $target,
        array $keyStats,
        int $niveau,
        string $motif,
        ?float $score
    ): array {
        $archive = T1MergeArchive::capturer($source, $target, $keyStats);

        try {
            $moved = \App\Models\ProductKeyMerge::merge($source, $target, null);
        } catch (\InvalidArgumentException) {
            return ['statut' => 'invalide', 'audit_id' => null];
        } catch (\Throwable $e) {
            \App\Core\Logger::error('t1.auto: fusion impossible', [
                'source' => $source,
                'target' => $target,
                'msg'    => $e->getMessage(),
            ]);

            return ['statut' => 'erreur', 'audit_id' => null];
        }

        $auditId = \App\Models\AuditLog::log(
            'inventory.merge',
            null,
            'product_key',
            $target,
            [
                'source'  => $source,
                'target'  => $target,
                'tables'  => $moved,
                'origine' => self::ORIGINE_AUTO,
                'niveau'  => $niveau,
                'motif'   => $motif,
                'archive' => $archive,
            ]
        );
        \App\Models\AuditLog::log(
            T1Suggestions::ACTION_FEEDBACK,
            null,
            T1Suggestions::ENTITY_TYPE,
            T1Suggestions::pairEntityId($source, $target),
            [
                'a'        => $source,
                'b'        => $target,
                'score'    => $score,
                'decision' => T1Suggestions::DECISION_FUSION,
                'origine'  => self::ORIGINE_AUTO,
                'niveau'   => $niveau,
                'motif'    => $motif,
            ]
        );

        return ['statut' => 'ok', 'audit_id' => $auditId];
    }
}