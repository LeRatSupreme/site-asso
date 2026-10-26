<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Extraction de texte d'une PHOTO de facture/ticket par le moteur de
 * vision GLM-OCR (API Z.ai, compatible OpenAI) — bouton « Prendre une
 * photo » du livre comptable, côté admin comme côté kiosque.
 *
 * Pourquoi une API : le VPS (4 vCPU, 7,6 Go, sans GPU) ne peut pas
 * héberger un modèle de vision ; l'API officielle Z.ai est joignable
 * depuis le serveur. Le pipeline local Tesseract/Poppler (InvoiceOcr)
 * reste le REPLI de référence : si ce client échoue (réseau, quota,
 * modèle renommé, réponse vide…), InvoiceScan::run() retombe dessus
 * sans rien changer au contrat de réponse du frontend.
 *
 * Aucune dépendance PHP externe : cURL pour l'appel, GD pour la mise
 * à l'échelle éventuelle. Config lue via getenv() — clés
 * GLM_OCR_API_KEY / GLM_OCR_BASE_URL / GLM_OCR_MODEL / GLM_OCR_TIMEOUT_S
 * (documentées dans config.env.example) ; sans clé, ce client n'est
 * jamais sollicité (isConfigured()) et l'OCR local continue.
 *
 * Toute défaillance lève une RuntimeException avec un message français
 * lisible — jamais de fatal, et JAMAIS la clé API dans un message ou un
 * log. Le transport HTTP est remplaçable (setTransportForTests), sur le
 * même modèle que le Mailer, pour tester la classe sans réseau.
 */
final class GlmOcrClient
{
    /** URL de base par défaut de l'API Z.ai (compatible OpenAI). */
    private const DEFAULT_BASE_URL = 'https://api.z.ai/api/paas/v4';

    /**
     * Modèle vision par défaut : glm-4.6v — validé en production sur
     * /chat/completions avec le payload image de ce client. « glm-ocr »
     * est réservé à un autre canal API de Z.ai et refuse ce payload
     * (code 1214) : il n'est PAS utilisable via chat/completions.
     */
    private const DEFAULT_MODEL = 'glm-4.6v';

    /** Délai maximal par défaut d'un appel, en secondes. */
    private const DEFAULT_TIMEOUT_S = 75;

    /** Côté long maximal (pixels) d'une photo envoyée : au-delà, rééchantillonnage. */
    private const MAX_EDGE_PX = 2048;

    /** Poids maximal (octets) d'une photo envoyée : au-delà, rééchantillonnage. */
    private const MAX_PAYLOAD_BYTES = 4 * 1024 * 1024;

    /**
     * Nombre maximal de pixels que GD peut décoder sans risque : au-delà
     * (un iPhone récent affiche 48 MP, soit ~195 Mo de canvas truecolor
     * contre un memory_limit PHP souvent à 128 Mo), le décodage EN PROCESS
     * meurt en fatal E_ERROR « Allowed memory size exhausted » qu'aucun
     * catch ne peut intercepter — le repli Tesseract serait contourné.
     * Une photo au-delà de cette garde part telle quelle : si l'API la
     * refuse, la RuntimeException obtenue déclenche le repli Tesseract,
     * le contrat « jamais de fatal » reste tenu.
     */
    private const MAX_DECODE_PIXELS = 40 * 1000 * 1000;

    /** Marge mémoire (octets) exigée en plus des canvas GD : tampons base64/JSON, etc. */
    private const DECODE_MEMORY_MARGIN_BYTES = 24 * 1024 * 1024;

    /** Qualité JPEG du rééchantillonnage. */
    private const RESAMPLE_JPEG_QUALITY = 85;

    /**
     * Consigne de transcription envoyée au modèle : fidélité intégrale,
     * texte brut, un article par ligne — le texte produit part dans
     * InvoiceParser, qui attend exactement la mise en page d'un ticket
     * ou d'une facture (mêmes attentes que le texte collé à la main).
     */
    private const PROMPT = <<<'TXT'
Transcris fidèlement et intégralement ce document (ticket de caisse ou facture), sans rien inventer, résumer ni corriger.
Respecte impérativement, dans l'ordre du document :
- l'enseigne / le nom du fournisseur ;
- la date et le numéro de ticket ou de facture ;
- CHAQUE ligne article, une seule par ligne : quantité, libellé, prix unitaire, total de la ligne ;
- les totaux : total HT, total TTC, montant payé, mode de paiement ;
- la TVA : chaque taux et son montant.
Réponds uniquement par la transcription en texte brut (aucun Markdown, aucun commentaire, aucun titre ajouté) : une seule information par ligne. Un élément illisible est transcrit tel que tu le lis ; une valeur absente du document ne s'invente jamais.
TXT;

    /** @var callable|null Hook remplaçant l'appel cURL réel (tests). */
    private static $transport = null;

    /**
     * Le moteur est-il configuré ? Vrai dès qu'une clé API (non vide)
     * est présente : InvoiceScan ne tente l'API que dans ce cas, sinon
     * le comportement reste l'OCR local Tesseract, à l'identique.
     */
    public static function isConfigured(): bool
    {
        $key = getenv('GLM_OCR_API_KEY');

        return is_string($key) && trim($key) !== '';
    }

    /**
     * Transcrit une PHOTO (jpg/png/webp) via l'API vision et renvoie le
     * texte intégral, prêt pour InvoiceParser::parse() (même chemin que
     * le texte collé). L'image est mise à l'échelle quand elle est trop
     * lourde pour l'API (côté long > 2048 px ou > 4 Mo) : le
     * rééchantillonnage JPEG maîtrise latence et coût sans perte utile
     * de lisibilité.
     *
     * @param string $filePath Chemin réel du fichier (tmp d'upload).
     * @param string $mime     MIME réel détecté (finfo) côté contrôleur.
     *
     * @throws \RuntimeException Clé absente, photo illisible, réseau,
     *                           HTTP en erreur (clé refusée, modèle
     *                           introuvable, quota…), réponse vide ou
     *                           malformée — message lisible destiné à
     *                           l'utilisateur, jamais la clé API.
     */
    public static function extractText(string $filePath, string $mime): string
    {
        $apiKey = trim((string) getenv('GLM_OCR_API_KEY'));
        if ($apiKey === '') {
            throw new \RuntimeException('Moteur GLM-OCR non configuré : renseignez GLM_OCR_API_KEY (config.env).');
        }

        $baseUrl = trim((string) getenv('GLM_OCR_BASE_URL'));
        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }

        $model = trim((string) getenv('GLM_OCR_MODEL'));
        if ($model === '') {
            $model = self::DEFAULT_MODEL;
        }

        $timeout = (int) (getenv('GLM_OCR_TIMEOUT_S') ?: self::DEFAULT_TIMEOUT_S);
        if ($timeout <= 0) {
            $timeout = self::DEFAULT_TIMEOUT_S;
        }

        // Corps au format OpenAI : l'image en data URI + la consigne de
        // transcription, dans le message utilisateur.
        $payload = json_encode(
            [
                'model'    => $model,
                'messages' => [
                    [
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'image_url', 'image_url' => ['url' => self::prepareImage($filePath, $mime)]],
                            ['type' => 'text', 'text' => self::PROMPT],
                        ],
                    ],
                ],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($payload === false) {
            throw new \RuntimeException('Photo impossible à préparer pour le moteur GLM-OCR.');
        }

        [$status, $body] = self::call(rtrim($baseUrl, '/') . '/chat/completions', $payload, $timeout, $apiKey);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(self::httpErrorMessage((int) $status, $body));
        }

        return self::extractContent($body);
    }

    /**
     * Remplace le transport HTTP réel (tests unitaires sans réseau),
     * même principe que Mailer::setTransport. Le callable reçoit
     * (url, corps JSON, délai) et renvoie [code HTTP — 0 si le
     * transport échoue, détail dans le corps —, corps brut]. Passer
     * null rétablit le cURL réel.
     *
     * @param callable(string $url, string $payload, int $timeoutS): array{0:int,1:string}|null $fn
     */
    public static function setTransportForTests(?callable $fn): void
    {
        self::$transport = $fn;
    }

    // ————————————————————————————————————————————————————————————
    // Transport HTTP
    // ————————————————————————————————————————————————————————————

    /**
     * Appel POST : transport injecté (tests) sinon cURL.
     *
     * @return array{0:int,1:string} [code HTTP (0 = transport impossible, détail dans le corps), corps]
     */
    private static function call(string $url, string $payload, int $timeoutS, string $apiKey): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($url, $payload, $timeoutS);
        }

        if (!function_exists('curl_init')) {
            return [0, 'extension cURL absente du serveur'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [0, 'initialisation cURL impossible'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey, // clé jamais journalisée
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeoutS,
            CURLOPT_USERAGENT      => 'AEIC-scan',
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $detail = curl_error($ch); // message réseau, sans la clé
            curl_close($ch);

            return [0, $detail !== '' ? $detail : 'erreur réseau inconnue'];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }

    /**
     * Message français lisible pour un appel en échec (réseau ou HTTP) :
     * le détail renvoyé par le service est repris quand il est
     * exploitable, sans jamais inclure la clé API.
     */
    private static function httpErrorMessage(int $status, string $body): string
    {
        return match (true) {
            $status === 0 => sprintf('Moteur GLM-OCR inaccessible (%s).', self::bodyDetail($body)),
            $status === 401, $status === 403 => 'Clé API GLM-OCR refusée par le service (vérifiez GLM_OCR_API_KEY, à créer sur la plateforme Z.ai).',
            $status === 404 => 'Modèle GLM-OCR introuvable (vérifiez GLM_OCR_MODEL dans config.env).',
            $status === 429 => 'Limite d\'appels GLM-OCR atteinte — réessayez dans un instant.',
            $status >= 500 => sprintf('Service GLM-OCR momentanément indisponible (HTTP %d côté Z.ai).', $status),
            default => sprintf('Service GLM-OCR en erreur (HTTP %d) : %s.', $status, self::bodyDetail($body)),
        };
    }

    /**
     * Détail exploitable d'un corps d'erreur : message JSON (schéma
     * OpenAI « error.message ») si présent, extrait brut sinon,
     * borné à 200 caractères.
     */
    private static function bodyDetail(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return 'aucune réponse du serveur';
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                $body = trim($message);
            }
        }

        $flat = trim((string) preg_replace('/\s+/', ' ', $body));

        return mb_substr($flat, 0, 200);
    }

    /**
     * Texte transcrit depuis le corps (HTTP 2xx) d'une réponse :
     * choices[0].message.content, débarrassé des clôtures Markdown que
     * certains modèles ajoutent autour de la transcription. Corps
     * malformé ou transcription vide : RuntimeException lisible.
     */
    private static function extractContent(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Réponse illisible du moteur GLM-OCR (JSON invalide).');
        }

        // Certaines passerelles renvoient le schéma d'erreur OpenAI avec
        // un statut 200 : le message y est plus parlant que le corps brut.
        if (isset($decoded['error'])) {
            $message = '';
            if (is_array($decoded['error']) && isset($decoded['error']['message'])
                && is_string($decoded['error']['message'])
            ) {
                $message = trim($decoded['error']['message']);
            } elseif (is_string($decoded['error'])) {
                $message = trim($decoded['error']);
            }
            throw new \RuntimeException(
                'Erreur renvoyée par le moteur GLM-OCR : '
                . ($message !== '' ? mb_substr($message, 0, 200) : 'raison inconnue')
                . '.'
            );
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new \RuntimeException('Réponse incomplète du moteur GLM-OCR (aucune transcription).');
        }

        $content = trim(str_replace("\r\n", "\n", $content));
        if (preg_match('/^```[^\n]*\n(.*)\n?```$/s', $content, $m) === 1) {
            $content = trim($m[1]);
        }
        if ($content === '') {
            throw new \RuntimeException('Réponse vide du moteur GLM-OCR (photo illisible pour le modèle ?).');
        }

        return $content;
    }

    // ————————————————————————————————————————————————————————————
    // Préparation de l'image (data URI + mise à l'échelle)
    // ————————————————————————————————————————————————————————————

    /**
     * Photo prête pour l'API, en data URI base64. Au-delà de 2048 px de
     * côté long ou de 4 Mo, l'image est rééchantillonnée en JPEG
     * (qualité 85) via GD : latence et coût maîtrisés, lisibilité OCR
     * préservée. GARDE ANTI « DECOMPRESSION BOMB » : GD n'est sollicité
     * que si les dimensions annoncées par getimagesize sont connues,
     * raisonnables (≤ MAX_DECODE_PIXELS) et tiennent dans la mémoire
     * PHP restante — sinon l'originale est envoyée telle quelle (GD
     * indisponible ou décodage raté : idem) ; si l'API la refuse, la
     * RuntimeException obtenue déclenchera le repli Tesseract côté
     * InvoiceScan : jamais de fatal.
     */
    private static function prepareImage(string $filePath, string $mime): string
    {
        $bytes = @file_get_contents($filePath);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Photo illisible : impossible de charger le fichier envoyé.');
        }

        $size = @getimagesize($filePath);
        $tooBig = ($size !== false && max((int) $size[0], (int) $size[1]) > self::MAX_EDGE_PX)
            || strlen($bytes) > self::MAX_PAYLOAD_BYTES;

        if ($tooBig && $size !== false && function_exists('imagecreatefromstring')
            && (int) $size[0] * (int) $size[1] <= self::MAX_DECODE_PIXELS
            && self::decodeFitsInMemory((int) $size[0], (int) $size[1])
        ) {
            $resampled = self::resampleToJpeg($bytes);
            if ($resampled !== null) {
                return 'data:image/jpeg;base64,' . base64_encode($resampled);
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * Le décodage GD de la photo tient-il dans la mémoire PHP restante ?
     * GD alloue ~5 octets par pixel truecolor pour la source, plus le
     * canvas destination (≤ 2048×2048) et les tampons de la requête
     * (base64/JSON) couverts par la marge. memory_limit illimité (-1)
     * ou illisible : la garde en pixels (MAX_DECODE_PIXELS) décide seule.
     */
    private static function decodeFitsInMemory(int $w, int $h): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }

        $limitBytes = self::shorthandBytes($limit);
        if ($limitBytes === null) {
            return true; // format inattendu : on ne bloque pas le scan
        }

        $besoin = (int) ($w * $h * 5)
            + self::MAX_EDGE_PX * self::MAX_EDGE_PX * 5
            + self::DECODE_MEMORY_MARGIN_BYTES;

        return $besoin <= ($limitBytes - memory_get_usage(true));
    }

    /**
     * Taille en octets d'une valeur de configuration PHP (« 128M »,
     * « 1G », « 268435456 »…), null si le format n'est pas reconnu.
     */
    private static function shorthandBytes(string $value): ?int
    {
        if (preg_match('/^(\d+)([KMG]?)$/i', $value, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * match (strtolower($m[2])) {
            'k'     => 1024,
            'm'     => 1024 ** 2,
            'g'     => 1024 ** 3,
            default => 1,
        };
    }

    /**
     * Rééchantillonnage JPEG (qualité 85) côté long ≤ 2048 px, sur fond
     * blanc (le JPEG n'a pas de transparence : PNG/WEBP aplatis).
     * null si GD échoue : l'appelante enverra l'originale.
     */
    private static function resampleToJpeg(string $bytes): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        try {
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1.0, self::MAX_EDGE_PX / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($nw, $nh);
            if ($dst === false) {
                return null;
            }
            try {
                $white = imagecolorallocate($dst, 255, 255, 255);
                if ($white !== false) {
                    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
                }
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

                ob_start();
                $ok = imagejpeg($dst, null, self::RESAMPLE_JPEG_QUALITY);
                $jpeg = (string) ob_get_clean();
                if (!$ok || $jpeg === '') {
                    return null;
                }

                return $jpeg;
            } finally {
                imagedestroy($dst);
            }
        } finally {
            imagedestroy($src);
        }
    }
}
