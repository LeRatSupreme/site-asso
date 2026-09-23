<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\SmsReport;
use App\Models\Sale;

/**
 * Graphique image du jour / de la semaine pour les SMS : répartition du
 * CA par catégorie en donut (style Analytics). PNG par défaut (rendu GD),
 * SVG conservé pour compatibilité. L'URL est protégée par un jeton secret
 * (setting `sms_chart_token`) : sans le bon jeton, 404.
 */
final class SmsChartController
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    private const FONT_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    public function show(string $token): void
    {
        // Le routeur capture le segment complet : retire l'extension éventuelle.
        $format = 'png';
        if (str_ends_with($token, '.svg')) {
            $format = 'svg';
            $token = substr($token, 0, -4);
        } elseif (str_ends_with($token, '.png')) {
            $token = substr($token, 0, -4);
        }

        header('Cache-Control: no-store');

        if (!hash_equals(SmsReport::chartToken(), $token)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return;
        }

        $today = date('Y-m-d');

        if (($_GET['p'] ?? '') === 'week') {
            $from = date('Y-m-d', strtotime('monday this week'));
            $label = 'Semaine du ' . date('d/m', strtotime($from)) . ' au ' . date('d/m');
        } else {
            $from = $today;
            $label = date('d/m/Y');
        }

        $rows = Sale::byCategoryBetween($from, $today);

        if ($format === 'svg') {
            header('Content-Type: image/svg+xml; charset=utf-8');
            echo self::buildSvg($rows, $label);
            return;
        }

        header('Content-Type: image/png');
        self::renderPng($rows, $label);
    }

    /**
     * Construit le SVG du donut « Répartition par catégorie » dans le
     * style de la page Analytics : anneau, total au centre, légende en
     * dessous, même palette (statique pour être testable).
     *
     * @param list<array<string,mixed>> $rows Lignes de Sale::byCategoryBetween()
     */
    public static function buildSvg(array $rows, string $date): string
    {
        $rows = array_slice($rows, 0, 6);
        $width = 640;
        $height = 600;
        $cx = 320;
        $cy = 240;
        $radius = 165;
        $hole = (int) round($radius * 0.64);   // cutout 64 % comme Analytics

        // Palette identique à Analytics.
        $colors = ['#48bdd3', '#6150aa', '#f59e0b', '#22c55e', '#ef4444', '#6db4ff'];
        $bg = '#0a1b33';

        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) ($r['ca'] ?? 0);
        }

        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
            . '<rect width="' . $width . '" height="' . $height . '" fill="' . $bg . '" rx="24"/>'
            . '<text x="28" y="46" fill="#eaf2fb" font-family="system-ui, Segoe UI, Arial, sans-serif" font-size="24" font-weight="700">'
            . 'Répartition par catégorie</text>'
            . '<text x="' . ($width - 28) . '" y="46" fill="#9fb3c8" font-family="system-ui, Segoe UI, Arial, sans-serif" font-size="18" text-anchor="end">'
            . 'CA · ' . htmlspecialchars($date, ENT_XML1) . '</text>';

        if ($rows === [] || $total <= 0.0) {
            $svg .= '<text x="' . ($width / 2) . '" y="' . ($cy + 8) . '" fill="#9fb3c8" '
                . 'font-family="system-ui, Segoe UI, Arial, sans-serif" font-size="22" text-anchor="middle">Aucune vente aujourd\'hui</text>'
                . '</svg>';
            return $svg;
        }

        // Donut : parts successives depuis le sommet (-90°), sens horaire.
        $slices = '';
        $angleStart = -90.0;

        foreach ($rows as $i => $r) {
            $ca = (float) ($r['ca'] ?? 0);
            $angle = ($total > 0 ? $ca / $total : 0.0) * 360.0;
            $angleEnd = $angleStart + $angle;
            $color = $colors[$i % count($colors)];

            if ($angle >= 359.995) {
                // Part unique : un arc ne peut pas faire 360°, anneau plein.
                $slices .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $radius . '" fill="none"'
                    . ' stroke="' . $color . '" stroke-width="' . ($radius - $hole) . '"/>';
            } else {
                $x1 = round($cx + $radius * cos(deg2rad($angleStart)), 2);
                $y1 = round($cy + $radius * sin(deg2rad($angleStart)), 2);
                $x2 = round($cx + $radius * cos(deg2rad($angleEnd)), 2);
                $y2 = round($cy + $radius * sin(deg2rad($angleEnd)), 2);
                $large = $angle > 180 ? 1 : 0;

                $slices .= '<path d="M' . $cx . ',' . $cy . ' L' . $x1 . ',' . $y1
                    . ' A' . $radius . ',' . $radius . ' 0 ' . $large . ' 1 ' . $x2 . ',' . $y2 . ' Z"'
                    . ' fill="' . $color . '" stroke="' . $bg . '" stroke-width="2"/>';
            }

            $angleStart = $angleEnd;
        }

        // Trou du donut (cutout) + total au centre, comme Analytics.
        $svg .= $slices
            . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $hole . '" fill="' . $bg . '"/>'
            . '<text x="' . $cx . '" y="' . ($cy - 8) . '" fill="#9fb3c8" font-family="system-ui, Segoe UI, Arial, sans-serif" '
            . 'font-size="17" text-anchor="middle">Total</text>'
            . '<text x="' . $cx . '" y="' . ($cy + 20) . '" fill="#eaf2fb" font-family="system-ui, Segoe UI, Arial, sans-serif" '
            . 'font-size="22" font-weight="700" text-anchor="middle">' . formatPrice($total) . '</text>';

        // Légende sous le donut : 2 colonnes, 3 lignes max.
        $ly = 448;
        $col = 0;
        foreach ($rows as $i => $r) {
            $label = mb_substr(trim((string) ($r['category'] ?? '?')), 0, 14);
            $ca = (float) ($r['ca'] ?? 0);
            $pct = $total > 0 ? (int) round($ca / $total * 100) : 0;
            $color = $colors[$i % count($colors)];
            $bx = $col === 0 ? 48 : 348;
            $tx = $bx + 30;
            $ve = $bx + 252;

            $svg .= '<rect x="' . $bx . '" y="' . $ly . '" width="18" height="18" rx="5" fill="' . $color . '"/>'
                . '<text x="' . $tx . '" y="' . ($ly + 15) . '" fill="#dfe9f5" font-family="system-ui, Segoe UI, Arial, sans-serif" '
                . 'font-size="17" font-weight="600">' . htmlspecialchars($label, ENT_XML1) . '</text>'
                . '<text x="' . $ve . '" y="' . ($ly + 15) . '" fill="#9fb3c8" font-family="system-ui, Segoe UI, Arial, sans-serif" '
                . 'font-size="16" text-anchor="end">' . formatPrice($ca) . ' · ' . $pct . ' %</text>';

            if ($col === 1) {
                $col = 0;
                $ly += 42;
            } else {
                $col = 1;
            }
        }

        $svg .= '<text x="28" y="' . ($height - 18) . '" fill="#5f7d9e" font-family="system-ui, Segoe UI, Arial, sans-serif" font-size="14">'
            . 'AEIC — asso.aremond.ovh</text>'
            . '</svg>';

        return $svg;
    }

    /**
     * Rend le même donut en PNG via GD (envoi direct en sortie).
     *
     * @param list<array<string,mixed>> $rows Lignes de Sale::byCategoryBetween()
     */
    public static function renderPng(array $rows, string $date): void
    {
        $W = 640;
        $H = 600;
        $s = 2;   // supersampling x2 puis réduction (anti-aliasing)

        $rows = array_slice($rows, 0, 6);
        $colors = ['#48bdd3', '#6150aa', '#f59e0b', '#22c55e', '#ef4444', '#6db4ff'];
        $bg = '#0a1b33';

        $hex = static function (string $c): array {
            return [(int) hexdec(substr($c, 1, 2)), (int) hexdec(substr($c, 3, 2)), (int) hexdec(substr($c, 5, 2))];
        };

        $im = imagecreatetruecolor($W * $s, $H * $s);
        $alloc = static function (string $c) use ($im, $hex): int {
            [$r, $g, $b] = $hex($c);
            return imagecolorallocate($im, $r, $g, $b);
        };
        $cBg = $alloc($bg);
        $cTitle = $alloc('#eaf2fb');
        $cSub = $alloc('#9fb3c8');
        $cMuted = $alloc('#5f7d9e');
        $cLegend = $alloc('#dfe9f5');
        $cVal = $alloc('#9fb3c8');

        // Fond arrondi (rayon 24).
        $r = 24 * $s;
        imagefilledrectangle($im, 0, $r, $W * $s, $H * $s - $r, $cBg);
        imagefilledrectangle($im, $r, 0, $W * $s - $r, $H * $s, $cBg);
        imagefilledarc($im, $r, $r, 2 * $r, 2 * $r, 180, 270, $cBg, IMG_ARC_FILLED);
        imagefilledarc($im, $W * $s - $r, $r, 2 * $r, 2 * $r, 270, 360, $cBg, IMG_ARC_FILLED);
        imagefilledarc($im, $W * $s - $r, $H * $s - $r, 2 * $r, 2 * $r, 0, 90, $cBg, IMG_ARC_FILLED);
        imagefilledarc($im, $r, $H * $s - $r, 2 * $r, 2 * $r, 90, 180, $cBg, IMG_ARC_FILLED);

        // Texte : helper centré verticalement via imagettfbbox.
        $font = is_file(self::FONT) ? self::FONT : null;
        $fontBold = is_file(self::FONT_BOLD) ? self::FONT_BOLD : $font;
        $text = static function (
            string $txt,
            float $x,
            float $y,
            int $size,
            int $color,
            bool $bold = false,
            int $align = 0   // 0 = gauche, 1 = centre, 2 = droite (sur $x)
        ) use ($im, $s, $font, $fontBold): void {
            if ($font === null) {
                return;
            }
            $box = imagettfbbox($size * $s, 0, $bold ? $fontBold : $font, $txt);
            $w = abs($box[4] - $box[0]);
            $xx = match ($align) { 1 => $x * $s - $w / 2, 2 => $x * $s - $w, default => $x * $s };
            imagettftext($im, $size * $s, 0, (int) $xx, (int) ($y * $s), $color, $bold ? $fontBold : $font, $txt);
        };

        $text('Répartition par catégorie', 28, 46, 22, $cTitle, true);
        $text('CA · ' . $date, 612, 46, 16, $cSub, false, 2);

        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) ($row['ca'] ?? 0);
        }

        $cx = 320;
        $cy = 240;
        $radius = 165;
        $hole = (int) round($radius * 0.64);

        if ($rows !== [] && $total > 0.0) {
            $angleStart = -90.0;
            foreach ($rows as $i => $row) {
                $ca = (float) ($row['ca'] ?? 0);
                $angle = ($total > 0 ? $ca / $total : 0.0) * 360.0;
                $angleEnd = $angleStart + $angle;
                $color = $alloc($colors[$i % count($colors)]);

                if ($angle >= 359.995) {
                    imagefilledellipse($im, $cx * $s, $cy * $s, 2 * $radius * $s, 2 * $radius * $s, $color);
                } else {
                    imagefilledarc(
                        $im,
                        (int) ($cx * $s),
                        (int) ($cy * $s),
                        (int) (2 * $radius * $s),
                        (int) (2 * $radius * $s),
                        (int) round($angleStart),
                        (int) round($angleEnd),
                        $color,
                        IMG_ARC_PIE
                    );
                }
                $angleStart = $angleEnd;
            }

            // Trou du donut + total au centre.
            imagefilledellipse($im, $cx * $s, $cy * $s, 2 * $hole * $s, 2 * $hole * $s, $cBg);
            $text('Total', $cx, $cy - 6, 15, $cSub, false, 1);
            $text(formatPrice($total), $cx, $cy + 22, 20, $cTitle, true, 1);
        } else {
            $text('Aucune vente', $cx, $cy + 6, 20, $cSub, false, 1);
        }

        // Légende : 2 colonnes × 3 lignes.
        $ly = 448;
        $col = 0;
        foreach ($rows as $i => $row) {
            $label = mb_substr(trim((string) ($row['category'] ?? '?')), 0, 14);
            $ca = (float) ($row['ca'] ?? 0);
            $pct = $total > 0 ? (int) round($ca / $total * 100) : 0;
            $color = $alloc($colors[$i % count($colors)]);
            $bx = $col === 0 ? 48 : 348;
            $ve = $bx + 252;

            imagefilledrectangle($im, $bx * $s, $ly * $s, ($bx + 18) * $s, ($ly + 18) * $s, $color);
            $text($label, $bx + 30, $ly + 15, 15, $cLegend, true);
            $text(formatPrice($ca) . ' · ' . $pct . ' %', $ve, $ly + 15, 14, $cVal, false, 2);

            if ($col === 1) {
                $col = 0;
                $ly += 42;
            } else {
                $col = 1;
            }
        }

        $text('AEIC — asso.aremond.ovh', 28, $H - 14, 12, $cMuted);

        // Réduction x2 (anti-aliasing) puis sortie.
        $out = imagecreatetruecolor($W, $H);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $W, $H, $W * $s, $H * $s);
        imagedestroy($im);
        imagepng($out, null, 6);
        imagedestroy($out);
    }
}
