<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Machine à états des commandes cafétéria.
 *
 * Workflow : PENDING → CONFIRMED → PREPARING → READY → DELIVERED,
 * avec CANCELLED accessible depuis les états non terminaux.
 *
 * Pure (sans DB) afin d'être testée unitairement.
 */
final class OrderWorkflow
{
    public const PENDING = 'PENDING';
    public const CONFIRMED = 'CONFIRMED';
    public const PREPARING = 'PREPARING';
    public const READY = 'READY';
    public const DELIVERED = 'DELIVERED';
    public const CANCELLED = 'CANCELLED';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::PENDING   => [self::CONFIRMED, self::CANCELLED],
        self::CONFIRMED => [self::PREPARING, self::CANCELLED],
        self::PREPARING => [self::READY, self::CANCELLED],
        self::READY     => [self::DELIVERED],
        self::DELIVERED => [],
        self::CANCELLED => [],
    ];

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::PENDING,
            self::CONFIRMED,
            self::PREPARING,
            self::READY,
            self::DELIVERED,
            self::CANCELLED,
        ];
    }

    /**
     * Indique si une transition est autorisée.
     */
    public static function canTransition(string $from, string $to): bool
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if (!isset(self::TRANSITIONS[$from])) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * Indique si un statut est terminal (plus d'évolution possible).
     */
    public static function isTerminal(string $status): bool
    {
        $status = strtoupper($status);

        return in_array($status, [self::DELIVERED, self::CANCELLED], true);
    }
}
