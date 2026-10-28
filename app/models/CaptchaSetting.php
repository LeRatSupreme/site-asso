<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Captcha\CaptchaService;

/**
 * Réglages du CAPTCHA (clé/valeur, table dédiée captcha_settings), avec
 * cache mémoire simple à la façon de Setting.
 *
 * Les valeurs lues sont TOUJOURS clampées via CaptchaService::clampSetting
 * (défense en profondeur) : même une valeur aberrante en base ne produit
 * jamais un rendu hors bornes. Défauts en code — une table vide est
 * parfaitement acceptable.
 */
final class CaptchaSetting extends Model
{
    protected static string $table = 'captcha_settings';

    /** @var array<string,int>|null Cache des valeurs lues (null = non chargé). */
    private static ?array $cache = null;

    /**
     * Valeur effective d'un paramètre (lue en base, clampée aux bornes).
     *
     * @throws \InvalidArgumentException si la clé est inconnue.
     */
    public static function get(string $key): int
    {
        if (!array_key_exists($key, CaptchaService::DEFAULTS)) {
            throw new \InvalidArgumentException(sprintf('Paramètre captcha inconnu : %s', $key));
        }

        $map = self::load();
        $raw = $map[$key] ?? CaptchaService::DEFAULTS[$key];

        return CaptchaService::clampSetting($key, $raw);
    }

    /**
     * Instantané des 4 paramètres clampés (une seule requête) — consommé
     * par le contrôleur d'image et le CLI d'échantillons d'audit.
     *
     * @return array<string,int>
     */
    public static function snapshot(): array
    {
        $snapshot = [];
        foreach (array_keys(CaptchaService::DEFAULTS) as $key) {
            $snapshot[$key] = self::get($key);
        }

        return $snapshot;
    }

    /**
     * Écrit un paramètre (idempotent) et invalide le cache.
     *
     * @throws \InvalidArgumentException si la clé est inconnue ou la valeur
     *                                   hors BOUNDS (jamais écrite).
     */
    public static function set(string $key, int $value): void
    {
        if (!isset(CaptchaService::BOUNDS[$key])) {
            throw new \InvalidArgumentException(sprintf('Paramètre captcha inconnu : %s', $key));
        }

        $bounds = CaptchaService::BOUNDS[$key];
        if ($value < $bounds['min'] || $value > $bounds['max']) {
            throw new \InvalidArgumentException(sprintf(
                'Valeur %d hors bornes pour %s (accepté : %d à %d).',
                $value,
                $key,
                $bounds['min'],
                $bounds['max']
            ));
        }

        $stmt = static::pdo()->prepare(
            'INSERT INTO captcha_settings (id, `key`, value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute(['cap_' . substr(sha1($key), 0, 20), $key, (string) $value]);

        self::clearCache();
    }

    /**
     * Charge la table en mémoire (une fois par requête) ; base ou table
     * indisponible → vide (les défaults du code prennent le relais : les
     * pages publiques ne doivent jamais tomber sur une erreur BDD).
     *
     * @return array<string,int>
     */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $map = [];
        try {
            $rows = static::pdo()->query('SELECT `key`, value FROM captcha_settings')->fetchAll();
            foreach ($rows as $row) {
                $key = (string) $row['key'];
                if (array_key_exists($key, CaptchaService::DEFAULTS) && is_numeric($row['value'])) {
                    $map[$key] = (int) $row['value'];
                }
            }
        } catch (\Throwable) {
            $map = [];
        }

        self::$cache = $map;

        return self::$cache;
    }

    /**
     * Invalide le cache (après écriture).
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
