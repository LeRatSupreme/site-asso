<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Assistant\PublicAssistant;
use App\Core\Controller;

/**
 * Endpoint PUBLIC de l'assistant conversationnel (widget chat du site,
 * sans authentification) : POST /assistant/ask.
 *
 * Ce contrôleur est volontairement MINCE : toute la logique (validation,
 * rate limit, sémaphore, appel LLM, journal) vit dans
 * App\Core\Assistant\PublicAssistant. Il n'expose AUCUNE donnée interne :
 * aucun modèle métier, aucun jeton, aucun outil — juste le prompt public
 * app/config/assistant_public.php et le llama-server local.
 *
 * Sécurité :
 *  - CSRF standard du routeur (tout POST hors /kiosque/ — cf.
 *    Router::dispatch) : le widget envoie le jeton de la page ;
 *  - rate limit 10 questions / 5 min / IP → 429 ;
 *  - sémaphore 2 appels LLM simultanés → 503 « occupé » ;
 *  - fail-closed : llama-server injoignable → 503 générique, détail en
 *    log serveur (Logger), jamais de stack trace ;
 *  - réponses jamais mémorisables : Cache-Control: no-store sur TOUTES
 *    les réponses (200 comme 400/405/429/503).
 */
final class AssistantPublicController extends Controller
{
    /**
     * POST /assistant/ask — form-urlencoded :
     *   question (string 1..500 après trim)
     *   history  (optionnel : JSON 0..6 items {role, content})
     * Réponse : {"ok":true,"reply":"…"} ou {"ok":false,"error":"…"}.
     */
    public function ask(): void
    {
        header('Cache-Control: no-store');

        $verdict = (new PublicAssistant())->traiter(
            $_POST['question'] ?? null,
            $_POST['history'] ?? null,
            client_ip()
        );

        if ($verdict['ok']) {
            $this->json(['ok' => true, 'reply' => (string) $verdict['reply']], $verdict['http']);
        }

        $this->json(['ok' => false, 'error' => (string) $verdict['error']], $verdict['http']);
    }

    /**
     * GET /assistant/ask → 405 (le chat ne s'interroge qu'en POST).
     */
    public function askGet(): void
    {
        header('Cache-Control: no-store');
        header('Allow: POST');

        $this->json(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
    }
}
