<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use PHPUnit\Framework\TestCase;

/**
 * Fix KeyboardInterrupt (selfreview 04/11 → 06/11, 7 starts orphelines
 * cumulées dans logs/assistant.jsonl) : une interruption (Ctrl+C ou
 * timeout -s INT du selfreview) pendant un tour LLM tuait le pont
 * scripts/assistant.py ENTRE l'entrée « start » (journaliser_debut) et
 * la ligne de fin (journaliser) — l'exception KeyboardInterrupt
 * franchissait poser_question avant tout nettoyage.
 *
 * Le fix attrape KeyboardInterrupt dans poser_question et referme la
 * session honnêtement ; ce test SIMULE l'interruption (requete_llm
 * remplacée par un raise KeyboardInterrupt, question volontairement
 * hors fast-path/gabarits) et vérifie le journal : start + fin
 * appareillées par id — plus de start orpheline.
 *
 * Pont PHPUnit → interpréteur externe, même convention que
 * AssistantCliRoutingTest : exec + skip propre si Python absent. La
 * sonde Python n'utilise que la stdlib, journal redirigé vers un
 * dossier temporaire (jamais logs/assistant.jsonl).
 */
final class AssistantCliInterruptTest extends TestCase
{
    public function test_interruption_pendant_le_tour_llm_ecrit_la_ligne_de_fin(): void
    {
        $r = $this->sondeInterruption();
        if ($r === null) {
            self::markTestSkipped('Python indisponible : interruption du pont assistant.py non testable ici.');
        }

        // La session est refermée honnêtement (pas ok=true résiduel).
        self::assertFalse((bool) $r['ok'], 'une session interrompue n\'est jamais un succès.');
        self::assertStringContainsString('interrompu', (string) $r['erreur'], 'erreur explicite : ' . (string) $r['erreur']);

        // AVANT le fix : 1 ligne « start » orpheline, le script mourait
        // avant journaliser. APRÈS : start + fin appareillées par id.
        $lignes = $r['lignes'];
        self::assertIsArray($lignes);
        self::assertCount(2, $lignes, 'une ligne start ET une ligne fin — plus de start orpheline.');

        self::assertSame('start', $lignes[0]['phase'] ?? null);
        self::assertNotSame('', (string) ($lignes[0]['id'] ?? ''), 'l\'entrée start porte un id.');

        self::assertSame('fin', $lignes[1]['phase'] ?? null);
        self::assertSame($lignes[0]['id'], $lignes[1]['id'] ?? null, 'les deux lignes se recollent par id.');
        self::assertFalse((bool) ($lignes[1]['ok'] ?? null), 'la ligne de fin porte l\'échec honnête.');
        self::assertStringContainsString('interrompu', (string) ($lignes[1]['erreur'] ?? ''));
        self::assertSame('llm', $lignes[1]['mode'] ?? null);
    }

    /**
     * Écrit et exécute la sonde Python : import du pont, journal
     * redirigé vers un dossier temporaire, requete_llm interrompue,
     * poser_question + journaliser (exactement le flux de main()).
     *
     * @return array<string,mixed>|null {ok, erreur, lignes} décodé, null si Python absent.
     */
    private function sondeInterruption(): ?array
    {
        $python = self::pythonBinary();
        if ($python === null) {
            return null;
        }

        $sonde = <<<'PY'
import json
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(sys.argv[1]) / "scripts"))
import assistant  # noqa: E402  (le pont : import seul, rien ne s'exécute)

with tempfile.TemporaryDirectory() as dossier:
    # Journal de test : jamais logs/assistant.jsonl.
    assistant.JOURNAL_JSONL = Path(dossier) / "assistant.jsonl"

    def _interrompu(*args, **kwargs):
        raise KeyboardInterrupt  # Ctrl-C au milieu du 1er tour LLM

    assistant.requete_llm = _interrompu

    # Question volontairement hors fast-path stock et gabarits : la voie
    # LLM écrit son entrée « start » PUIS demande le 1er tour (interrompu).
    session = assistant.poser_question(
        "question hors gabarits pour le test d'interruption", "jeton-test"
    )
    assistant.journaliser(session)  # même flux que main()

    lignes = [
        json.loads(ligne)
        for ligne in assistant.JOURNAL_JSONL.read_text(encoding="utf-8").splitlines()
    ]

print(json.dumps({
    "ok": session.ok,
    "erreur": session.erreur,
    "lignes": lignes,
}, ensure_ascii=False))
PY;

        $fichier = tempnam(sys_get_temp_dir(), 'aeic-interrupt-');
        file_put_contents($fichier, $sonde);

        try {
            $sorties = [];
            $code = 1;
            exec(
                escapeshellcmd($python) . ' ' . escapeshellarg($fichier) . ' ' . escapeshellarg(AEIC_ROOT) . ' 2>&1',
                $sorties,
                $code
            );
            $brut = implode("\n", $sorties);
            self::assertSame(0, $code, "la sonde doit s'exécuter (code {$code}) :\n{$brut}");

            $r = json_decode($brut, true);
            self::assertIsArray($r, 'la sonde imprime du JSON (extrait : ' . mb_substr($brut, 0, 300) . ').');

            return $r;
        } finally {
            @unlink($fichier);
        }
    }

    /** Interpréteur Python du pont (stdlib uniquement) : venv du runbook
     *  s'il est présent, sinon python3/python du système, sinon null. */
    private static function pythonBinary(): ?string
    {
        $venv = AEIC_ROOT . '/.venv-assistant/bin/python3';
        if (is_executable($venv)) {
            return $venv;
        }

        $devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        foreach (['python3', 'python'] as $bin) {
            $out = [];
            $code = 1;
            exec(escapeshellcmd($bin) . ' -V 2>' . $devNull, $out, $code);
            if ($code === 0) {
                return $bin;
            }
        }

        return null;
    }
}
