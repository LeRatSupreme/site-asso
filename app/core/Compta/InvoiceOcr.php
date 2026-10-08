<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Extraction de texte d'une facture scannée (image ou PDF) via les outils
 * en ligne de commande du VPS : Tesseract (OCR) et Poppler (PDF).
 *
 * Aucune dépendance PHP externe : on encadre les binaires système.
 *  - PDF textuel  : pdftotext -layout (rapide, fidèle) ;
 *  - PDF scanné   : pdftoppm -r 200 puis Tesseract page par page (max 5) ;
 *  - Image        : Tesseract directement (français).
 *
 * Les binaires absents (poste Windows de dev, VPS non équipé) lèvent une
 * RuntimeException avec un message d'installation en français — jamais de
 * fatal. Tous les chemins passés au shell sont échappés (escapeshellarg) ;
 * les fichiers temporaires sont créés par nous (nom aléatoire) et supprimés
 * en finally.
 */
final class InvoiceOcr
{
    /** Taille minimale (caractères non blancs) pour considérer un PDF textuel. */
    private const PDF_TEXT_MIN_CHARS = 30;

    /** Nombre maximal de pages rastérisées pour un PDF scanné. */
    private const PDF_MAX_PAGES = 5;

    /** Durée maximale d'un appel OCR (préfixe « timeout » sous Linux). */
    private const COMMAND_TIMEOUT_S = 90;

    /**
     * Extrait le texte d'une facture (PDF ou image).
     *
     * @param string $filePath Chemin réel du fichier (tmp upload ou copie).
     * @param string $mime     MIME réel détecté (finfo) côté contrôleur.
     *
     * @throws \RuntimeException Outil absent, exec() désactivé ou échec OCR —
     *                           message lisible destiné à l'utilisateur.
     */
    public static function extractText(string $filePath, string $mime): string
    {
        if (!function_exists('exec')) {
            throw new \RuntimeException('La fonction exec() est désactivée sur ce serveur : OCR indisponible.');
        }

        return str_starts_with($mime, 'application/pdf')
            ? self::extractFromPdf($filePath)
            : self::ocrImage($filePath);
    }

    /** Texte d'un PDF : couche texte d'abord, OCR page par page sinon. */
    private static function extractFromPdf(string $filePath): string
    {
        // 1) PDF textuel : pdftotext suffit et reste le plus fidèle.
        if (self::hasBinary('pdftotext')) {
            [$code, $output] = self::run('pdftotext -layout ' . escapeshellarg($filePath) . ' -');
            $text = $code === 0 ? implode("\n", $output) : '';
            if (strlen((string) preg_replace('/\s+/', '', $text)) >= self::PDF_TEXT_MIN_CHARS) {
                return $text;
            }
        }

        // 2) PDF scanné (ou pdftotext absent) : rastérisation puis OCR.
        if (!self::hasBinary('pdftoppm') || !self::hasBinary('tesseract')) {
            throw new \RuntimeException(
                'OCR indisponible sur ce serveur (installez tesseract-ocr tesseract-ocr-fra poppler-utils).'
            );
        }

        $dir = sys_get_temp_dir() . '/aeic-ocr-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de préparer le dossier temporaire OCR.');
        }

        try {
            self::run('pdftoppm -png -r 200 ' . escapeshellarg($filePath) . ' ' . escapeshellarg($dir . '/scan'));

            $pages = glob($dir . '/scan-*.png') ?: [];
            sort($pages, SORT_NATURAL);

            $text = '';
            foreach (array_slice($pages, 0, self::PDF_MAX_PAGES) as $page) {
                $text .= self::ocrImage($page) . "\n";
            }

            return trim($text);
        } finally {
            self::removeDirectory($dir);
        }
    }

    /** OCR d'une image (PNG/JPG/WEBP) en français via Tesseract. */
    private static function ocrImage(string $filePath): string
    {
        if (!self::hasBinary('tesseract')) {
            throw new \RuntimeException(
                'OCR indisponible sur ce serveur (installez tesseract-ocr tesseract-ocr-fra poppler-utils).'
            );
        }

        [$code, $output] = self::run('tesseract ' . escapeshellarg($filePath) . ' stdout -l fra');
        $text = implode("\n", $output);
        if ($code !== 0) {
            throw new \RuntimeException("Échec de l'OCR (tesseract) : " . mb_substr(trim($text), 0, 200));
        }

        return $text;
    }

    /**
     * Exécute une commande shell et renvoie [code retour, sortie (stderr inclus)].
     * Sous Linux, un préfixe « timeout 90 » évite qu'un OCR bloqué fige le PHP.
     *
     * @return array{0:int,1:list<string>}
     */
    private static function run(string $command): array
    {
        $full = $command . ' 2>&1';
        if (PHP_OS_FAMILY !== 'Windows' && self::hasBinary('timeout')) {
            $full = 'timeout ' . self::COMMAND_TIMEOUT_S . ' ' . $full;
        }

        $output = [];
        $code = 0;
        exec($full, $output, $code);

        return [$code, $output];
    }

    /** Présence d'un binaire, portable Windows (`where`) / Linux (`command -v`). */
    private static function hasBinary(string $bin): bool
    {
        static $cache = [];
        if (isset($cache[$bin])) {
            return $cache[$bin];
        }

        $output = [];
        $code = 1;
        if (PHP_OS_FAMILY === 'Windows') {
            exec('where ' . escapeshellarg($bin) . ' 2>NUL', $output, $code);
        } else {
            exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $output, $code);
        }

        return $cache[$bin] = ($code === 0 && $output !== []);
    }

    /** Suppression récursive d'un dossier temporaire créé par cette classe. */
    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = glob($dir . '/*') ?: [];
        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                self::removeDirectory($entry);
            } else {
                @unlink($entry);
            }
        }
        @rmdir($dir);
    }
}
