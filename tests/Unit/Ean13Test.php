<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\Ean13;
use PHPUnit\Framework\TestCase;

/**
 * Tests de l'utilitaire EAN-13 (clé de contrôle, réparation d'un chiffre).
 *
 * Les EAN valides sont ceux des vraies factures METRO (fixtures OCR) :
 * 5449000340085 (Minute Maid), 9002490205997 (Red Bull),
 * 3124488201173 (Orangina)…
 */
final class Ean13Test extends TestCase
{
    public function test_eans_valides_des_factures_reelles(): void
    {
        foreach ([
            '5449000340085', // MINUTE MAID NECT POMME (metro_invoice.txt)
            '9002490205997', // RED BULL BOITE 25CL
            '3124488201173', // ORANGINA SLIM BOITE JAUN
            '3124488201166', // OASIS TROPICAL SLIM (facture_1)
            '5060337505284', // MONSTER ULTRA ZERO
            '9002490290344', // COCA COLA BTE SLIM (facture_1)
            '5000112617955', // COCA SANS SUCRES (facture_1)
            '8000500217078', // NUTELLA B READY
            '3254383004316', // CRISTALI 50CL PET
        ] as $ean) {
            self::assertTrue(Ean13::isValid($ean), "EAN $ean attendu valide.");
        }
    }

    public function test_eans_invalides(): void
    {
        foreach ([
            '5449000340080', // clé de contrôle fausse (5 attendu)
            '544900034008',  // 12 chiffres : EAN tronqué par l'OCR
            '54490003400850',// 14 chiffres
            '544900034X085', // caractère non numérique
            '',              // vide
            'ABCDEFGHIJKLM', // lettres
        ] as $ean) {
            self::assertFalse(Ean13::isValid($ean), "EAN « $ean » attendu invalide.");
        }
    }

    public function test_check_digit(): void
    {
        self::assertSame('5', Ean13::checkDigit('544900034008'));
        self::assertSame('7', Ean13::checkDigit('900249020599'));
        self::assertSame('3', Ean13::checkDigit('312448820117'));
        self::assertNull(Ean13::checkDigit('54490003400'), 'Base trop courte : null.');
        self::assertNull(Ean13::checkDigit('5449000340085'), '13 chiffres : ce n\'est plus une base.');
    }

    public function test_reparation_un_chiffre(): void
    {
        // Un chiffre du corps corrompu (5 → 0 en 1re position) : la
        // réparation retrouve l'EAN d'origine.
        $fixes = Ean13::repairOneDigit('0449000340085');
        self::assertContains('5449000340085', $fixes);
        foreach ($fixes as $fix) {
            self::assertTrue(Ean13::isValid($fix), "Toute réparation proposée doit être valide ($fix).");
        }

        // Clé de contrôle corrompue : la réparation retrouve l'EAN d'origine
        // (la clé réparée est celle, unique, calculée depuis le corps lu).
        // D'autres substitutions du corps peuvent aussi redevenir valides :
        // la LEVÉE d'ambiguïté est l'affaire d'InvoiceEnsemble, qui ne
        // retient une réparation que si elle est confirmée par un EAN
        // valide lu ailleurs sur la même facture — jamais ici.
        $fixesKey = Ean13::repairOneDigit('5449000340089');
        self::assertContains('5449000340085', $fixesKey);
        foreach ($fixesKey as $fix) {
            self::assertTrue(Ean13::isValid($fix), "Toute réparation proposée doit être valide ($fix).");
        }

        // EAN déjà valide : aucune « réparation » (rien à inventer).
        self::assertSame([], Ean13::repairOneDigit('5449000340085'));

        // Entrée mal formée (12 chiffres, lettres) : liste vide.
        self::assertSame([], Ean13::repairOneDigit('544900034008'));
        self::assertSame([], Ean13::repairOneDigit('5449X00340085'));
    }

    public function test_cas_limite_que_des_zeros_est_valide(): void
    {
        // Cas limite mathématique : somme nulle → clé de contrôle 0.
        // (Tout EAN-13 mal formé possède au moins une réparation à un
        // chiffre — les poids 1/3 couvrent tous les résidus — aussi le
        // seul cas « aucune réparation » est un EAN déjà valide ou mal
        // formé, couverts ci-dessus.)
        self::assertTrue(Ean13::isValid('0000000000000'));
    }
}
