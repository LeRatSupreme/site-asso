<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\ImportBatch;
use App\Models\Sale;

/**
 * Synchronisation automatique des ventes SumUp vers la table `sales`.
 *
 * Remplace (ou complète) l'import manuel des rapports CSV : à chaque
 * exécution, on interroge l'API SumUp sur une fenêtre glissante, on détecte
 * les transactions encore inconnues (par « Réf. transaction »), et on les
 * insère via exactement le même chemin que le CSV (Sale::importBatch), donc
 * avec la même déduplication (clé unique uniq_sale_line + INSERT IGNORE).
 *
 * Idempotence : relancer la synchro n'insère jamais deux fois une vente.
 * Compatibilité CSV : une transaction déjà importée via un rapport SumUp
 * n'est pas ré-importée ici (et inversement, l'import d'un CSV couvrant une
 * période déjà synchronisée affichera l'avertissement de chevauchement,
 * puisque chaque lot de synchro renseigne sa période).
 */
final class SumUpSalesSync
{
    /** @var callable|null function(string $description): ?string */
    private $productResolver;

    public function __construct(
        private readonly SumUpApiClient $client,
        ?callable $productResolver = null
    ) {
        $this->productResolver = $productResolver;
    }

    /**
     * Effectue un cycle de synchronisation.
     *
     * @param \DateTimeImmutable|null $since  Début de la fenêtre (défaut : 24 h).
     * @param int                     $limit  Nb max de transactions par appel API (1–200).
     * @param bool                    $dryRun true = ne touche pas à la base
     *                                        (diagnostic : compte seulement).
     *
     * @return array{fetched:int, sales:int, inserted:int, skipped:int, ignored:int, batch_id:?string}
     *         fetched = transactions vues dans la fenêtre ; sales = nouvelles
     *         transactions (absentes de la base) ; inserted/skipped = lignes
     *         insérées / déjà présentes ; ignored = transactions inexploitables
     *         (sans référence) ; batch_id = lot de traçabilité créé le cas échéant.
     */
    public function sync(?\DateTimeImmutable $since = null, int $limit = 200, bool $dryRun = false): array
    {
        $stats = ['fetched' => 0, 'sales' => 0, 'inserted' => 0, 'skipped' => 0, 'ignored' => 0, 'batch_id' => null];
        $since ??= new \DateTimeImmutable('-24 hours');

        $items = $this->client->history($since, $limit);
        $stats['fetched'] = count($items);

        // Ne garder que les paiements aboutis, référencés, uniques dans le lot.
        $candidates = [];
        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'PAYMENT' || ($item['status'] ?? '') !== 'SUCCESSFUL') {
                continue;
            }

            $code = trim((string) ($item['transaction_code'] ?? ''));
            if ($code === '') {
                $stats['ignored']++;

                continue;
            }

            $candidates[$code] ??= $item; // ordre descendant : la 1ère occurrence gagne
        }

        if ($candidates === []) {
            return $stats;
        }

        // Transactions déjà comptabilisées (par CSV ou synchro antérieure) → ignorées.
        if (!$dryRun) {
            $known = Sale::existingTransactionRefs(array_keys($candidates));
            foreach ($known as $ref) {
                unset($candidates[(string) $ref]);
            }
        }

        if ($candidates === []) {
            return $stats;
        }

        $stats['sales'] = count($candidates);

        // Détail de chaque nouvelle transaction (produits, heure locale),
        // puis mapping au format des lignes du parseur CSV.
        $rows = [];
        foreach ($candidates as $item) {
            $detail = $this->client->detail((string) ($item['transaction_id'] ?? $item['id'] ?? ''));
            $rows = array_merge($rows, self::mapTransaction($detail ?? $item, $this->productResolver));
        }

        if ($rows === []) {
            return $stats;
        }

        if ($dryRun) {
            return $stats;
        }

        // Même chemin de traçabilité que l'import CSV (lot + période couverte).
        $days = array_map(static fn (array $r): string => substr((string) $r['sold_at'], 0, 10), $rows);
        $hash = hash('sha256', json_encode(array_keys($candidates), JSON_THROW_ON_ERROR));

        // Auto-réparation : un lot orphelin (crash entre create et import)
        // libérerait son empreinte UNIQUE — il n'a plus de raison d'exister.
        $stale = ImportBatch::findByHash($hash);
        if ($stale !== null) {
            ImportBatch::delete((string) $stale['id']);
        }

        $batchId = ImportBatch::create([
            'filename'     => 'synchro-api-sumup.json',
            'file_hash'    => $hash,
            'period_start' => min($days),
            'period_end'   => max($days),
            'rows_total'   => count($rows),
            'imported_by'  => 'sumup_sync',
        ]);

        try {
            $res = Sale::importBatch($batchId, $rows);
        } catch (\Throwable $e) {
            ImportBatch::delete($batchId); // pas de lot fantôme (empreinte UNIQUE)

            throw $e;
        }

        ImportBatch::finalize($batchId, $res['inserted'], $res['skipped']);

        $stats['inserted'] = $res['inserted'];
        $stats['skipped'] = $res['skipped'];
        $stats['batch_id'] = $batchId;

        return $stats;
    }

    /**
     * Convertit une transaction SumUp (détail API, ou item d'historique à
     * défaut) en lignes au format du parseur CSV (cf. SumUpCsvParser::parse).
     *
     * - Avec panier (`products[]`) : une ligne par produit, comme le CSV.
     * - Sans panier : une ligne unique (« Montant personnalisé »).
     * - `sold_at` = heure locale du point de vente (comme la colonne « Date »
     *   des rapports CSV), à partir de `local_time`.
     *
     * @param array<string,mixed> $tx
     * @param callable|null       $resolver function(string): ?string
     *
     * @return list<array<string,mixed>>
     */
    public static function mapTransaction(array $tx, ?callable $resolver = null): array
    {
        $ref = trim((string) ($tx['transaction_code'] ?? ''));
        $soldAt = self::soldAt($tx);
        if ($ref === '' || $soldAt === null) {
            return [];
        }

        $paymentRaw = (string) ($tx['simple_payment_type'] ?? $tx['payment_type'] ?? '');
        $payment = mb_strtoupper($paymentRaw) === 'CASH' ? 'LIQUIDE' : 'CARTE';
        $currency = trim((string) ($tx['currency'] ?? '')) !== '' ? (string) $tx['currency'] : 'EUR';
        $seller = isset($tx['username']) && trim((string) $tx['username']) !== ''
            ? (string) $tx['username']
            : (isset($tx['user']) && trim((string) $tx['user']) !== '' ? (string) $tx['user'] : null);

        /** @var list<array<string,mixed>> $products */
        $products = is_array($tx['products'] ?? null) ? $tx['products'] : [];

        $rows = [];
        if ($products !== []) {
            foreach ($products as $p) {
                $description = trim((string) ($p['name'] ?? ''));
                $quantity = max(1, (int) ($p['quantity'] ?? 1));
                $priceTtc = self::positiveFloat($p['total_with_vat'] ?? null)
                    ?? ((self::positiveFloat($p['price'] ?? null) ?? 0.0) * $quantity);

                $isCustom = (new SumUpCsvParser())->isCustomAmount($description);

                $productKey = null;
                if (!$isCustom && $resolver !== null) {
                    $resolved = $resolver($description);
                    $productKey = $resolved !== null && $resolved !== '' ? (string) $resolved : null;
                }

                $rows[] = [
                    'transaction_ref'  => $ref,
                    'sold_at'          => $soldAt,
                    'payment_method'   => $payment,
                    'payment_raw'      => $paymentRaw !== '' ? $paymentRaw : null,
                    'quantity'         => $quantity,
                    'description'      => $description,
                    'product_key'      => $productKey,
                    'category'         => null,
                    'sku'              => null,
                    'currency'         => $currency,
                    'price_ttc'        => round($priceTtc, 2),
                    'price_ht'         => self::positiveFloat($p['total_price'] ?? null),
                    'vat'              => self::positiveFloat($p['vat_amount'] ?? null),
                    'vat_rate'         => null,
                    'seller_account'   => $seller,
                    'is_custom_amount' => $isCustom ? 1 : 0,
                ];
            }

            return $rows;
        }

        // Transaction sans panier : montant unique (saisie libre à la borne).
        $summary = trim((string) ($tx['product_summary'] ?? ''));
        $description = $summary !== '' ? $summary : 'Montant personnalisé';
        $amount = (float) ($tx['amount'] ?? 0);

        $rows[] = [
            'transaction_ref'  => $ref,
            'sold_at'          => $soldAt,
            'payment_method'   => $payment,
            'payment_raw'      => $paymentRaw !== '' ? $paymentRaw : null,
            'quantity'         => 1,
            'description'      => $description,
            'product_key'      => null,
            'category'         => null,
            'sku'              => null,
            'currency'         => $currency,
            'price_ttc'        => round($amount, 2),
            'price_ht'         => null,
            'vat'              => self::positiveFloat($tx['vat_amount'] ?? null),
            'vat_rate'         => null,
            'seller_account'   => $seller,
            'is_custom_amount' => 1,
        ];

        return $rows;
    }

    /**
     * Heure locale de la vente au format DATETIME SQL.
     *
     * `local_time` (ex. « 2026-09-21T12:16:14.714+02:00 ») reprend l'heure
     * du point de vente, comme la colonne « Date » des rapports CSV.
     * À défaut, conversion du timestamp UTC sur Europe/Paris.
     */
    private static function soldAt(array $tx): ?string
    {
        $local = trim((string) ($tx['local_time'] ?? ''));
        if ($local !== '') {
            try {
                return (new \DateTimeImmutable($local))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                // format inattendu → repli sur timestamp UTC
            }
        }

        $utc = trim((string) ($tx['timestamp'] ?? ''));
        if ($utc === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($utc))
                ->setTimezone(new \DateTimeZone('Europe/Paris'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /** Convertit en float positif, sinon null (absent/illégal/zéro-négatif). */
    private static function positiveFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $f = (float) $value;

        return $f > 0 ? $f : null;
    }
}
