<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Mailer;
use App\Core\Permissions;
use App\Models\AuditLog;
use App\Models\Membership;
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
        $this->guardSystem();

        $this->renderAdmin('admin/users/index', [
            'title'        => 'Utilisateurs',
            'users'        => User::allForAdmin(),
            'currentId'    => Auth::id(),
            'memberIds'    => Membership::paidUserIds(Membership::currentSeason()),
            'currentSeason'=> Membership::currentSeason(),
        ]);
    }

    /**
     * Change le rôle d'un utilisateur (promotion / rétrogradation).
     */
    public function changeRole(string $id): void
    {
        $this->guardSystem();

        $target = User::find($id);
        if ($target === null) {
            $this->abort(404);
        }

        $newRole = (string) ($_POST['role'] ?? '');
        $oldRole = (string) $target['role'];

        if (!array_key_exists($newRole, Permissions::roles())) {
            $this->setFlash('error', 'Rôle invalide.');
            redirect(url('/admin/users'));
        }

        // Hiérarchie : le rôle SUPERADMIN (Fondateur) n'est JAMAIS attribuable
        // ni retirable depuis le site — uniquement directement en base (SQL).
        if ($oldRole === Auth::ROLE_SUPERADMIN || $newRole === Auth::ROLE_SUPERADMIN) {
            $this->setFlash('error', 'Le rôle Fondateur ne peut pas être modifié depuis le site.');
            redirect(url('/admin/users'));
        }

        // Le rôle TRÉSORERIE est géré par le Fondateur seul : un ADMIN ne
        // peut ni l'attribuer, ni retirer le rôle d'un trésorier.
        if (Auth::role() !== Auth::ROLE_SUPERADMIN
            && ($oldRole === Auth::ROLE_TRESORERIE || $newRole === Auth::ROLE_TRESORERIE)) {
            $this->setFlash('error', 'Le rôle Trésorerie est géré par le Fondateur.');
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
        $this->guardSystem();

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
     * Réinitialise le mot de passe d'un compte avec un mot de passe temporaire.
     *
     * Génère un mot de passe temporaire aléatoire (≥ 8 caractères, lettre +
     * chiffre), le hash et le stocke sur le compte. Le mot de passe temporaire
     * est affiché dans le flash (à communiquer hors-bande à l'utilisateur) et
     * l'action est journalisée (audit log).
     */
    public function resetPassword(string $id): void
    {
        $this->guardSystem();

        $target = User::find($id);
        if ($target === null) {
            $this->abort(404);
        }

        $temporary = self::generateTemporaryPassword();
        User::changePassword($id, $temporary);

        // Envoi de l'email avec le mot de passe temporaire à l'utilisateur.
        $email = (string) ($target['email'] ?? '');
        $prenom = (string) ($target['prenom'] ?? '');
        if ($email !== '') {
            try {
                Mailer::send('admin_password_reset', $email, 'Votre nouveau mot de passe — AEIC', [
                    'prenom'   => $prenom,
                    'password' => $temporary,
                ]);
            } catch (\Throwable) {
                // Non bloquant : le mot de passe reste affiché dans le flash.
            }
        }

        AuditLog::log('user.password_reset', Auth::id(), 'user', $id, [
            'email' => $target['email'] ?? null,
            'role'  => (string) $target['role'],
        ]);

        if (APP_ENV !== 'prod') {
            // Hors production uniquement : le mot de passe temporaire est
            // affiché dans le flash pour faciliter les tests.
            $this->setFlash('success', sprintf(
                'Mot de passe temporaire pour %s : %s — Un email a été envoyé à %s. Il devra le changer rapidement.',
                e(trim((string) $target['prenom'] . ' ' . (string) $target['nom'])),
                e($temporary),
                e($email !== '' ? $email : '(pas d\'email)')
            ));
        } else {
            // En production, aucun secret dans le flash : le mot de passe
            // temporaire transite uniquement par e-mail.
            $this->setFlash('success', 'Le mot de passe a été envoyé par e-mail.');
        }
        redirect(url('/admin/users'));
    }

    /**
     * Génère un mot de passe temporaire aléatoire (10 caractères, alphabet
     * sans ambiguïté, au moins une lettre et un chiffre).
     */
    private static function generateTemporaryPassword(): string
    {
        $letters = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits  = '23456789';

        // Garantie de robustesse minimale (lettre + chiffre).
        $password = $letters[random_int(0, strlen($letters) - 1)]
            . $digits[random_int(0, strlen($digits) - 1)];

        $pool = $letters . $digits;
        for ($i = 0; $i < 8; $i++) {
            $password .= $pool[random_int(0, strlen($pool) - 1)];
        }

        return str_shuffle($password);
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
        $this->guardSystem();

        $target = User::find($id);
        if ($target === null) {
            $this->abort(404);
        }

        if ($id === Auth::id()) {
            $this->setFlash('error', 'Vous ne pouvez pas supprimer votre propre compte depuis ici.');
            redirect(url('/admin/users'));
        }

        if (in_array((string) $target['role'], [Auth::ROLE_SUPERADMIN, Auth::ROLE_ADMIN], true)
            && User::countActiveAdmins() <= 1) {
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
