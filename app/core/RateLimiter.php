<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Limitation de tentatives (rate limiting) anti brute-force.
 *
 * Implémentation simple basée sur des fichiers (un compteur JSON par clé),
 * pour rester sans dépendance externe (ni Redis, ni DB additionnelle).
 * La clé est généralement l'adresse IP du client.
 */
final class RateLimiter
{
    /** Dossier de stockage des compteurs. */
    private string $storage;

    /**
     * @param string|null $storage Dossier des compteurs (défaut : AEIC_ROOT/cache/ratelimit).
     */
    public function __construct(?string $storage = null)
    {
        $this->storage = $storage ?? (AEIC_ROOT . '/cache/ratelimit');
    }

    /**
     * Indique si la clé a dépassé le nombre maximal de tentatives sur la fenêtre.
     *
     * @param int $maxAttempts  Nombre maximal de tentatives autorisées.
     * @param int $windowSeconds Durée de la fenêtre en secondes.
     */
    public function tooManyAttempts(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        return count($this->attempts($key, $windowSeconds)) >= $maxAttempts;
    }

    /**
     * Enregistre une nouvelle tentative pour la clé.
     */
    public function hit(string $key): void
    {
        $file = $this->file($key);
        $attempts = $this->read($file);
        $attempts[] = time();

        $this->write($file, $attempts);
    }

    /**
     * Réinitialise le compteur d'une clé (en cas de succès).
     */
    public function clear(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Renvoie les tentatives encore valides (dans la fenêtre) pour la clé.
     *
     * @return list<int> Horodatages des tentatives récentes.
     */
    public function attempts(string $key, int $windowSeconds): array
    {
        $file = $this->file($key);
        $cutoff = time() - $windowSeconds;

        return array_values(
            array_filter(
                $this->read($file),
                static fn (int $t): bool => $t > $cutoff
            )
        );
    }

    /**
     * Nombre de secondes restant avant qu'une tentative expire (réouverture).
     */
    public function availableIn(string $key, int $windowSeconds): int
    {
        $attempts = $this->attempts($key, $windowSeconds);
        if ($attempts === []) {
            return 0;
        }

        $oldest = min($attempts);

        return max(0, ($oldest + $windowSeconds) - time());
    }

    private function file(string $key): string
    {
        return $this->storage . '/' . $this->safeName($key) . '.json';
    }

    private function safeName(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $key) ?: 'default';
    }

    /**
     * @return list<int>
     */
    private function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        /** @var list<int> $result */
        $result = array_values(array_filter($data, 'is_int'));

        return $result;
    }

    /**
     * @param list<int> $attempts
     */
    private function write(string $file, array $attempts): void
    {
        if (!is_dir($this->storage)) {
            @mkdir($this->storage, 0o775, true);
        }

        @file_put_contents($file, json_encode(array_values($attempts), JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
