<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de la logique de construction SMTP du Mailer, sans aucune
 * ouverture de socket (validation de resolveTransport + buildDataMessage).
 */
final class MailerSmtpTest extends TestCase
{
    public function test_resolveTransport_ssl_implicite_sur_port_465(): void
    {
        $t = Mailer::resolveTransport('ssl', 'smtp.example.fr', 465);

        self::assertSame('ssl://smtp.example.fr:465', $t['remote']);
        self::assertFalse($t['starttls']);
    }

    public function test_resolveTransport_tls_negocie_starttls_sur_587(): void
    {
        $t = Mailer::resolveTransport('tls', 'smtp.example.fr', 587);

        self::assertSame('smtp.example.fr:587', $t['remote']);
        self::assertTrue($t['starttls']);
    }

    public function test_resolveTransport_none_desactive_tls(): void
    {
        $t = Mailer::resolveTransport('none', 'smtp.example.fr', 25);

        self::assertSame('smtp.example.fr:25', $t['remote']);
        self::assertFalse($t['starttls']);
    }

    public function test_resolveTransport_auto_465_implique_ssl_implicite(): void
    {
        $t = Mailer::resolveTransport('', 'smtp.example.fr', 465);

        self::assertStringStartsWith('ssl://', $t['remote']);
        self::assertFalse($t['starttls']);
    }

    public function test_resolveTransport_auto_587_active_starttls(): void
    {
        $t = Mailer::resolveTransport('', 'smtp.example.fr', 587);

        self::assertTrue($t['starttls']);
    }

    public function test_buildDataMessage_format_le_bloc_data_complet(): void
    {
        $message = Mailer::buildDataMessage(
            'noreply@aeic.fr',
            'eleve@exemple.fr',
            'Bienvenue',
            'Bonjour',
            ['From: AEIC <noreply@aeic.fr>', 'Content-Type: text/plain; charset=UTF-8']
        );

        self::assertStringContainsString('Subject: =?UTF-8?B?', $message);
        self::assertStringContainsString('To: <eleve@exemple.fr>', $message);
        self::assertStringContainsString('From: AEIC <noreply@aeic.fr>', $message);
        self::assertStringContainsString('Bonjour', $message);
        self::assertStringEndsWith("\r\n.\r\n", $message);
    }

    public function test_sendRaw_en_mode_testing_capture_sans_envoi(): void
    {
        // APP_TESTING=true est positionné globalement par phpunit.xml : aucun
        // transport n'étant injecté, l'envoi doit être capturé (pas de mail()
        // ni de socket SMTP).
        Mailer::resetTransport();
        $ok = Mailer::sendRaw('captured@exemple.fr', 'Sujet', 'Corps');

        self::assertTrue($ok);
        $captured = Mailer::captured();
        self::assertCount(1, $captured);
        self::assertSame('captured@exemple.fr', $captured[0]['to']);
        self::assertSame('Sujet', $captured[0]['subject']);
    }

    protected function tearDown(): void
    {
        Mailer::resetTransport();
    }
}
