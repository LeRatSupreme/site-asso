<?php

declare(strict_types=1);

namespace Tests\Unit\Compta;

use App\Core\Compta\GlmOcrClient;
use App\Core\Compta\InvoiceOcr;
use App\Core\Compta\InvoiceScan;
use PHPUnit\Framework\TestCase;

/**
 * Tests de l'intégration du moteur GLM-OCR dans InvoiceScan::run() —
 * SANS AUCUN réseau (transport injecté) et SANS tesseract requis :
 * chaque test simule l'upload ($_FILES) d'une vraie petite image/PDF
 * générée à la volée.
 *
 * Contrats vérifiés :
 *  - PHOTO avec clé + API qui répond : la transcription GLM part dans
 *    InvoiceParser (même chemin que le texte collé), audit.engine =
 *    'glm-ocr', contrat {ok, source, text, invoice, audit} intact ;
 *  - PHOTO avec clé + API en panne : repli automatique sur l'OCR local
 *    Tesseract, sans fatal — avertissement côté utilisateur quand le
 *    repli aboutit, erreur 500 historique quand les outils manquent ;
 *  - PHOTO sans clé : GLM n'est JAMAIS sollicité, comportement
 *    historique inchangé ;
 *  - PDF et TEXTE COLLÉ : jamais envoyés au moteur GLM.
 *
 * Les branches dépendantes de l'environnement (tesseract / poppler
 * installés ou non) s'assertent sur les deux issues possibles : le
 * point invariant est l'absence d'appel GLM et le contrat de réponse.
 */
final class InvoiceScanGlmOcrTest extends TestCase
{
    /** Clé factice reconnaissable : sa présence dans une réponse serait une fuite. */
    private const API_KEY = 'sk-glm-scan-test-SECRET';

    private array $envBackup = [];

    private string $png = '';

    private string $pdf = '';

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
        // InvoiceScan::run() borne l'exécution à 240 s (VPS Apache) : sous
        // CLI ce plafond s'appliquerait au PROCESSUS phpunit tout entier et
        // tuerait la suite en cours — on rétablit la limite illimitée du CLI.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        foreach ($this->envBackup as $var => $valeur) {
            if ($valeur === false) {
                putenv($var);
            } else {
                putenv($var . '=' . $valeur);
            }
        }
        $_POST = [];
        $_FILES = [];
        foreach ([$this->png, $this->pdf] as $fichier) {
            if ($fichier !== '' && is_file($fichier)) {
                @unlink($fichier);
            }
        }
    }

    // ————————————————————————————————————————————————————————————
    // PHOTO + clé : succès du moteur GLM
    // ————————————————————————————————————————————————————————————

    /**
     * Clé configurée + API qui répond : la transcription simulée (la
     * vraie fixture METRO du projet) est parsée par InvoiceParser,
     * audit.engine = 'glm-ocr', aucun repli local ni avertissement.
     */
    public function test_photo_avec_cle_succes_glm_invoice_parsee_engine_glm_ocr(): void
    {
        $this->setKey();
        $transcription = $this->fixture('metro_invoice.txt');
        $appels = 0;
        GlmOcrClient::setTransportForTests(static function () use (&$appels, $transcription): array {
            $appels++;

            return [200, (string) json_encode(['choices' => [['message' => ['content' => $transcription]]]])];
        });

        $r = $this->scanPhoto();

        self::assertSame(1, $appels, 'Le moteur GLM est sollicité exactement une fois.');

        // Contrat de réponse inchangé : {ok, source, text, invoice, audit}.
        self::assertCount(5, $r, 'Clés du contrat : ok, source, text, invoice, audit.');
        self::assertTrue($r['ok']);
        self::assertSame('file', $r['source']);
        self::assertSame(trim($transcription), $r['text'], 'Le texte renvoyé est la transcription GLM (trimée par le client).');
        self::assertIsArray($r['invoice']);
        self::assertIsArray($r['audit']);
        self::assertSame('glm-ocr', $r['audit']['engine']);
        self::assertSame('image/png', $r['audit']['mime']);
        self::assertArrayHasKey('sha256', $r['audit']);

        // Même chemin que le texte collé : InvoiceParser sur la transcription.
        $inv = $r['invoice'];
        self::assertSame('metro', $inv['kind']);
        self::assertSame('METRO', $inv['supplier']);
        self::assertSame('2026-10-02', $inv['purchased_at']);
        self::assertSame('0/0(087)0054/033871', $inv['invoice_number']);
        self::assertSame(250.46, $inv['total_ht']);
        self::assertSame(264.24, $inv['total_ttc']);
        self::assertCount(13, $inv['lines']);
        self::assertSame('MINUTE MAID NECT POMME BTE33CL', $inv['lines'][0]['label']);
        self::assertSame(17.28, $inv['lines'][0]['total']);

        // Aucun repli n'a eu lieu : aucun avertissement GLM.
        foreach ($inv['warnings'] as $warning) {
            self::assertStringNotContainsString('GLM-OCR indisponible', $warning);
        }
    }

    // ————————————————————————————————————————————————————————————
    // PHOTO + clé : panne GLM → repli OCR local sans fatal
    // ————————————————————————————————————————————————————————————

    /**
     * API en échec (HTTP 503 → RuntimeException FR côté client) : le
     * pipeline Tesseract historique prend le relais. Machine sans
     * tesseract (dev/CI) : repli tenté mais outils absents → erreur
     * 500 historique, sans fatal. Machine avec tesseract : OCR local
     * rendu, avertissement de repli au premier plan.
     */
    public function test_photo_avec_cle_glm_echec_repli_ocr_local_tente_sans_fatal(): void
    {
        $this->setKey();
        $appels = 0;
        GlmOcrClient::setTransportForTests(static function () use (&$appels): array {
            $appels++;

            return [503, '{"error":{"message":"upstream overloaded"}}'];
        });

        $r = $this->scanPhoto();

        self::assertSame(1, $appels, 'Le moteur GLM a bien été tenté avant le repli.');
        self::assertIsArray($r);
        self::assertArrayHasKey('ok', $r);

        if ($r['ok'] === true) {
            // Tesseract local présent : l'OCR local a rendu quelque chose.
            self::assertSame('file', $r['source']);
            self::assertContains($r['audit']['engine'], ['tesseract', 'ensemble'], 'Après un échec GLM, jamais glm-ocr dans l\'audit.');
            $warnings = $r['invoice']['warnings'] ?? [];
            self::assertNotEmpty($warnings, 'Le repli doit être signalé à l\'utilisateur.');
            self::assertStringContainsString(
                'GLM-OCR indisponible',
                (string) $warnings[0],
                'Avertissement de repli en tête des warnings.'
            );
        } else {
            // Sans tesseract local : repli tenté, impossible → 500 FR historique.
            self::assertSame(500, $r['status']);
            self::assertStringContainsString('OCR indisponible', (string) $r['error'], 'Erreur du pipeline local, pas un fatal GLM.');
        }

        // La clé API ne fuit jamais dans la réponse, quel que soit le chemin.
        self::assertStringNotContainsString(
            self::API_KEY,
            (string) json_encode($r),
            'La clé API ne doit jamais apparaître dans la réponse.'
        );
    }

    // ————————————————————————————————————————————————————————————
    // PHOTO sans clé : GLM jamais sollicité, comportement historique
    // ————————————————————————————————————————————————————————————

    public function test_photo_sans_cle_glm_jamais_sollicite_chemin_historique(): void
    {
        GlmOcrClient::setTransportForTests(static function (): array {
            self::fail('Sans GLM_OCR_API_KEY, le moteur GLM ne doit jamais être appelé.');

            return [200, '{}'];
        });

        $r = $this->scanPhoto();

        if (!InvoiceOcr::hasOcr()) {
            // Comportement d'origine au pixel près : OCR local absent →
            // 500 avec le message FR historique.
            self::assertFalse($r['ok']);
            self::assertSame(500, $r['status']);
            self::assertSame(
                'OCR indisponible sur ce serveur (installez tesseract-ocr tesseract-ocr-fra poppler-utils).',
                $r['error']
            );
        } else {
            // Tesseract présent : chemin local historique, jamais glm-ocr.
            self::assertArrayHasKey('ok', $r);
            if ($r['ok'] === true) {
                self::assertNotSame('glm-ocr', $r['audit']['engine']);
                self::assertSame('file', $r['source']);
            }
        }
    }

    // ————————————————————————————————————————————————————————————
    // PDF : jamais GLM (OCR local uniquement), clé présente ou non
    // ————————————————————————————————————————————————————————————

    public function test_pdf_glm_configure_mais_jamais_appele(): void
    {
        $this->setKey(); // GLM prêt : il ne doit pourtant PAS servir pour un PDF.
        GlmOcrClient::setTransportForTests(static function (): array {
            self::fail('Un PDF ne doit jamais partir vers le moteur GLM (OCR local uniquement).');

            return [200, '{}'];
        });

        $r = $this->scanUpload($this->pdfPath(), 'facture.pdf');

        if ($r['ok'] === true) {
            self::assertSame('file', $r['source']);
            self::assertContains($r['audit']['engine'], ['ensemble', 'tesseract'], 'PDF : OCR local, jamais glm-ocr.');
            self::assertArrayHasKey('text', $r);
            self::assertArrayHasKey('invoice', $r);
        } else {
            self::assertSame(500, $r['status'], 'Sans poppler/tesseract locaux : erreur FR, pas de fatal.');
            self::assertStringNotContainsString('GLM', (string) $r['error']);
        }
    }

    // ————————————————————————————————————————————————————————————
    // Texte collé : jamais GLM, contrat historique sans engine
    // ————————————————————————————————————————————————————————————

    public function test_texte_colle_glm_jamais_appele_audit_sans_engine(): void
    {
        $this->setKey();
        GlmOcrClient::setTransportForTests(static function (): array {
            self::fail('Le texte collé va directement à InvoiceParser, jamais par GLM.');

            return [200, '{}'];
        });

        $_POST = ['text' => $this->fixture('metro_invoice.txt')];

        $r = InvoiceScan::run();

        self::assertTrue($r['ok']);
        self::assertSame('text', $r['source']);
        self::assertSame('metro', $r['invoice']['kind']);
        self::assertCount(13, $r['invoice']['lines']);
        self::assertArrayNotHasKey('engine', $r['audit'], 'Contrat historique du texte collé : pas de clé engine.');
    }

    // ————————————————————————————————————————————————————————————
    // Aides
    // ————————————————————————————————————————————————————————————

    private function setKey(): void
    {
        putenv('GLM_OCR_API_KEY=' . self::API_KEY);
    }

    private function fixture(string $nom): string
    {
        $contenu = file_get_contents(__DIR__ . '/../../Fixtures/' . $nom);
        self::assertNotFalse($contenu, 'Fixture tests/Fixtures/' . $nom . ' introuvable.');

        return $contenu;
    }

    /** Scan d'une PHOTO png (upload « file » simulé), texte collé vide. */
    private function scanPhoto(): array
    {
        return $this->scanUpload($this->pngPath(), 'photo-metro.png');
    }

    /**
     * Simule l'upload du document dans $_FILES puis délègue à
     * InvoiceScan::run() — même point d'entrée que les contrôleurs.
     */
    private function scanUpload(string $chemin, string $nom): array
    {
        $_POST = [];
        $_FILES = [
            'file' => [
                'name'     => $nom,
                'type'     => 'application/octet-stream', // jamais cru : finfo tranche
                'tmp_name' => $chemin,
                'error'    => UPLOAD_ERR_OK,
                'size'     => (int) @filesize($chemin),
            ],
        ];

        return InvoiceScan::run();
    }

    /** Petite photo PNG réelle (le texte vient du transport simulé). */
    private function pngPath(): string
    {
        if ($this->png !== '') {
            return $this->png;
        }
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD indisponible : impossible de générer la photo de test.');
        }
        $this->png = sys_get_temp_dir() . '/aeic-scan-glm-test-' . uniqid() . '.png';
        $img = imagecreatetruecolor(64, 40);
        self::assertNotFalse($img);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 255, 255, 255));
        imagestring($img, 5, 4, 12, 'METRO', (int) imagecolorallocate($img, 0, 0, 0));
        self::assertTrue(imagepng($img, $this->png));
        imagedestroy($img);

        return $this->png;
    }

    /**
     * PDF minimal mais valide (finfo → application/pdf) embarquant une
     * couche texte METRO : sur une machine avec poppler il ressort par
     * l'OCR local, sinon erreur FR — jamais par GLM dans les deux cas.
     */
    private function pdfPath(): string
    {
        if ($this->pdf !== '') {
            return $this->pdf;
        }

        $lignes = [
            'METRO',
            'Date facture : 03-10-2026 10:00',
            'N FACTURE 0/0(087)0054/999999',
            'COCA COLA BOITE FAT 33CL 0,634 24 1 15,21 B',
            'Total H.T. : 15,21',
            '15,21 B = 5,50% 0,76 15,97',
        ];
        $echappe = static fn (string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        $operations = [];
        foreach ($lignes as $ligne) {
            $operations[] = '(' . $echappe($ligne) . ') Tj';
        }
        $contenu = "BT\n/F1 10 Tf\n14 TL\n40 800 Td\n" . implode("\nT*\n", $operations) . "\nET";

        $objets = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($contenu) . " >>\nstream\n" . $contenu . "\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $positions = [];
        foreach ($objets as $numero => $corps) {
            $positions[$numero] = strlen($pdf);
            $pdf .= $numero . " 0 obj\n" . $corps . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $total = count($objets) + 1;
        $pdf .= "xref\n0 $total\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objets); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $positions[$i]);
        }
        $pdf .= "trailer\n<< /Size $total /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

        $this->pdf = sys_get_temp_dir() . '/aeic-scan-glm-test-' . uniqid() . '.pdf';
        self::assertTrue(file_put_contents($this->pdf, $pdf) !== false);

        return $this->pdf;
    }
}
