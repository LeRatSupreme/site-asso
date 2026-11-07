<?php

declare(strict_types=1);

namespace App\Core\Captcha;

use App\Core\Logger;

/**
 * CAPTCHA auto-hébergé (image PNG anti-bot), sans dépendance base de données.
 *
 * Les paramètres de réglage (longueur du code, rotation, bruit) sont injectés
 * au constructeur — typiquement le snapshot de App\Models\CaptchaSetting —
 * ce qui permet de tester ce service sans base. Le code généré n'est JAMAIS
 * stocké en clair en session : seul un hash bcrypt y vit, avec une
 * expiration de 10 minutes et un usage unique (consommé même en cas
 * d'échec, cf. verify()).
 */
final class CaptchaService
{
    /** Clé de session portant le challenge courant. */
    public const SESSION_KEY = 'captcha';

    /**
     * Alphabet sans ambiguïtés visuelles (32 caractères) :
     * A-Z sans I ni O, 2-9 sans 0 ni 1 — aucun 0/O/1/l/I.
     */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Champ honeypot du formulaire (rempli = robot). */
    public const HONEYPOT_FIELD = 'entreprise_site';

    /** Valeurs par défaut si la table de réglages est vide / base down. */
    public const DEFAULTS = [
        'code_length'          => 6,
        'rotation_max_degrees' => 13,
        'noise_lines'          => 4,
        'noise_intensity'      => 60, // 0–100 (facteur densité points/texture)
    ];

    /** Bornes STRICTES des paramètres (défense en profondeur : clampé ici ET côté modèle). */
    public const BOUNDS = [
        'code_length'          => ['min' => 4,  'max' => 8],
        'rotation_max_degrees' => ['min' => 0,  'max' => 35],
        'noise_lines'          => ['min' => 0,  'max' => 8],
        'noise_intensity'      => ['min' => 0,  'max' => 100],
    ];

    /** Durée de vie du challenge en secondes (10 minutes). */
    private const TTL_SECONDS = 600;

    /** Dimensions du PNG rendu. */
    private const WIDTH = 220;
    private const HEIGHT = 74;

    /** @var array<string,int> Paramètres effectifs, déjà clampés aux bornes. */
    private array $settings;

    /**
     * @param array<string,int>|null $settings Snapshot de réglages (clés de
     *                                          DEFAULTS) ; null → DEFAULTS.
     *                                          Toute valeur aberrante est
     *                                          clampée aux bornes BOUNDS.
     */
    public function __construct(?array $settings = null)
    {
        $source = $settings ?? self::DEFAULTS;
        $this->settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            $this->settings[$key] = self::clampSetting($key, $source[$key] ?? $default);
        }
    }

    /**
     * Borne une valeur de paramètre dans [min, max] (BOUNDS).
     *
     * @throws \InvalidArgumentException si la clé est inconnue.
     */
    public static function clampSetting(string $key, int|float $value): int
    {
        if (!isset(self::BOUNDS[$key])) {
            throw new \InvalidArgumentException(sprintf('Paramètre captcha inconnu : %s', $key));
        }

        $bounded = (int) round((float) $value);

        return max(self::BOUNDS[$key]['min'], min(self::BOUNDS[$key]['max'], $bounded));
    }

    /**
     * Génère un nouveau code et l'enregistre en session (hashé, écrase le
     * challenge précédent). Retourne le code en clair : usage exclusif =
     * rendu de l'image ; jamais loggé, jamais affiché.
     */
    public function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $length = $this->settings['code_length'];

        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // Seul le hash est stocké : une fuite du contenu de session ne
        // permet pas de retrouver le code hors-ligne (§8.1 de la spec).
        $_SESSION[self::SESSION_KEY] = [
            'hash'    => password_hash(strtoupper($code), PASSWORD_DEFAULT),
            'expires' => time() + self::TTL_SECONDS,
        ];

        if (defined('APP_TESTING') && APP_TESTING) {
            // Uniquement pour les tests fonctionnels, jamais en production —
            // même pattern que Csrf::checkRequest.
            $_SESSION[self::SESSION_KEY]['test_code'] = $code;
        }

        return $code;
    }

    /**
     * Rend le code en PNG truecolor durci mais lisible (~220×74).
     *
     * @return string Binaire PNG (aucun texte périphérique, aucun marquage).
     */
    public function render(string $code): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;

        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            throw new \RuntimeException('Impossible de créer l\'image CAPTCHA (GD).');
        }
        imagealphablending($im, true);
        imagesavealpha($im, false);

        $intensity = $this->settings['noise_intensity'];

        // Fond clair de base, tiré aléatoirement.
        $bg = imagecolorallocate(
            $im,
            random_int(228, 248),
            random_int(228, 248),
            random_int(228, 248)
        );
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);

        // Texture : mosaïque de petits rectangles semi-transparents,
        // densité pilotée par noise_intensity.
        $tiles = (int) round(24 + 200 * $intensity / 100);
        for ($i = 0; $i < $tiles; $i++) {
            $color = imagecolorallocatealpha(
                $im,
                random_int(150, 240),
                random_int(150, 240),
                random_int(150, 240),
                random_int(60, 110)
            );
            if ($color === false) {
                continue;
            }
            imagefilledrectangle(
                $im,
                random_int(0, $w - 1),
                random_int(0, $h - 1),
                random_int(0, $w - 1),
                random_int(0, $h - 1),
                $color
            );
        }

        // Bruit global : lignes/arcs traversants.
        for ($i = 0, $n = $this->settings['noise_lines']; $i < $n; $i++) {
            $lineColor = imagecolorallocatealpha(
                $im,
                random_int(40, 160),
                random_int(40, 160),
                random_int(40, 160),
                random_int(35, 85)
            );
            if ($lineColor === false) {
                continue;
            }
            if (random_int(0, 1) === 0) {
                // Ligne pointillée (style aléatoire de 6 pixels).
                $on = $lineColor;
                $off = imagecolorallocatealpha($im, 0, 0, 0, 127) ?: $lineColor;
                imagesetstyle($im, [$on, $on, $off, $off, $on, $off]);
                imageline(
                    $im,
                    random_int(-10, $w),
                    random_int(-10, $h),
                    random_int(-10, $w),
                    random_int(-10, $h),
                    IMG_COLOR_STYLED
                );
            } else {
                imagearc(
                    $im,
                    random_int(-$w, 2 * $w),
                    random_int(-$h, 2 * $h),
                    random_int((int) round($h * 0.8), (int) round($w * 1.8)),
                    random_int((int) round($h * 0.8), (int) round($w * 1.8)),
                    random_int(0, 180),
                    random_int(181, 360),
                    $lineColor
                );
            }
        }

        // Caractères : teintes foncées contrastées sur fond clair.
        $chars = str_split(strtoupper($code));
        $count = max(1, count($chars));
        $margin = 12;
        $slotWidth = ($w - 2 * $margin) / $count;
        $font = self::resolveFont();
        $rotationMax = $this->settings['rotation_max_degrees'];

        foreach ($chars as $i => $char) {
            $dark = self::darkColor($im);
            $size = random_int(24, 30);
            $angle = $rotationMax > 0 ? random_int(-$rotationMax, $rotationMax) : 0;
            $jitterY = random_int(-4, 4);
            $jitterX = random_int(-2, 2);

            $slotCenterX = $margin + $slotWidth * ($i + 0.5) + $jitterX;
            $centerY = $h / 2 + $jitterY;

            if ($font !== null) {
                $bbox = imagettfbbox($size, $angle, $font, $char);
                if ($bbox === false) {
                    continue;
                }
                $minX = min($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
                $maxX = max($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
                $minY = min($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
                $maxY = max($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
                $x = (int) round($slotCenterX - ($minX + $maxX) / 2);
                $y = (int) round($centerY - ($minY + $maxY) / 2);
                if (imagettftext($im, $size, $angle, $x, $y, $dark, $font, $char) === false) {
                    continue;
                }
            } else {
                // Fallback sans TTF : police intégrée sur une tuile agrandie
                // (la couleur du texte DOIT être allouée dans la tuile).
                $tile = imagecreatetruecolor(40, 40);
                if ($tile === false) {
                    continue;
                }
                $tileBg = imagecolorallocate($tile, 255, 255, 255);
                imagefilledrectangle($tile, 0, 0, 39, 39, $tileBg);
                imagecolortransparent($tile, $tileBg);
                imagestring($tile, 5, 15, 12, $char, self::darkColor($tile));
                $targetH = 34;
                $targetW = 26;
                imagecopyresized(
                    $im,
                    $tile,
                    (int) round($slotCenterX - $targetW / 2),
                    (int) round($centerY - $targetH / 2),
                    0,
                    0,
                    $targetW,
                    $targetH,
                    40,
                    40
                );
                imagedestroy($tile);
            }
        }

        // Points salt-and-pepper (150–400 selon noise_intensity), posés en
        // dernier pour traverser aussi les caractères.
        $points = (int) round(150 + 250 * $intensity / 100);
        for ($i = 0; $i < $points; $i++) {
            $pepper = imagecolorallocatealpha(
                $im,
                random_int(20, 220),
                random_int(20, 220),
                random_int(20, 220),
                random_int(0, 90)
            );
            if ($pepper === false) {
                continue;
            }
            imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $pepper);
        }

        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /**
     * Vérifie la saisie contre le challenge en session (usage unique strict :
     * le secret est SUPPRIMÉ avant comparaison, même en cas d'échec).
     */
    public function verify(string $input): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // Suppression immédiate : un échec ne doit pas permettre de rejouer
        // le même challenge indéfiniment (§8.3 de la spec).
        $challenge = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');

        if (!is_array($challenge) || !isset($challenge['hash'], $challenge['expires'])) {
            Logger::warning('captcha.verify_failed', ['ip' => $ip, 'raison' => 'absent']);

            return false;
        }

        if (time() > (int) $challenge['expires']) {
            Logger::warning('captcha.verify_failed', ['ip' => $ip, 'raison' => 'expire']);

            return false;
        }

        // Insensible à la casse et aux espaces internes (saisie mobile).
        $normalized = preg_replace('/\s+/u', '', strtoupper(trim($input))) ?? '';

        if (!password_verify($normalized, (string) $challenge['hash'])) {
            // JAMAIS le code soumis ni le hash dans les logs (base du futur
            // rate-limit : l'IP est le contexte).
            Logger::warning('captcha.verify_failed', ['ip' => $ip, 'raison' => 'invalide']);

            return false;
        }

        return true;
    }

    /**
     * Le honeypot est-il rempli ? (pur, testable unitairement)
     *
     * @param array<string,mixed> $post
     */
    public static function honeypotFilled(array $post): bool
    {
        return trim((string) ($post[self::HONEYPOT_FIELD] ?? '')) !== '';
    }

    /**
     * Résout la première police TTF disponible (cache statique).
     *
     * @return string|null Chemin de la police, ou null → fallback police
     *                    intégrée de GD (imagestring).
     */
    private static function resolveFont(): ?string
    {
        static $resolved = false;
        static $font = null;

        if ($resolved) {
            return $font;
        }

        $candidates = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            'C:\Windows\Fonts\arialbd.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $font = $candidate;
                break;
            }
        }

        $resolved = true;

        return $font;
    }

    /**
     * Couleur foncée tirée par caractère (contraste sur fond clair,
     * variation de luminosité).
     *
     * @return int<0, max> Identifiant de couleur GD.
     */
    private static function darkColor(\GdImage $im): int
    {
        // Palettes foncées variées, puis variation de luminosité par caractère.
        $palettes = [
            [25, 45, 110],   // bleu nuit
            [110, 30, 30],   // rouge sombre
            [20, 90, 45],    // vert sombre
            [95, 35, 100],   // violet sombre
            [15, 65, 95],    // bleu-vert
            [85, 70, 15],    // ocre sombre
        ];
        [$r, $g, $b] = $palettes[random_int(0, count($palettes) - 1)];
        $var = random_int(-15, 15);
        $color = imagecolorallocate(
            $im,
            max(0, min(255, $r + $var)),
            max(0, min(255, $g + $var)),
            max(0, min(255, $b + $var))
        );

        return $color === false ? 0 : $color;
    }
}
