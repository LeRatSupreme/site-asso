<?php

declare(strict_types=1);

namespace App\Core\Compta\T1;

use Throwable;

/**
 * Quarantaine des fusions automatiques de niveau 2 (T1.5) : fichier JSON
 * unique data/eval_t1/t15/quarantaine.json, protégé par flock — AUCUNE
 * nouvelle table (décision de cadrage : privilégier le fichier verrouillé,
 * la traçabilité lourde vit déjà dans audit_logs).
 *
 * Une entrée par paire (id = T1Suggestions::pairEntityId, stable et
 * symétrique — même identité que la boucle P3) :
 *
 *   { id, source, target, score, motif, niveau: 2,
 *     created_at: ISO Paris, statut: en_attente|annule|applique|expire,
 *     applied_at: ISO Paris|null, annule_par: user id|null,
 *     expire_raison: string|null, audit_id: id du inventory.merge|null }
 *
 * Statuts :
 *  - en_attente : quarantaine en cours (compte à rebours 48 h, veto
 *    possible) ;
 *  - annule     : veto humain — jamais appliquée, reste visible à
 *    l'écran (transparence) et nourrit t1.pair_feedback (paire négative) ;
 *  - applique   : fusionnée à maturité (audit_id pointe l'archive
 *    pré-fusion, base de la restauration) ;
 *  - expire     : clé morte avant maturité (fusion manuelle concurrente,
 *    clé supprimée…) — jamais appliquée.
 *
 * Le verrou vit dans un fichier dédié <chemin>.lock (JAMAIS renommé —
 * verrouiller le fichier de données lui-même casserait le verrou au
 * premier rename : deux inodes = deux verrous indépendants). Toute
 * mutation passe par avecVerrou()/acquireNonBloquant() : LOCK_EX,
 * relecture, mutation de l'état, écriture ATOMIQUE (tmp + rename),
 * libération.
 *
 * Les mutateurs d'état (appliquer*) sont PURES et séparés des
 * enveloppes verrouillées : T1AutoFusion::passage() détient déjà le
 * verrou et doit muter l'état SANS re-verrouiller (flock est associé au
 * descripteur : re-verrouiller depuis le même processus par un second
 * descripteur se bloquerait lui-même).
 */
final class T1Quarantaine
{
    public const STATUT_EN_ATTENTE = 'en_attente';
    public const STATUT_ANNULE = 'annule';
    public const STATUT_APPLIQUE = 'applique';
    public const STATUT_EXPIRE = 'expire';

    public const VERSION = 1;

    /** @var list<string> */
    private const STATUTS = [
        self::STATUT_EN_ATTENTE,
        self::STATUT_ANNULE,
        self::STATUT_APPLIQUE,
        self::STATUT_EXPIRE,
    ];

    public function __construct(
        private readonly string $chemin,
    ) {
    }

    // ── Enveloppes verrouillées (usage autonome) ───────────────────────────

    /**
     * Exécute $fn sous verrou exclusif (attente courte).
     *
     * $fn reçoit l'état complet (référence) et retourne ce que
     * avecVerrou doit rendre ; l'état éventuellement modifié est
     * réécrit ATOMIQUEMENT avant de libérer le verrou.
     *
     * @template T
     * @param callable(array &$data): T $fn
     * @return array{T, bool} résultat + état réécrit ?
     */
    public function avecVerrou(callable $fn): array
    {
        $handle = $this->ouvrir();
        if ($handle === null) {
            /** @var array<string,mixed> $vide */
            $vide = $this->etatVide();

            return [$fn($vide), false];
        }

        try {
            flock($handle, LOCK_EX);
            $data = $this->lireEtat();
            $avant = $data;
            /** @var mixed $resultat */
            $resultat = $fn($data);
            $modifie = $data !== $avant;
            if ($modifie) {
                $this->ecrireEtat($data);
            }

            return [$resultat, $modifie];
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Tente le verrou SANS attendre (LOCK_NB) : deux chargements de page
     * simultanés → le second passe son tour (idempotence, jamais
     * d'attente bloquante sur une page admin). Rend null si le verrou
     * n'est pas obtenable, sinon le résultat de $fn.
     *
     * @template T
     * @param callable(array &$data): T $fn
     * @return T|null
     */
    public function acquireNonBloquant(callable $fn)
    {
        $handle = $this->ouvrir();
        if ($handle === null) {
            /** @var array<string,mixed> $vide */
            $vide = $this->etatVide();

            return $fn($vide);
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return null; // un autre passage tient le verrou : on passe.
            }
            $data = $this->lireEtat();
            $avant = $data;
            /** @var mixed $resultat */
            $resultat = $fn($data);
            if ($data !== $avant) {
                $this->ecrireEtat($data);
            }

            return $resultat;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Déclare des paires niveau 2 (idempotent par id) — enveloppe
     * verrouillée de appliquerDeclaration().
     *
     * @param list<array{source:string,target:string,score:float,motif:string}> $paires
     *
     * @return int nombre d'entrées réellement ajoutées.
     */
    public function declarer(array $paires, string $maintenantIso): int
    {
        [$n] = $this->avecVerrou(static function (array &$data) use ($paires, $maintenantIso): int {
            return self::appliquerDeclaration($data, $paires, $maintenantIso);
        });

        return $n;
    }

    /** Veto humain sur une paire en attente. */
    public function annuler(string $id, ?string $parUtilisateur): bool
    {
        [$ok] = $this->avecVerrou(static function (array &$data) use ($id, $parUtilisateur): bool {
            return self::appliquerAnnulation($data, $id, $parUtilisateur);
        });

        return $ok;
    }

    /** Marque une paire appliquée (fusion réelle effectuée). */
    public function marquerAppliquee(string $id, string $quandIso, ?string $auditId): bool
    {
        [$ok] = $this->avecVerrou(static function (array &$data) use ($id, $quandIso, $auditId): bool {
            return self::appliquerApplication($data, $id, $quandIso, $auditId);
        });

        return $ok;
    }

    /**
     * Marque une paire expirée (clé morte avant maturité — jamais
     * appliquée, motif conservé pour l'écran).
     */
    public function marquerExpiree(string $id, string $raison): bool
    {
        [$ok] = $this->avecVerrou(static function (array &$data) use ($id, $raison): bool {
            return self::appliquerExpiration($data, $id, $raison);
        });

        return $ok;
    }

    // ── Mutateurs d'état PURES (appelés sous verrou par l'appelant) ───────

    /**
     * Ajoute des paires à l'état (idempotent par id) : une paire déjà
     * présente (en_attente, annule, applique) garde SON created_at et
     * son statut — un veto n'est jamais contourné par un rechargement ;
     * seule une entrée « expire » peut être redéclarée (la clé est
     * revenue à la vie, ex. restauration).
     *
     * @param array{version:int,paires:list<array<string,mixed>>} $data
     * @param list<array{source:string,target:string,score:float,motif:string}> $paires
     *
     * @return int nombre d'entrées réellement ajoutées.
     */
    public static function appliquerDeclaration(array &$data, array $paires, string $maintenantIso): int
    {
        $n = 0;
        foreach ($paires as $p) {
            $id = T1Suggestions::pairEntityId($p['source'], $p['target']);
            foreach ($data['paires'] as $i => $existante) {
                if (($existante['id'] ?? '') !== $id) {
                    continue;
                }
                if (($existante['statut'] ?? '') !== self::STATUT_EXPIRE) {
                    continue 2; // déjà connue : son created_at/statut fait foi.
                }
                // Redéclaration d'une paire expirée : remplacement en place.
                $data['paires'][$i] = self::entreeNeuve($id, $p, $maintenantIso);
                $n++;
                continue 2;
            }
            $data['paires'][] = self::entreeNeuve($id, $p, $maintenantIso);
            $n++;
        }

        return $n;
    }

    /** @param array{source:string,target:string,score:float,motif:string} $p */
    private static function entreeNeuve(string $id, array $p, string $maintenantIso): array
    {
        return [
            'id'            => $id,
            'source'        => $p['source'],
            'target'        => $p['target'],
            'score'         => round((float) $p['score'], 4),
            'motif'         => $p['motif'],
            'niveau'        => T1AutoFusion::NIVEAU_2,
            'created_at'    => $maintenantIso,
            'statut'        => self::STATUT_EN_ATTENTE,
            'applied_at'    => null,
            'annule_par'    => null,
            'expire_raison' => null,
            'audit_id'      => null,
        ];
    }

    /**
     * @param array{version:int,paires:list<array<string,mixed>>} $data
     */
    public static function appliquerAnnulation(array &$data, string $id, ?string $parUtilisateur): bool
    {
        foreach ($data['paires'] as $i => $e) {
            if (($e['id'] ?? '') === $id && ($e['statut'] ?? '') === self::STATUT_EN_ATTENTE) {
                $data['paires'][$i]['statut'] = self::STATUT_ANNULE;
                $data['paires'][$i]['annule_par'] = $parUtilisateur;

                return true;
            }
        }

        return false;
    }

    /**
     * @param array{version:int,paires:list<array<string,mixed>>} $data
     */
    public static function appliquerApplication(array &$data, string $id, string $quandIso, ?string $auditId): bool
    {
        foreach ($data['paires'] as $i => $e) {
            if (($e['id'] ?? '') === $id && ($e['statut'] ?? '') === self::STATUT_EN_ATTENTE) {
                $data['paires'][$i]['statut'] = self::STATUT_APPLIQUE;
                $data['paires'][$i]['applied_at'] = $quandIso;
                $data['paires'][$i]['audit_id'] = $auditId;

                return true;
            }
        }

        return false;
    }

    /**
     * @param array{version:int,paires:list<array<string,mixed>>} $data
     */
    public static function appliquerExpiration(array &$data, string $id, string $raison): bool
    {
        foreach ($data['paires'] as $i => $e) {
            if (($e['id'] ?? '') === $id && ($e['statut'] ?? '') === self::STATUT_EN_ATTENTE) {
                $data['paires'][$i]['statut'] = self::STATUT_EXPIRE;
                $data['paires'][$i]['expire_raison'] = $raison;

                return true;
            }
        }

        return false;
    }

    // ── Lectures tolérantes (jamais d'exception) ───────────────────────────

    /**
     * État brut défensif : structure vide si le fichier est
     * absent/corrompu.
     *
     * @return array{version:int,paires:list<array<string,mixed>>}
     */
    public function lire(): array
    {
        try {
            $data = $this->lireEtat();
            $data['paires'] = array_values(array_filter(
                $data['paires'],
                static fn ($e): bool => is_array($e)
                    && is_string($e['id'] ?? null)
                    && is_string($e['source'] ?? null)
                    && is_string($e['target'] ?? null)
                    && in_array($e['statut'] ?? '', self::STATUTS, true)
            ));

            return $data;
        } catch (Throwable) {
            return $this->etatVide();
        }
    }

    /**
     * Toutes les entrées valides (les plus récentes d'abord).
     *
     * @return list<array<string,mixed>>
     */
    public function entrees(): array
    {
        $paires = $this->lire()['paires'];
        usort($paires, static fn (array $x, array $y): int => strcmp((string) $y['created_at'], (string) $x['created_at']));

        return $paires;
    }

    /**
     * Une entrée par id (null si absente).
     *
     * @return array<string,mixed>|null
     */
    public function trouver(string $id): ?array
    {
        foreach ($this->entrees() as $e) {
            if ((string) ($e['id'] ?? '') === $id) {
                return $e;
            }
        }

        return null;
    }

    /**
     * Identifiants de paires quarantainées, par statut — sert de liste
     * noire pour la carte « Suggestions de rapprochement » (une paire
     * en attente/appliquée/annulée ne doit pas réapparaître côté humain).
     *
     * @return array<string,string> id → statut.
     */
    public function idsStatuts(): array
    {
        $out = [];
        foreach ($this->entrees() as $e) {
            $out[(string) $e['id']] = (string) $e['statut'];
        }

        return $out;
    }

    // ── Internes ───────────────────────────────────────────────────────────

    /**
     * Ouvre (crée au besoin) le fichier de VERROU — distinct du fichier
     * de données, qui lui est remplacé par rename(). null si impossible
     * (répertoire absent ET incréable, permissions…).
     *
     * @return resource|null
     */
    private function ouvrir()
    {
        $dir = dirname($this->chemin);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($this->chemin . '.lock', 'c');
        if ($handle === false) {
            return null;
        }

        return $handle;
    }

    /**
     * @return array{version:int,paires:list<array<string,mixed>>}
     */
    private function etatVide(): array
    {
        return ['version' => self::VERSION, 'paires' => []];
    }

    /**
     * Lecture du fichier de données (appelé sous verrou ou en lecture
     * tolérante). Corrompu/absent → état vide.
     *
     * @return array{version:int,paires:list<array<string,mixed>>}
     */
    private function lireEtat(): array
    {
        try {
            $contenu = is_file($this->chemin) && is_readable($this->chemin)
                ? (string) @file_get_contents($this->chemin)
                : '';
        } catch (Throwable) {
            return $this->etatVide();
        }
        if (trim($contenu) === '') {
            return $this->etatVide();
        }
        $data = json_decode($contenu, true, 16);
        if (!is_array($data) || !is_array($data['paires'] ?? null)) {
            return $this->etatVide();
        }
        $data['version'] = self::VERSION;

        return $data;
    }

    /**
     * Écriture ATOMIQUE sous verrou : sérialisation puis tmp + rename()
     * sur le fichier de données — jamais de contenu à moitié écrit pour
     * un lecteur concurrent (le fichier de verrou, lui, n'est JAMAIS
     * renommé : l'identité du verrou est stable).
     *
     * @param array<string,mixed> $data
     */
    private function ecrireEtat(array $data): void
    {
        $json = json_encode(
            ['version' => self::VERSION, 'paires' => array_values($data['paires'] ?? [])],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        if ($json === false) {
            return;
        }
        $tmp = $this->chemin . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @rename($tmp, $this->chemin);
    }
}
