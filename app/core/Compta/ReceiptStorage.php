<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Stockage des justificatifs de dépenses (tickets de caisse, factures).
 *
 * Règles partagées par l'admin (AdminExpenseController) et le kiosque
 * (saisie express du livre comptable) : PDF ou image, 5 Mo maximum, MIME
 * réel vérifié (comme les médias), stockage sous /assets/uploads/receipts/
 * avec un nom aléatoire. Le chemin renvoyé est relatif sous /assets
 * (ex. « uploads/receipts/xx.pdf ») — prêt pour asset().
 */
final class ReceiptStorage
{
    public const MAX_SIZE = 5 * 1024 * 1024;

    private const ALLOWED = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    /**
     * Valide et déplace le justificatif envoyé avec la dépense
     * (champ $_FILES['receipt']).
     *
     * @return string|null Chemin relatif sous /assets, null si aucun fichier envoyé.
     *
     * @throws \RuntimeException Message utilisateur si le fichier est refusé
     *                           (à afficher tel quel dans un flash).
     */
    public static function store(): ?string
    {
        return self::storeFrom($_FILES['receipt'] ?? null);
    }

    /**
     * Variante paramétrée : valide et déplace n'importe quel fichier
     * téléversé (au format $_FILES) — utilisée pour conserver le
     * justificatif d'un scan de facture (champ « file ») en même
     * temps que son OCR.
     *
     * @param array<string,mixed>|null $file Entrée $_FILES complète.
     *
     * @return string|null Chemin relatif sous /assets, null si aucun fichier.
     *
     * @throws \RuntimeException Message utilisateur si le fichier est refusé.
     */
    public static function storeFrom(?array $file): ?string
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Échec de l\'envoi du justificatif (erreur ' . (int) ($file['error'] ?? 0) . ').');
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_SIZE) {
            throw new \RuntimeException('Justificatif trop volumineux (5 Mo maximum).');
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) {
            throw new \RuntimeException('Justificatif : formats acceptés PDF, JPG, PNG ou WEBP.');
        }

        // Validation MIME réelle : l'extension déclarée n'est jamais une preuve.
        $detected = null;
        if (function_exists('mime_content_type')) {
            $detected = mime_content_type((string) $file['tmp_name']);
        } elseif (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, (string) $file['tmp_name']);
                finfo_close($finfo);
            }
        }
        if ($detected !== self::ALLOWED[$ext]) {
            throw new \RuntimeException('Le contenu du justificatif ne correspond pas à son extension.');
        }

        $dir = AEIC_PUBLIC . '/assets/uploads/receipts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            throw new \RuntimeException('Échec de l\'enregistrement du justificatif.');
        }

        return 'uploads/receipts/' . $name;
    }
}
