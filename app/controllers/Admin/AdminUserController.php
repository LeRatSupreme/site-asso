<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserPolicy;

/**
 * Gestion des utilisateurs : rôles, activation.
 *
 * Règles de sécurité (§10.2) :
 *  - chaque promotion / rétrogradation est journalisée (audit log) ;
 *  - la protection du dernier administrateur interdit toute action qui
 *    réduirait à zéro le nombre d'ADMIN actifs (rétrogradation, désactivation).
 */
final class AdminUserController extends AdminBaseController
{
    public function index(): void
    {
        $this->guard();

        $this->renderAdmin('admin/users/index', [
            'title'     => 'Utilisateurs',
            'users'     => User::allForAdmin(),
            'currentId' => Auth::id(),
        ]);
    }

    /**
     * Change le rôle d'un utilisateur (promotion / rétrogradation).
     */
    public function changeRole(string $id): void
    {
        $this->guard();

        $target = User::find($id);
        if ($target === null) {
            $this->abort(404);
        }

        $newRole = (string) ($_POST['role'] ?? '');
        $oldRole = (string) $target['role'];

        if (!in_array($newRole, [Auth::ROLE_ADMIN, Auth::ROLE_TRESORERIE, Auth::ROLE_ELEVE], true)) {
            $this->setFlash('error', 'Rôle invalide.');
            redirect(url('/admin/users'));
        }

        // Protection du dernier admin : on ne quitte pas le rôle ADMIN
        // s'il s'agit du dernier administrateur actif.
        if (UserPolicy::demotionRemovesLastAdmin($oldRole, $newRole, User::countActiveAdmins())) {
            $this->setFlash('error', 'Impossible : c\'est le dernier administrateur actif.');
            redirect(url('/admin/users'));
        }

        User::setRole($id, $newRole);
        AuditLog::log('user.role_change', Auth::id(), 'user', $id, [
            'from' => $oldRole,
            'to'   => $newRole,
        ]);

        $this->setFlash('success', sprintf('Rôle de %s modifié (%s → %s).',
            e($target['prenom'] . ' ' . $target['nom']), $oldRole, $newRole));
        redirect(url('/admin/users'));
    }

    /**
     * Active ou désactive un compte.
     */
    public function toggleActive(string $id): void
    {
        $this->guard();

        $target = User::find($id);
        if ($target === null) {
            $this->abort(404);
        }

        $isActive = (int) $target['is_active'] === 1;

        // Protection du dernier admin : désactivation d'un admin actif bloquée
        // s'il est le dernier.
        if (UserPolicy::deactivationRemovesLastAdmin((string) $target['role'], $isActive, User::countActiveAdmins())) {
            $this->setFlash('error', 'Impossible : c\'est le dernier administrateur actif.');
            redirect(url('/admin/users'));
        }

        User::setActive($id, !$isActive);
        AuditLog::log($isActive ? 'user.deactivate' : 'user.activate', Auth::id(), 'user', $id);

        $this->setFlash('success', sprintf('Compte de %s %s.',
            e($target['prenom'] . ' ' . $target['nom']),
            $isActive ? 'désactivé' : 'activé'));
        redirect(url('/admin/users'));
    }
}
