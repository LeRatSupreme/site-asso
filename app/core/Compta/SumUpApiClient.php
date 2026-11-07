<?php

declare(strict_types=1);

namespace App\Core\Compta;

use RuntimeException;

/**
 * Client minimal de l'API SumUp (lecture seule) pour la synchro des ventes.
 *
 * Endpoints utilisés (auth Bearer par clé API) :
 *   - GET /v0.1/me                                     (découverte du code marchand)
 *   - GET /v2.1/merchants/{code}/transactions/history  (ventes récentes)
 *   - GET /v2.1/merchants/{code}/transactions?id=…     (détail : produits, heure locale)
 *
 * Le transport HTTP est injectable (callable string $url : array) afin de
 * tester la logique sans réseau, selon la convention du projet (aucun socket
 * dans les tests unitaires).
 */
final class SumUpApiClient
{
    private const API_BASE = 'https://api.sumup.com';

    /** @var callable(string):array<string,mixed> */
    private $transport;

    private ?string $merchantCode;

    /**
     * @param string        $apiKey       Clé API SumUp (sup_sk_…).
     * @param string|null   $merchantCode Code marchand ; découvert via /me si null/vide.
     * @param callable|null $transport    function(string $url): array qui renvoie
     *                                    le JSON décodé ; lève RuntimeException
     *                                    en cas d'erreur HTTP. Par défaut : cURL.
     */
    public function __construct(
        private readonly string $apiKey,
        ?string $merchantCode = null,
        ?callable $transport = null
    ) {
        $this->merchantCode = $merchantCode !== null && $merchantCode !== '' ? $merchantCode : null;

        if ($transport !== null) {
            $this->transport = $transport;

            return;
        }

        $apiKey = $this->apiKey;
        $this->transport = static function (string $url) use ($apiKey): array {
            return self::request($apiKey, $url);
        };
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Code marchand SumUp (paramètre de chemin des endpoints v2.1).
     * Découvert automatiquement via /v0.1/me si non fourni au constructeur.
     */
    public function merchantCode(): string
    {
        if ($this->merchantCode !== null) {
            return $this->merchantCode;
        }

        $me = ($this->transport)(self::API_BASE . '/v0.1/me');
        $code = (string) ($me['merchant_profile']['merchant_code'] ?? '');
        if ($code === '') {
            throw new RuntimeException('Code marchand SumUp introuvable dans /v0.1/me.');
        }

        return $this->merchantCode = $code;
    }

    /**
     * Transactions de type PAYMENT créées depuis $oldestTime (inclus).
     *
     * Renvoie la liste « items » de l'API, pages suivantes incluses
     * (pagination via links[rel=next], plafonnée à $maxPages appels).
     *
     * @return list<array<string,mixed>>
     */
    public function history(\DateTimeImmutable $oldestTime, int $limit = 200, int $maxPages = 3): array
    {
        $limit = max(1, min(200, $limit));
        $endpoint = self::API_BASE . '/v2.1/merchants/' . rawurlencode($this->merchantCode()) . '/transactions/history';
        $query = 'order=descending&limit=' . $limit
            . '&oldest_time=' . rawurlencode($oldestTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'))
            . '&types%5B%5D=PAYMENT';

        $items = [];
        $url = $endpoint . '?' . $query;

        for ($page = 0; $page < $maxPages; $page++) {
            $payload = ($this->transport)($url);

            /** @var list<array<string,mixed>> $pageItems */
            $pageItems = $payload['items'] ?? [];
            $items = array_merge($items, $pageItems);

            $next = null;
            foreach ($payload['links'] ?? [] as $link) {
                if (($link['rel'] ?? '') === 'next') {
                    $next = (string) ($link['href'] ?? '');

                    break;
                }
            }

            if ($next === null || $next === '' || $pageItems === []) {
                break;
            }

            $url = str_starts_with($next, 'http') ? $next : $endpoint . '?' . $next;
        }

        return $items;
    }

    /**
     * Détail complet d'une transaction (produits du panier, heure locale…).
     * Renvoie null si la transaction est introuvable, illisible ou en erreur.
     *
     * @return array<string,mixed>|null
     */
    public function detail(string $transactionId): ?array
    {
        $url = self::API_BASE . '/v2.1/merchants/' . rawurlencode($this->merchantCode())
            . '/transactions?id=' . rawurlencode($transactionId);

        try {
            $payload = ($this->transport)($url);
        } catch (RuntimeException) {
            return null;
        }

        return is_array($payload) && ($payload['id'] ?? null) !== null ? $payload : null;
    }

    /**
     * Requête GET authentifiée (transport cURL par défaut).
     *
     * @return array<string,mixed>
     *
     * @throws RuntimeException en cas d'erreur réseau ou de statut non-2xx.
     */
    private static function request(string $apiKey, string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURL : initialisation impossible.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('cURL : ' . ($error !== '' ? $error : 'erreur réseau'));
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse SumUp illisible (HTTP ' . $status . ').');
        }

        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['message'] ?? $decoded['detail'] ?? 'erreur HTTP ' . $status);
            throw new RuntimeException('API SumUp (' . $status . ') : ' . $message);
        }

        return $decoded;
    }
}
