<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Notification;

/**
 * API des notifications in-app (connexion requise).
 */
final class NotificationController extends Controller
{
    /**
     * GET /api/notifications — liste des notifications de l'utilisateur.
     */
    public function index(): void
    {
        if (!Auth::check()) {
            $this->json(['error' => 'Non authentifié'], 401);
        }

        $userId = (string) Auth::id();
        $items = Notification::forUser($userId);

        $this->json([
            'count' => Notification::unreadCount($userId),
            'items' => $items,
        ]);
    }

    /**
     * POST /api/notifications/read-all — marque toutes les notifications comme lues.
     */
    public function readAll(): void
    {
        if (!Auth::check()) {
            $this->json(['error' => 'Non authentifié'], 401);
        }

        Notification::markAllAsRead((string) Auth::id());

        $this->json(['ok' => true]);
    }
}
