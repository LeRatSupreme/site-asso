<?php

declare(strict_types=1);

namespace App\Core\Assistant;

/**
 * Aides PARTAGÉES de la V1+ « questions préparées » de l'assistant admin :
 * normalisation (MÊME règle que la FAQ publique, via PublicAssistant),
 * détection heuristique d'injections (AFFICHAGE uniquement, jamais
 * bloquante — le pont y résiste déjà), validation du routage JSON
 * [{outil, params}] copié du journal (scalaires OU expressions relatives
 * de dates), résolution de ces expressions au rejeu, marquage
 * dates figées/relatives, catégorie dérivée, filtrage anti-doublon du
 * « Préparer », libellés de sources et formatage des réponses sans LLM.
 *
 * Classe PURE (aucune I/O) : testée unitairement (tests/Unit/AgentQuestionsTest).
 */
final class AgentQuestions
{
    /** Whitelist des outils — miroir de AssistantToolsController::OUTILS. */
    public const OUTILS_VALIDES = ['ventes_periode', 'stock_produit', 'produits_a_commander', 'marge_produit', 'doublons_en_attente'];

    /** Appels d'outils maximum par routage (miroir du pont : MAX_APPELS_OUTILS). */
    public const APPELS_MAX = 4;

    /**
     * Normalisation d'une question : MÊME règle que la FAQ publique
     * (méthode partagée PublicAssistant::normaliserTexte) — minuscules,
     * accents translittérés, ponctuation/espacements → un seul espace.
     */
    public static function normaliser(string $question): string
    {
        return PublicAssistant::normaliserTexte($question);
    }

    /**
     * Heuristique de signalisation d'injection (affichage « ⚠ » dans
     * l'agrégat du journal) : motifs observés dans les payloads réels du
     * journal (rm -rf, substitution de commandes, jailbreak « ignore tes
     * règles », demande de jeton/prompt système, SQL sur les users…).
     * JAMAIS bloquant pour l'affichage : le pont et la page échappent
     * déjà tout. En revanche une question signalée n'est JAMAIS
     * préparable (AgentQuestions::preparable).
     */
    public static function suspecteInjection(string $question): bool
    {
        $bas = mb_strtolower($question, 'UTF-8');
        $sansAccents = strtr($bas, self::ACCENTS);

        foreach (self::MOTIFS_INJECTION as $motif) {
            if (str_contains($bas, $motif) || str_contains($sansAccents, $motif)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Valide un routage JSON : liste de 1..APPELS_MAX appels {outil,
     * params} — outil whitelisté ; chaque paramètre est un scalaire
     * (statique : date absolue, nom…) OU une expression relative
     * {"relatif":"semaine_courante.debut"} recalculée à chaque exécution.
     * Renvoie le routage propre ou null (toute anomalie → repli LLM).
     *
     * @return list<array{outil:string,params:array<string,string|array{relatif:string}>}>|null
     */
    public static function routageValide(mixed $json): ?array
    {
        if (!is_string($json) || trim($json) === '') {
            return null;
        }
        $dec = json_decode($json, true);
        if (!is_array($dec) || $dec === [] || array_is_list($dec) === false || count($dec) > self::APPELS_MAX) {
            return null;
        }

        $valides = array_fill_keys(self::OUTILS_VALIDES, true);
        $propres = [];
        foreach ($dec as $appel) {
            if (!is_array($appel) || !is_string($appel['outil'] ?? null) || !isset($valides[$appel['outil']])) {
                return null;
            }
            $params = $appel['params'] ?? [];
            if (!is_array($params)) {
                return null;
            }
            $propresParams = [];
            foreach ($params as $cle => $valeur) {
                if (!is_string($cle)) {
                    return null;
                }
                if (is_array($valeur)) {
                    if (count($valeur) !== 1 || !is_string($valeur['relatif'] ?? null) || $valeur['relatif'] === '') {
                        return null; // seule forme d'objet admise : {"relatif":"..."}
                    }
                    $propresParams[$cle] = ['relatif' => $valeur['relatif']];
                } elseif (is_string($valeur) || is_numeric($valeur)) {
                    $propresParams[$cle] = (string) $valeur;
                } else {
                    return null; // jamais de structure imbriquée autre
                }
            }
            $propres[] = ['outil' => $appel['outil'], 'params' => $propresParams];
        }

        return $propres;
    }

    /**
     * Résout les params au moment du REJEU (fuseau Europe/Paris) :
     * scalaires conservés tels quels, expressions relatives recalculées.
     * Expression inconnue → null (fail-closed : repli voie LLM).
     *
     * @param array<string,string|array{relatif:string}> $params
     *
     * @return array<string,string>|null
     */
    public static function resoudreParams(array $params, ?\DateTimeImmutable $maintenant = null): ?array
    {
        $tz = new \DateTimeZone('Europe/Paris');
        $now = ($maintenant ?? new \DateTimeImmutable('now'))->setTimezone($tz)->setTime(0, 0);
        $lundiCourant = $now->modify('-' . ((int) $now->format('N') - 1) . ' days');
        $bornes = [
            'semaine_courante'   => [$lundiCourant, $now],
            'semaine_precedente' => [$lundiCourant->modify('-7 days'), $lundiCourant->modify('-1 day')],
        ];

        $resolus = [];
        foreach ($params as $cle => $valeur) {
            if (is_array($valeur)) {
                $resolu = self::resoudreExpression((string) $valeur['relatif'], $cle, $bornes, $now);
                if ($resolu === null) {
                    return null;
                }
                $resolus[$cle] = $resolu;
            } else {
                $resolus[$cle] = (string) $valeur;
            }
        }

        return $resolus;
    }

    /**
     * Une expression relative : jours_glissants:N[.debut|.fin] ou
     * (semaine_courante|semaine_precedente)[.debut|.fin]. Borne absente
     * → inférée de la clé du param (« …fin » → fin, sinon debut).
     *
     * @param array<string,array{\DateTimeImmutable,\DateTimeImmutable}> $bornes
     */
    private static function resoudreExpression(string $expr, string $cleParam, array $bornes, \DateTimeImmutable $now): ?string
    {
        if (preg_match('/^jours_glissants:(\d+)(?:\.(debut|fin))?$/', $expr, $m) === 1) {
            $n = max(1, (int) $m[1]);
            $borne = $m[2] ?? (str_ends_with(mb_strtolower($cleParam, 'UTF-8'), 'fin') ? 'fin' : 'debut');

            return ($borne === 'fin' ? $now : $now->modify('-' . ($n - 1) . ' days'))->format('Y-m-d');
        }

        if (preg_match('/^(semaine_courante|semaine_precedente)(?:\.(debut|fin))?$/', $expr, $m) === 1) {
            $borne = $m[2] ?? (str_ends_with(mb_strtolower($cleParam, 'UTF-8'), 'fin') ? 'fin' : 'debut');
            [$debut, $fin] = $bornes[$m[1]];

            return ($borne === 'fin' ? $fin : $debut)->format('Y-m-d');
        }

        return null; // expression inconnue : jamais de date approximative
    }

    /** Vrai si le routage contient au moins un param à expression relative. */
    public static function routageADatesRelatives(array $routage): bool
    {
        foreach ($routage as $appel) {
            foreach ($appel['params'] as $valeur) {
                if (is_array($valeur)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Vrai si le routage fige au moins une date absolue (AAAA-MM-JJ). */
    public static function routageADatesFigees(array $routage): bool
    {
        foreach ($routage as $appel) {
            foreach ($appel['params'] as $valeur) {
                if (is_string($valeur) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Catégorie dérivée du premier outil du routage. */
    public static function categorieRoutage(array $routage): string
    {
        $map = [
            'stock_produit'        => 'quantite',
            'produits_a_commander' => 'restock',
            'ventes_periode'       => 'ventes',
            'marge_produit'        => 'marge',
            'doublons_en_attente'  => 'autre',
        ];
        foreach ($routage as $appel) {
            return $map[(string) ($appel['outil'] ?? '')] ?? 'autre';
        }

        return 'autre';
    }

    /**
     * Filtrage ANTI-DOUBLON du « Préparer » (steer V1+ n°3) :
     *  - question d'injection : JAMAIS préparable ;
     *  - sans routage (aucun appel HTTP 200 au journal) : non préparable ;
     *  - sinon : ≥ 2 occurrences OU catégorie claire (quantite/restock).
     *
     * @return array{ok:bool,motif:string}
     */
    public static function preparable(int $frequence, string $categorie, bool $injection, bool $routable): array
    {
        if ($injection) {
            return ['ok' => false, 'motif' => 'injection suspectée — jamais préparable'];
        }
        if (!$routable) {
            return ['ok' => false, 'motif' => 'aucun appel d\'outil en HTTP 200 au journal'];
        }
        if ($frequence < 2 && !in_array($categorie, ['quantite', 'restock'], true)) {
            return ['ok' => false, 'motif' => 'question trop rare (' . $frequence . '×) et catégorie non prioritaire'];
        }

        return ['ok' => true, 'motif' => ''];
    }

    /**
     * Libellé de source lisible « outil(cle=valeur, …) » — même format
     * que le pont (scripts/assistant.py::_source), affiché sous chaque
     * réponse (règle « jamais de chiffre sans source »).
     *
     * @param array<string,string> $params
     */
    public static function sourceLibelle(string $outil, array $params): string
    {
        if ($params === []) {
            return $outil;
        }
        $details = [];
        foreach ($params as $cle => $valeur) {
            $details[] = $cle . '=' . $valeur;
        }

        return $outil . '(' . implode(', ', $details) . ')';
    }

    /**
     * Formate une réponse HUMAINE depuis le JSON d'un outil (champs
     * français stables du contrat AssistantToolsController) — c'est ce
     * qui remplace la rédaction du LLM en fast-path (quand la question
     * préparée n'a pas de gabarit pré-écrit). Défensif : tout champ
     * absent a une valeur de repli, aucun chiffre n'est inventé.
     */
    public static function formaterReponse(string $outil, array $r): string
    {
        return match ($outil) {
            'stock_produit' => self::formaterStock($r),
            'ventes_periode' => self::formaterVentes($r),
            'produits_a_commander' => self::formaterReappro($r),
            'marge_produit' => self::formaterMarge($r),
            'doublons_en_attente' => self::formaterDoublons($r),
            default => 'Résultat de l\'outil ' . $outil . ' récupéré.',
        };
    }

    private static function formaterStock(array $r): string
    {
        $nom = (string) ($r['cle_canonique'] ?? $r['nom_demande'] ?? 'produit');
        if (($r['trouve'] ?? false) !== true) {
            $candidats = self::candidatsLibelles($r);
            $suite = $candidats !== '' ? ' Candidats proches : ' . $candidats . '.' : '';

            return 'Produit « ' . $nom . ' » introuvable dans le livre de stock.' . $suite;
        }
        $stock = $r['stock'] ?? '?';
        $comptage = (string) ($r['dernier_comptage'] ?? '');
        $suite = $comptage !== '' ? ' (dernier comptage : ' . $comptage . ')' : '';

        return 'Stock de ' . $nom . ' : ' . $stock . ' unité(s)' . $suite . '.';
    }

    private static function formaterVentes(array $r): string
    {
        $debut = (string) ($r['debut'] ?? '?');
        $fin = (string) ($r['fin'] ?? '?');
        $total = self::euros($r['total_ventes_eur'] ?? null);
        $lignes = [];
        foreach (is_array($r['top_produits'] ?? null) ? $r['top_produits'] : [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $lignes[] = (string) ($p['produit'] ?? '?') . ' (' . (string) ($p['quantite'] ?? '?')
                . ' unités, ' . self::euros($p['ca_eur'] ?? null) . ')';
        }
        $top = $lignes !== [] ? ' Top : ' . implode(' ; ', array_slice($lignes, 0, 5)) . '.' : '';

        return 'Ventes du ' . $debut . ' au ' . $fin . ' : ' . $total
            . ' — ' . (string) ($r['nb_transactions'] ?? '?') . ' transactions, '
            . (string) ($r['quantite_vendue'] ?? '?') . ' unités vendues.' . $top;
    }

    private static function formaterReappro(array $r): string
    {
        $periode = is_array($r['periode_analyse'] ?? null) ? $r['periode_analyse'] : [];
        $lignes = [];
        foreach (is_array($r['produits'] ?? null) ? $r['produits'] : [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $lignes[] = '• ' . (string) ($p['produit'] ?? '?') . ' : ' . (string) ($p['a_commander'] ?? '?')
                . ' à commander (stock ' . (string) ($p['stock'] ?? '?') . ', pack '
                . (string) ($p['pack'] ?? '?') . ', coût ligne ' . self::euros($p['cout_ligne_eur'] ?? null) . ')';
        }
        $nonListes = (int) ($r['produits_non_listes'] ?? 0);
        $suite = $nonListes > 0 ? "\n(+" . $nonListes . ' autres produits non listés — liste complète sur la page Réappro.)' : '';

        return 'À commander pour couvrir ' . (string) ($r['horizon_jours'] ?? '?') . ' jours (analyse du '
            . (string) ($periode['debut'] ?? '?') . ' au ' . (string) ($periode['fin'] ?? '?') . ', '
            . (string) ($r['nb_alertes'] ?? '?') . ' alertes) :'
            . "\n" . implode("\n", array_slice($lignes, 0, 8)) . $suite;
    }

    private static function formaterMarge(array $r): string
    {
        $nom = (string) ($r['nom_demande'] ?? 'produit');
        $periode = is_array($r['periode'] ?? null) ? $r['periode'] : [];
        $periodeLib = 'du ' . (string) ($periode['debut'] ?? '?') . ' au ' . (string) ($periode['fin'] ?? '?');
        if (($r['trouve'] ?? false) !== true) {
            $candidats = self::candidatsLibelles($r);
            $suite = $candidats !== '' ? ' Candidats proches : ' . $candidats . '.' : '';

            return 'Aucune vente de ' . $nom . ' sur la période ' . $periodeLib . '.' . $suite;
        }

        return 'Marge de ' . $nom . ' ' . $periodeLib . ' : ' . self::euros($r['marge_eur'] ?? null)
            . ' (' . self::pct($r['marge_pct'] ?? null) . ') — CA ' . self::euros($r['ca_eur'] ?? null)
            . ', coût ' . self::euros($r['cout_eur'] ?? null) . ', '
            . (string) ($r['quantite_vendue'] ?? '?') . ' unités vendues.';
    }

    private static function formaterDoublons(array $r): string
    {
        $quarantaine = is_array($r['quarantaine'] ?? null) ? $r['quarantaine'] : [];

        return (string) ($r['total_suggestions'] ?? '?') . ' doublons produits en attente de décision'
            . ' (quarantaine : ' . (string) ($quarantaine['nb_en_attente'] ?? 0) . ' en attente, '
            . (string) ($quarantaine['nb_mures'] ?? 0) . ' mûres).'
            . ' Décision par un humain (écran Inventaire) — l\'assistant ne fusionne jamais.';
    }

    /** @return string libellé « a, b, c » des candidats proches (cap 5). */
    private static function candidatsLibelles(array $r): string
    {
        $noms = [];
        foreach (is_array($r['candidats'] ?? null) ? $r['candidats'] : [] as $c) {
            if (is_array($c) && is_string($c['cle'] ?? null)) {
                $noms[] = $c['cle'];
            }
        }

        return implode(', ', array_slice($noms, 0, 5));
    }

    /** Format monétaire français « 12,50 € » (jamais de chiffre inventé : null → « ? »). */
    private static function euros(mixed $valeur): string
    {
        return is_numeric($valeur) ? number_format((float) $valeur, 2, ',', ' ') . ' €' : '?';
    }

    private static function pct(mixed $valeur): string
    {
        return is_numeric($valeur) ? number_format((float) $valeur, 1, ',', ' ') . ' %' : '?';
    }

    /** @var array<string,string> translittération (même table que PublicAssistant) */
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
    ];

    /** Motifs (déjà en minuscules ; testés aussi sans accents). */
    private const MOTIFS_INJECTION = [
        'rm -rf', '$(', '`',
        'ignore toutes tes règles', 'ignore tes règles', 'ignore les règles', 'ignore toutes les instructions',
        'mode débogage', 'mode développeur', 'mode developpeur', 'sans restriction',
        'assistant_token', 'x-assistant-token', 'jeton d\'authentification', 'jeton d authentification',
        'prompt système', 'prompt systeme', 'ton prompt',
        'mot de passe', 'password',
        'drop table', 'select * from users', 'union select',
    ];
}
