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
        echo self::buildSvg(Sale::byCategoryBetween($today, $today), date('d/m/Y'));
    }

    /**
     * Construit le SVG du camembert (statique pour être testable).
     *
     * @param list<array<string,mixed>> $rows Lignes de Sale::byCategoryBetween()
     */
    public static function buildSvg(array $rows, string $date): string
    {
        $rows = array_slice($rows, 0, 6);
        $width = 640;
        $height = 480;
        $cx = 200;
        $cy = 280;
        $radius = 150;

        $colors = ['#7fd0e4', '#3a9bb8', '#8b7ae0', '#c084fc', '#f59e0b', '#34d399'];

        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) ($r['ca'] ?? 0);
        }

        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
            . '<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="#08172d"/><stop offset="1" stop-color="#0d2447"/>'
            . '</linearGradient></defs>'
            . '<rect width="' . $width . '" height="' . $height . '" fill="url(#bg)" rx="24"/>'
            . '<text x="28" y="46" fill="#ffffff" font-family="Segoe UI, Arial, sans-serif" font-size="26" font-weight="700">'
            . '🥧 Répartition du jour</text>'
            . '<text x="' . ($width - 28) . '" y="46" fill="#7fa3c8" font-family="Segoe UI, Arial, sans-serif" font-size="20" text-anchor="end">'
            . htmlspecialchars($date, ENT_XML1) . '</text>';

        if ($rows === [] || $total <= 0.0) {
            $svg .= '<text x="' . ($width / 2) . '" y="' . ($cy + 8) . '" fill="#93a4bd" '
                . 'font-family="Segoe UI, Arial, sans-serif" font-size="22" text-anchor="middle">Aucune vente aujourd\'hui</text>'
                . '</svg>';
            return $svg;
        }

        // Camembert : parts successives depuis le sommet (-90°), sens horaire.
        $slices = '';
        $legend = '';
        $angleStart = -90.0;
        $ly = 104;

        foreach ($rows as $i => $r) {
            $ca = (float) ($r['ca'] ?? 0);
            $pct = $total > 0 ? $ca / $total : 0.0;
            $color = $colors[$i % count($colors)];

            $angle = $pct * 360.0;
            $angleEnd = $angleStart + $angle;

            if ($angle >= 359.995) {
                // Part unique : un arc ne peut pas faire 360°, cercle plein.
                $slices .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $radius . '" fill="' . $color . '"/>';
            } else {
                $x1 = round($cx + $radius * cos(deg2rad($angleStart)), 2);
                $y1 = round($cy + $radius * sin(deg2rad($angleStart)), 2);
                $x2 = round($cx + $radius * cos(deg2rad($angleEnd)), 2);
                $y2 = round($cy + $radius * sin(deg2rad($angleEnd)), 2);
                $large = $angle > 180 ? 1 : 0;

                $slices .= '<path d="M' . $cx . ',' . $cy . ' L' . $x1 . ',' . $y1
                    . ' A' . $radius . ',' . $radius . ' 0 ' . $large . ' 1 ' . $x2 . ',' . $y2 . ' Z"'
                    . ' fill="' . $color . '" stroke="#08172d" stroke-width="2"/>';
            }

            // Légende à droite.
            $label = mb_substr(trim((string) ($r['category'] ?? '?')), 0, 16);
            $legend .= '<rect x="392" y="' . $ly . '" width="22" height="22" rx="6" fill="' . $color . '"/>'
                . '<text x="426" y="' . ($ly + 17) . '" fill="#dfe9f5" font-family="Segoe UI, Arial, sans-serif" font-size="19" font-weight="600">'
                . htmlspecialchars($label, ENT_XML1) . '</text>'
                . '<text x="612" y="' . ($ly + 17) . '" fill="#9fc2e0" font-family="Segoe UI, Arial, sans-serif" '
                . 'font-size="18" text-anchor="end">' . formatPrice($ca) . ' · ' . round($pct * 100) . ' %</text>';

            $angleStart = $angleEnd;
            $ly += 52;
        }

        // Pastille centrale.
        $svg .= $slices . $legend
            . '<text x="28" y="' . ($height - 20) . '" fill="#5f7d9e" font-family="Segoe UI, Arial, sans-serif" font-size="15">'
            . 'Total : ' . formatPrice($total) . ' · AEIC — asso.aremond.ovh</text>'
            . '</svg>';

        return $svg;
    }
}
