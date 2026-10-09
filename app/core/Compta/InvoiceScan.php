<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Analyse d'un document fournisseur (facture METRO ou ticket de caisse)
 * pour les écrans de scan — partagée par les scans ADMIN (achat :
 * /admin/compta/achats/scan, dépense : /admin/compta/depenses/scan) et
 * par le livre comptable KIOSQUE (jeton dans l'URL, sans session).
 *
 * Deux sources acceptées :
 *  - champ POST « text » : texte du document collé (200 000 caractères max) ;
 *  - upload multipart « file » : photo ou PDF (10 Mo max, extension ET
 *    MIME réels vérifiés), texte extrait par OCR (Tesseract/Poppler).
 *
 * Le document est interprété par InvoiceParser (aiguillage METRO / ticket
 * de caisse, clé kind dans la réponse). Pour les PHOTOS, quand ImageMagick
 * est disponible, l'OCR est ensembliste : plusieurs variantes de
 * prétraitement sont OCRisées puis fusionnées par InvoiceEnsemble (PDF et
 * texte collé : comportement direct inchangé). Lecture seule : rien n'est
 * enregistré ici, le formulaire reste modifiable avant validation.
 *
 * L'audit reste à la charge du contrôleur appelant (mécanismes
 * différents : Auth::id() côté admin, AuditLog sans utilisateur côté
 * kiosque) — « run() » renvoie la base du journal dans la clé « audit ».
 */
final class InvoiceScan
{
    /** Taille maximale d'un document envoyé au scan (10 Mo). */
    public const MAX_SIZE = 10 * 1024 * 1024;

    /** Extensions acceptées pour le scan → MIME réel attendu (finfo). */
    public const ALLOWED_MIMES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ];

    /** Taille maximale d'un texte de document collé (200 000 caractères). */
    public const MAX_TEXT_LENGTH = 200000;

    /**
     * Extrait le texte du document de la requête courante puis l'« invoice »
     * correspondante. Réponse de succès (contrat partagé avec le
     * frontend) : {ok:true, source:'file'|'text', text:..., invoice:{...},
     * audit:{...}} — la clé « audit » porte la base du journal (source,
     * taille, sha256…) à enrichir par l'appelant. Erreurs : {ok:false,
     * status:int, error:'message lisible'} — 400 (requête invalide),
     * 413 (trop volumineux), 500 (OCR indisponible ou en échec).
     *
     * @return array<string,mixed>
     */
    public static function run(): array
    {
        // Source 1 : texte collé directement (prioritaire sur le fichier).
        $text = (string) ($_POST['text'] ?? '');
        if (trim($text) !== '') {
            if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
                return ['ok' => false, 'status' => 400, 'error' => 'Texte trop long (200 000 caractères maximum).'];
            }

            return [
                'ok'      => true,
                'source'  => 'text',
                'text'    => $text,
                'invoice' => InvoiceParser::parse($text),
                'audit'   => [
                    'source' => 'text',
                    'taille' => strlen($text),
                    'sha256' => hash('sha256', $text),
                ],
            ];
        }

        // Source 2 : fichier envoyé (photo/PDF du document).
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [
                'ok'     => false,
                'status' => 400,
                'error'  => 'Aucun document reçu : envoyez un fichier (champ « file ») ou du texte (champ « text »).',
            ];
        }
        if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return [
                'ok'     => false,
                'status' => 400,
                'error'  => "Échec de l'envoi du fichier (erreur " . (int) $file['error'] . ").",
            ];
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_SIZE) {
            return ['ok' => false, 'status' => 413, 'error' => 'Fichier trop volumineux (10 Mo maximum).'];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_MIMES[$ext])) {
            return [
                'ok'     => false,
                'status' => 400,
                'error'  => 'Format non accepté : image JPG, PNG, WEBP ou PDF attendu.',
            ];
        }

        // Validation MIME réelle : l'extension déclarée n'est jamais une preuve.
        $mime = self::detectUploadMime((string) $file['tmp_name']);
        if ($mime !== self::ALLOWED_MIMES[$ext]) {
            return [
                'ok'     => false,
                'status' => 400,
                'error'  => 'Le contenu du fichier ne correspond pas à son extension (JPG, PNG, WEBP ou PDF attendu).',
            ];
        }

        $tmpPath = (string) $file['tmp_name'];
        $audit = [
            'source' => 'file',
            'nom'    => (string) ($file['name'] ?? ''),
            'taille' => (int) ($file['size'] ?? 0),
            'mime'   => $mime,
            'sha256' => hash('sha256', (string) @file_get_contents($tmpPath)),
        ];

        try {
            if (str_starts_with($mime, 'application/pdf') || !InvoiceOcr::hasImageMagick()) {
                // PDF : couche texte puis OCR page par page — inchangé.
                // Image sans ImageMagick : OCR historique de la photo seule.
                $text = InvoiceOcr::extractText($tmpPath, $mime);
                $invoice = InvoiceParser::parse($text);
            } else {
                // Image avec ImageMagick : OCR ensembliste — variantes de
                // prétraitement (originale, seuillage, redressement) × deux
                // modes Tesseract, fusionnées par InvoiceEnsemble. La
                // variante « dernier recours » (upscale) n'est OCRisée que
                // si la fusion a donné trop peu de lignes validées.
                $texts = InvoiceOcr::extractImageTexts($tmpPath);
                $result = InvoiceEnsemble::consolidate($texts);
                if (InvoiceEnsemble::needsLastResort($result)) {
                    $extra = InvoiceOcr::extractLastResortText($tmpPath);
                    if ($extra !== null) {
                        $texts[] = $extra;
                        $result = InvoiceEnsemble::consolidate($texts);
                    }
                }
                $text = $result['text'];
                $invoice = $result['invoice'];
                if ((int) $result['variants'] >= 2) {
                    $invoice['warnings'][] = sprintf(
                        "Extraction consolidée sur %d variantes de l'image (OCR ensembliste) — %d ligne(s) fusionnée(s).",
                        (int) $result['variants'],
                        (int) $result['groups']
                    );
                }
            }
        } catch (\RuntimeException $e) {
            // Outil absent ou OCR en échec : message FR déjà lisible.
            return ['ok' => false, 'status' => 500, 'error' => $e->getMessage()];
        }

        return [
            'ok'      => true,
            'source'  => 'file',
            'text'    => $text,
            'invoice' => $invoice,
            'audit'   => $audit,
        ];
    }

    /**
     * MIME réel d'un fichier envoyé, via finfo (extension Fileinfo), avec
     * repli mime_content_type — même logique que ReceiptStorage.
     */
    public static function detectUploadMime(string $path): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return function_exists('mime_content_type') ? (mime_content_type($path) ?: null) : null;
    }
}
