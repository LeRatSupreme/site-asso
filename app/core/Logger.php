<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Journalisation structurée simple (fichier), sans dépendance externe.
 *
 * Niveaux au style RFC 5424 (debug, info, warning, error).
 */
final class Logger
{
    private const LEVELS = ['debug' => 7, 'info' => 6, 'warning' => 4, 'error' => 3];

    /** Niveau minimum journalisé (configurable). */
    private static string $minLevel = 'info';

    public static function setLevel(string $level): void
    {
        if (isset(self::LEVELS[$level])) {
            self::$minLevel = $level;
        }
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::LEVELS[$level] > self::LEVELS[self::$minLevel]) {
            return;
        }

        $line = sprintf(
            '[%s] aeic.%s: %s',
            date('Y-m-d H:i:s'),
            $level,
            $context === [] ? $message : $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE)
        );

        $dir = AEIC_ROOT . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        @file_put_contents($dir . '/app.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
