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
     * Construit le SVG du graphique (statique pour être testable).
     *
     * @param list<array<string,mixed>> $rows Lignes de Sale::byCategoryBetween()
     */
    public static function buildSvg(array $rows, string $date): string
    {
        $rows = array_slice($rows, 0, 6);
        $n = max(count($rows), 1);
        $width = 640;
        $headerH = 92;
        $rowH = 64;
        $height = $headerH + $n * $rowH + 48;

        $colors = ['#7fd0e4', '#3a9bb8', '#8b7ae0', '#c084fc', '#48bdd3', '#5a6fd0'];

        $max = 0.0;
        $total = 0.0;
        foreach ($rows as $r) {
            $ca = (float) ($r['ca'] ?? 0);
            $max = max($max, $ca);
            $total += $ca;
        }

        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
            . '<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="#08172d"/><stop offset="1" stop-color="#0d2447"/>'
            . '</linearGradient></defs>'
            . '<rect width="' . $width . '" height="' . $height . '" fill="url(#bg)" rx="24"/>'
            . '<text x="28" y="44" fill="#ffffff" font-family="Segoe UI, Arial, sans-serif" font-size="26" font-weight="700">'
            . '📊 Répartition du jour</text>'
            . '<text x="' . ($width - 28) . '" y="44" fill="#7fa3c8" font-family="Segoe UI, Arial, sans-serif" font-size="20" text-anchor="end">'
            . htmlspecialchars($date, ENT_XML1) . '</text>';

        if ($rows === []) {
            $svg .= '<text x="' . ($width / 2) . '" y="' . ($headerH + 30) . '" fill="#93a4bd" '
                . 'font-family="Segoe UI, Arial, sans-serif" font-size="22" text-anchor="middle">Aucune vente aujourd\'hui</text>';
        }

        $y = $headerH;
        foreach ($rows as $i => $r) {
            $label = mb_substr(trim((string) ($r['category'] ?? '?')), 0, 24);
            $ca = (float) ($r['ca'] ?? 0);
            $pct = $total > 0 ? (int) round($ca / $total * 100) : 0;
            $barW = $max > 0 ? max(10, (int) round($ca / $max * 400)) : 10;
            $color = $colors[$i % count($colors)];

            $svg .= '<text x="28" y="' . ($y + 20) . '" fill="#dfe9f5" font-family="Segoe UI, Arial, sans-serif" font-size="20" font-weight="600">'
                . htmlspecialchars($label, ENT_XML1) . '</text>'
                . '<rect x="28" y="' . ($y + 30) . '" width="' . $barW . '" height="18" rx="9" fill="' . $color . '"/>'
                . '<text x="' . ($width - 28) . '" y="' . ($y + 45) . '" fill="#9fc2e0" font-family="Segoe UI, Arial, sans-serif" '
                . 'font-size="18" text-anchor="end">' . formatPrice($ca) . ' · ' . $pct . ' %</text>';

            $y += $rowH;
        }

        $svg .= '<text x="28" y="' . ($height - 20) . '" fill="#5f7d9e" font-family="Segoe UI, Arial, sans-serif" font-size="15">'
            . 'AEIC — asso.aremond.ovh</text>'
            . '</svg>';

        return $svg;
    }
}
