<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Compta\InvoiceScan;
use App\Core\Compta\ScanCatalogMatcher;
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
     * délégation au service partagé InvoiceScan::run() (texte collé ou
     * upload OCR — utilisé aussi par le livre comptable kiosque),
     * puis audit et réponse JSON côté admin. Réponse 200 :
     * {ok:true, source:'file'|'text', text:..., invoice:{...}} (contrat
     * partagé avec le frontend). Erreurs : 400 (requête invalide), 413
     * (trop volumineux), 500 (OCR indisponible ou en échec) sous forme
     * {ok:false, error:'message lisible'}.
     *
     * @param string $auditAction Action d'audit (« compta.purchase.scan »
     *                            ou « compta.expense.scan »).
     * @param string $entityType  Type d'entité audité (« purchase » ou
     *                            « expense »).
     */
    protected function handleInvoiceScan(string $auditAction, string $entityType): void
    {
        $res = InvoiceScan::run();
        if (($res['ok'] ?? false) !== true) {
            $this->json(['ok' => false, 'error' => (string) $res['error']], (int) $res['status']);
        }

        $invoice = $res['invoice'];

        // Produits reconnus dans le catalogue (fiches, alias de ventes,
        // clés SumUp) : chaque ligne reçoit « product » en plus du
        // libellé OCR brut « label » — les grilles de saisie
        // pré-remplissent alors la clé d'achat/stock. Panne de base :
        // enrichissement silencieusement sauté, le scan reste utilisable.
        $invoice['lines'] = ScanCatalogMatcher::enrichLines($invoice['lines']);

        $audit = $res['audit'];
        $audit['lignes_extraites'] = count($invoice['lines']);
        $audit['fournisseur'] = $invoice['supplier'];
        $audit['facture'] = $invoice['invoice_number'];
        $this->audit($auditAction, $entityType, null, $audit);

        $this->json([
            'ok'      => true,
            'source'  => $res['source'],
            'text'    => $res['text'],
            'invoice' => $invoice,
        ]);
    }
}
