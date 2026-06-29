<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Envoi d'e-mails transactionnels (templates HTML/texte).
 *
 * Sans dépendance externe : utilise un client SMTP natif (socket) si un hôte
 * SMTP est configuré, sinon la fonction `mail()` de PHP.
 *
 * La configuration est lue depuis les settings (modifiables en admin) avec
 * possibilité de surcharge par variables d'environnement (config.env).
 */
final class Mailer
{
    /** @var array<string> Logs des dernières opérations (debug / tests). */
    private static array $log = [];

    /** @var callable|null Hook de remplacement (tests : intercepte l'envoi réel). */
    private static $transport = null;

    /**
     * Définit un transport personnalisé (tests).
     *
     * @param callable(string $to, string $subject, string $body, array $headers):bool $fn
     */
    public static function setTransport(callable $fn): void
    {
        self::$transport = $fn;
    }

    public static function resetTransport(): void
    {
        self::$transport = null;
        self::$log = [];
    }

    /** @return list<string> */
    public static function log(): array
    {
        return self::$log;
    }

    /**
     * Envoie un e-mail à partir d'un template.
     *
     * Le template doit exister en deux versions : HTML et texte.
     *
     * @param string               $template Nom du template (ex. 'welcome').
     * @param string               $to       Adresse du destinataire.
     * @param string               $subject  Sujet de l'e-mail.
     * @param array<string,mixed>  $data     Données injectées dans le template.
     */
    public static function send(string $template, string $to, string $subject, array $data = []): bool
    {
        $html = self::render($template, $data);
        $text = self::renderText($template, $data);

        $from     = env('MAILER_FROM', Setting::get('mailer_from', 'noreply@aeic.fr'));
        $fromName = env('MAILER_FROM_NAME', Setting::get('mailer_from_name', 'AEIC'));

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . self::encodeName($fromName) . ' <' . $from . '>',
            'Reply-To: ' . $from,
        ];

        if (self::$transport !== null) {
            $ok = (self::$transport)($to, $subject, $text, $headers);
            self::$log[] = 'test-transport: ' . $to . ' / ' . $subject;

            return $ok;
        }

        // SMTP natif si configuré, sinon mail().
        $host = env('SMTP_HOST', Setting::get('smtp_host', ''));
        if ($host !== '') {
            $ok = self::sendSmtp(
                $host,
                (int) env('SMTP_PORT', Setting::get('smtp_port', '587')),
                env('SMTP_USER', Setting::get('smtp_user', '')),
                env('SMTP_PASS', Setting::get('smtp_pass', '')),
                $from,
                $to,
                $subject,
                $text,
                $headers
            );
            self::$log[] = 'smtp: ' . $to . ' / ' . $subject;

            return $ok;
        }

        $ok = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, implode("\r\n", $headers));
        self::$log[] = 'mail(): ' . $to . ' / ' . $subject;

        return $ok;
    }

    /**
     * Rend le corps HTML d'un template.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = []): string
    {
        return self::renderTemplate($template, $data, true);
    }

    /**
     * Rend le corps texte d'un template.
     *
     * @param array<string,mixed> $data
     */
    public static function renderText(string $template, array $data = []): string
    {
        $path = AEIC_VIEWS . '/emails/' . $template . '.txt.php';
        if (is_file($path)) {
            return self::renderTemplate($template, $data, false);
        }

        // Fallback : version texte dérivée du HTML.
        return html_entity_decode(strip_tags(self::render($template, $data)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderTemplate(string $template, array $data, bool $html): string
    {
        $ext = $html ? 'html' : 'txt';
        $path = AEIC_VIEWS . '/emails/' . $template . '.' . $ext . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Template e-mail introuvable : %s', $template));
        }

        $data['siteName'] = $data['siteName'] ?? Setting::get('site_name', 'AEIC');
        $data['siteUrl']  = $data['siteUrl'] ?? APP_URL;

        extract($data, EXTR_SKIP);

        ob_start();
        require $path;

        return (string) ob_get_clean();
    }

    private static function encodeName(string $name): string
    {
        return '=?UTF-8?B?' . base64_encode($name) . '?=';
    }

    /**
     * Client SMTP minimaliste (EHLO, STARTTLS optionnel, AUTH LOGIN, DATA).
     *
     * @param list<string> $headers
     */
    private static function sendSmtp(
        string $host,
        int $port,
        string $user,
        string $pass,
        string $from,
        string $to,
        string $subject,
        string $body,
        array $headers
    ): bool {
        $remote = ($port === 465 ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15);
        if ($fp === false) {
            self::$log[] = 'smtp-error: ' . $errstr;

            return false;
        }

        try {
            self::smtpRead($fp, 220);
            self::smtpWrite($fp, 'EHLO aeic');
            self::smtpRead($fp, 250);

            if ($port !== 465) {
                self::smtpWrite($fp, 'STARTTLS');
                self::smtpRead($fp, 220);
                stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                self::smtpWrite($fp, 'EHLO aeic');
                self::smtpRead($fp, 250);
            }

            if ($user !== '') {
                self::smtpWrite($fp, 'AUTH LOGIN');
                self::smtpRead($fp, 334);
                self::smtpWrite($fp, base64_encode($user));
                self::smtpRead($fp, 334);
                self::smtpWrite($fp, base64_encode($pass));
                self::smtpRead($fp, 235);
            }

            self::smtpWrite($fp, 'MAIL FROM:<' . $from . '>');
            self::smtpRead($fp, 250);
            self::smtpWrite($fp, 'RCPT TO:<' . $to . '>');
            self::smtpRead($fp, 250);
            self::smtpWrite($fp, 'DATA');
            self::smtpRead($fp, 354);

            $message = 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
            $message .= 'To: <' . $to . ">\r\n";
            foreach ($headers as $h) {
                $message .= $h . "\r\n";
            }
            $message .= "\r\n" . $body . "\r\n.\r\n";
            self::smtpWrite($fp, $message);
            self::smtpRead($fp, 250);

            self::smtpWrite($fp, 'QUIT');

            return true;
        } catch (\Throwable $e) {
            self::$log[] = 'smtp-error: ' . $e->getMessage();

            return false;
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    private static function smtpRead($fp, int $expected): string
    {
        $response = '';
        while (is_resource($fp) && !feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expected) {
            throw new \RuntimeException('SMTP : ' . trim($response) . ' (attendu ' . $expected . ')');
        }

        return $response;
    }

    /** @param resource $fp */
    private static function smtpWrite($fp, string $data): void
    {
        fwrite($fp, $data . "\r\n");
    }
}
