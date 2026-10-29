<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Assistant\PublicAssistant;
use App\Core\Controller;

/**
 * Endpoint PUBLIC de l'assistant conversationnel (widget chat du site,
 * sans authentification) : POST /assistant/ask.
 *
 * Et son pendant KIOSQUE : POST /kiosque/assistant/ask/{token} — le
 * JETON du kiosque EST l'authentification (même pattern que les autres
 * POST /kiosque/* : exempté du CSRF de session par le routeur, jeton
 * imprévisible porté par l'URL).
 *
 * Ce contrôleur est volontairement MINCE : toute la logique (validation,
 * rate limit, sémaphore, appel LLM, journal) vit dans
 * App\Core\Assistant\PublicAssistant. Il n'expose AUCUNE donnée interne :
 * aucun modèle métier, aucun jeton d'outil, aucun outil — juste les
 * prompts app/config/assistant_public.php / assistant_kiosk.php et le
 * llama-server local.
 *
 * Sécurité :
 *  - CSRF standard du routeur pour POST /assistant/ask (le widget envoie
 *    le jeton de la page) ; les POST /kiosque/* en sont exemptés (le
 *    jeton du kiosque EST l'auth — cf. Router::dispatch) ;
 *  - rate limit 10 questions / 5 min / IP (public : par IP ; kiosque :
 *    clé DÉDIÉE IP+jeton) → 429 ;
 *  - sémaphore 2 appels LLM simultanés → 503 « occupé » ;
 *  - fail-closed : llama-server injoignable → 503 générique, détail en
 *    log serveur (Logger), jamais de stack trace ; kiosque inconnu →
 *    404 générique unique (aucune énumération possible) ;
 *  - réponses jamais mémorisables : Cache-Control: no-store sur TOUTES
 *    les réponses (200 comme 400/404/405/429/503).
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

    /**
     * POST /kiosque/assistant/ask/{token} — assistant des pages KIOSQUE,
     * le jeton du kiosque EST l'authentification (exempté de CSRF par le
     * routeur comme tout POST /kiosque/*). Même contrat que ask() :
     * question (string 1..500 après trim), history (optionnel : JSON 0..6
     * items {role, content}) → {"ok":true,"reply":"…"} ou
     * {"ok":false,"error":"…"} ; jeton inconnu → 404 « Kiosque
     * introuvable. » (générique, aucune énumération).
     */
    public function askKiosk(string $token): void
    {
        header('Cache-Control: no-store');

        $verdict = (new PublicAssistant())->traiterKiosk(
            $token,
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
     * GET /kiosque/assistant/ask/{token} → 405 (le chat kiosque ne
     * s'interroge qu'en POST ; le jeton n'est même pas regardé ici).
     */
    public function askKioskGet(string $token): void
    {
        header('Cache-Control: no-store');
        header('Allow: POST');

        $this->json(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
    }
}
