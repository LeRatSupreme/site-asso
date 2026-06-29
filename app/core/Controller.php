<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Contrôleur de base.
 *
 * Fournit le rendu de vues avec layout, la sérialisation JSON et
 * la gestion des messages flash (via $_SESSION).
 */
abstract class Controller
{
    /**
     * Rend une vue dans le layout public et termine le script.
     *
     * @param array<string,mixed> $data Variables injectées dans la vue.
     */
    protected function render(string $view, array $data = []): void
    {
        $viewFile = AEIC_VIEWS . '/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new \RuntimeException(sprintf('Vue introuvable : %s', $view));
        }

        extract($data, EXTR_SKIP);

        // Le contenu de la vue est capturé puis injecté dans le layout.
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        $layoutPath = AEIC_VIEWS . '/layouts/public.php';
        if (!is_file($layoutPath)) {
            throw new \RuntimeException('Layout public introuvable.');
        }

        require $layoutPath;
    }

    /**
     * Renvoie une réponse JSON et termine le script.
     *
     * @param array<string,mixed>|list<mixed> $data
     */
    protected function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    /**
     * Stocke un message flash pour la prochaine requête.
     *
     * @param 'success'|'error'|'info'|'warning' $type
     */
    protected function setFlash(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /**
     * Récupère (et consomme) les messages flash courants.
     *
     * @return list<array{type:string,message:string}>
     */
    public static function getFlash(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $flash;
    }

    /**
     * Redirige via une méthode utilitaire globale et termine le script.
     */
    protected function redirect(string $path): void
    {
        redirect($path);
    }

    /**
     * Renvoie une page d'erreur avec le bon code HTTP.
     */
    protected function abort(int $code): void
    {
        http_response_code($code);

        $title = 'Erreur ' . $code . ' — AEIC';
        $description = '';

        $view = AEIC_VIEWS . '/errors/' . $code . '.php';
        if (!is_file($view)) {
            $view = AEIC_VIEWS . '/errors/404.php';
        }

        if (is_file($view)) {
            extract(['code' => $code, 'title' => $title, 'description' => $description], EXTR_SKIP);
            ob_start();
            require $view;
            $content = (string) ob_get_clean();
            require AEIC_VIEWS . '/layouts/public.php';
        }

        exit;
    }
}
