<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Envoi de messages vers un salon Discord via Webhook.
 *
 * Configuration en base (settings, group "social") :
 *  - discord_webhook_url : URL du webhook.
 *  - discord_enabled     : "1" pour activer l'envoi.
 *
 * Toutes les méthodes sont non bloquantes : en cas d'échec réseau ou de
 * webhook désactivé, elles renvoient false sans lever d'exception.
 */
final class Discord
{
    /**
     * Envoie un message (texte + embed optionnel) au webhook Discord.
     *
     * @param array<string,mixed>|null $embed
     */
    public static function send(string $message, ?array $embed = null): bool
    {
        $url     = Setting::get('discord_webhook_url', '');
        $enabled = Setting::get('discord_enabled', '0') === '1';

        if (!$enabled || $url === '') {
            return false;
        }

        $payload = ['content' => $message];
        if ($embed !== null) {
            $payload['embeds'] = [$embed];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);

        try {
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } catch (\Throwable) {
            $code = 0;
        }

        curl_close($ch);

        return $code >= 200 && $code < 300;
    }

    /**
     * Annonce un nouvel événement (embed teal avec date + lieu).
     */
    public static function notifyEvent(string $title, string $date, string $location, string $url): bool
    {
        $description = "📅 " . $date;
        if (trim($location) !== '') {
            $description .= "\n📍 " . $location;
        }

        return self::send("📅 Nouvel événement AEIC !", [
            'title'       => $title,
            'description' => $description,
            'url'         => $url,
            'color'       => 4760557, // teal
            'footer'      => ['text' => 'AEIC — Calais'],
        ]);
    }

    /**
     * Annonce un nouveau sondage (embed violet).
     */
    public static function notifyPoll(string $title, string $url): bool
    {
        return self::send("📊 Nouveau sondage AEIC !", [
            'title'  => $title,
            'url'    => $url,
            'color'  => 6378714, // violet
            'footer' => ['text' => 'AEIC — Calais'],
        ]);
    }
}
