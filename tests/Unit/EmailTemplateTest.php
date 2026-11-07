<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Tests du rendu des templates d'e-mails transactionnels.
 *
 * Vérifie que les templates HTML et texte se rendent sans erreur et
 * contiennent les informations clés (pas d'envoi réel : on teste render()).
 */
final class EmailTemplateTest extends TestCase
{
    protected function tearDown(): void
    {
        Mailer::resetTransport();
    }

    public function test_template_welcome_html_contient_le_prenom_et_le_lien(): void
    {
        $html = Mailer::render('welcome', ['prenom' => 'Camille']);

        self::assertStringContainsString('Bienvenue Camille', $html);
        self::assertStringContainsString('/eleve', $html);
        self::assertStringContainsString('<!DOCTYPE html>', $html);
    }

    public function test_template_welcome_texte_est_du_texte_brut(): void
    {
        $text = Mailer::renderText('welcome', ['prenom' => 'Sami']);

        self::assertStringContainsString('Bienvenue Sami', $text);
        self::assertStringNotContainsString('<!DOCTYPE', $text);
        self::assertStringNotContainsString('<p>', $text);
    }

    public function test_template_order_ready_contient_id_et_montant(): void
    {
        $html = Mailer::render('order_ready', [
            'prenom'  => 'Inès',
            'orderId' => 'CMD-42',
            'total'   => '4,50 €',
        ]);

        self::assertStringContainsString('CMD-42', $html);
        self::assertStringContainsString('4,50 €', $html);
        self::assertStringContainsString('prête', $html);
    }

    public function test_template_password_reset_contient_le_lien(): void
    {
        $html = Mailer::render('password_reset', [
            'prenom'    => 'Paul',
            'resetUrl'  => 'https://example.test/reset-password?token=abc123',
            'expiresIn' => 1,
        ]);

        self::assertStringContainsString('Paul', $html);
        self::assertStringContainsString('https://example.test/reset-password?token=abc123', $html);
        self::assertStringContainsString('expir', $html);
    }

    public function test_send_utilise_le_transport_de_test(): void
    {
        $captured = null;
        Mailer::setTransport(function (string $to, string $subject, string $body, array $headers) use (&$captured): bool {
            $captured = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });

        $ok = Mailer::send('welcome', 'etudiant@exemple.fr', 'Bienvenue !', ['prenom' => 'Léa']);

        self::assertTrue($ok);
        self::assertNotNull($captured);
        self::assertSame('etudiant@exemple.fr', $captured['to']);
        self::assertSame('Bienvenue !', $captured['subject']);
        self::assertStringContainsString('Bienvenue Léa', $captured['body']);
    }
}
