<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\SmsReport;
use App\Models\Sale;

/**
 * Graphique image (SVG) du jour pour les SMS : répartition du CA par
 * catégorie. L'URL est protégée par un jeton secret (setting
 * `sms_chart_token`) : sans le bon jeton, 404.
 */
final class SmsChartController
{
    public function show(string $token): void
    {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: no-store');

        // Le routeur capture le segment complet : retire l'extension éventuelle.
        if (str_ends_with($token, '.svg')) {
            $token = substr($token, 0, -4);
        }

        if (!hash_equals(SmsReport::chartToken(), $token)) {
            http_response_code(404);
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

        echo self::buildSvg(Sale::byCategoryBetween($from, $today), $label);
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
}
