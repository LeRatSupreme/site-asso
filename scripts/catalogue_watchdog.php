<?php

declare(strict_types=1);

/**
 * AEIC — Veille qualité quotidienne du catalogue (CLI ONE-SHOT, cron).
 *
 * « Le LLM vérifie tout le temps, prend des initiatives » : chaque matin,
 * ce script collecte les anomalies du catalogue via le point d'assemblage
 * UNIQUE (CatalogueQc::anomalies()), les fait analyser par le LLM local
 * (llama-server 127.0.0.1:8080 — helpers PublicAssistant::buildMessages /
 * buildPayload / callLlm, instancié avec un journal DÉDIÉ et un timeout
 * de 300 s — contexte cron : personne n'attend derrière, on laisse au
 * llama-server chargé (≈ 2-3 tok/s constatés) le temps de finir)), puis applique le SEUL auto-fix zéro-risque : la purge des
 * catégories vides AUTO-CRÉÉES (ProductCategory::purgeAutoCreatedEmpty,
 * mécanisme mission E audité — re-vérifie trace 'category.ensure', aucune
 * trace 'category.save', 0 produit, 0 alias, et journalise chaque
 * suppression 'category.purge'). RIEN d'autre n'est modifié en v1 : aucune
 * désactivation automatique, aucun changement de prix — ces décisions
 * restent humaines (rapport [SÛR] / [À DÉCIDER]).
 *
 * Idempotence (leçon 6) :
 *  - flock NON BLOQUANT sur cache/catalogue_watchdog.lock : une exécution
 *    déjà en cours → abandon immédiat (exit 0) ;
 *  - la purge est intrinsèquement idempotente (une catégorie purgée
 *    n'existe plus au passage suivant) ;
 *  - le rapport du jour (logs/catalogue_watchdog/YYYY-MM-DD.md) est
 *    RÉÉCRIT à chaque exécution, le journal (journal.jsonl) est
 *    append-only : relancer le même jour ne crée jamais de doublon d'état.
 *
 * Sorties (répertoire logs/catalogue_watchdog/, droits 0666 comme les
 * autres journaux runtime) :
 *  - YYYY-MM-DD.md  : rapport complet du jour — résumé, analyse LLM,
 *                     anomalies détaillées (données brutes) ;
 *  - journal.jsonl  : une ligne par exécution {ts, compte_par_type,
 *                     purgees, latence_llm_ms, statut} ;
 *  - llm_journal.jsonl : journal PublicAssistant dédié (défensif — callLlm
 *                     n'écrit rien, il n'est passé que pour isoler tout
 *                     futur chemin d'écriture du chat public/kiosque).
 *
 * Échec LLM (service arrêté, timeout 300 s, réponse vide) = rapport SANS
 * analyse : les données brutes restent, statut « ok_llm_indisponible ».
 *
 * Usage :
 *   php scripts/catalogue_watchdog.php             # veille complète
 *   php scripts/catalogue_watchdog.php --dry-run   # collecte + affichage, AUCUNE écriture (ni rapport, ni journal, ni purge)
 *   php scripts/catalogue_watchdog.php --no-llm    # sans analyse LLM (rapport données brutes)
 *
 * PRIORITÉ CPU : le llama-server est PARTAGÉ avec l'AIDE utilisateur
 * en journée — la veille doit lui céder la place. D'une part l'appel
 * proc_nice(19) au démarrage abaisse la priorité du processus (et de
 * ses enfants curl) même en lancement manuel ; d'autre part le cron
 * documenté ci-dessous double la garde avec nice/ionice.
 *
 * FENÊTRE NOCTURNE OBLIGATOIRE : le serveur LLM est partagé en journée
 * (assistant public + kiosque + aide utilisateur) — ce script ne doit
 * tourner que la NUIT (créneau 3 h - 6 h Paris), JAMAIS pendant les
 * heures d'ouverture du kiosque ou d'utilisation de l'assistant.
 *
 * Cron recommandé (serveur AEIC en UTC, daemon cron Debian — pas de
 * CRON_TZ) — exécution quotidienne à 5 h 30 Europe/Paris :
 *
 *   heure d'HIVER (Paris = UTC+1) : 5 h 30 Paris = 4 h 30 UTC ->
 *     30 4 * * * cd /home/ubuntu/AEIC && flock -n /tmp/catalogue_watchdog.lock nice -n 19 ionice -c3 php scripts/catalogue_watchdog.php >> /home/ubuntu/AEIC/logs/catalogue_watchdog/cron.log 2>&1
 *   heure d'ÉTÉ (Paris = UTC+2)   : 5 h 30 Paris = 3 h 30 UTC -> passer à « 30 3 »
 *
 * (Ligne unique « 30 4 » : en été l'exécution glisse à 6 h 30 Paris, sans
 * conséquence pour une veille nocturne ; l'important est UNE exécution par
 * jour dans la fenêtre nocturne. nice -n 19 + ionice -c3 : priorité CPU et
 * disque au plancher — l'analyse LLM (jusqu'à 300 s) ne doit jamais
 * concurrencer les réponses de l'assistant aux utilisateurs.)
 *
 * AUCUNE écriture base hors la purge auditée ci-dessus (lecture stricte
 * partout ailleurs).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Assistant\PublicAssistant;
use App\Core\Compta\CatalogueQc;
use App\Core\Compta\Edt\CalendarContext;
use App\Models\ProductCategory;

/** Prompt analyste qualité (mission VEILLE LLM CATALOGUE — texte imposé). */
const PROMPT_ANALYSTE = "Tu es l'analyste qualité du catalogue AEIC. Voici les anomalies en JSON. Produis un rapport court en français : résumé 3 lignes, puis anomalies priorisées (P1/P2/P3) avec pour chacune : produit/catégorie, problème, action recommandée marquée [SÛR] (zéro risque : purge catégorie vide, désactivation fiche jamais vendue jamais achetée en stock) ou [À DÉCIDER] (prix, renommage, fusion — un humain décide).";

/**
 * Timeout TOTAL de l'appel LLM, en secondes. Contexte cron nocturne :
 * personne n'attend — 300 s (5 min) laisse un llama-server chargé le
 * temps de générer le rapport complet (le timeout 120 s initial coupait
 * une génération à ~2-3 tok/s). Le contrat web public/kiosque reste à 60 s.
 */
const TIMEOUT_LLM_S = 300;

/**
 * Longueur maximale de la génération LLM : rapport COURT demandé (450
 * tokens) — plus fiable sous charge qu'une génération longue qui frôle
 * le timeout, et suffisant pour un résumé + les priorités P1/P2/P3.
 */
const MAX_TOKENS_LLM = 450;

// ── Options CLI ──────────────────────────────────────────────────────────────

$opts     = getopt('', ['dry-run', 'no-llm']);
$dryRun   = array_key_exists('dry-run', $opts);
$sansLlm  = array_key_exists('no-llm', $opts);

// Priorité CPU au plancher DÈS le démarrage (llama-server partagé en
// journée) — le cron documenté double la garde avec nice/ionice, ceci
// protège aussi les lancements manuels.
@proc_nice(19);

$paris      = new DateTimeZone('Europe/Paris');
$maintenant = new DateTimeImmutable('now', $paris);
$prefixe    = '[' . $maintenant->format('Y-m-d H:i:s') . '] catalogue_watchdog : ';

$repertoire = AEIC_ROOT . '/logs/catalogue_watchdog';

// ── (a) flock non bloquant : une seule exécution à la fois ───────────────────

$verrou = @fopen(AEIC_ROOT . '/cache/catalogue_watchdog.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) {
    echo $prefixe . 'une exécution est déjà en cours — abandon (idempotence).' . PHP_EOL;
    exit(0);
}

// ── (b) Collecte via le point d'assemblage unique ────────────────────────────

// Sonde de connexion d'abord (pattern reco_report) : une base injoignable
// produirait un rapport VIDE trompeur — on échoue proprement.
try {
    db();
} catch (Throwable $e) {
    echo $prefixe . 'base indisponible — abandon (' . $e->getMessage() . ')' . PHP_EOL;
    exit(1);
}

$anomalies = CatalogueQc::anomalies();
$totaux    = is_array($anomalies['totaux'] ?? null) ? $anomalies['totaux'] : [];
$nbTotal   = array_sum(array_map('intval', $totaux));

// ── (c) Analyse LLM (llama-server local, helpers PublicAssistant) ────────────

$analyse      = null;
$latenceLlmMs = null;
$statut       = $sansLlm ? 'ok_sans_llm' : 'ok';

if (!$sansLlm) {
    // Journal et verrous DÉDIÉS : ce chemin CLI ne touche JAMAIS les
    // compteurs/journaux du chat public/kiosque ; timeout 300 s (le
    // défaut 60 s du contrat web reste inchangé pour ces derniers).
    $llm = new PublicAssistant(
        journalPath: $repertoire . '/llm_journal.jsonl',
        cheminLock: AEIC_ROOT . '/cache/catalogue_watchdog_llm',
        timeoutTotalS: TIMEOUT_LLM_S
    );

    // Un encodage cassé (donnée non sérialisable) ne doit PAS tuer la
    // veille : le rapport texte survit — analyse LLM simplement sautée.
    $json = null;
    try {
        $json = json_encode($anomalies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $analyse = null;
        $statut  = 'ok_llm_indisponible';
    }

    if ($json !== null) {
        $payload = PublicAssistant::buildPayload(
            PublicAssistant::buildMessages(PROMPT_ANALYSTE, [], $json)
        );
        // Overrides du contrat veille sur le payload commun : analyse factuelle
        // (temperature 0) et rapport plus long (450 tokens) que le chat public
        // (200). Aucun « tools » : le modèle ANALYSE, il n'appelle rien.
        $payload['temperature'] = 0.0;
        $payload['max_tokens']  = MAX_TOKENS_LLM;

        $tLlm     = microtime(true);
        $reponse  = $llm->callLlm($payload);
        $latenceLlmMs = (int) round((microtime(true) - $tLlm) * 1000);

        $analyse = PublicAssistant::extractReply($reponse['body']);
        if ($analyse === null) {
            $statut = 'ok_llm_indisponible';
        }
    }
}

// ── (d) Auto-fix ZÉRO-RISQUE UNIQUEMENT : purge des catégories vides ─────────

$purgees = 0;
if (!$dryRun) {
    // ProductCategory::purgeAutoCreatedEmpty (mission E) : ne supprime QUE
    // les catégories auto-créées ('category.ensure') sans trace admin
    // ('category.save'), sans produit ET sans alias — chaque suppression
    // est auditée 'category.purge'. RIEN d'autre n'est écrit en v1.
    $purgees = ProductCategory::purgeAutoCreatedEmpty('system:catalogue_watchdog');
}

// ── (d-bis) Contexte calendrier universitaire (corrélation ventes ↔ EDT) ─────

// La veille qualité lit le contexte calendrier chaque matin (mission E3) :
// semaine morte en cours/prochaine + prochain jour de cours — dérivé des
// données edt-ulco.fr via CalendarContext (point d'assemblage unique,
// aucun nom de vacances en dur). Fail-soft : calendrier indisponible →
// valeurs null, la veille qualité du catalogue reste la référence.
$contexteCalendrier = contexteCalendrier($maintenant);

// ── (e) Rapport + journal + affichage ────────────────────────────────────────

$rapport = rendreRapport($anomalies, $analyse, $latenceLlmMs, $purgees, $sansLlm, $maintenant, $contexteCalendrier);

if ($dryRun) {
    echo $prefixe . '--dry-run : collecte terminée, ' . $nbTotal . ' anomalie(s), AUCUNE écriture (rapport, journal et purge neutralisés).' . PHP_EOL;
    echo $rapport;
    exit(0);
}

if (!is_dir($repertoire) && !@mkdir($repertoire, 0777, true) && !is_dir($repertoire)) {
    echo $prefixe . "impossible de créer $repertoire — abandon (rapport affiché ci-dessous)." . PHP_EOL;
    echo $rapport;
    exit(1);
}
@chmod($repertoire, 0777);

$cheminRapport = $repertoire . '/' . $maintenant->format('Y-m-d') . '.md';
if (file_put_contents($cheminRapport, $rapport) === false) {
    // Le cron doit VOIR l'échec d'écriture — pas de exit 0 silencieux.
    echo $prefixe . "ERREUR : impossible d'écrire le rapport $cheminRapport — abandon." . PHP_EOL;
    exit(1);
}
@chmod($cheminRapport, 0666);

$ligneJournal = json_encode(
    [
        'ts'             => $maintenant->format('Y-m-d\TH:i:sP'),
        'compte_par_type' => $totaux,
        'purgees'        => $purgees,
        'latence_llm_ms' => $latenceLlmMs,
        'statut'         => $statut,
        // Additif E3 : contexte calendrier du jour (semaines mortes +
        // prochain jour de cours) — corrélation ventes ↔ vacances visible
        // chaque matin, null si l'EDT est indisponible.
        'contexte_calendrier' => $contexteCalendrier,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
$cheminJournal = $repertoire . '/journal.jsonl';
if (file_put_contents($cheminJournal, $ligneJournal, FILE_APPEND) === false) {
    // Le cron doit VOIR l'échec d'écriture — pas de exit 0 silencieux.
    echo $prefixe . "ERREUR : impossible d'écrire le journal $cheminJournal — abandon." . PHP_EOL;
    exit(1);
}
@chmod($cheminJournal, 0666);

echo $prefixe . $nbTotal . ' anomalie(s), ' . $purgees . ' catégorie(s) purgée(s), LLM : '
    . ($sansLlm ? 'désactivé (--no-llm)' : ($analyse !== null ? $latenceLlmMs . ' ms' : 'indisponible'))
    . ' — rapport : ' . $cheminRapport . PHP_EOL;

fclose($verrou);
exit(0);

// ── Fonctions locales ────────────────────────────────────────────────────────

/**
 * Contexte calendrier universitaire du jour (mission E3 — corrélation
 * ventes ↔ calendrier dans la veille quotidienne) : ligne factuelle de
 * la semaine EN COURS et de la semaine PROCHAINE via
 * CalendarContext::ligneRecap (formulation dérivée des données EDT,
 * aucun nom de vacances en dur) + prochain jour de cours publié
 * (CalendarContext::pourPeriode). Fail-soft : indisponibilité → null.
 *
 * @return array{en_cours:?string, prochaine:?string, prochain_jour_cours:?string}
 */
function contexteCalendrier(DateTimeImmutable $maintenant): array
{
    $vide = ['en_cours' => null, 'prochaine' => null, 'prochain_jour_cours' => null];
    try {
        $journee = CalendarContext::pourPeriode(
            $maintenant->format('Y-m-d'),
            $maintenant->format('Y-m-d'),
            $maintenant
        );

        return [
            'en_cours'            => CalendarContext::ligneRecap($maintenant),
            'prochaine'           => CalendarContext::ligneRecap($maintenant->modify('monday next week')),
            'prochain_jour_cours' => $journee['journee']['prochain_cours_fr'] ?? null,
        ];
    } catch (Throwable) {
        return $vide;
    }
}

/**
 * Rendu Markdown du rapport complet : résumé, contexte calendrier,
 * analyse LLM éventuelle, puis anomalies détaillées type par type
 * (données brutes compactes).
 *
 * @param array<string,mixed>  $anomalies           Sortie de CatalogueQc::anomalies().
 * @param array{en_cours:?string, prochaine:?string, prochain_jour_cours:?string} $contexteCalendrier
 */
function rendreRapport(
    array $anomalies,
    ?string $analyse,
    ?int $latenceLlmMs,
    int $purgees,
    bool $sansLlm,
    DateTimeImmutable $maintenant,
    array $contexteCalendrier
): string {
    $totaux = is_array($anomalies['totaux'] ?? null) ? $anomalies['totaux'] : [];

    $lignes = [
        '# Veille qualité du catalogue — ' . $maintenant->format('Y-m-d'),
        '',
        'Généré le ' . $maintenant->format('Y-m-d H:i') . ' (Europe/Paris) — `php scripts/catalogue_watchdog.php`.',
        '',
        '## Résumé',
        '',
    ];
    foreach (CatalogueQc::TYPES as $type => $libelle) {
        $lignes[] = '- ' . $libelle . ' : **' . (int) ($totaux[$type] ?? 0) . '**';
    }
    $lignes[] = '- catégories vides purgées (auto-créées, audit `category.purge`) : **' . $purgees . '**';

    // Mission E3 — contexte calendrier universitaire : la corrélation
    // ventes ↔ vacances devient visible chaque matin (une baisse de CA
    // pendant une semaine morte s'explique, ce n'est pas une anomalie).
    $lignes[] = '';
    $lignes[] = '## Contexte calendrier universitaire (EDT ULCO — edt-ulco.fr)';
    $lignes[] = '';
    $lignes[] = '- Semaine en cours : ' . ($contexteCalendrier['en_cours'] ?? 'cours prévus (aucune semaine morte signalée)');
    $lignes[] = '- Semaine prochaine : ' . ($contexteCalendrier['prochaine'] ?? 'cours prévus (aucune semaine morte signalée)');
    $lignes[] = '- Prochain jour de cours : ' . ($contexteCalendrier['prochain_jour_cours'] ?? 'inconnu (données EDT indisponibles ou hors horizon publié)');

    if (!$sansLlm) {
        $lignes[] = '';
        $lignes[] = '## Analyse LLM' . ($latenceLlmMs !== null ? ' (latence ' . $latenceLlmMs . ' ms)' : '');
        $lignes[] = '';
        $lignes[] = $analyse ?? '_Analyse indisponible (llama-server injoignable, timeout ou réponse vide) — les données brutes ci-dessous restent la référence._';
    }

    $lignes[] = '';
    $lignes[] = '## Anomalies détaillées';
    foreach (CatalogueQc::TYPES as $type => $libelle) {
        $items = is_array($anomalies[$type] ?? null) ? $anomalies[$type] : [];
        $total = (int) ($totaux[$type] ?? 0);
        $lignes[] = '';
        $lignes[] = '### ' . $libelle . ' (' . $total . ')';
        if ($items === []) {
            $lignes[] = '_Aucune._';
            continue;
        }
        foreach ($items as $item) {
            $paires = [];
            foreach ($item as $cle => $valeur) {
                $paires[] = $cle . ' : ' . formaterValeur($valeur);
            }
            $lignes[] = '- ' . implode(' · ', $paires);
        }
        if ($total > count($items)) {
            $lignes[] = '- … et ' . ($total - count($items)) . ' autre(s) non listée(s) (limite d\'affichage : ' . CatalogueQc::LIMITE_PAR_TYPE . ').';
        }
    }

    return implode(PHP_EOL, $lignes) . PHP_EOL;
}

/** Format lisible d'une valeur d'anomalie (booléens, null, montants). */
function formaterValeur(mixed $valeur): string
{
    if (is_bool($valeur)) {
        return $valeur ? 'oui' : 'non';
    }
    if ($valeur === null) {
        return '—';
    }
    if (is_array($valeur)) {
        return implode(' / ', array_map(static fn ($v): string => formaterValeur($v), $valeur));
    }
    if (is_float($valeur)) {
        return number_format($valeur, 2, ',', ' ') . ' €';
    }

    return (string) $valeur;
}
