<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\InvoiceScan;
use PHPUnit\Framework\TestCase;

/**
 * Tests des messages d'erreur d'upload d'InvoiceScan : le code 1
 * (UPLOAD_ERR_INI_SIZE — photo plus lourde que upload_max_filesize)
 * est la panne historique du scan (« erreur 1 ») et doit porter une
 * explication + une parade, pas un code opaque.
 */
final class InvoiceScanUploadErrorTest extends TestCase
{
    public function test_erreur_1_message_explicite_avec_parade(): void
    {
        $msg = InvoiceScan::uploadErrorMessage(UPLOAD_ERR_INI_SIZE);

        self::assertStringContainsString('taille maximale', $msg);
        self::assertStringContainsString('erreur 1', $msg);
        self::assertStringContainsString('compressez', $msg, 'La parade doit être indiquée.');
    }

    public function test_erreurs_connues_toutes_explicitees(): void
    {
        foreach ([2, 3, 6, 7, 8] as $code) {
            $msg = InvoiceScan::uploadErrorMessage($code);

            self::assertStringNotContainsString('erreur inconnue', $msg);
            self::assertStringContainsString('Échec de l\'envoi', $msg, "Code $code : préfixe cohérent.");
        }
    }

    public function test_erreur_inconnue_code_dans_le_message(): void
    {
        self::assertSame(
            'Échec de l\'envoi du fichier (erreur 42).',
            InvoiceScan::uploadErrorMessage(42)
        );
    }

    public function test_erreur_3_mentionne_reessai(): void
    {
        self::assertStringContainsString('réessayez', InvoiceScan::uploadErrorMessage(UPLOAD_ERR_PARTIAL));
    }
}
