<?php

declare(strict_types=1);

/**
 * Bench OCR sur de vraies photos (usage VPS) :
 *
 *   php scripts/bench_ocr.php chemin/photo1.jpg chemin/photo2.jpg ...
 *
 * Pour chaque image : variantes de prétraitement (ImageMagick si présent)
 * × deux modes Tesseract, fusion ensembliste (InvoiceEnsemble), puis
 * affichage du verdict — nombre de lignes fortement validées, lignes
 * finales, totaux et avertissements — avec le temps de chaque étape.
 * Aucune écriture : lecture seule des images, fichiers temporaires
 * supprimés par InvoiceOcr.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Compta\InvoiceEnsemble;
use App\Core\Compta\InvoiceOcr;
use App\Core\Compta\InvoiceParser;

if ($argc < 2) {
    fwrite(STDERR, "Usage : php scripts/bench_ocr.php image1.jpg [image2.jpg ...]\n");

    exit(1);
}

printf(
    "ImageMagick : %s | Tesseract : %s\n\n",
    InvoiceOcr::hasImageMagick() ? 'OUI (OCR ensembliste)' : 'non (v0 seul)',
    (string) (shell_exec('tesseract --version 2>&1') ? 'oui' : 'ABSENT')
);

foreach (array_slice($argv, 1) as $path) {
    echo "════ {$path} ════\n";
    if (!is_file($path)) {
        echo "  !! fichier introuvable\n\n";

        continue;
    }

    $t0 = microtime(true);
    try {
        $texts = InvoiceOcr::hasImageMagick()
            ? InvoiceOcr::extractImageTexts($path)
            : [['name' => 'v0', 'text' => InvoiceOcr::ocrTwoPasses($path)]];
    } catch (RuntimeException $e) {
        echo '  !! ' . $e->getMessage() . "\n\n";

        continue;
    }
    $tOcr = microtime(true);
    printf(
        "  OCR : %d variante(s) [%s] en %.1f s\n",
        count($texts),
        implode(', ', array_column($texts, 'name')),
        $tOcr - $t0
    );

    $result = InvoiceEnsemble::consolidate($texts);
    if (InvoiceEnsemble::needsLastResort($result) && InvoiceOcr::hasImageMagick()) {
        $extra = InvoiceOcr::extractLastResortText($path);
        if ($extra !== null) {
            $texts[] = $extra;
            $result = InvoiceEnsemble::consolidate($texts);
            echo "  + variante dernier recours v3 appliquée\n";
        }
    }
    $tFusion = microtime(true);

    $invoice = $result['invoice'];
    printf(
        "  Fusion : %d variante(s), %d candidat(s) fortement validé(s), %d ligne(s) en %.1f s\n",
        (int) $result['variants'],
        (int) $result['validated'],
        (int) $result['groups'],
        $tFusion - $tOcr
    );
    printf(
        "  → kind=%s fournisseur=%s n°=%s date=%s HT=%s TTC=%s TVA=%s\n",
        (string) ($invoice['kind'] ?? '?'),
        $invoice['supplier'] ?? '?',
        $invoice['invoice_number'] ?? '?',
        $invoice['purchased_at'] ?? '?',
        $invoice['total_ht'] !== null ? number_format($invoice['total_ht'], 2, ',', ' ') : '?',
        $invoice['total_ttc'] !== null ? number_format($invoice['total_ttc'], 2, ',', ' ') : '?',
        $invoice['vat_rate'] !== null ? number_format($invoice['vat_rate'], 1, ',', ' ') . ' %' : 'multi/??'
    );
    foreach ($invoice['lines'] as $line) {
        printf(
            "    · %-44s u=%-4d %s %s\n",
            mb_substr((string) $line['label'], 0, 44),
            (int) $line['units'],
            number_format((float) $line['total'], 2, ',', ' '),
            $line['vat_letter'] ?? ''
        );
    }
    foreach ($invoice['warnings'] as $warning) {
        echo '    ⚠ ' . $warning . "\n";
    }
    $sum = 0.0;
    foreach ($invoice['lines'] as $line) {
        $sum += (float) $line['total'];
    }
    printf("  Σ lignes = %s €\n\n", number_format($sum, 2, ',', ' '));
}
