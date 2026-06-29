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

    /**
     * Supprime un compte (droit à l'effacement RGPD).
     *
     * Garde-fous :
     *  - impossible de supprimer son propre compte depuis ici ( passer par
     *    « Mon compte » /account/privacy) ;
     *  - impossible de supprimer le dernier administrateur actif ;
     *  - les commandes (comptabilité) sont conservées mais anonymisées
     *    (ON DELETE SET NULL).
     */
    public function delete(string $id): void
    {
        $this->guard();

        $target = User::find($id);
        if ($target === null) {
            $this->abort(404);
        }

        if ($id === Auth::id()) {
            $this->setFlash('error', 'Vous ne pouvez pas supprimer votre propre compte depuis ici.');
            redirect(url('/admin/users'));
        }

        if ((string) $target['role'] === Auth::ROLE_ADMIN && User::countActiveAdmins() <= 1) {
            $this->setFlash('error', 'Impossible : c\'est le dernier administrateur actif.');
            redirect(url('/admin/users'));
        }

        User::delete($id);
        AuditLog::log('user.delete', Auth::id(), 'user', $id, [
            'email' => $target['email'] ?? null,
            'role'  => (string) $target['role'],
        ]);

        $this->setFlash('success', sprintf('Compte de %s supprimé.', e($target['prenom'] . ' ' . $target['nom'])));
        redirect(url('/admin/users'));
    }
}
