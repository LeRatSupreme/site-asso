<?php

declare(strict_types=1);

/**
 * Fonctions utilitaires globales de l'application AEIC.
 *
 * Ces fonctions sont chargées via l'autoloader Composer (clé "files").
 * Elles sont volontairement simples et sans dépendance.
 */

/**
 * Signal de redirection levé par redirect() en mode APP_TESTING à la place
 * de exit(), afin que le harness d'intégration puisse capturer l'en-tête
 * `Location` (voir tests/Integration/runner.php).
 */
final class RedirectSignal extends \RuntimeException
{
}

/**
 * Échappe une valeur pour un affichage HTML sûr (anti-XSS).
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Formate une date selon un motif ICU/strftime-compatible simple.
 *
 * Accepte une chaîne parsable (Y-m-d H:i:s, etc.) ou un objet DateTimeInterface.
 */
function formatDate(string|DateTimeInterface|null $date, string $fmt = 'd/m/Y'): string
{
    if ($date === null || $date === '') {
        return '';
    }

    if (!$date instanceof DateTimeInterface) {
        try {
            $date = new DateTimeImmutable($date);
        } catch (Throwable) {
            return '';
        }
    }

    return $date->format($fmt);
}

/**
 * Formate une date + heure au format français (d/m/Y H:i).
 */
function formatDateTime(string|DateTimeInterface|null $date): string
{
    return formatDate($date, 'd/m/Y H:i');
}

/**
 * Formate un nombre en prix euros français (2 décimales, virgule, suffixe " €").
 *
 * @param float|int|string|null $n
 */
function formatPrice(float|int|string|null $n): string
{
    if ($n === null || $n === '') {
        return '0,00 €';
    }

    $value = (float) $n;

    return number_format($value, 2, ',', ' ') . ' €';
}

/**
 * Parse un nombre "à la française" (virgule décimale) en float.
 *
 * Gère "1,75", "1.75", "1 234,5", "0,99".
 */
function parseFrenchFloat(string $s): float
{
    $s = trim($s);
    if ($s === '') {
        return 0.0;
    }

    // Supprime les espaces fins et insécables utilisés comme séparateurs de milliers.
    $s = str_replace(["\u{00a0}", "\u{202f}", ' '], '', $s);
    // Remplace la virgule décimale par un point.
    $s = str_replace(',', '.', $s);

    // S'il reste plusieurs points, on ne garde que le dernier comme séparateur décimal.
    $parts = explode('.', $s);
    if (count($parts) > 2) {
        $integerPart = implode('', array_slice($parts, 0, -1));
        $s = $integerPart . '.' . end($parts);
    }

    return (float) $s;
}

/**
 * Redirige vers une URL et termine le script.
 *
 * En mode test (APP_TESTING), on dépile la pile d'exécution en levant une
 * exception dédiée plutôt qu'en appelant exit() : cela permet au harness
 * d'intégration (tests/Integration/runner.php) de capturer l'en-tête
 * `Location` via headers_list() tout en interrompant le contrôleur comme
 * le ferait un exit() en production.
 */
function redirect(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url);
    }

    if (defined('APP_TESTING') && APP_TESTING) {
        throw new RedirectSignal();
    }

    exit;
}

/**
 * Construit l'URL d'un asset (préfixe APP_URL + /assets).
 */
function asset(string $path): string
{
    $path = '/' . ltrim($path, '/');

    return APP_URL . '/assets' . $path;
}

/**
 * Construit l'URL d'un asset en ajoutant un paramètre de cache-bust
 * (?v=filemtime) pour forcer le rechargement navigateur à chaque modification.
 */
function assetVersioned(string $path): string
{
    $url = asset($path);
    $file = AEIC_PUBLIC . '/assets' . '/' . ltrim($path, '/');
    if (is_file($file)) {
        $url .= '?v=' . filemtime($file);
    }

    return $url;
}

/**
 * Idem pour les assets servis depuis / (hors /assets), avec cache-bust.
 */
function rootAssetVersioned(string $path): string
{
    $path = '/' . ltrim($path, '/');
    $file = AEIC_PUBLIC . $path;
    $url = APP_URL . $path;
    if (is_file($file)) {
        $url .= '?v=' . filemtime($file);
    }

    return $url;
}

/**
 * Construit une URL absolue à partir d'un chemin relatif.
 */
function url(string $path = ''): string
{
    if ($path === '') {
        return APP_URL;
    }

    return APP_URL . '/' . ltrim($path, '/');
}

/**
 * Génère (et stocke en session) le token CSRF courant.
 * Le même token est renvoyé jusqu'à expiration de la session.
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

/**
 * Rend un champ caché contenant le token CSRF, à insérer dans tout formulaire POST.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Récupère une ancienne valeur de formulaire (depuis $_SESSION['_old'] ou $_POST).
 */
function old(string $key, mixed $default = ''): mixed
{
    if (isset($_SESSION['_old'][$key])) {
        $value = $_SESSION['_old'][$key];
        unset($_SESSION['_old'][$key]);

        return $value;
    }

    return $_POST[$key] ?? $default;
}

/**
 * Vérifie qu'une chaîne est une URL absolue (http/https).
 */
function is_absolute_url(string $url): bool
{
    return preg_match('#^https?://#i', $url) === 1;
}

/**
 * Renvoie l'initiale d'un prénom (utilisé pour les avatars sans photo).
 */
function initial(?string $name): string
{
    $name = trim((string) $name);

    return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
}

/**
 * Résout (sans base de données) la priorité du lien de paiement SumUp :
 * lien spécifique à l'événement > lien par défaut > null.
 *
 * Fonction pure isolée afin d'être testée unitairement sans base.
 */
function sumup_resolve_link(?string $eventLink, ?string $defaultLink): ?string
{
    $eventLink = trim((string) $eventLink);
    if ($eventLink !== '') {
        return $eventLink;
    }

    $defaultLink = trim((string) $defaultLink);

    return $defaultLink !== '' ? $defaultLink : null;
}

/**
 * Renvoie le lien de paiement SumUp applicable (lien événement sinon défaut).
 *
 * Priorité : lien spécifique à l'événement > setting « sumup_default_link » > null.
 */
function sumup_link(?string $eventLink = null): ?string
{
    return sumup_resolve_link($eventLink, \App\Models\Setting::get('sumup_default_link', ''));
}

/**
 * Indique si les paiements par lien SumUp sont activés (setting « sumup_enabled »).
 */
function sumup_enabled(): bool
{
    return \App\Models\Setting::getBool('sumup_enabled', false);
}

/**
 * Renvoie l'adresse IP du client (premier de la chaîne X-Forwarded-For, sinon REMOTE_ADDR).
 */
function client_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $first = trim(explode(',', $forwarded)[0]);
        if ($first !== '') {
            return $first;
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Renvoie le user-agent du client (tronqué).
 */
function user_agent(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    return mb_substr($ua, 0, 255);
}

/**
 * Indique si l'utilisateur courant est à jour de cotisation (saison en cours).
 */
function is_member(): bool
{
    if (!\App\Core\Auth::check()) {
        return false;
    }

    return \App\Models\Membership::isMember((string) \App\Core\Auth::id());
}

// ---------------------------------------------------------------------
//  Fonctionnalité 12 — Système de traduction multilingue (7 langues).
// ---------------------------------------------------------------------

/**
 * Langues officiellement supportées par le site.
 *
 * @return list<string>
 */
function available_langs(): array
{
    return ['fr', 'en', 'de', 'es', 'zh', 'ja', 'pl'];
}

/**
 * Drapeau (emoji) associé à un code de langue.
 */
function lang_flag(string $lang): string
{
    $flags = [
        'fr' => '🇫🇷',
        'en' => '🇬🇧',
        'de' => '🇩🇪',
        'es' => '🇪🇸',
        'zh' => '🇨🇳',
        'ja' => '🇯🇵',
        'pl' => '🇵🇱',
    ];

    return $flags[$lang] ?? '🌐';
}

/**
 * Code de locale BCP-47 associé à une langue (balises <html lang>, og:locale).
 */
function lang_locale(string $lang): string
{
    $locales = [
        'fr' => 'fr_FR',
        'en' => 'en_US',
        'de' => 'de_DE',
        'es' => 'es_ES',
        'zh' => 'zh_CN',
        'ja' => 'ja_JP',
        'pl' => 'pl_PL',
    ];

    return $locales[$lang] ?? 'fr_FR';
}

/**
 * Langue courante (préférence stockée en cookie `aeic_lang`), par défaut "fr".
 */
function current_lang(): string
{
    $lang = strtolower((string) ($_COOKIE['aeic_lang'] ?? 'fr'));
    if (in_array($lang, available_langs(), true)) {
        return $lang;
    }

    return 'fr';
}

/**
 * Traduit une clé depuis le catalogue app/translations/messages.php.
 *
 * Renvoie, dans l'ordre : la traduction dans la langue courante, sinon la
 * traduction française (référence), sinon le fallback fourni, sinon la clé.
 *
 * Les éventuels marqueurs `{n}`, `{a}`... de la chaîne peuvent être remplacés
 * via tt() (ou manuellement par strtr()).
 */
function t(string $key, ?string $fallback = null): string
{
    static $messages = null;
    if ($messages === null) {
        $messages = require AEIC_ROOT . '/app/translations/messages.php';
    }

    $row = $messages[$key] ?? null;
    if (is_array($row)) {
        $lang = current_lang();
        if (isset($row[$lang]) && $row[$lang] !== '') {
            return (string) $row[$lang];
        }
        if (isset($row['fr'])) {
            return (string) $row['fr'];
        }
    }

    return $fallback ?? $key;
}

/**
 * Variante de t() qui remplace des marqueurs {clé} par des valeurs.
 *
 * Exemple : tt('poll.voters', ['{n}' => 42]).
 *
 * @param array<string,scalar> $vars
 */
function tt(string $key, array $vars, ?string $fallback = null): string
{
    $text = t($key, $fallback);
    if ($vars === []) {
        return $text;
    }

    $replacements = [];
    foreach ($vars as $k => $v) {
        $replacements[(string) $k] = (string) $v;
    }

    return strtr($text, $replacements);
}

/**
 * Traduit un contenu dynamique stocké en français en base (titre, extrait,
 * lieu d'événement, setting...).
 *
 * Contrairement à t() qui travaille par clé symbolique, tc() prend en entrée
 * le texte français EXACT et le résout via l'overlay app/translations/content.php.
 *
 * Comportement :
 *  - langue courante == fr : renvoie le texte tel quel ;
 *  - traduction connue pour la langue courante : la renvoie ;
 *  - sinon : renvoie le texte français (fallback safe, ne casse rien).
 */
function tc(string $frenchText): string
{
    if ($frenchText === '') {
        return '';
    }

    static $content = null;
    if ($content === null) {
        $content = require AEIC_ROOT . '/app/translations/content.php';
    }

    $lang = current_lang();
    if ($lang === 'fr') {
        return $frenchText;
    }

    $key = trim($frenchText);
    if (isset($content[$key][$lang]) && $content[$key][$lang] !== '') {
        return (string) $content[$key][$lang];
    }

    return $frenchText;
}

/**
 * Traduit une catégorie d'événement (stockée en français en base).
 * Si la catégorie n'a pas de traduction connue, retourne la valeur brute.
 */
function t_category(string $category): string
{
    $cat = strtolower(trim($category));
    if ($cat === '') {
        return '';
    }

    // Mapping vers les clés de traduction.
    $map = [
        'soirée'         => 'cat.soiree',
        'soiree'         => 'cat.soiree',
        'afterwork'      => 'cat.afterwork',
        'barbecue'       => 'cat.barbecue',
        'tournoi / lan'  => 'cat.tournoi',
        'tournoi'        => 'cat.tournoi',
        'conférence'     => 'cat.conference',
        'conference'     => 'cat.conference',
        'sortie'         => 'cat.sortie',
        'atelier'        => 'cat.atelier',
        'nuit de l\'info'=> 'cat.nuitinfo',
        'autre'          => 'cat.autre',
    ];

    // Recherche par correspondance partielle.
    foreach ($map as $needle => $key) {
        if ($cat === $needle || str_contains($cat, $needle)) {
            return t($key);
        }
    }

    return $category;
}
