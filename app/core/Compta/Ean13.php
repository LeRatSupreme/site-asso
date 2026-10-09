<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Utilitaire EAN-13 : validation par clé de contrôle et réparation d'un
 * chiffre erroné (OCR).
 *
 * La clé de contrôle est le treizième chiffre : en pondérant alternativement
 * les douze premiers chiffres par 1 puis 3 (de gauche à droite), la somme
 * complétée du contrôle doit être un multiple de 10 :
 * clé = (10 − somme % 10) % 10.
 *
 * Utilisé par InvoiceEnsemble pour noter les lignes produits candidates
 * (EAN dont la clé est valide → candidat fiable) et pour réparer un EAN
 * corrompu par l'OCR : on teste la substitution d'UN chiffre sur chacune
 * des treize positions, en ne gardant que les candidats dont la clé
 * redevient valide. Classe purement statique, sans dépendance.
 */
final class Ean13
{
    /**
     * L'EAN-13 est-il valide ? Accepte uniquement 13 chiffres exactement
     * (les EAN tronqués par l'OCR — 12 chiffres ou moins — sont invalides,
     * jamais devinés).
     */
    public static function isValid(string $ean): bool
    {
        if (preg_match('/^\d{13}$/', $ean) !== 1) {
            return false;
        }

        return self::computedCheckDigit(substr($ean, 0, 12)) === (int) $ean[12];
    }

    /**
     * Chiffre de contrôle attendu pour une base de 12 chiffres, null si la
     * base n'est pas exactement 12 chiffres.
     */
    public static function checkDigit(string $base12): ?string
    {
        if (preg_match('/^\d{12}$/', $base12) !== 1) {
            return null;
        }

        return (string) self::computedCheckDigit($base12);
    }

    /**
     * Réparations d'un EAN-13 invalide par substitution d'UN seul chiffre :
     * renvoie la liste (dédublonnée, ordre de lecture) des EAN de clé valide
     * accessibles en changeant exactement un chiffre des treize positions —
     * clé de contrôle comprise (une OCR qui lit « 9 » au lieu de « 5 » sur
     * le dernier chiffre est aussi fréquente que sur le corps ; la clé
     * réparée est celle, unique, calculée depuis le corps lu). Il y a donc
     * en général PLUSIEURS réparations : la levée d'ambiguïté est l'affaire
     * de l'appelant (InvoiceEnsemble ne retient une réparation que si elle
     * est confirmée par un EAN valide lu ailleurs sur la même facture).
     * Liste vide si l'EAN est déjà valide ou mal formé.
     *
     * @return list<string>
     */
    public static function repairOneDigit(string $ean): array
    {
        if (self::isValid($ean) || preg_match('/^\d{13}$/', $ean) !== 1) {
            return [];
        }

        // NB : pas de clés de tableau par EAN — PHP convertirait la chaîne
        // numérique « 5449000340085 » en clé entière (perte des zéros de
        // tête) ; une simple comparaison stricte suffit.
        $fixes = [];
        for ($pos = 0; $pos < 13; $pos++) {
            $original = (int) $ean[$pos];
            for ($digit = 0; $digit <= 9; $digit++) {
                if ($digit === $original) {
                    continue;
                }
                $candidate = substr($ean, 0, $pos) . (string) $digit . substr($ean, $pos + 1);
                if (self::isValid($candidate) && !in_array($candidate, $fixes, true)) {
                    $fixes[] = $candidate;
                }
            }
        }

        return $fixes;
    }

    /** Clé de contrôle calculée pour une base de 12 chiffres (poids 1/3). */
    private static function computedCheckDigit(string $base12): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $base12[$i]) * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }
}
