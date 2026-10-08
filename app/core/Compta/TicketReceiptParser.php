<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Parseur de tickets de caisse / reçus thermiques (Auchan, Carrefour, Lidl…).
 *
 * Entrée : le texte complet du ticket (résultat Tesseract ou photo lue).
 * Sortie : la structure « invoice » normalisée consommée par le
 * préremplissage du formulaire de dépense (contrat JSON partagé avec le
 * frontend, POST /admin/compta/depenses/scan) :
 *   kind:'ticket', supplier, invoice_number, purchased_at (ISO), vat_rate,
 *   amount_basis:'ttc', total_ht, total_ttc, vat_rates:[], lines[],
 *   warnings[].
 *
 * Différences avec une facture METRO :
 *  - le montant imprimé est un TTC (amount_basis='ttc' toujours) ;
 *  - le ticket est souvent tronqué (seule la partie paiement sur la photo) :
 *    c'est un cas NORMAL — lignes vides et avertissement informatif, jamais
 *    d'exception ;
 *  - les lettres de TVA en fin de ligne suivent en principe le standard
 *    français (classes A-E), contrairement aux factures METRO où la table
 *    imprimée fait foi : ici A=20, B=10, C=5,5, D=2,1, E=0 (à vérifier).
 *
 * Classe purement statique, sans dépendance DB ni OCR : robuste aux erreurs
 * de reconnaissance (espaces insécables, doubles espaces, casse et accents
 * variables). Les lignes non reconnues sont simplement ignorées avec un
 * avertissement, jamais fatales.
 */
final class TicketReceiptParser
{
    /**
     * Marques d'enseignes reconnues (comparaison insensible à la casse et
     * aux accents, par mots entiers) — sortie UPPERCASE. La liste couvre les
     * enseignes les plus fréquentes sur les tickets de l'association.
     */
    public const BRANDS = [
        'AUCHAN',
        'CARREFOUR',
        'LIDL',
        'ALDI',
        'INTERMARCHE',
        'SUPER U',
        'SYSTEME U',
        'CASINO',
        'MONOPRIX',
        'FRANPRIX',
        'BOULANGER',
        'LERROY MERLIN',
        'LEROY MERLIN',
        'BRICOMARCHE',
        'CASTORAMA',
        'IKEA',
        'ACTION',
        'DECATHLON',
        'FNAC',
        'DARTY',
        'KIABI',
        'NETTO',
        'PICARD',
        'PAUL',
        'NORBERT',
    ];

    /**
     * Classes de TVA standard imprimées en fin de ligne de ticket
     * (lettre → taux) — contrairement aux factures METRO, ce standard est
     * en principe respecté ; un avertissement invite à vérifier.
     */
    public const STANDARD_VAT_CLASSES = [
        'A' => 20.0,
        'B' => 10.0,
        'C' => 5.5,
        'D' => 2.1,
        'E' => 0.0,
    ];

    /** Mois français (clés minuscules SANS accent) → numéro. */
    private const FR_MONTHS = [
        'janvier'   => 1,
        'janv'      => 1,
        'fevrier'   => 2,
        'fev'       => 2,
        'fevr'      => 2,
        'mars'      => 3,
        'avril'     => 4,
        'avr'       => 4,
        'mai'       => 5,
        'juin'      => 6,
        'juillet'   => 7,
        'juil'      => 7,
        'aout'      => 8,
        'septembre' => 9,
        'sept'      => 9,
        'octobre'   => 10,
        'oct'       => 10,
        'novembre'  => 11,
        'nov'       => 11,
        'decembre'  => 12,
        'dec'       => 12,
    ];

    /**
     * Fragments (comparés en majuscules sans accents) qui identifient une
     * ligne à ne JAMAIS considérer comme un produit : totaux, paiement,
     * mentions légales, horaires…
     */
    private const SKIP_KEYWORDS = [
        'TOTAL',
        'MONTANT',
        'TVA',
        'CB',
        'CARTE',
        'ESPECES',
        'RENDU',
        'REMISE',
        'SOLDE',
        'POINT',
        'CAISSE',
        'TICKET',
        'HOTE',
        'ACCUEILLI',
        'TELEPHONE',
        'FACEBOOK',
        'WWW',
        'HTTP',
        'BANCAIRE',
        'DEBIT',
        'CREDIT',
        'CONSERVER',
        'RETROUVEZ',
        'LUNDI',
        'SAMEDI',
        'DIMANCHE',
        'AUTO',
        'SIRET',
        'LOGO',
    ];

    /**
     * Analyse le texte d'un ticket de caisse et renvoie la structure
     * « invoice ». Ne lève jamais d'exception métier : un ticket tronqué ou
     * illisible renvoie lines:[] et des avertissements explicites.
     *
     * @return array{
     *     kind:'ticket', supplier:?string, invoice_number:?string,
     *     purchased_at:?string, vat_rate:?float, amount_basis:'ttc',
     *     total_ht:?float, total_ttc:?float, vat_rates:list<never>,
     *     lines:list<array{label:string,ean:string,article:string,
     *                      unit_price:?float,units:int,total:float,
     *                      vat_letter:?string,vat_rate:?float,notes:string}>,
     *     warnings:list<string>
     * }
     */
    public static function parse(string $text): array
    {
        $warnings = [];

        // Normalisation globale : fins de ligne et BOM éventuel.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;

        // Lignes produits : première passe OCR seulement (voir MetroInvoiceParser).
        $parts = preg_split('/^\s*-{3,}\s*OCR alt\s*-+.*$/m', $text) ?: [];
        $linesText = $parts[0] ?? $text;

        $supplier = self::extractSupplier($text);
        $invoiceNumber = self::extractTicketNumber($text);
        $purchasedAt = self::extractDate($text);
        $totalTtc = self::extractTotalTtc($text, $warnings);

        $lines = self::extractLines($linesText, $warnings);
        if ($lines === []) {
            // Ticket tronqué (seule la partie paiement sur la photo) : cas
            // normal, informatif seulement.
            $warnings[] = 'Aucune ligne produit reconnue (ticket tronqué ?) — seul le montant est prérempli.';
        }

        // Taux de TVA global (classes standard) : un seul taux distinct
        // utilisé par les lignes → ce taux, et le HT est déduit du TTC ;
        // plusieurs → null + avertissement ; aucun → null.
        [$vatRate, $totalHt] = self::resolveGlobalVat($lines, $totalTtc, $warnings);

        return [
            'kind'           => 'ticket',
            'supplier'       => $supplier,
            'invoice_number' => $invoiceNumber,
            'purchased_at'   => $purchasedAt,
            'vat_rate'       => $vatRate,
            'amount_basis'   => 'ttc',
            'total_ht'       => $totalHt,
            'total_ttc'      => $totalTtc,
            'vat_rates'      => [],
            'lines'          => $lines,
            'warnings'       => $warnings,
        ];
    }

    /**
     * Fournisseur : une marque connue (CONSTANTE publique BRANDS) d'abord,
     * cherchée sur toutes les lignes et comparée sans casse ni accents par
     * mots entiers ; sinon la première ligne courte (< 25 caractères) sans
     * chiffre fait office d'enseigne ; sinon null. Sortie UPPERCASE.
     */
    private static function extractSupplier(string $text): ?string
    {
        // Passe 1 : une marque reconnue fait foi où qu'elle soit (la 1re
        // ligne n'est pas toujours lisible sur la photo).
        foreach (explode("\n", $text) as $raw) {
            $line = self::normKey($raw);
            if ($line === '') {
                continue;
            }

            foreach (self::BRANDS as $brand) {
                // Mots entiers : « LIDL » ne matche pas « SALDI ».
                if (preg_match('/\b' . preg_quote(self::normKey($brand), '/') . '\b/u', $line) === 1) {
                    return $brand;
                }
            }
        }

        // Passe 2 : repli sur la première ligne « en-tête » plausible
        // (courte, sans chiffre) — un ticket complet commence par l'enseigne.
        foreach (explode("\n", $text) as $raw) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                continue;
            }
            if (mb_strlen($trimmed) < 25 && !preg_match('/\d/', $trimmed) && preg_match('/\p{L}{2,}/u', $trimmed) === 1) {
                return mb_strtoupper($trimmed, 'UTF-8');
            }
        }

        return null;
    }

    /**
     * Numéro de ticket : ligne « Ticket : 67647 » (prioritaire).
     */
    private static function extractTicketNumber(string $text): ?string
    {
        if (preg_match('/Ticket\s*:?\s*(\d{3,10})/i', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Date du ticket, par priorité :
     *  a) « Le 31 août 2026 à 15:55 » — mois français (complets et
     *     abréviations, casse et accents ignorés) ;
     *  b) « le 31/08/26 » / « 31/08/2026 » (séparateur « / ») ;
     *  c) « 31.08.2026 » (séparateur « . », année 4 chiffres).
     * Renvoie null si absente ou invalide (checkdate).
     */
    private static function extractDate(string $text): ?string
    {
        // a) « 31 août 2026 » — alternance triée du plus long au plus court
        // pour que « septembre » gagne sur « sept ».
        $months = self::FR_MONTHS;
        uksort($months, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $alternation = implode('|', array_map('preg_quote', array_keys($months)));
        if (preg_match('/\b(\d{1,2})(?:er)?\s+(' . $alternation . ')\.?\s+(\d{4})\b/iu', $text, $m) === 1) {
            $month = self::FR_MONTHS[self::normKey($m[2])] ?? null;
            if ($month !== null) {
                $iso = self::toIso((int) $m[1], $month, (int) $m[3]);
                if ($iso !== null) {
                    return $iso;
                }
            }
        }

        // b) « le 31/08/26 » ou « 31/08/2026 » — année sur 2 ou 4 chiffres.
        if (preg_match('/\ble\s*:?\s*(\d{1,2})\/(\d{1,2})\/(\d{4}|\d{2})\b/i', $text, $m) === 1) {
            $year = (int) $m[3];
            $iso = self::toIso((int) $m[1], (int) $m[2], $year < 100 ? 2000 + $year : $year);
            if ($iso !== null) {
                return $iso;
            }
        }

        // c) « 31.08.2026 ».
        if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $text, $m) === 1) {
            $iso = self::toIso((int) $m[1], (int) $m[2], (int) $m[3]);
            if ($iso !== null) {
                return $iso;
            }
        }

        return null;
    }

    /** Jour/mois/année → ISO, null si la date n'existe pas (checkdate). */
    private static function toIso(int $day, int $month, int $year): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Total TTC du ticket :
     *  1) « MONTANT = 37,24 EUR » (partie paiement, fait foi) ;
     *  2) ligne « TOTAL A PAYER » / « NET A PAYER » → dernier prix de la ligne ;
     *  3) autre ligne « TOTAL » (« TOTAL TVA » exclue) ;
     *  4) « TOTAL PRODUITS » seul → utilisé avec avertissement ;
     *  5) sinon null.
     *
     * @param list<string> $warnings Avertissements accumulés (par référence).
     */
    private static function extractTotalTtc(string $text, array &$warnings): ?float
    {
        // « MONTANT = 37,24 EUR » ; virgule parfois perdue par l'OCR
        // (« MONTANT= 37 24 EUR ») — alors les deux derniers groupes sont
        // euros puis centimes.
        if (preg_match('/MONTANT\s*[=:]\s*(\d+[.,]\d{2})/i', $text, $m) === 1) {
            return round(parseFrenchFloat($m[1]), 2);
        }
        if (preg_match('/MONTANT\s*[=:]\s*(\d{1,5})\s+(\d{2})\s*(?:EUR)?/i', $text, $m) === 1) {
            return round((float) ($m[1] . '.' . $m[2]), 2);
        }

        $produitsOnly = null;
        foreach (explode("\n", $text) as $raw) {
            $up = self::upperNoAccents($raw);
            if ($up === '' || str_contains($up, 'TOTAL TVA')) {
                continue;
            }

            $price = self::lastPriceInLine($raw);
            if ($price === null) {
                continue;
            }

            if (str_contains($up, 'NET A PAYER') || str_contains($up, 'TOTAL A PAYER')) {
                return $price;
            }
            if (str_contains($up, 'TOTAL')) {
                if (str_contains($up, 'TOTAL PRODUITS')) {
                    $produitsOnly = $produitsOnly ?? $price;

                    continue;
                }
                return $price;
            }
        }

        if ($produitsOnly !== null) {
            $warnings[] = 'Seul « TOTAL PRODUITS » a été lu : montant TTC à vérifier sur le ticket.';

            return $produitsOnly;
        }

        return null;
    }

    /** Dernier prix « X,YZ » d'une ligne, ou null. */
    private static function lastPriceInLine(string $line): ?float
    {
        if (preg_match_all('/(\d+[.,]\d{2})/u', $line, $ms) === 0) {
            return null;
        }

        $last = end($ms[0]);

        return round(parseFrenchFloat((string) $last), 2);
    }

    /**
     * Lignes produits d'un ticket complet : une ligne par ligne de texte,
     * finissant par un prix (et une lettre de classe TVA A-E optionnelle),
     * avec du texte alphabétique (≥ 2 lettres) avant. Les lignes contenant
     * un mot-clé (totaux, paiement, mentions) sont ignorées.
     *
     * Quantité : « 2 X 1,33 » / « 3@0,80 » → unités + prix unitaire ;
     * sinon 1 unité au prix de la ligne. La lettre TVA finale donne
     * vat_rate via les classes standard (avertissement de vérification).
     *
     * @param list<string> $warnings Avertissements accumulés (par référence).
     *
     * @return list<array{label:string,ean:string,article:string,
     *                    unit_price:?float,units:int,total:float,
     *                    vat_letter:?string,vat_rate:?float,notes:string}>
     */
    private static function extractLines(string $text, array &$warnings): array
    {
        $lines = [];
        $standardNoticeShown = false;

        foreach (explode("\n", $text) as $raw) {
            // Normalisation : insécables → espace, espaces multiples réduits.
            $line = str_replace(["\u{00a0}", "\u{202f}"], ' ', $raw);
            $line = trim((string) preg_replace('/ {2,}/', ' ', $line));

            if ($line === '') {
                continue;
            }

            // Mots-clés : jamais une ligne produit (comparé sans accents).
            $up = self::upperNoAccents($line);
            foreach (self::SKIP_KEYWORDS as $keyword) {
                if (str_contains($up, $keyword)) {
                    continue 2;
                }
            }

            // Chaînes de montants façon OCR de bande papier (« 03,21,46,92,92 »
            // pour un téléphone) : au moins 3 montants décimaux = jamais un
            // produit, c'est du bruit de paiement ou d'en-tête.
            if (preg_match_all('/\d+[.,]\d{2}/u', $line) >= 3) {
                continue;
            }

            // Prix final (lettre TVA A-E optionnelle) : « 0,89 C ».
            if (preg_match('/(\d+[.,]\d{2})\s*([A-E])?\s*$/u', $line, $pm, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) !== 1) {
                continue;
            }

            $total = round(parseFrenchFloat((string) $pm[1][0]), 2);
            $vatLetter = ($pm[2][0] ?? null) !== null && ($pm[2][0] ?? '') !== '' ? (string) $pm[2][0] : null;

            // Quantité « 2 X 1,33 » / « 3@0,80 » éventuelle avant le prix.
            $units = 1;
            $unitPrice = $total;
            $cutAt = (int) $pm[1][1];
            if (preg_match('/(\d{1,3})\s*[xX@]\s*(\d+[.,]\d{2,3})/u', $line, $qm, PREG_OFFSET_CAPTURE) === 1) {
                $qtyEnd = (int) $qm[0][1] + strlen((string) $qm[0][0]);
                if ($qtyEnd <= $cutAt) {
                    $units = max(1, (int) $qm[1][0]);
                    $unitPrice = round(parseFrenchFloat((string) $qm[2][0]), 3);
                    $cutAt = (int) $qm[0][1];
                }
            }

            // Libellé = partie gauche épurée (≥ 2 lettres exigées).
            $label = trim(mb_strcut($line, 0, max(0, $cutAt)));
            $label = trim((string) preg_replace('/[\s:;,\-\.]+$/u', '', $label));
            if (preg_match_all('/\p{L}/u', $label) < 2) {
                continue;
            }

            // Lettre TVA → classes standard (avertissement une seule fois).
            $vatRate = null;
            if ($vatLetter !== null) {
                $vatRate = self::STANDARD_VAT_CLASSES[$vatLetter] ?? null;
                if ($vatRate !== null && !$standardNoticeShown) {
                    $standardNoticeShown = true;
                    $warnings[] = 'Taux déduits des classes standard (A=20 %, B=10 %, C=5,5 %, D=2,1 %) — à vérifier sur le ticket.';
                }
            }

            $lines[] = [
                'label'      => $label,
                'ean'        => '',
                'article'    => '',
                'unit_price' => $unitPrice,
                'units'      => $units,
                'total'      => $total,
                'vat_letter' => $vatLetter,
                'vat_rate'   => $vatRate,
                'notes'      => '',
            ];
        }

        return $lines;
    }

    /**
     * Taux de TVA global du formulaire (un seul champ) :
     *  - un seul taux distinct utilisé → ce taux, et HT = TTC ÷ (1 + taux) ;
     *  - plusieurs taux → null + avertissement « à répartir manuellement » ;
     *  - aucun taux lisible → null (total HT indisponible).
     *
     * @param list<array{label:string,ean:string,article:string,
     *                    unit_price:?float,units:int,total:float,
     *                    vat_letter:?string,vat_rate:?float,notes:string}> $lines
     * @param list<string> $warnings
     *
     * @return array{0:?float,1:?float} [vat_rate, total_ht]
     */
    private static function resolveGlobalVat(array $lines, ?float $totalTtc, array &$warnings): array
    {
        $usedRates = [];
        foreach ($lines as $line) {
            $rate = $line['vat_rate'];
            if ($rate !== null && !in_array($rate, $usedRates, true)) {
                $usedRates[] = $rate;
            }
        }

        if (count($usedRates) > 1) {
            $parts = [];
            foreach ($usedRates as $rate) {
                $parts[] = self::formatRate($rate) . ' %';
            }
            $warnings[] = 'TVA multiple sur le ticket : ' . implode(' + ', $parts) . ' — à répartir manuellement.';

            return [null, null];
        }

        if (count($usedRates) === 1) {
            $rate = $usedRates[0];

            return [$rate, $totalTtc !== null ? round($totalTtc / (1 + $rate / 100), 2) : null];
        }

        return [null, null];
    }

    /** Taux formaté « à la française » : 5,5 / 20 / 0 (sans décimale inutile). */
    private static function formatRate(float $rate): string
    {
        // Comparaison floue : la stricte égalité float/int renverrait
        // « 20,0 » pour 20.0.
        return (float) (int) $rate === $rate
            ? number_format($rate, 0, ',', ' ')
            : number_format($rate, 1, ',', ' ');
    }

    /**
     * Clé de comparaison « normKey » : minuscules, sans accents, séparateurs
     * repliés en espaces (même logique que AliasSuggester::normalizeKey).
     */
    private static function normKey(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', self::deaccent(mb_strtolower(trim($value), 'UTF-8')))));
    }

    /** Ligne en MAJUSCULES sans accents (comparaison aux mots-clés). */
    private static function upperNoAccents(string $value): string
    {
        return mb_strtoupper(self::deaccent(str_replace(["\u{00a0}", "\u{202f}"], ' ', $value)), 'UTF-8');
    }

    /** Suppression des accents (table manuelle — aucune dépendance intl). */
    private static function deaccent(string $value): string
    {
        return strtr($value, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'œ' => 'oe', 'Æ' => 'AE', 'æ' => 'ae',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I',
            'Ô' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Œ' => 'OE',
        ]);
    }
}
