<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Mailer;
use App\Core\Middleware;
use App\Models\Consent;
use App\Models\User;

/**
 * Exercice des droits RGPD depuis l'espace membre.
 *
 * - Droit à la portabilité : export JSON des données de l'utilisateur.
 * - Droit à l'effacement : anonymisation du compte (les données comptables
 *   obligatoires sont conservées mais déliées de l'identité).
 */
final class AccountController extends Controller
{
    /**
     * Page « mes données » (RGPD) : présente les droits et actions.
     */
    public function privacy(): void
    {
        Middleware::requireLogin();

        $user = Auth::user();

        $this->render('account/privacy', [
            'title'       => 'Mes données — AEIC',
            'description' => 'Exercer vos droits RGPD : exporter ou supprimer vos données.',
            'user'        => $user,
        ]);
    }

    /**
     * Changement de mot de passe depuis l'espace membre.
     */
    public function changePassword(): void
    {
        Middleware::requireLogin();

        $user = Auth::user();
        $userId = (string) $user['id'];

        $oldPassword = (string) ($_POST['old_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['new_password_confirmation'] ?? '');

        // Vérifier l'ancien mot de passe.
        if (!password_verify($oldPassword, (string) ($user['password'] ?? ''))) {
            $this->setFlash('error', 'Votre mot de passe actuel est incorrect.');
            redirect(url('/account/privacy'));
        }

        // Valider le nouveau mot de passe.
        if (strlen($newPassword) < 8) {
            $this->setFlash('error', 'Le nouveau mot de passe doit faire au moins 8 caractères.');
            redirect(url('/account/privacy'));
        }
        if (!preg_match('/[a-zA-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            $this->setFlash('error', 'Le mot de passe doit contenir au moins une lettre et un chiffre.');
            redirect(url('/account/privacy'));
        }
        if ($newPassword !== $confirmPassword) {
            $this->setFlash('error', 'La confirmation ne correspond pas au nouveau mot de passe.');
            redirect(url('/account/privacy'));
        }

        // Mettre à jour.
        User::changePassword($userId, $newPassword);

        // Envoyer l'email de confirmation.
        $email = (string) ($user['email'] ?? '');
        $prenom = (string) ($user['prenom'] ?? '');
        if ($email !== '') {
            try {
                Mailer::send('password_changed', $email, 'Votre mot de passe a été modifié — AEIC', [
                    'prenom' => $prenom,
                ]);
            } catch (\Throwable) {
                // Non bloquant.
            }
        }

        $this->setFlash('success', 'Votre mot de passe a été modifié. Un email de confirmation vous a été envoyé.');
        redirect(url('/account/privacy'));
    }

    /**
     * Export JSON de toutes les données de l'utilisateur connecté (portabilité).
     */
    public function export(): void
    {
        Middleware::requireLogin();

        $user = Auth::user();
        $userId = (string) $user['id'];

        $data = [
            'utilisateur' => $this->publicFields($user),
            'consentements' => Consent::forUser($userId),
            'inscriptions_evenements' => $this->registrations($userId),
            'commandes_cafeteria' => $this->orders($userId),
            'exporte_le' => date('c'),
        ];

        $filename = 'mes-donnees-aeic-' . date('Y-m-d') . '.json';

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    /**
     * Formulaire de confirmation de suppression du compte.
     */
    public function deleteConfirm(): void
    {
        Middleware::requireLogin();

        $user = Auth::user();

        $this->render('account/delete', [
            'title'       => 'Supprimer mon compte — AEIC',
            'description' => 'Anonymisation de vos données personnelles (RGPD).',
            'user'        => $user,
        ]);
    }

    /**
     * Traitement de la suppression : notifie l'utilisateur puis anonymise le
     * compte et déconnecte.
     */
    public function delete(): void
    {
        Middleware::requireLogin();

        $user = Auth::user();
        $userId = (string) $user['id'];

        // Capture des données avant anonymisation (pour la notification).
        $email = (string) ($user['email'] ?? '');
        $prenom = (string) ($user['prenom'] ?? '');

        // Journalisation du retrait de consentement (avant anonymisation).
        Consent::log('account_deletion', true, 'cgu-v1', [
            'user_id'    => $userId,
            'email'      => $user['email'] ?? null,
            'ip_address' => client_ip(),
            'user_agent' => user_agent(),
        ]);

        // E-mail de confirmation RGPD (envoyé AVANT l'anonymisation, car
        // l'adresse réelle sera détruite juste après). Non bloquant.
        if ($email !== '') {
            try {
                Mailer::send('account_deleted', $email, 'Votre compte a été supprimé — AEIC', [
                    'prenom' => $prenom,
                ]);
            } catch (\Throwable) {
                // L'échec d'envoi ne doit pas empêcher la suppression.
            }
        }

        // Anonymisation (commandes conservées mais déliées de l'identité).
        User::anonymize($userId);

        Auth::logout();

        $this->setFlash('success', 'Votre compte a été supprimé. Vos données personnelles ont été effacées.');
        redirect(url('/'));
    }

    /**
     * Ne conserve que les champs personnels non sensibles de l'utilisateur.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function publicFields(array $user): array
    {
        return [
            'prenom'     => $user['prenom'] ?? null,
            'nom'        => $user['nom'] ?? null,
            'email'      => $user['email'] ?? null,
            'role'       => $user['role'] ?? null,
            'image'      => $user['image'] ?? null,
            'cree_le'    => $user['created_at'] ?? null,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function registrations(string $userId): array
    {
        try {
            $stmt = db()->prepare(
                'SELECT e.title, e.slug, e.date, r.created_at
                 FROM event_registrations r
                 INNER JOIN events e ON e.id = r.event_id
                 WHERE r.user_id = ?
                 ORDER BY r.created_at DESC'
            );
            $stmt->execute([$userId]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function orders(string $userId): array
    {
        try {
            $stmt = db()->prepare(
                'SELECT o.id, o.status, o.total, o.created_at
                 FROM cafeteria_orders o
                 WHERE o.user_id = ?
                 ORDER BY o.created_at DESC'
            );
            $stmt->execute([$userId]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }
}
