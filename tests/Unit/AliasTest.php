<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ProductAlias;
use App\Models\ScanLabel;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * RÈGLE ANTI-DOUBLONS D'APPELLATIONS (garde ProductAlias::save et file
 * scan_labels) : deux libellés qui NORMALISENT identiquement
 * (StockPublic::normalizeKey — « Coca cherry » et « Coca_cherry »)
 * ne font QU'UNE seule entrée.
 *
 *  - upsert de FUSION sur doublon normalisé : la ligne existante est
 *    mise à jour (cible/category/kind), JAMAIS une seconde ligne ;
 *  - trace d'audit « alias.fusion » avec les occurrences de ventes
 *    avant/après (ex. 80+6→86) — aucune perte de compteur ;
 *  - file scan_labels : occurrences des lignes sœurs redondantes
 *    ADDITIONNÉES dans la ligne canonique (80+6, +1 événement → 87),
 *    première vue conservée, une seule ligne physique.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 * JAMAIS la base de production (garde TestDatabaseTrait/db_guard).
 */
final class AliasTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test Alias ignoré.');

            return;
        }
        $this->pdo = $pdo;

        try {
            foreach (['product_aliases', 'sales', 'scan_labels', 'audit_logs'] as $table) {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            }
        } catch (\Throwable) {
            self::markTestSkipped('Tables alias absentes de aeic_test : importez database/schema.sql.');

            return;
        }

        $this->reset($pdo, ['product_aliases', 'sales', 'scan_labels', 'audit_logs']);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        $this->pdo = null;
    }

    // -----------------------------------------------------------------
    //  Garde structurelle : upsert de fusion (product_aliases)
    // -----------------------------------------------------------------

    public function test_save_upsert_sur_doublon_normalise_pas_de_seconde_ligne(): void
    {
        $id1 = ProductAlias::save(['raw_description' => 'Coca cherry', 'product_key' => 'coca cherry']);
        self::assertNotSame('', $id1);

        // « Coca_cherry » normalise comme « Coca cherry » : FUSION sur la
        // ligne existante (même id retourné), jamais une seconde ligne.
        $id2 = ProductAlias::save(['raw_description' => 'Coca_cherry', 'product_key' => 'coca cherry']);

        self::assertSame($id1, $id2, 'Le doublon normalisé réutilise la ligne existante.');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM product_aliases')->fetchColumn());
        self::assertSame(
            'coca cherry',
            (string) $this->pdo->query('SELECT product_key FROM product_aliases')->fetchColumn(),
            'La cible est conservée (identique des deux côtés).'
        );

        // Les deux orthographes restent résolvables (repli normalisé).
        self::assertSame('coca cherry', ProductAlias::resolve('Coca cherry'));
        self::assertSame('coca cherry', ProductAlias::resolve('Coca_cherry'));
    }

    public function test_save_fusion_repointe_vers_la_derniere_intention(): void
    {
        ProductAlias::save(['raw_description' => 'Coca cherry', 'product_key' => 'coca cherry']);

        // Upsert classique : la dernière saisie fait foi (même à
        // l'orthographe près), toujours sur UNE seule ligne.
        ProductAlias::save(['raw_description' => 'Coca_cherry', 'product_key' => 'cherry cola']);

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM product_aliases')->fetchColumn());
        self::assertSame(
            'cherry cola',
            (string) $this->pdo->query('SELECT product_key FROM product_aliases')->fetchColumn()
        );
        self::assertSame('cherry cola', ProductAlias::resolve('Coca cherry'));
    }

    public function test_save_fusion_trace_les_compteurs_avant_apres(): void
    {
        // Cas réel : « Coca cherry » 80 occurrences ET « Coca_cherry » 6.
        for ($i = 0; $i < 80; $i++) {
            $this->addSale('Coca cherry', 'coca cherry');
        }
        for ($i = 0; $i < 6; $i++) {
            $this->addSale('Coca_cherry', null);
        }

        ProductAlias::save(['raw_description' => 'Coca cherry', 'product_key' => 'coca cherry'], 'user_test');
        ProductAlias::save(['raw_description' => 'Coca_cherry', 'product_key' => 'coca cherry'], 'user_test');

        // Une seule ligne, et la fusion est TRACÉE avec les compteurs
        // avant/après (80+6→86) — jamais une suppression silencieuse.
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM product_aliases')->fetchColumn());

        $stmt = $this->pdo->query("SELECT details FROM audit_logs WHERE action = 'alias.fusion'");
        $brut = $stmt->fetchColumn();
        self::assertNotFalse($brut, 'La fusion est tracée dans le journal d\'audit.');

        /** @var array<string,mixed> $details */
        $details = json_decode((string) $brut, true);
        self::assertSame('Coca cherry', (string) $details['conserve']);
        self::assertSame('Coca_cherry', (string) $details['absorbe']);
        self::assertSame('coca cherry', (string) $details['cible_apres']);
        self::assertSame(
            ['Coca cherry' => 80, 'Coca_cherry' => 6],
            $details['occurrences_avant'],
            'Compteurs AVANT la fusion, libellé par libellé.'
        );
        self::assertSame(86, (int) $details['occurrences_apres'], 'Compteur APRÈS : 80+6→86, aucune perte.');
    }

    /** Un libellé sans doublon normalisé reste une création normale. */
    public function test_save_sans_doublon_cree_normalement(): void
    {
        ProductAlias::save(['raw_description' => 'Coca cherry', 'product_key' => 'coca cherry']);
        ProductAlias::save(['raw_description' => 'Coca zero', 'product_key' => 'coca']);

        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM product_aliases')->fetchColumn());
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'alias.fusion'")->fetchColumn(),
            'Pas de doublon : pas de trace de fusion.'
        );
    }

    // -----------------------------------------------------------------
    //  Garde structurelle : fusion d'occurrences (file scan_labels)
    // -----------------------------------------------------------------

    public function test_scan_labels_fusionne_les_occurrences_des_doublons(): void
    {
        // Doublons HÉRITÉS de la file (créés avant la garde) : 80 + 6.
        $this->addScanLabel('Coca cherry', 80);
        $this->addScanLabel('Coca_cherry', 6);

        // Nouvel événement sous une troisième écriture : tout converge
        // vers l'entrée canonique (la plus fréquente), sœurs fusionnées.
        ScanLabel::recordAll([['label' => 'COCA CHERRY', 'product' => null]]);

        $rows = $this->pdo->query('SELECT label, occurrences FROM scan_labels')->fetchAll();
        self::assertCount(1, $rows, 'Une seule entrée pour toutes les écritures.');
        self::assertSame('Coca cherry', (string) $rows[0]['label'], 'La plus fréquente est la canonique.');
        self::assertSame(87, (int) $rows[0]['occurrences'], 'Occurrences FUSIONNÉES 80+6, puis +1 événement → 87.');

        // La fusion (pas la suppression) est tracée avec ses compteurs.
        $stmt = $this->pdo->query(
            "SELECT entity_type, details FROM audit_logs WHERE action = 'alias.fusion'"
        );
        $trace = $stmt->fetch();
        self::assertNotFalse($trace, 'La fusion de la file est tracée.');
        self::assertSame('scan_label', (string) $trace['entity_type']);
        /** @var array<string,mixed> $details */
        $details = json_decode((string) $trace['details'], true);
        self::assertSame(86, (int) $details['occurrences_apres'], 'Trace avant/après : 80+6→86 (avant le +1).');
    }

    /** Sans doublon normalisé, le comportement historique est
     *  INCHANGÉ : occurrences = nombre de SCANS mentionnant le
     *  libellé (+1 par événement), pas le nombre de mentions DANS un
     *  scan — « allLabels » déduplique par scan depuis l'origine,
     *  et l'upsert W4 ne touche aucun compteur sans sœur à fusionner.
     *  (Correction de l'assertion initiale « une passe => 2 » :
     *  erronée — le comptage par mention n'a jamais existé.) */
    public function test_scan_labels_sans_doublon_comportement_inchange(): void
    {
        // Premier scan : deux mentions d'« EVIEN 50CL » ne comptent
        // qu'UN événement (dédup par scan, inchangé), création à 1.
        ScanLabel::recordAll([
            ['label' => 'EVIEN 50CL', 'product' => null],
            ['label' => 'KitKat', 'product' => 'KitKat'],
            ['label' => 'EVIEN 50CL', 'product' => null],
        ]);

        // Second scan ré-mentionnant le libellé : +1 par événement.
        ScanLabel::recordAll([['label' => 'EVIEN 50CL', 'product' => null]]);

        $rows = [];
        foreach (ScanLabel::all() as $row) {
            $rows[(string) $row['label']] = (int) $row['occurrences'];
        }

        self::assertSame(['EVIEN 50CL' => 2, 'KitKat' => 1], $rows, 'Comptage inchangé sans doublon normalisé : +1 par scan, pas par mention.');
    }

    // -----------------------------------------------------------------
    //  Aides de fixtures (aeic_test)
    // -----------------------------------------------------------------

    private function addSale(string $description, ?string $productKey): void
    {
        $this->pdo->prepare(
            "INSERT INTO sales (id, transaction_ref, sold_at, payment_method, quantity, description, product_key, price_ttc)
             VALUES (?, ?, NOW(), 'CARTE', 1, ?, ?, 1.0)"
        )->execute(['sal_' . bin2hex(random_bytes(6)), 'tx_' . bin2hex(random_bytes(8)), $description, $productKey]);
    }

    private function addScanLabel(string $label, int $occurrences): void
    {
        $this->pdo->prepare(
            'INSERT INTO scan_labels (id, label, occurrences) VALUES (?, ?, ?)'
        )->execute(['scan_' . bin2hex(random_bytes(6)), $label, $occurrences]);
    }
}
