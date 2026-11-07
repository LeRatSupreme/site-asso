<?php

declare(strict_types=1);

namespace Tests\Unit\Compta;

use App\Core\Compta\GlmOcrClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du client GLM-OCR (photo → texte par l'API vision
 * Z.ai) SANS AUCUN réseau : le transport HTTP est remplacé par le
 * callable injecté (setTransportForTests) qui capture la requête et
 * renvoie la réponse simulée.
 *
 * Contrats vérifiés :
 *  - configuration par getenv : sans clé (absente, vide, espaces) le
 *    client se déclare non configuré — InvoiceScan ne le sollicite
 *    alors jamais ;
 *  - requête au format OpenAI : la photo voyage en data URI base64
 *    (octets d'origine pour une petite image, rééchantillonnage JPEG
 *    au-delà de 2048 px de côté), avec la consigne de transcription,
 *    le nom du modèle, l'URL et le délai configurables ;
 *  - toute panne (HTTP 401/403/404/429/5xx, transport HS, JSON
 *    invalide, erreur OpenAI dans un 200, transcription absente ou
 *    vide, photo illisible) lève une RuntimeException française
 *    lisible — qui ne contient JAMAIS la clé API.
 */
final class GlmOcrClientTest extends TestCase
{
    /** Clé factice reconnaissable : sa présence dans un message serait une fuite. */
    private const API_KEY = 'sk-glm-test-SECRET-a-ne-jamais-afficher';

    private const PREFIXE_DATA_URI_PNG = 'data:image/png;base64,';

    private const PREFIXE_DATA_URI_JPEG = 'data:image/jpeg;base64,';

    /** Sauvegarde des variables GLM_* de l'environnement d'exécution. */
    private array $envBackup = [];

    /** Dernière requête vue par le transport simulé. */
    private ?array $requete = null;

    private string $petitPng = '';

    private string $grandPng = '';

    protected function setUp(): void
    {
        foreach (['GLM_OCR_API_KEY', 'GLM_OCR_BASE_URL', 'GLM_OCR_MODEL', 'GLM_OCR_TIMEOUT_S'] as $var) {
            $this->envBackup[$var] = getenv($var);
            putenv($var); // état de départ : aucune configuration GLM
        }
    }

    protected function tearDown(): void
    {
        GlmOcrClient::setTransportForTests(null);
        foreach ($this->envBackup as $var => $valeur) {
            if ($valeur === false) {
                putenv($var);
            } else {
                putenv($var . '=' . $valeur);
            }
        }
        foreach ([$this->petitPng, $this->grandPng] as $fichier) {
            if ($fichier !== '' && is_file($fichier)) {
                @unlink($fichier);
            }
        }
    }

    // ————————————————————————————————————————————————————————————
    // Configuration (isConfigured)
    // ————————————————————————————————————————————————————————————

    public function test_sans_cle_isConfigured_faux(): void
    {
        self::assertFalse(GlmOcrClient::isConfigured(), 'Variable absente : non configuré.');

        putenv('GLM_OCR_API_KEY=');
        self::assertFalse(GlmOcrClient::isConfigured(), 'Clé vide : non configuré.');

        putenv('GLM_OCR_API_KEY=   ');
        self::assertFalse(GlmOcrClient::isConfigured(), 'Clé d\'espacements : non configuré.');
    }

    public function test_cle_presente_isConfigured_vrai_meme_bordee_d_espaces(): void
    {
        putenv('GLM_OCR_API_KEY=' . self::API_KEY);
        self::assertTrue(GlmOcrClient::isConfigured());

        putenv('GLM_OCR_API_KEY=  ' . self::API_KEY . '  ');
        self::assertTrue(GlmOcrClient::isConfigured(), 'La clé est trimée par le client.');
    }

    public function test_extractText_sans_cle_leve_une_RuntimeException_explicite(): void
    {
        putenv('GLM_OCR_API_KEY');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GLM_OCR_API_KEY');
        GlmOcrClient::extractText('/photo-inexistante.png', 'image/png');
    }

    // ————————————————————————————————————————————————————————————
    // Succès : transcription renvoyée, requête envoyée
    // ————————————————————————————————————————————————————————————

    public function test_succes_renvoie_la_transcription_du_modele(): void
    {
        $this->setKey();
        $transcription = "METRO\nDate facture : 02-10-2026 17:12\n5449000340085 3162401 MINUTE MAID 0,720 24 1 17,28 B\nTotal H.T. : 17,28";
        $this->setTransportReponse(200, $this->corpsReussite($transcription));

        self::assertSame($transcription, GlmOcrClient::extractText($this->petitPngPath(), 'image/png'));
    }

    public function test_succes_retire_les_clotures_markdown_autour_de_la_transcription(): void
    {
        $this->setKey();
        $transcription = "METRO\nTotal H.T. : 250,46";
        $this->setTransportReponse(200, $this->corpsReussite("```text\n$transcription\n```"));

        self::assertSame(
            $transcription,
            GlmOcrClient::extractText($this->petitPngPath(), 'image/png'),
            'Certains modèles encadrent la réponse de clôtures Markdown : elles ne doivent pas polluer InvoiceParser.'
        );
    }

    public function test_requete_photo_en_data_uri_base64_avec_modele_et_url_par_defaut(): void
    {
        $this->setKey();
        $this->setTransportCapturant(200, $this->corpsReussite('ok'));

        $png = $this->petitPngPath();
        GlmOcrClient::extractText($png, 'image/png');

        $this->assertRequeteCapturee();
        self::assertSame('https://api.z.ai/api/paas/v4/chat/completions', $this->requete['url'], 'URL par défaut de l\'API Z.ai.');
        self::assertSame(75, $this->requete['timeout'], 'Délai par défaut : 75 s.');

        $corps = $this->corpsDecode();
        self::assertSame('glm-4.6v', $corps['model'], 'Modèle vision par défaut.');

        // La photo voyage en data URI base64, octets d'origine intacts
        // (petite image : aucun rééchantillonnage attendu).
        $partImage = $this->partDuMessage('image_url');
        self::assertNotNull($partImage, 'La photo doit être portée par une part image_url.');
        $url = $partImage['image_url']['url'] ?? '';
        self::assertStringStartsWith(self::PREFIXE_DATA_URI_PNG, $url);
        self::assertSame(
            file_get_contents($png),
            base64_decode(substr($url, strlen(self::PREFIXE_DATA_URI_PNG)), true),
            'Les octets du fichier sont encodés tels quels.'
        );

        // La consigne de transcription accompagne l'image dans le message.
        $partTexte = $this->partDuMessage('text');
        self::assertNotNull($partTexte);
        self::assertStringContainsString('Transcris', (string) ($partTexte['text'] ?? ''), 'La consigne de transcription fidèle est envoyée.');
    }

    public function test_requete_respecte_base_url_modele_et_timeout_configures(): void
    {
        $this->setKey();
        putenv('GLM_OCR_BASE_URL=https://glm.example.test/v1/');
        putenv('GLM_OCR_MODEL=glm-4.7v-test');
        putenv('GLM_OCR_TIMEOUT_S=12');
        $this->setTransportCapturant(200, $this->corpsReussite('ok'));

        GlmOcrClient::extractText($this->petitPngPath(), 'image/png');

        $this->assertRequeteCapturee();
        self::assertSame('https://glm.example.test/v1/chat/completions', $this->requete['url'], 'Base URL configurable, slash final neutralisé.');
        self::assertSame(12, $this->requete['timeout']);
        self::assertSame('glm-4.7v-test', $this->corpsDecode()['model']);
    }

    /**
     * La consigne (prompt) insiste sur les points critiques du
     * préremplissage : montants recopiés chiffre pour chiffre, ligne de
     * TOTAL À PAYER toujours transcrite, TVA seulement si imprimée —
     * jamais un total ou une TVA calculée.
     */
    public function test_consigne_insiste_sur_montants_total_et_tva(): void
    {
        $this->setKey();
        $this->setTransportCapturant(200, $this->corpsReussite('ok'));

        GlmOcrClient::extractText($this->petitPngPath(), 'image/png');

        $partTexte = $this->partDuMessage('text');
        self::assertNotNull($partTexte, 'La consigne accompagne la photo.');
        $consigne = (string) ($partTexte['text'] ?? '');
        self::assertStringContainsString('Transcris', $consigne, 'Toujours une transcription fidèle, jamais un résumé.');
        self::assertStringContainsString('chiffre pour chiffre', $consigne, 'Les montants sont recopiés exactement, jamais arrondis ni corrigés.');
        self::assertStringContainsString('TOTAL À PAYER', $consigne, 'La ligne de total du bas doit toujours être transcrite.');
        self::assertStringContainsString('TVA', $consigne, 'Les lignes de TVA imprimées sont demandées quand elles existent.');
        self::assertStringContainsString("s'invente jamais", $consigne, 'Une valeur absente ne s\'invente jamais — surtout pas un total ou une TVA calculée.');
    }

    public function test_timeout_invalide_ou_nul_retombe_sur_le_defaut(): void
    {
        $this->setKey();
        putenv('GLM_OCR_TIMEOUT_S=0');
        $this->setTransportCapturant(200, $this->corpsReussite('ok'));

        GlmOcrClient::extractText($this->petitPngPath(), 'image/png');

        $this->assertRequeteCapturee();
        self::assertSame(75, $this->requete['timeout'], '0 s n\'est pas un délai exploitable : 75 s par défaut.');
    }

    public function test_photo_hors_limites_reechantillonnee_en_jpeg(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD indisponible : rééchantillonnage non testable.');
        }
        $this->setKey();
        $this->setTransportCapturant(200, $this->corpsReussite('ok'));

        GlmOcrClient::extractText($this->grandPngPath(), 'image/png');

        $this->assertRequeteCapturee();
        $partImage = $this->partDuMessage('image_url');
        self::assertNotNull($partImage);
        $url = $partImage['image_url']['url'] ?? '';
        self::assertStringStartsWith(self::PREFIXE_DATA_URI_JPEG, $url, 'Photo de plus de 2048 px de côté : envoyée rééchantillonnée en JPEG.');

        $jpeg = base64_decode(substr($url, strlen(self::PREFIXE_DATA_URI_JPEG)), true);
        self::assertNotFalse($jpeg);
        [$largeur, $hauteur] = getimagesizefromstring($jpeg);
        self::assertSame(2048, max((int) $largeur, (int) $hauteur), 'Le côté long est ramené à 2048 px.');
    }

    public function test_photo_illisible_aucune_requete_ne_part(): void
    {
        $this->setKey();
        $appele = false;
        GlmOcrClient::setTransportForTests(static function () use (&$appele): array {
            $appele = true;

            return [200, '{}'];
        });

        try {
            GlmOcrClient::extractText(__DIR__ . '/photo-inexistante-' . uniqid() . '.png', 'image/png');
            self::fail('Photo illisible : une RuntimeException était attendue.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Photo illisible', $e->getMessage());
        }
        self::assertFalse($appele, 'Aucune requête ne doit partir sans photo lisible.');
    }

    // ————————————————————————————————————————————————————————————
    // Pannes : RuntimeException FR, jamais la clé
    // ————————————————————————————————————————————————————————————

    public function test_http_401_cle_refusee(): void
    {
        $this->assertErreurFrancaise([401, '{"error":{"message":"invalid api key"}}'], 'Clé API GLM-OCR refusée');
    }

    public function test_http_403_comme_une_cle_refusee(): void
    {
        $this->assertErreurFrancaise([403, '{"error":{"message":"forbidden"}}'], 'Clé API GLM-OCR refusée');
    }

    public function test_http_404_modele_introuvable(): void
    {
        $this->assertErreurFrancaise([404, '{"error":{"message":"model not found"}}'], 'Modèle GLM-OCR introuvable');
    }

    public function test_http_429_limite_d_appels_atteinte(): void
    {
        $this->assertErreurFrancaise([429, '{"error":{"message":"rate limit"}}'], 'Limite d\'appels GLM-OCR atteinte');
    }

    public function test_http_500_service_indisponible(): void
    {
        $this->assertErreurFrancaise([500, '{"error":{"message":"internal"}}'], 'momentanément indisponible (HTTP 500 côté Z.ai)');
    }

    public function test_http_503_service_indisponible(): void
    {
        $this->assertErreurFrancaise([503, 'overloaded'], 'momentanément indisponible (HTTP 503 côté Z.ai)');
    }

    public function test_http_inattendu_le_detail_du_corps_est_repris(): void
    {
        $this->assertErreurFrancaise([418, '{"error":{"message":"teapot"}}'], "en erreur (HTTP 418) : teapot");
    }

    public function test_transport_hs_ou_timeout_service_inaccessible(): void
    {
        $this->assertErreurFrancaise([0, 'cURL error 28: Operation timed out'], 'Moteur GLM-OCR inaccessible (cURL error 28: Operation timed out)');
    }

    public function test_corps_200_non_json_reponse_illisible(): void
    {
        $this->assertErreurFrancaise([200, '<html>Service en maintenance</html>'], 'Réponse illisible du moteur GLM-OCR (JSON invalide)');
    }

    public function test_erreur_openai_renvoyee_malgre_un_http_200(): void
    {
        $this->assertErreurFrancaise([200, '{"error":{"message":"quota exceeded"}}'], 'Erreur renvoyée par le moteur GLM-OCR : quota exceeded');
    }

    public function test_reponse_200_sans_transcription(): void
    {
        $this->assertErreurFrancaise([200, '{"choices":[]}'], 'aucune transcription');
    }

    public function test_reponse_200_transcription_vide(): void
    {
        $this->assertErreurFrancaise([200, $this->corpsReussite("```\n   \n```")], 'Réponse vide du moteur GLM-OCR');
    }

    // ————————————————————————————————————————————————————————————
    // Aides
    // ————————————————————————————————————————————————————————————

    private function setKey(): void
    {
        putenv('GLM_OCR_API_KEY=' . self::API_KEY);
    }

    private function corpsReussite(string $transcription): string
    {
        return (string) json_encode(['choices' => [['message' => ['content' => $transcription]]]]);
    }

    private function setTransportReponse(int $status, string $body): void
    {
        GlmOcrClient::setTransportForTests(static fn (): array => [$status, $body]);
    }

    private function setTransportCapturant(int $status, string $body): void
    {
        $this->requete = null;
        GlmOcrClient::setTransportForTests(function (string $url, string $payload, int $timeout) use ($status, $body): array {
            $this->requete = ['url' => $url, 'payload' => $payload, 'timeout' => $timeout];

            return [$status, $body];
        });
    }

    private function assertRequeteCapturee(): void
    {
        self::assertNotNull($this->requete, 'Le transport simulé doit avoir reçu la requête.');
    }

    private function corpsDecode(): array
    {
        $this->assertRequeteCapturee();
        $corps = json_decode($this->requete['payload'], true);
        self::assertIsArray($corps, 'La requête porte un corps JSON valide.');

        return $corps;
    }

    /** Part de contenu du message utilisateur portant ce type (image_url/text), null sinon. */
    private function partDuMessage(string $type): ?array
    {
        $corps = $this->corpsDecode();
        foreach ($corps['messages'][0]['content'] ?? [] as $part) {
            if (is_array($part) && ($part['type'] ?? '') === $type) {
                return $part;
            }
        }

        return null;
    }

    /**
     * Réponse simulée en échec : extractText doit lever une RuntimeException
     * au message FR annoncé, sans jamais y faire figurer la clé API.
     *
     * @param array{0:int,1:string} $reponse [code HTTP, corps brut]
     */
    private function assertErreurFrancaise(array $reponse, string $extraitAttendu): void
    {
        $this->setKey();
        $this->setTransportReponse($reponse[0], $reponse[1]);

        try {
            GlmOcrClient::extractText($this->petitPngPath(), 'image/png');
            self::fail('Une RuntimeException était attendue (réponse HTTP ' . $reponse[0] . ').');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($extraitAttendu, $e->getMessage(), 'Message français attendu pour HTTP ' . $reponse[0] . '.');
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage(), 'La clé API ne doit JAMAIS apparaître dans un message.');
        }
    }

    /**
     * Petite photo PNG réelle (le contenu n'importe pas : le texte vient
     * du transport simulé, mais finfo/GD doivent pouvoir la lire).
     */
    private function petitPngPath(): string
    {
        if ($this->petitPng === '') {
            $this->petitPng = $this->ecrirePng(64, 40);
        }

        return $this->petitPng;
    }

    /** Photo PNG délibérément hors limites (côté long 3000 px > 2048). */
    private function grandPngPath(): string
    {
        if ($this->grandPng === '') {
            $this->grandPng = $this->ecrirePng(3000, 1200);
        }

        return $this->grandPng;
    }

    private function ecrirePng(int $largeur, int $hauteur): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD indisponible : impossible de générer la photo de test.');
        }
        $chemin = sys_get_temp_dir() . '/aeic-glm-test-' . uniqid() . '.png';
        $img = imagecreatetruecolor($largeur, $hauteur);
        self::assertNotFalse($img);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 255, 255, 255));
        imagestring($img, 5, 4, 12, 'METRO', (int) imagecolorallocate($img, 0, 0, 0));
        self::assertTrue(imagepng($img, $chemin), 'Photo de test écrite sur disque.');
        imagedestroy($img);

        return $chemin;
    }
}
