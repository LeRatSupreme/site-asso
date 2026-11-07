<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Codes de récupération pour le 2FA (usage unique).
 *
 * Génère une série de codes aléatoires, stockés **hachés** (jamais en clair
 * après génération). Chaque code ne peut être utilisé qu'une seule fois.
 */
final class RecoveryCodes
{
    /** Nombre de codes générés par défaut. */
    public const DEFAULT_COUNT = 8;

    /** Format des codes : XXXX-XXXX (8 hex, deux groupes de 4). */
    private const HEX_BYTES = 4;

    /**
     * Génère `count` codes en clair (à afficher une seule fois à l'utilisateur).
     *
     * @return list<string>
     */
    public static function generate(int $count = self::DEFAULT_COUNT): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = self::format(self::randomBlock());
        }

        return $codes;
    }

    /**
     * Hache une liste de codes pour le stockage.
     *
     * @param list<string> $codes
     * @return list<string>
     */
    public static function hash(array $codes): array
    {
        return array_map(static fn (string $c): string => hash('sha256', strtolower($c)), $codes);
    }

    /**
     * Vérifie un code contre une liste hachée et le retire s'il correspond.
     *
     * @param list<string> $hashed
     * @return array{0:bool,1:list<string>} [succès, codes restants hachés]
     */
    public static function verifyAndConsume(string $code, array $hashed): array
    {
        $candidate = hash('sha256', strtolower(trim($code)));

        foreach ($hashed as $i => $h) {
            if (hash_equals($h, $candidate)) {
                unset($hashed[$i]);

                return [true, array_values($hashed)];
            }
        }

        return [false, $hashed];
    }

    private static function randomBlock(): string
    {
        return strtoupper(bin2hex(random_bytes(self::HEX_BYTES)));
    }

    private static function format(string $block): string
    {
        return substr($block, 0, 4) . '-' . substr($block, 4, 4);
    }
}
