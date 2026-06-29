<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Parseur du rapport de ventes SumUp (CSV français).
 *
 * Le format attendu (entête SumUp) :
 *   Date, Type, Réf. transaction, Moyen de paiement, Quantité, Description,
 *   Catégorie, SKU, Devise, Prix avant réduction, Réduction,
 *   Prix (TTC), Prix (HT), TVA, Taux de TVA, Compte
 *
 * Le parseur est volontairement sans dépendance DB : la résolution des
 * libellés produits (product_aliases) est déléguée à un résolveur injectable,
 * ce qui permet de tester le parsing pur sans base de données.
 */
final class SumUpCsvParser
{
    /** Mois français (minuscules, sans accent) -> numéro. */
    public const FR_MONTHS = [
        'janvier'   => 1,
        'fevrier'   => 2,
        'février'   => 2,
        'mars'      => 3,
        'avril'     => 4,
        'mai'       => 5,
        'juin'      => 6,
        'juillet'   => 7,
        'aout'      => 8,
        'août'      => 8,
        'septembre' => 9,
        'octobre'   => 10,
        'novembre'  => 11,
        'decembre'  => 12,
        'décembre'  => 12,
    ];

    /**
     * Analyse un contenu CSV SumUp et renvoie des lignes normalisées.
     *
     * @param string        $csvContent Contenu brut du fichier CSV.
     * @param callable|null $resolver   function(string $description): ?string
     *                                  qui résout un libellé en product_key.
     *                                  Si null, product_key reste toujours null.
     *
     * @return array{
     *     rows: list<array<string,mixed>>,
     *     meta: array{period_start:?string, period_end:?string, total:int}
     * }
     */
    public function parse(string $csvContent, ?callable $resolver = null): array
    {
        // Normalise les fins de ligne et supprime un éventuel BOM UTF-8.
        $csvContent = str_replace(["\r\n", "\r"], "\n", $csvContent);
        $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;

        $lines = explode("\n", $csvContent);
        if ($lines === []) {
            return ['rows' => [], 'meta' => ['period_start' => null, 'period_end' => null, 'total' => 0]];
        }

        // En-tête : index des colonnes par nom.
        $header = $this->parseRow(array_shift($lines));
        $index = $this->mapHeader($header);

        $rows = [];
        $minDate = null;
        $maxDate = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cols = $this->parseRow($line);

            // On n'importe que les ventes (Type = « Vente »), si la colonne existe.
            $typeIdx = $index['Type'] ?? null;
            if ($typeIdx !== null) {
                $type = trim($cols[$typeIdx] ?? '');
                if (mb_strtolower($type) !== 'vente') {
                    continue;
                }
            }

            $get = static function (string $key) use ($index, $cols): string {
                $i = $index[$key] ?? null;

                return $i === null ? '' : trim($cols[$i] ?? '');
            };

            $dateStr = $get('Date');
            $soldAt = $this->parseFrenchDate($dateStr);

            $rawPayment = $get('Moyen de paiement');
            $description = $get('Description');
            $quantity = (int) ($get('Quantité') !== '' ? $get('Quantité') : '1');
            $priceTtc = parseFrenchFloat($get('Prix (TTC)'));

            $isCustom = $this->isCustomAmount($description);

            $productKey = null;
            if (!$isCustom && $resolver !== null) {
                $resolved = $resolver($description);
                $productKey = $resolved !== null && $resolved !== '' ? (string) $resolved : null;
            }

            $rows[] = [
                'transaction_ref'  => $get('Réf. transaction'),
                'sold_at'          => $soldAt,
                'payment_method'   => $this->normalizePayment($rawPayment),
                'payment_raw'      => $rawPayment,
                'quantity'         => $quantity > 0 ? $quantity : 1,
                'description'      => $description !== '' ? $description : null,
                'product_key'      => $productKey,
                'category'         => $get('Catégorie') !== '' ? $get('Catégorie') : null,
                'sku'              => $get('SKU') !== '' ? $get('SKU') : null,
                'currency'         => $get('Devise') !== '' ? $get('Devise') : 'EUR',
                'price_ttc'        => $priceTtc,
                'price_ht'         => $get('Prix (HT)') !== '' ? parseFrenchFloat($get('Prix (HT)')) : null,
                'vat'              => $get('TVA') !== '' ? parseFrenchFloat($get('TVA')) : null,
                'vat_rate'         => $get('Taux de TVA') !== '' ? $get('Taux de TVA') : null,
                'seller_account'   => $get('Compte') !== '' ? $get('Compte') : null,
                'is_custom_amount' => $isCustom ? 1 : 0,
            ];

            // Suivi de la période couverte par le fichier.
            if ($soldAt !== null) {
                $day = substr($soldAt, 0, 10);
                if ($minDate === null || $day < $minDate) {
                    $minDate = $day;
                }
                if ($maxDate === null || $day > $maxDate) {
                    $maxDate = $day;
                }
            }
        }

        return [
            'rows' => $rows,
            'meta' => [
                'period_start' => $minDate,
                'period_end'   => $maxDate,
                'total'        => count($rows),
            ],
        ];
    }

    /**
     * Convertit une date française (« 1 juin 2026 09:59 ») en DATETIME SQL.
     * Renvoie null si le format n'est pas reconnu.
     */
    public function parseFrenchDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Déjà au format ISO ? (sécurité)
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 19);
        }

        // « 1 juin 2026 09:59 » ou « 1 juin 2026 ».
        if (preg_match('/^(\d{1,2})\s+([a-zA-Zéûôîàèùç]+)\s+(\d{4})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?))?$/u', $value, $m)) {
            $day = (int) $m[1];
            $monthName = mb_strtolower($m[2], 'UTF-8');
            $year = (int) $m[3];
            $time = $m[4] ?? '00:00:00';
            if (mb_strlen($time) === 5) {
                $time .= ':00';
            }

            $month = self::FR_MONTHS[$monthName] ?? null;
            if ($month === null) {
                return null;
            }

            return sprintf('%04d-%02d-%02d %s', $year, $month, $day, $time);
        }

        return null;
    }

    /**
     * Normalise un libellé de moyen de paiement SumUp en CARTE ou LIQUIDE.
     *
     * Règle (§21.4) :
     *  - contient Visa / Mastercard / Express  → CARTE
     *  - contient Espèces                       → LIQUIDE
     *  - sinon                                   → CARTE par défaut (SumUp = carte).
     */
    public function normalizePayment(string $raw): string
    {
        $lower = mb_strtolower($raw, 'UTF-8');

        if (mb_strpos($lower, 'espèce') !== false
            || mb_strpos($lower, 'espece') !== false
            || mb_strpos($lower, 'cash') !== false
        ) {
            return 'LIQUIDE';
        }

        // Par défaut tout le reste (Visa, Mastercard, American Express, etc.)
        // est considéré comme un paiement par carte.
        return 'CARTE';
    }

    /**
     * Détecte un « Montant personnalisé » (libre saisie du prix à la borne).
     */
    public function isCustomAmount(string $description): bool
    {
        $lower = mb_strtolower(trim($description), 'UTF-8');

        return $lower === 'montant personnalisé'
            || str_contains($lower, 'montant personnalisé')
            || str_contains($lower, 'custom amount');
    }

    /**
     * Découpe une ligne CSV (gère les guillemets et virgules échappées).
     *
     * @return list<string>
     */
    private function parseRow(string $line): array
    {
        $row = str_getcsv($line, ',', '"', '\\');
        if ($row === false) {
            return [];
        }

        return array_map(static fn ($v) => is_string($v) ? $v : (string) $v, $row);
    }

    /**
     * Construit un dictionnaire nom de colonne -> index, en normalisant les
     * libellés (suppression des espaces, insensibilité à la casse).
     *
     * @param list<string> $header
     *
     * @return array<string,int>
     */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $key = trim($name);
            if ($key === '') {
                continue;
            }
            if (!isset($map[$key])) {
                $map[$key] = $i;
            }
        }

        return $map;
    }
}
