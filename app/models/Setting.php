<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des paramètres du site (clé/valeur), avec cache mémoire simple.
 */
final class Setting extends Model
{
    protected static string $table = 'settings';

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /**
     * Charge tous les settings en mémoire (une seule fois par requête).
     *
     * @return array<string,string>
     */
    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $stmt = static::pdo()->query('SELECT `key`, `value` FROM settings');
            /** @var array<string,string> $map */
            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $map[(string) $row['key']] = (string) $row['value'];
            }
            self::$cache = $map;
        } catch (\Throwable) {
            self::$cache = [];
        }

        return self::$cache;
    }

    /**
     * Récupère la valeur d'un setting (ou la valeur par défaut).
     */
    public static function get(string $key, string $default = ''): string
    {
        $map = self::load();

        return $map[$key] ?? $default;
    }

    /**
     * Récupère un setting booléen (les valeurs 'true'/'1'/'on'/'yes' valent vrai).
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = strtolower(self::get($key, $default ? 'true' : 'false'));

        return in_array($value, ['true', '1', 'on', 'yes'], true);
    }

    /**
     * Invalide le cache (après une modification en admin).
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Liste tous les settings.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        $stmt = static::pdo()->query('SELECT * FROM settings ORDER BY `group`, `key`');

        /** @var list<array<string,mixed>> $result */
        $result = $stmt->fetchAll();

        return $result;
    }
}
