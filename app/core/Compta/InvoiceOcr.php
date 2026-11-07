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
 *  - Image        : Tesseract directement (français), en ENSEMBLE quand
 *                   ImageMagick est disponible : plusieurs variantes de
 *                   prétraitement (originale, contraste/seuillage,
 *                   redressement, upscale de dernier recours) sont OCRisées
 *                   puis fusionnées par InvoiceEnsemble — chaque variante
 *                   rattrape ce que les autres perdent sur de vraies photos.
 *
 * Les binaires absents (poste Windows de dev, VPS non équipé) lèvent une
 * RuntimeException avec un message d'installation en français — jamais de
 * fatal. ImageMagick absent → une seule variante (comportement historique,
 * repli silencieux). Tous les chemins passés au shell sont échappés
 * (escapeshellarg) ; les fichiers temporaires sont créés par nous (nom
 * aléatoire) et supprimés en finally ; chaque commande est bornée (timeout).
 */
final class InvoiceOcr
{
    /** Taille minimale (caractères non blancs) pour considérer un PDF textuel. */
    private const PDF_TEXT_MIN_CHARS = 30;

    /** Nombre maximal de pages rastérisées pour un PDF scanné. */
    private const PDF_MAX_PAGES = 5;

    /** Pages d'un PDF passées à l'OCR ensembliste (lignes + totaux y sont). */
    private const PDF_ENSEMBLE_PAGES = 2;

    /** Durée maximale d'un appel OCR (préfixe « timeout » sous Linux). */
    private const COMMAND_TIMEOUT_S = 90;

    /**
     * Séparateur inséré entre les deux passes OCR d'une photo : les parseurs
     * lisent les LIGNES produits dans la première passe (psm 6) et cherchent
     * les en-têtes (n°, date, totaux) dans tout le texte, la seconde passe
     * (psm par défaut) rattrapant les champs perdus par l'une ou l'autre.
     */
    public const ALT_MARKER = '----- OCR alt -----';

    /**
     * Séparateur entre les textes des différentes variantes de prétraitement
     * dans le texte consolidé (InvoiceEnsemble) : distinct du marqueur ALT,
     * que les parseurs ne coupent jamais.
     */
    public const VARIANT_MARKER = '----- OCR variante %s -----';

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
            : self::extractFromImage($filePath);
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
    private static function ocrImage(string $filePath, string $psm = ''): string
    {
        if (!self::hasBinary('tesseract')) {
            throw new \RuntimeException(
                'OCR indisponible sur ce serveur (installez tesseract-ocr tesseract-ocr-fra poppler-utils).'
            );
        }

        [$code, $output] = self::run('tesseract ' . escapeshellarg($filePath) . ' stdout -l fra' . $psm);
        $text = implode("\n", $output);
        if ($code !== 0) {
            throw new \RuntimeException("Échec de l'OCR (tesseract) : " . mb_substr(trim($text), 0, 200));
        }

        return $text;
    }

    /**
     * OCR d'une photo : deux passes (psm 6 « bloc uniforme » puis psm par
     * défaut), séparées par le marqueur ALT_MARKER. Sur les vraies photos
     * téléphone, le psm 6 restitue les lignes du tableau produit que le psm
     * par défaut éparpille, mais peut tronquer un en-tête (n°, année) que la
     * seconde passe relit correctement.
     */
    public static function ocrTwoPasses(string $filePath): string
    {
        $psm6 = self::ocrImage($filePath, ' --psm 6');
        $default = self::ocrImage($filePath);

        return $psm6 . "\n" . self::ALT_MARKER . "\n" . $default;
    }

    /** OCR d'une image (une seule photo, deux passes) — historique. */
    private static function extractFromImage(string $filePath): string
    {
        return self::ocrTwoPasses($filePath);
    }

    // ————————————————————————————————————————————————————————————
    // Ensemble : variantes de prétraitement image (ImageMagick)
    // ————————————————————————————————————————————————————————————

    /**
     * ImageMagick est-il utilisable ? Sous Windows, seul « magick » est
     * accepté : « convert » y désigne souvent l'outil de conversion de
     * fichiers de Windows (System32), jamais ImageMagick.
     */
    public static function hasImageMagick(): bool
    {
        return self::imageMagickBinary() !== null;
    }

    /** Poppler (rastérisation et couche texte des PDF) est-il installé ? */
    public static function hasPoppler(): bool
    {
        return self::hasBinary('pdftoppm') && self::hasBinary('pdftotext');
    }

    /** Tesseract (OCR) est-il installé ? */
    public static function hasOcr(): bool
    {
        return self::hasBinary('tesseract');
    }

    /** Binaire ImageMagick trouvé (« magick » sinon « convert »), null sinon. */
    private static function imageMagickBinary(): ?string
    {
        static $cached = null;
        static $resolved = false;
        if ($resolved) {
            return $cached;
        }
        $resolved = true;

        if (self::hasBinary('magick')) {
            return $cached = 'magick';
        }
        if (PHP_OS_FAMILY !== 'Windows' && self::hasBinary('convert')) {
            return $cached = 'convert';
        }

        return $cached = null;
    }

    /**
     * OCR ENSEMBLISTE d'une photo : variante originale (v0) toujours, plus —
     * si ImageMagick est disponible — les variantes v1 (niveaux de gris +
     * contraste + seuillage 55 %) et v2 (redressement + netteté). Chaque
     * variante subit les deux passes Tesseract (voir ocrTwoPasses) ; une
     * variante que le prétraitement ou l'OCR met en échec est simplement
     * absente du résultat (repli silencieux sur les suivantes). La variante
     * v3 « dernier recours » (upscale 150 % + seuillage 65 %) est à demander
     * séparément (extractLastResortText), quand la fusion déçoit.
     *
     * @return list<array{name:string, text:string}> (au minimum v0)
     */
    public static function extractImageTexts(string $filePath): array
    {
        $texts = [['name' => 'v0', 'text' => self::ocrTwoPasses($filePath)]];

        $bin = self::imageMagickBinary();
        if ($bin === null) {
            return $texts; // pas d'ImageMagick : comportement historique
        }

        $dir = sys_get_temp_dir() . '/aeic-ocr-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return $texts; // préparation impossible : v0 seul, sans bruit
        }

        try {
            $recipes = [
                // v1 : contraste maximal puis binarisation douce (ombres).
                ['v1', ' -colorspace Gray -normalize -threshold 55%'],
                // v2 : redressement de la photo + netteté (flou/biais).
                ['v2', ' -deskew 40% -colorspace Gray -normalize -sharpen 0x1'],
            ];
            foreach ($recipes as [$name, $filters]) {
                $variant = $dir . '/' . $name . '.png';
                [$code] = self::run(
                    $bin . ' ' . escapeshellarg($filePath) . $filters . ' ' . escapeshellarg($variant)
                );
                if ($code !== 0 || !is_file($variant) || filesize($variant) === 0) {
                    continue; // prétraitement en échec : variante ignorée
                }
                try {
                    $texts[] = ['name' => $name, 'text' => self::ocrTwoPasses($variant)];
                } catch (\RuntimeException) {
                    // Variante illisible pour Tesseract : suivante.
                }
            }
        } finally {
            self::removeDirectory($dir);
        }

        return $texts;
    }

    /**
     * OCR ENSEMBLISTE d'un PDF : rastérisation 300 dpi (les métro-factures
     * sont des tableaux à colonnes que pdftotext désaligne — lignes éclatées
     * en blocs séparés) puis, pour chaque page, les variantes de
     * extractImageTexts (prétraitements × deux passes Tesseract). Toutes
     * les variantes de toutes les pages sont renvoyées ensemble : la
     * fusion InvoiceEnsemble rattrape ce qu'une page perd. Les premières
     * pages portent les lignes et les totaux ; au-delà de
     * PDF_ENSEMBLE_PAGES le coût OCR explose pour un gain nul.
     *
     * @return list<array{name:string, text:string}> (au minimum la v0 de la page 1)
     */
    public static function extractPdfVariants(string $filePath): array
    {
        if (!function_exists('exec')) {
            throw new \RuntimeException('La fonction exec() est désactivée sur ce serveur : OCR indisponible.');
        }
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
            self::run('pdftoppm -png -r 300 ' . escapeshellarg($filePath) . ' ' . escapeshellarg($dir . '/scan'));

            $pages = glob($dir . '/scan-*.png') ?: [];
            sort($pages, SORT_NATURAL);
            if ($pages === []) {
                throw new \RuntimeException('PDF illisible : aucune page n\'a pu être extraite (fichier corrompu ?).');
            }

            $variants = [];
            foreach (array_slice($pages, 0, self::PDF_ENSEMBLE_PAGES) as $pi => $page) {
                foreach (self::extractImageTexts($page) as $v) {
                    $variants[] = [
                        'name' => 'p' . ($pi + 1) . '-' . $v['name'],
                        'text' => $v['text'],
                    ];
                }
            }

            return $variants;
        } finally {
            self::removeDirectory($dir);
        }
    }

    /**
     * Variante « dernier recours » v3 : upscale 150 % + niveaux de gris +
     * seuillage plus ferme (65 %) — réservée aux photos dont la fusion a
     * donné trop peu de lignes validées (voir InvoiceEnsemble::
     * needsLastResort). null si ImageMagick est absent ou si la variante
     * échoue : l'appelant garde le résultat déjà consolidé.
     *
     * @return array{name:string, text:string}|null
     */
    public static function extractLastResortText(string $filePath): ?array
    {
        $bin = self::imageMagickBinary();
        if ($bin === null) {
            return null;
        }

        $dir = sys_get_temp_dir() . '/aeic-ocr-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }

        try {
            $variant = $dir . '/v3.png';
            [$code] = self::run(
                $bin . ' ' . escapeshellarg($filePath)
                . ' -resize 150% -colorspace Gray -normalize -threshold 65% '
                . escapeshellarg($variant)
            );
            if ($code !== 0 || !is_file($variant) || filesize($variant) === 0) {
                return null;
            }
            try {
                return ['name' => 'v3', 'text' => self::ocrTwoPasses($variant)];
            } catch (\RuntimeException) {
                return null;
            }
        } finally {
            self::removeDirectory($dir);
        }
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
        // Tesseract multi-thread se fige sur certains VPS (OpenMP) : une
        // seule thread OpenMP, sans impact notable sur la vitesse.
        if (PHP_OS_FAMILY !== 'Windows') {
            $full = 'OMP_THREAD_LIMIT=1 ' . $full;
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
