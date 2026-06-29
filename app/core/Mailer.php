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
 *
 * Modes d'envoi (par ordre de priorité) :
 *  1. transport injectable (tests unitaires) ;
 *  2. APP_TESTING=true (tests d'intégration) : capture, rien n'est réellement envoyé ;
 *  3. SMTP natif si `smtp_host` renseigné ;
 *  4. fallback `mail()`.
 */
final class Mailer
{
    /** @var array<string> Logs des dernières opérations (debug / tests). */
    private static array $log = [];

    /** @var callable|null Hook de remplacement (tests : intercepte l'envoi réel). */
    private static $transport = null;

    /** @var list<array{to:string,subject:string}> E-mails capturés en mode APP_TESTING. */
    private static array $captured = [];

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
        self::$captured = [];
    }

    /** @return list<string> */
    public static function log(): array
    {
        return self::$log;
    }

    /**
     * E-mails capturés en mode APP_TESTING (tests d'intégration).
     *
     * @return list<array{to:string,subject:string}>
     */
    public static function captured(): array
    {
        return self::$captured;
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

        return self::dispatchRaw($to, $subject, $text, $html);
    }

    /**
     * Envoie un e-mail « brut » (sans template), utilisé notamment par le
     * bouton « Envoyer un e-mail de test » des paramètres.
     */
    public static function sendRaw(string $to, string $subject, string $body): bool
    {
        return self::dispatchRaw($to, $subject, $body, $body);
    }

    /**
     * Résout la configuration de transport depuis les settings / variables
     * d'environnement. Exposé publiquement pour les tests et le diagnostique.
     *
     * @return array{
     *     host:string, port:int, user:string, pass:string,
     *     encryption:string, from:string, fromName:string
     * }
     */
    public static function config(): array
    {
        return [
            'host'       => env('SMTP_HOST', Setting::get('smtp_host', '')),
            'port'       => (int) env('SMTP_PORT', Setting::get('smtp_port', '587')),
            'user'       => env('SMTP_USER', Setting::get('smtp_user', '')),
            'pass'       => env('SMTP_PASS', Setting::get('smtp_pass', '')),
            'encryption' => strtolower(env('SMTP_ENCRYPTION', Setting::get('smtp_encryption', ''))),
            'from'       => env('MAILER_FROM', Setting::get('mailer_from', 'noreply@aeic.fr')),
            'fromName'   => env('MAILER_FROM_NAME', Setting::get('mailer_from_name', 'AEIC')),
        ];
    }

    /**
     * Indique si un envoi via SMTP natif aura lieu (hôte renseigné et pas en
     * mode test / transport injecté).
     */
    public static function isSmtpConfigured(): bool
    {
        return self::config()['host'] !== '';
    }

    /**
     * Indique si on est en mode test (constante APP_TESTING définie à true
     * par le bootstrap des tests / config.php, ou variable d'env 'true').
     */
    private static function isTesting(): bool
    {
        if (defined('APP_TESTING')) {
            return (bool) APP_TESTING;
        }

        return getenv('APP_TESTING') === 'true';
    }

    /**
     * Cœur d'envoi : applique la priorité des transports.
     */
    private static function dispatchRaw(string $to, string $subject, string $text, string $html): bool
    {
        $cfg = self::config();

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . self::encodeName($cfg['fromName']) . ' <' . $cfg['from'] . '>',
            'Reply-To: ' . $cfg['from'],
        ];

        if (self::$transport !== null) {
            $ok = (self::$transport)($to, $subject, $text, $headers);
            self::$log[] = 'test-transport: ' . $to . ' / ' . $subject;

            return $ok;
        }

        if (self::isTesting()) {
            self::$captured[] = ['to' => $to, 'subject' => $subject];
            self::$log[] = 'captured: ' . $to . ' / ' . $subject;

            return true;
        }

        if ($cfg['host'] !== '') {
            $ok = self::sendSmtp(
                $cfg['host'],
                $cfg['port'],
                $cfg['user'],
                $cfg['pass'],
                $cfg['encryption'],
                $cfg['from'],
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
     * Résout le mode de connexion SMTP à partir du réglage d'encryption et du
     * port : renvoie le remote à ouvrir et indique si STARTTLS doit être
     * négocié après la bannière. Méthode publique pour testabilité (sans réseau).
     *
     * @return array{remote:string, starttls:bool}
     */
    public static function resolveTransport(string $encryption, string $host, int $port): array
    {
        $encryption = strtolower($encryption);
        $implicitSsl = $encryption === 'ssl' || ($encryption === '' && $port === 465);

        if ($implicitSsl) {
            return ['remote' => 'ssl://' . $host . ':' . $port, 'starttls' => false];
        }

        $starttls = $encryption === 'tls' || ($encryption === '' && $port !== 25);

        return ['remote' => $host . ':' . $port, 'starttls' => $starttls];
    }

    /**
     * Construit le bloc DATA d'un message SMTP (en-têtes + corps + fin ".\r\n").
     * Exposé pour les tests (validation du format sans ouverture de socket).
     *
     * @param list<string> $headers
     */
    public static function buildDataMessage(
        string $from,
        string $to,
        string $subject,
        string $body,
        array $headers
    ): string {
        $message = 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
        $message .= 'To: <' . $to . ">\r\n";
        foreach ($headers as $h) {
            $message .= $h . "\r\n";
        }
        $message .= "\r\n" . $body . "\r\n.\r\n";

        return $message;
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
        string $encryption,
        string $from,
        string $to,
        string $subject,
        string $body,
        array $headers
    ): bool {
        $transport = self::resolveTransport($encryption, $host, $port);
        $fp = @stream_socket_client($transport['remote'], $errno, $errstr, 15);
        if ($fp === false) {
            self::$log[] = 'smtp-error: ' . $errstr;

            return false;
        }

        try {
            self::smtpRead($fp, 220);
            self::smtpWrite($fp, 'EHLO aeic');
            self::smtpRead($fp, 250);

            if ($transport['starttls']) {
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

            self::smtpWrite($fp, self::buildDataMessage($from, $to, $subject, $body, $headers));
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
