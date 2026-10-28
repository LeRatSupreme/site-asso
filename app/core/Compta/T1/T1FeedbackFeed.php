<?php

declare(strict_types=1);

namespace App\Core\Compta\T1;

/**
 * Récolte des retours humains/auto pour la boucle de ré-entraînement
 * automatique (P2-auto) : lit les décisions journalisées
 * « t1.pair_feedback » (fusion = paire positive, ignorer = paire
 * négative ; origine modele/residuel/auto) POSTÉRIEURES à la date du
 * dernier entraînement (etat.json, T1AutoRetrain).
 *
 * Classe PURE (aucun accès base, aucun fichier) : les lignes d'audit
 * sont INJECTÉES (AuditLog::rowsForAction côté appelant) et l'horloge
 * de référence est passée en paramètre ISO. Dédoublonnage par
 * entity_id (T1Suggestions::pairEntityId, stable et symétrique) : pour
 * une paire décidée plusieurs fois, la décision la plus RÉCENTE gagne
 * (les lignes sont attendues created_at DESC, id DESC).
 *
 * Règle anti-régression projet : l'identité d'une paire passe par
 * T1Suggestions::pairEntityId (normalisation StockPublic) — jamais de
 * clé ad hoc (AGENTS.md).
 */
final class T1FeedbackFeed
{
    /**
     * Filtre, dédoublonne et sépare les paires nouvelles.
     *
     * @param list<array<string,mixed>> $lignes   Lignes audit_logs action = t1.pair_feedback
     *                                             (created_at DESC, id DESC — la plus récente d'abord).
     * @param string|null               $depuisIso Instant du dernier entraînement (ISO à décalage
     *                                             explicite, ex. « 2026-10-28T03:39:53+00:00 »),
     *                                             null = tout l'historique compte.
     *
     * @return array{positives:list<array<string,mixed>>,negatives:list<array<string,mixed>>,total:int}
     *         positives : décision fusion ; negatives : décision ignorer ; total = paires distinctes.
     */
    public static function collecter(array $lignes, ?string $depuisIso): array
    {
        $depuis = $depuisIso === null ? null : self::instantIso($depuisIso);

        $vues = [];
        $positives = [];
        $negatives = [];
        foreach ($lignes as $ligne) {
            if (!is_array($ligne)) {
                continue;
            }
            $entityId = trim((string) ($ligne['entity_id'] ?? ''));
            $decision = (string) ($ligne['decision'] ?? '');
            $origine = (string) ($ligne['origine'] ?? '');
            $a = trim((string) ($ligne['a'] ?? ''));
            $b = trim((string) ($ligne['b'] ?? ''));

            if ($entityId === '' || $a === '' || $b === '' || $a === $b) {
                continue;
            }
            if (!in_array($decision, [T1Suggestions::DECISION_FUSION, T1Suggestions::DECISION_IGNORER], true)) {
                continue;
            }
            if (!in_array($origine, [T1Suggestions::ORIGINE_MODELE, T1Suggestions::ORIGINE_RESIDUEL, T1AutoFusion::ORIGINE_AUTO], true)) {
                continue;
            }

            // Lignes les plus récentes d'abord : la première occurrence
            // d'une paire porte sa décision définitive.
            if (isset($vues[$entityId])) {
                continue;
            }

            // Filtre temporel sur les INSTANTS absolus : created_at est
            // stocké UTC (session MySQL forcée +00:00), $depuisIso porte
            // un décalage explicite — la comparaison est sans ambiguïté.
            $cree = self::instantUtc((string) ($ligne['created_at'] ?? ''));
            if ($cree === null || ($depuis !== null && $cree <= $depuis)) {
                continue;
            }

            $vues[$entityId] = true;
            $paire = [
                'entity_id' => $entityId,
                'a'         => $a,
                'b'         => $b,
                'score'     => is_numeric($ligne['score'] ?? null) ? (float) $ligne['score'] : null,
                'decision'  => $decision,
                'origine'   => $origine,
                'decide_le' => (string) ($ligne['created_at'] ?? ''),
            ];
            if ($decision === T1Suggestions::DECISION_FUSION) {
                $positives[] = $paire;
            } else {
                $negatives[] = $paire;
            }
        }

        return [
            'positives' => $positives,
            'negatives' => $negatives,
            'total'     => count($positives) + count($negatives),
        ];
    }

    /**
     * Instant absolu d'une valeur ISO à décalage EXPLICITE (format
     * T1AutoFusion::maintenantIso / generated_at de candidats_fusion.json).
     * Toute autre forme (heure locale nue ambiguë, chaîne illisible) →
     * null — l'appelant décide du repli (leçon #1 : jamais d'heure
     * locale nue, PHP parserait « nimportequoi » en date plausible).
     */
    public static function instantIso(string $iso): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:?\d{2})$/', $iso) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Instant absolu d'un created_at MySQL (DATETIME « Y-m-d H:i:s »),
     * interprété en UTC (fuseau de session forcé par db()).
     * Illisible → null (ligne ignorée par l'appelant).
     */
    public static function instantUtc(string $dbDate): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dbDate) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($dbDate, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Bornage SQL d'un ISO « depuis » : forme « Y-m-d H:i:s » UTC pour
     * la clause created_at > ? (le filtre fin reste fait en PHP sur les
     * instants — la borne SQL n'est qu'une optimisation de volume).
     * ISO illisible → null (aucune borne : tout l'historique).
     */
    public static function borneSqlUtc(?string $depuisIso): ?string
    {
        if ($depuisIso === null) {
            return null;
        }
        $instant = self::instantIso($depuisIso);
        if ($instant === null) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $instant);
    }
}
