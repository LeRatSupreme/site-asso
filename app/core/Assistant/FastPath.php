<?php

declare(strict_types=1);

namespace App\Core\Assistant;

use App\Core\Logger;
use App\Models\AssistantQuestionPreparee;

/**
 * FAST-PATH de l'assistant admin (V1+ rapidité) : question normalisée
 * correspondant EXACTEMENT à une question préparée ACTIVE, au routage
 * valide et frais → REJEU des appels d'outils (GET /internal/assistant/
 * …, boucle locale + en-tête X-Assistant-Token, même mécanique que le
 * pont scripts/assistant.py) et réponse formée SANS LLM (~1-3 s).
 *
 * Réponse : GABARIT pré-écrit (gabarit_reponse + mapping_json) rempli
 * avec le résultat LIVE — un placeholder non résolu → null → repli
 * voie LLM (jamais de gabarit troué affiché). Sans gabarit : formateur
 * générique AgentQuestions::formaterReponse.
 *
 * Dates : params à expression relative recalculés À CHAQUE exécution
 * (« cette semaine » reste juste) ; dates absolues figées → TTL court
 * (7 j), routages à dates recalculées → TTL 30 j.
 *
 * Garde-fous : ASSISTANT_TOKEN vide → aucun appel réseau (fail-closed) ;
 * routage revalidé (whitelist, scalaires/relatifs, ≤ 4 appels) ; tout
 * échec → null → voie LLM normale silencieuse ; journal APPEND-ONLY
 * logs/assistant.jsonl avec champ additif mode=fastpath.
 */
final class FastPath
{
    /** Fraîcheur d'un routage à dates recalculées (jours). */
    public const TTL_RELATIF_JOURS = 30;

    /** Fraîcheur d'un routage qui FIGE des dates absolues (jours). */
    public const TTL_FIGE_JOURS = 7;

    /** Budget par appel d'outil (même contrat que le pont : 10 s). */
    private const TIMEOUT_OUTIL_S = 10;

    /** Domaine des endpoints outils si APP_URL est vide (miroir du pont). */
    private const BASE_URL_DEFAUT = 'https://asso.aremond.ovh';

    /**
     * Tente la réponse rapide pour cette question (journalise au succès).
     *
     * @return array{reponse:string,sources:list<string>,appels:int,duree_ms:int,mode:string,marque:string}|null
     */
    public static function tenter(string $question): ?array
    {
        try {
            $jeton = env('ASSISTANT_TOKEN', '');
            if ($jeton === '') {
                return null; // fail-closed : jamais d'appel sans jeton
            }

            $norm = AgentQuestions::normaliser($question);
            if ($norm === '') {
                return null;
            }

            $ligne = AssistantQuestionPreparee::activeParNorm($norm);
            if ($ligne === null) {
                return null;
            }

            $routage = AgentQuestions::routageValide((string) ($ligne['routage_json'] ?? ''));
            if ($routage === null) {
                return null;
            }

            $creeLe = strtotime((string) ($ligne['cree_le'] ?? ''));
            $ttl = self::ttlJours($routage);
            if ($creeLe === false || (time() - $creeLe) > $ttl * 86400) {
                return null; // routage périmé : la voie LLM reprend la main
            }

            $gabarit = trim((string) ($ligne['gabarit_reponse'] ?? ''));
            $mapping = json_decode((string) ($ligne['mapping_json'] ?? ''), true);

            $rendu = self::rendre(
                $routage,
                $jeton,
                $gabarit !== '' ? $gabarit : null,
                is_array($mapping) && $mapping !== [] ? $mapping : null
            );
            if ($rendu === null) {
                return null;
            }

            self::journaliser($question, $rendu['reponse'], $rendu['appels'], $rendu['duree_ms'], $rendu['sources']);

            return [
                'reponse'  => $rendu['reponse'],
                'sources'  => $rendu['sources'],
                'appels'   => count($rendu['appels']),
                'duree_ms' => $rendu['duree_ms'],
                'mode'     => 'fastpath',
                'marque'   => 'routage préparé (rapide)'
                    . (AgentQuestions::routageADatesRelatives($routage) ? ' · dates recalculées' : ''),
            ];
        } catch (\Throwable $e) {
            Logger::info('assistant fastpath: désactivé (' . $e->getMessage() . ')');

            return null;
        }
    }

    /**
     * RENDU partagé fast-path / aperçu : rejoue les appels d'outils puis
     * forme la réponse (gabarit rempli sur données live, sinon formateur
     * générique). Null au moindre échec — l'appelant replie sur la voie
     * LLM (ou signale l'échec d'aperçu). AUCUN journal ni session ici.
     *
     * @param list<array{outil:string,params:array<string,string|array{relatif:string}>}}> $routage
     * @param array<string,string>|null $mapping
     *
     * @return array{reponse:string,sources:list<string>,appels:list<array<string,mixed>>,duree_ms:int}|null
     */
    public static function rendre(array $routage, string $jeton, ?string $gabarit, ?array $mapping): ?array
    {
        $debut = microtime(true);
        $appels = [];
        $resultats = [];
        $sources = [];

        foreach ($routage as $appel) {
            $params = AgentQuestions::resoudreParams($appel['params']);
            if ($params === null) {
                Logger::info('assistant fastpath: expression de date inconnue, repli LLM');

                return null;
            }

            $url = self::baseUrl() . '/internal/assistant/' . rawurlencode($appel['outil']);
            if ($params !== []) {
                $url .= '?' . http_build_query($params);
            }

            ['statut' => $statut, 'corps' => $corps, 'ms' => $ms] = self::appelOutil($url, $jeton);

            $appels[] = [
                'outil'       => $appel['outil'],
                'arguments'   => $params,
                'statut_http' => $statut,
                'ms'          => $ms,
                'resultat'    => $corps,
            ];

            if ($statut !== 200) {
                Logger::info('assistant fastpath: outil ' . $appel['outil'] . ' en échec HTTP ' . $statut . ', repli LLM');

                return null;
            }

            $donnees = json_decode($corps, true);
            if (!is_array($donnees)) {
                Logger::info('assistant fastpath: JSON outil ' . $appel['outil'] . ' invalide, repli LLM');

                return null;
            }

            $resultats[] = ['outil' => $appel['outil'], 'donnees' => $donnees];
            $sources[] = AgentQuestions::sourceLibelle($appel['outil'], $params);
        }

        if ($gabarit !== null && $mapping !== null) {
            $texte = Gabarits::remplir($gabarit, $mapping, $resultats);
            if ($texte === null) {
                Logger::info('assistant fastpath: placeholder non résolu (gabarit troué interdit), repli LLM');

                return null;
            }
        } else {
            $texte = implode("\n\n", array_map(
                static fn (array $r): string => AgentQuestions::formaterReponse($r['outil'], $r['donnees']),
                $resultats
            ));
        }

        return [
            'reponse'  => $texte,
            'sources'  => $sources,
            'appels'   => $appels,
            'duree_ms' => (int) round((microtime(true) - $debut) * 1000),
        ];
    }

    /**
     * URLs d'appel résolues pour un routage (PURE : horloge injectable,
     * aucun réseau) — sert aux tests de validation « exécutée à J puis
     * simulée à J+3 » et à la documentation des fenêtres.
     *
     * @param list<array{outil:string,params:array<string,mixed>}> $routage
     *
     * @return list<string>|null
     */
    public static function urlsOutils(array $routage, ?\DateTimeImmutable $maintenant = null): ?array
    {
        $urls = [];
        foreach ($routage as $appel) {
            $params = AgentQuestions::resoudreParams($appel['params'], $maintenant);
            if ($params === null) {
                return null;
            }
            $url = self::baseUrl() . '/internal/assistant/' . rawurlencode($appel['outil']);
            $urls[] = $params === [] ? $url : $url . '?' . http_build_query($params);
        }

        return $urls;
    }

    /** TTL du routage : 7 j si une date est FIGÉE, 30 j sinon (relatif/pas de date). */
    public static function ttlJours(array $routage): int
    {
        return AgentQuestions::routageADatesFigees($routage) ? self::TTL_FIGE_JOURS : self::TTL_RELATIF_JOURS;
    }

    /**
     * Base des endpoints outils : surcharge d'environnement (tests),
     * sinon APP_URL, sinon le domaine du pont.
     */
    private static function baseUrl(): string
    {
        $surcharge = rtrim(trim((string) getenv('ASSISTANT_OUTILS_URL')), '/');
        if ($surcharge !== '') {
            return $surcharge;
        }
        if (defined('APP_URL') && is_string(APP_URL) && APP_URL !== '') {
            return rtrim(APP_URL, '/');
        }

        return self::BASE_URL_DEFAUT;
    }

    /**
     * UN appel d'outil : GET en boucle locale avec le jeton serveur
     * (curl durci comme PublicAssistant : aucun proxy, pas de
     * redirection), hôte https épinglé sur 127.0.0.1 comme le pont
     * (SNI du vhost + certificat vérifié).
     *
     * @return array{statut:int,corps:string,ms:int}
     */
    private static function appelOutil(string $url, string $jeton): array
    {
        $debut = microtime(true);
        if (!function_exists('curl_init')) {
            return ['statut' => 0, 'corps' => '', 'ms' => 0];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_OUTIL_S,
            CURLOPT_TIMEOUT        => self::TIMEOUT_OUTIL_S,
            CURLOPT_HTTPHEADER     => ['X-Assistant-Token: ' . $jeton, 'Accept: application/json'],
            CURLOPT_PROXY          => '',           // cible locale : aucun proxy d'environnement
            CURLOPT_FOLLOWLOCATION => false,
        ];

        $hote = (string) parse_url($url, PHP_URL_HOST);
        $schema = (string) parse_url($url, PHP_URL_SCHEME);
        if ($schema === 'https'
            && $hote !== '' && $hote !== '127.0.0.1' && $hote !== 'localhost'
            && getenv('ASSISTANT_OUTILS_LOCAL_PIN') !== '0') {
            $options[CURLOPT_RESOLVE] = [$hote . ':443:127.0.0.1'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['statut' => 0, 'corps' => '', 'ms' => 0];
        }
        curl_setopt_array($ch, $options);
        $corps = curl_exec($ch);
        $statut = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'statut' => $statut,
            'corps'  => is_string($corps) ? $corps : '',
            'ms'     => (int) round((microtime(true) - $debut) * 1000),
        ];
    }

    /**
     * Journalise la session fast-path dans logs/assistant.jsonl (APPEND
     * uniquement, même forme d'entrée que le pont + champ additif
     * mode="fastpath"). Best effort : jamais bloquant.
     *
     * @param list<array<string,mixed>> $appels
     * @param list<string> $sources
     */
    private static function journaliser(string $question, string $reponse, array $appels, int $dureeMs, array $sources): void
    {
        try {
            $chemin = trim((string) getenv('AEIC_ASSISTANT_JOURNAL'));
            if ($chemin === '') {
                $chemin = AEIC_ROOT . '/logs/assistant.jsonl';
            }
            $dossier = dirname($chemin);
            if (!is_dir($dossier)) {
                @mkdir($dossier, 0775, true);
            }

            $entree = [
                'horodatage'          => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d\TH:i:sP'),
                'question'            => $question,
                'ok'                  => true,
                'duree_ms'            => $dureeMs,
                'reflexion'           => false,
                'chiffre_sans_source' => false,
                'contexte_messages'   => 0,
                'appels_outils'       => $appels,
                'tours_llm'           => [],
                'reponse'             => $reponse,
                'erreur'              => '',
                'mode'                => 'fastpath', // champ additif (voie LLM / préparée)
                'sources'             => $sources,
            ];

            @file_put_contents($chemin, json_encode($entree, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // journal best-effort : jamais bloquant pour la réponse
        }
    }
}
