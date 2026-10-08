<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\InvoiceOcr;
use App\Core\Compta\InvoiceParser;
use App\Core\Controller;
use App\Core\Middleware;
use App\Core\Permissions;

/**
 * Contrôleur de base de l'espace d'administration.
 *
 * Quatre niveaux d'accès :
 *  - guard()          : réservé à ADMIN (hors groupe Système) ;
 *  - guardSystem()    : ADMIN explicitement autorisé via SYSTEM_ADMINS
 *                      (utilisateurs, caisses, inventaire, coûts de
 *                      revient, paramètres…) ;
 *  - guardModule(x)   : ADMIN + rôles auxquels le module x est ouvert
 *                      (voir App\Core\Permissions) ;
 *  - guardAdminArea() : tout rôle ayant accès à au moins un module
 *                      (tableau de bord, wiki).
 */
abstract class AdminBaseController extends Controller
{
    /** Taille maximale d'un document envoyé au scan (10 Mo). */
    protected const SCAN_MAX_SIZE = 10 * 1024 * 1024;

    /** Extensions acceptées pour le scan → MIME réel attendu (finfo). */
    protected const SCAN_ALLOWED_MIMES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ];

    /** Taille maximale d'un texte de document collé (200 000 caractères). */
    protected const SCAN_MAX_TEXT_LENGTH = 200000;

    /**
     * Vérifie l'accès administrateur et renvoie l'utilisateur connecté.
     *
     * @return array<string,mixed>
     */
    protected function guard(): array
    {
        Middleware::requireRole([Auth::ROLE_SUPERADMIN, Auth::ROLE_ADMIN]);

        return Auth::user();
    }

    /**
     * Garde-fou du groupe « Système » (Utilisateurs, Paramètres, adhésions) :
     * rôle SUPERADMIN (Fondateur) = accès systématique ; ADMIN = accès si
     * listé dans SYSTEM_ADMINS (voir Permissions::isSystemAdmin()).
     *
     * @return array<string,mixed>
     */
    protected function guardSystem(): array
    {
        Middleware::requireRole([Auth::ROLE_SUPERADMIN, Auth::ROLE_ADMIN]);

        if (!Permissions::isSystemAdmin()) {
            Middleware::forbidden();
        }

        return Auth::user();
    }

    /**
     * Garde d'une page du groupe Système avec attribution individuelle :
     * Fondateur/ADMIN système = tout accès ; tout autre rôle du bureau =
     * uniquement si la page lui a été attribuée (users.extra_pages, géré
     * depuis Utilisateurs). Les élèves restent exclus.
     *
     * @return array<string,mixed>
     */
    protected function guardSystemOrPage(string $pageKey): array
    {
        Middleware::requireRole(Permissions::adminRoles());

        if (Permissions::isSystemAdmin()) {
            return Auth::user();
        }

        // Attribution individuelle (users.extra_pages) : au-delà du rôle.
        if (Permissions::userHasExtraPage($pageKey)) {
            return Auth::user();
        }

        Middleware::forbidden();
    }

    /**
     * Garde-fou par module : rôle dédié OU attribution individuelle via
     * Users → Pages + (users.extra_pages — mêmes clés que les modules,
     * voir Permissions::extraPages()).
     *
     * @return array<string,mixed>
     */
    protected function guardModule(string $module): array
    {
        if (Permissions::userHasExtraPage($module)) {
            // Attribution individuelle : tout rôle du bureau, élèves exclus.
            Middleware::requireRole(Permissions::adminRoles());

            return Auth::user();
        }

        Middleware::requireRole(Permissions::rolesForModule($module));

        return Auth::user();
    }

    /**
     * Garde-fou de l'espace d'administration (tableau de bord, wiki) :
     * tout rôle ayant accès à au moins un module.
     *
     * @return array<string,mixed>
     */
    protected function guardAdminArea(): array
    {
        Middleware::requireRole(Permissions::adminRoles());

        return Auth::user();
    }

    /**
     * Garde-fou spécifique au module comptabilité.
     *
     * Les routes /admin/compta/* sont accessibles aux rôles ADMIN et
     * TRESORERIE (voir Permissions) ; toutes les autres routes /admin/*
     * restent régies par guard() / guardModule().
     *
     * @return array<string,mixed>
     */
    protected function guardCompta(): array
    {
        return $this->guardModule(Permissions::MODULE_COMPTA);
    }

    /**
     * Journalise une action sensible (audit log).
     */
    protected function audit(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $details = null
    ): void {
        \App\Models\AuditLog::log($action, Auth::id(), $entityType, $entityId, $details);
    }

    /**
     * Rend une vue dans le layout admin.
     *
     * @param array<string,mixed> $data
     */
    protected function renderAdmin(string $view, array $data = []): void
    {
        $this->render($view, $data, 'admin');
    }

    // -----------------------------------------------------------------
    //  Scan de document fournisseur (facture METRO ou ticket de caisse)
    // -----------------------------------------------------------------

    /**
     * Point d'entrée partagé des scanneurs d'achat et de dépense :
     * extrait le texte d'un document (facture fournisseur ou ticket de
     * caisse) et renvoie l'« invoice » en JSON pour préremplir le
     * formulaire correspondant. Deux sources acceptées :
     *  - champ POST « text » : texte du document collé (200 000 caractères max) ;
     *  - upload multipart « file » : photo ou PDF (10 Mo max, extension ET
     *    MIME réels vérifiés), texte extrait par OCR (Tesseract/Poppler).
     *
     * Le document est interprété par InvoiceParser (aiguillage METRO /
     * ticket de caisse, clé kind dans la réponse). Réponse 200 :
     * {ok:true, source:'file'|'text', text:..., invoice:{...}} (contrat
     * partagé avec le frontend). Erreurs : 400 (requête invalide), 413
     * (trop volumineux), 500 (OCR indisponible ou en échec) sous forme
     * {ok:false, error:'message lisible'}.
     *
     * Lecture seule : rien n'est enregistré ici, le formulaire reste
     * modifiable avant validation.
     *
     * @param string $auditAction Action d'audit (« compta.purchase.scan »
     *                            ou « compta.expense.scan »).
     * @param string $entityType  Type d'entité audité (« purchase » ou
     *                            « expense »).
     */
    protected function handleInvoiceScan(string $auditAction, string $entityType): void
    {
        // Source 1 : texte collé directement (prioritaire sur le fichier).
        $text = (string) ($_POST['text'] ?? '');
        if (trim($text) !== '') {
            if (mb_strlen($text) > self::SCAN_MAX_TEXT_LENGTH) {
                $this->json(['ok' => false, 'error' => 'Texte trop long (200 000 caractères maximum).'], 400);
            }
            $source = 'text';
            $audit = [
                'source' => 'text',
                'taille' => strlen($text),
                'sha256' => hash('sha256', $text),
            ];
        } else {
            // Source 2 : fichier envoyé (photo/PDF du document).
            $file = $_FILES['file'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $this->json([
                    'ok'    => false,
                    'error' => 'Aucun document reçu : envoyez un fichier (champ « file ») ou du texte (champ « text »).',
                ], 400);
            }
            if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
                $this->json([
                    'ok'    => false,
                    'error' => "Échec de l'envoi du fichier (erreur " . (int) $file['error'] . ").",
                ], 400);
            }
            if ((int) ($file['size'] ?? 0) > self::SCAN_MAX_SIZE) {
                $this->json(['ok' => false, 'error' => 'Fichier trop volumineux (10 Mo maximum).'], 413);
            }

            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!isset(self::SCAN_ALLOWED_MIMES[$ext])) {
                $this->json([
                    'ok'    => false,
                    'error' => 'Format non accepté : image JPG, PNG, WEBP ou PDF attendu.',
                ], 400);
            }

            // Validation MIME réelle : l'extension déclarée n'est jamais une preuve.
            $mime = self::detectUploadMime((string) $file['tmp_name']);
            if ($mime !== self::SCAN_ALLOWED_MIMES[$ext]) {
                $this->json([
                    'ok'    => false,
                    'error' => 'Le contenu du fichier ne correspond pas à son extension (JPG, PNG, WEBP ou PDF attendu).',
                ], 400);
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
                $text = InvoiceOcr::extractText($tmpPath, $mime);
            } catch (\RuntimeException $e) {
                // Outil absent ou OCR en échec : message FR déjà lisible.
                $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
            }
            $source = 'file';
        }

        $invoice = InvoiceParser::parse($text);

        $audit['lignes_extraites'] = count($invoice['lines']);
        $audit['fournisseur'] = $invoice['supplier'];
        $audit['facture'] = $invoice['invoice_number'];
        $this->audit($auditAction, $entityType, null, $audit);

        $this->json([
            'ok'      => true,
            'source'  => $source,
            'text'    => $text,
            'invoice' => $invoice,
        ]);
    }

    /**
     * MIME réel d'un fichier envoyé, via finfo (extension Fileinfo), avec
     * repli mime_content_type — même logique que ReceiptStorage.
     */
    protected static function detectUploadMime(string $path): ?string
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
