<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Parseur de factures fournisseur METRO (texte brut issu d'un OCR ou collé).
 *
 * Entrée : le texte complet de la facture (résultat Tesseract ou copié-collé).
 * Sortie : la structure « invoice » normalisée consommée par le préremplissage
 * du formulaire d'achat (contrat JSON de POST /admin/compta/achats/scan) :
 *   supplier, invoice_number, purchased_at (ISO), vat_rate, amount_basis,
 *   total_ht, total_ttc, vat_rates[], lines[], warnings[].
 *
 * TVA multi-taux : les lettres METRO ne suivent PAS le standard français
 * (réel observé : B = 5,5 %, D = 20 %) — la table TVA imprimée sur la facture
 * (« 153,57 B = 5,50% 8,45 162,02 ») fait foi et alimente vat_rates[] ; la
 * lettre en fin de chaque ligne produit donne vat_letter / vat_rate. Un seul
 * taux distinct utilisé → vat_rate global (rétrocompatibilité) ; taux
 * multiples → vat_rate null + avertissement « à répartir manuellement ».
 *
 * Classe purement statique, sans dépendance DB ni OCR : robuste aux erreurs
 * de reconnaissance (O/0 confondus, espaces insécables, puces de notes ①②…,
 * doubles espaces). Les lignes non reconnues sont simplement ignorées avec un
 * avertissement, jamais fatales.
 */
final class MetroInvoiceParser
{
    /** Taux de TVA français autorisés (mêmes valeurs que les achats). */
    private const VAT_RATES = [20.0, 10.0, 5.5, 2.1, 0.0];

    /**
     * Fragments (comparés en majuscules) qui identifient une ligne à ignorer :
     * en-têtes de colonnes, lignes « prix au kg », totaux, mentions légales.
     */
    private const SKIP_PATTERNS = [
        'PRIX AU KG OU AU LITRE',
        'MM EAN',
        'NUMERO ARTICLE',
        'NUMÉRO ARTICLE',
        'DESIGNATION',
        'DÉSIGNATION',
        'PRIX UNITAIRE',
        'COLISAGE',
        'NOMBRE DE COLIS',
        'POIDS TOTAL',
        'CONSIGNE',
        'TOTAL VOLUME',
        'TOTAL À PAYER',
        'TOTAL A PAYER',
        'MSC',
        'ASC',
        'ECOC',
    ];

    /**
     * Analyse le texte d'une facture METRO et renvoie la structure « invoice ».
     *
     * Ne lève jamais d'exception métier : un texte qui ne ressemble pas à une
     * facture renvoie lines:[] et des avertissements explicites.
     *
     * @return array{
     *     supplier:?string, invoice_number:?string, purchased_at:?string,
     *     vat_rate:?float, amount_basis:'ht', total_ht:?float, total_ttc:?float,
     *     vat_rates:list<array{letter:string,rate:float,base_ht:?float,
     *                          vat:?float,total_ttc:?float}>,
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

        $supplier = preg_match('/METRO/i', $text) === 1 ? 'METRO' : null;
        if ($supplier === null) {
            $warnings[] = 'Fournisseur METRO non détecté — vérifiez qu\'il s\'agit bien d\'une facture METRO.';
        }

        $invoiceNumber = self::extractInvoiceNumber($text);
        $purchasedAt = self::extractDate($text);
        $vatRates = self::extractVatTable($text);
        $totalHt = self::extractTotalHt($text);
        // Total TTC : « Total à payer » prioritaire ; sinon somme de la
        // table TVA si toutes ses lignes sont complètes ; sinon null.
        $totalTtc = self::extractTotalTtc($text) ?? self::totalTtcFromVatTable($vatRates);

        $lines = self::extractLines($text, $vatRates, $warnings);
        if ($lines === []) {
            $warnings[] = 'Aucune ligne produit reconnue — collez le texte manuellement.';
        }

        // Taux de TVA global : un seul taux distinct utilisé → ce taux ;
        // plusieurs (ou aucun) → null + avertissement explicite.
        $vatRate = self::resolveGlobalVatRate($lines, $vatRates, $warnings);

        // Cohérence globale : Σ lignes vs Total H.T. (tolérance 2 centimes).
        if ($totalHt !== null && $lines !== []) {
            $sum = 0.0;
            foreach ($lines as $line) {
                $sum += (float) $line['total'];
            }
            if (abs($sum - $totalHt) > 0.02) {
                $warnings[] = sprintf(
                    'Somme des lignes (%.2f €) différente du Total H.T. (%.2f €) — des lignes ont pu être manquées.',
                    $sum,
                    $totalHt
                );
            }
        }

        // Cohérence table TVA : Σ bases HT vs Total H.T. (tolérance 2 c).
        $sumBase = 0.0;
        $hasBase = false;
        foreach ($vatRates as $entry) {
            if ($entry['base_ht'] !== null) {
                $hasBase = true;
                $sumBase += $entry['base_ht'];
            }
        }
        if ($hasBase && $totalHt !== null && abs($sumBase - $totalHt) > 0.02) {
            $warnings[] = sprintf(
                'Somme des bases HT de la table TVA (%.2f €) différente du Total H.T. (%.2f €) — à vérifier.',
                $sumBase,
                $totalHt
            );
        }

        return [
            'supplier'       => $supplier,
            'invoice_number' => $invoiceNumber,
            'purchased_at'   => $purchasedAt,
            'vat_rate'       => $vatRate,
            'amount_basis'   => 'ht',
            'total_ht'       => $totalHt,
            'total_ttc'      => $totalTtc,
            'vat_rates'      => $vatRates,
            'lines'          => $lines,
            'warnings'       => $warnings,
        ];
    }

    /**
     * Numéro de facture : ligne « N° FACTURE 0/0(087)0054/033871 (… ».
     *
     * Le mot FACTURE est exigé (une « facture : date » ou « facturée » ne
     * matche pas : aucun N immédiatement avant, ou « é » bloquant). Les O/o/Q
     * du token numérique sont normalisés en 0 (erreurs OCR), les marqueurs de
     * note ①-⑳ collés au dernier chiffre sont retirés, et la référence
     * entre parenthèses qui suit (chantier) est ignorée.
     */
    public static function extractInvoiceNumber(string $text): ?string
    {
        if (preg_match('/N\s*[\x{00B0}\x{00BA}o]?\s*F\s*A\s*C\s*T\s*U\s*R\s*E\s*([0-9OoQ\/().\- ]{6,40})/u', $text, $m) !== 1) {
            return null;
        }

        $token = $m[1];
        // Marqueurs de note ①-⑳ éventuellement collés au dernier chiffre.
        $token = preg_replace('/[\x{2460}-\x{2473}]/u', '', $token) ?? $token;
        // Confusions OCR classiques dans un token purement numérique.
        $token = strtr(trim($token), ['O' => '0', 'o' => '0', 'Q' => '0']);

        // On garde le premier bloc (le n°) : la suite « (054-052687) » est
        // un autre identifiant, séparé par une espace.
        if (preg_match('/^\d[\d\/().\-]{5,39}/', $token, $t) !== 1) {
            return null;
        }

        return $t[0];
    }

    /**
     * Date de facture « Date facture : 02-10-2026 » → ISO yyyy-mm-dd.
     * Renvoie null si absente ou invalide (checkdate).
     */
    public static function extractDate(string $text): ?string
    {
        if (preg_match('/facture\s*:?\s*(\d{2})\s*[.\/\- ]\s*(\d{2})\s*[.\/\- ]\s*(\d{4})/i', $text, $m) === 1) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }

    /**
     * Table TVA imprimée sur la facture, ligne par ligne :
     *   153,57 B = 5,50% 8,45 162,02
     *   11,75 D = 20,00% 2,35 14,10
     *
     * Les lettres METRO ne suivent pas le standard A=20/B=10/C=5,5/D=2,1 :
     * ce tableau fait foi. La base HT est le nombre AVANT la lettre ; après
     * « = x,y% » viennent le montant de TVA puis le TTC (colines optionnelles,
     * photo coupée). Dédoublonné par lettre, ordre d'apparition conservé.
     *
     * @return list<array{letter:string,rate:float,base_ht:?float,
     *                    vat:?float,total_ttc:?float}>
     */
    private static function extractVatTable(string $text): array
    {
        if (preg_match_all(
            '/^[ \x{00a0}\x{202f}]*([ \d\x{00a0}\x{202f}]+[.,]\d{2})[ \x{00a0}\x{202f}]+([A-Z])[ \x{00a0}\x{202f}]*=[ \x{00a0}\x{202f}]*(\d{1,2})[.,](\d{1,2})[ \x{00a0}\x{202f}]*%'
            . '(?:[ \x{00a0}\x{202f}]+([ \d\x{00a0}\x{202f}]+[.,]\d{2}))?(?:[ \x{00a0}\x{202f}]+([ \d\x{00a0}\x{202f}]+[.,]\d{2}))?[ \x{00a0}\x{202f}]*$/mu',
            $text,
            $ms,
            PREG_SET_ORDER
        ) === 0) {
            return [];
        }

        $rates = [];
        $seen = [];
        foreach ($ms as $m) {
            $letter = $m[2];
            if (isset($seen[$letter])) {
                continue;
            }
            $seen[$letter] = true;
            $rates[] = [
                'letter'     => $letter,
                'rate'       => round((float) ($m[3] . '.' . $m[4]), 1),
                // Le groupe 1 (nombre AVANT la lettre) est la base HT ;
                // les deux nombres après le taux sont la TVA puis le TTC.
                'base_ht'    => round(parseFrenchFloat($m[1]), 2),
                'vat'        => ($m[5] ?? '') !== '' ? round(parseFrenchFloat($m[5]), 2) : null,
                'total_ttc'  => ($m[6] ?? '') !== '' ? round(parseFrenchFloat($m[6]), 2) : null,
            ];
        }

        return $rates;
    }

    /**
     * Total TTC de secours : si « Total à payer » est illisible mais que la
     * table TVA est complète (chaque ligne a un TTC), la somme fait foi.
     *
     * @param list<array{letter:string,rate:float,base_ht:?float,
     *                   vat:?float,total_ttc:?float}> $vatRates
     */
    private static function totalTtcFromVatTable(array $vatRates): ?float
    {
        if ($vatRates === []) {
            return null;
        }

        $sum = 0.0;
        foreach ($vatRates as $entry) {
            if ($entry['total_ttc'] === null) {
                return null;
            }
            $sum += $entry['total_ttc'];
        }

        return round($sum, 2);
    }

    /**
     * Taux de TVA global du formulaire (un seul champ) :
     *  - un seul taux distinct utilisé par les lignes → ce taux (s'il est un
     *    taux français) — cas facture mono-taux, rétrocompatible ;
     *  - plusieurs taux distincts → null + avertissement « TVA multiple »
     *    listant les taux et leurs bases (à répartir manuellement) ;
     *  - aucune lettre résolue mais table mono-taux → ce taux (photo où les
     *    lettres de lignes sont perdues) ;
     *  - lettres présentes mais table illisible → null + avertissement clair.
     *
     * @param list<array{label:string,ean:string,article:string,
     *                    unit_price:?float,units:int,total:float,
     *                    vat_letter:?string,vat_rate:?float,notes:string}> $lines
     * @param list<array{letter:string,rate:float,base_ht:?float,
     *                   vat:?float,total_ttc:?float}> $vatRates
     * @param list<string> $warnings
     */
    private static function resolveGlobalVatRate(array $lines, array $vatRates, array &$warnings): ?float
    {
        $usedRates = [];
        $letters = [];
        foreach ($lines as $line) {
            $letter = $line['vat_letter'];
            if ($letter !== null) {
                $letters[$letter] = true;
            }
            $rate = $line['vat_rate'];
            if ($rate !== null && !in_array($rate, $usedRates, true)) {
                $usedRates[] = $rate;
            }
        }

        // Taux multiples utilisés : pas de taux global possible.
        if (count($usedRates) > 1) {
            $parts = [];
            foreach ($vatRates as $entry) {
                if (!in_array($entry['rate'], $usedRates, true)) {
                    continue;
                }
                $rateTxt = self::formatRate($entry['rate']);
                $baseTxt = $entry['base_ht'] !== null
                    ? sprintf(' (base %s)', number_format($entry['base_ht'], 2, ',', ' '))
                    : '';
                $parts[] = $rateTxt . ' %' . $baseTxt;
            }
            $warnings[] = 'TVA multiple : ' . implode(' + ', $parts) . ' — à répartir manuellement.';

            return null;
        }

        // Un seul taux utilisé : retenu s'il est un taux français.
        if (count($usedRates) === 1) {
            $rate = $usedRates[0];
            if (in_array($rate, self::VAT_RATES, true)) {
                return $rate;
            }
            $warnings[] = sprintf(
                'Taux de TVA lu « %s %% » non reconnu (taux français : 20, 10, 5,5, 2,1 ou 0) — à vérifier.',
                number_format($rate, 1, ',', ' ')
            );

            return null;
        }

        // Aucune lettre résolue : une table mono-taux fait foi.
        if (count($vatRates) === 1) {
            $rate = $vatRates[0]['rate'];
            if (in_array($rate, self::VAT_RATES, true)) {
                return $rate;
            }
            $warnings[] = sprintf(
                'Taux de TVA lu « %s %% » non reconnu (taux français : 20, 10, 5,5, 2,1 ou 0) — à vérifier.',
                number_format($rate, 1, ',', ' ')
            );

            return null;
        }

        // Des lettres existent mais aucune table : photo coupée typiquement.
        if ($letters !== []) {
            $warnings[] = sprintf(
                'Lettres TVA lues (%s) mais table des taux illisible (table coupée sur la photo ?) — taux à saisir manuellement.',
                implode(', ', array_keys($letters))
            );
        }

        return null;
    }

    /** Taux formaté « à la française » : 5,5 / 20 / 0 (sans décimale inutile). */
    private static function formatRate(float $rate): string
    {
        // Comparaison floue : (int) 20.0 = 20 et 20.0 === 20.0, mais la
        // stricte égalité float/int renverrait « 20,0 » pour 20.0.
        return (float) (int) $rate === $rate
            ? number_format($rate, 0, ',', ' ')
            : number_format($rate, 1, ',', ' ');
    }

    /**
     * Total HT : « Total H.T. : 250,46 » ou « Montant hors T.V.A. : 250,46 »
     * (premier des deux motifs trouvé).
     */
    private static function extractTotalHt(string $text): ?float
    {
        foreach ([
            '/Total\s*H\.?\s*T\.?\s*:?\s*([\d\s]+[.,]\d{2})/i',
            '/Montant\s*hors\s*T\.?\s*V\.?\s*A\.?\s*:?\s*([\d\s]+[.,]\d{2})/i',
        ] as $re) {
            if (preg_match($re, $text, $m) === 1) {
                return round(parseFrenchFloat($m[1]), 2);
            }
        }

        return null;
    }

    /** Total TTC : « Total à payer 264,24 » (tolère a/à et les deux points). */
    private static function extractTotalTtc(string $text): ?float
    {
        if (preg_match('/Total\s*[\x{00E0}a]\s*payer\s*:?\s*([\d\s]+[.,]\d{2})/iu', $text, $m) === 1) {
            return round(parseFrenchFloat($m[1]), 2);
        }

        return null;
    }

    /**
     * Lignes produits : une ligne de facture par ligne de texte.
     *
     * Chaque ligne est normalisée (puces OCR retirées, espaces insécables et
     * multiples réduits), filtrée (en-têtes, totaux, mentions légales), puis
     * confrontée au motif STRICT « EAN article désignation PU colisage qté
     * montant [B] [P] » ; en cas d'échec, un motif RELAXÉ tente de récupérer
     * les lignes dégradées par l'OCR.
     *
     * La lettre TVA finale de chaque ligne est conservée (vat_letter) et
     * convertie en taux via la table TVA lue sur la facture (vat_rate) ;
     * une lettre absente de la table génère un avertissement (table coupée).
     *
     * @param list<array{letter:string,rate:float,base_ht:?float,
     *                   vat:?float,total_ttc:?float}> $vatRates
     * @param list<string> $warnings Avertissements accumulés (par référence).
     *
     * @return list<array{label:string,ean:string,article:string,
     *                    unit_price:?float,units:int,total:float,
     *                    vat_letter:?string,vat_rate:?float,notes:string}>
     */
    private static function extractLines(string $text, array $vatRates, array &$warnings): array
    {
        $lines = [];
        $missingLetters = [];

        foreach (explode("\n", $text) as $raw) {
            // Normalisation : insécables → espace, puces ①-⑳ retirées,
            // espaces multiples réduits, trim.
            $line = str_replace(["\u{00a0}", "\u{202f}"], ' ', $raw);
            $line = preg_replace('/[\x{2460}-\x{2473}]/u', '', $line) ?? $line;
            $line = trim((string) preg_replace('/ {2,}/', ' ', $line));

            if ($line === '' || self::isSkippable($line)) {
                continue;
            }

            $parsed = self::matchStrict($line) ?? self::matchRelaxed($line, $warnings);
            if ($parsed === null) {
                continue;
            }

            // Lettre TVA de la ligne → taux via la table lue sur la facture.
            $vatLetter = $parsed['vat_letter'];
            $vatRate = null;
            if ($vatLetter !== null) {
                foreach ($vatRates as $entry) {
                    if ($entry['letter'] === $vatLetter) {
                        $vatRate = $entry['rate'];
                        break;
                    }
                }
                if ($vatRate === null && !isset($missingLetters[$vatLetter])) {
                    $missingLetters[$vatLetter] = true;
                    $warnings[] = sprintf(
                        'Lettre TVA "%s" sans taux lu (table coupée sur la photo ?) — taux de la ligne à compléter.',
                        $vatLetter
                    );
                }
            }

            // Cohérence ligne : colisage×qté×PU vs montant (tolérance 2 c).
            $unitPrice = $parsed['unit_price'];
            if ($parsed['colisage'] !== null && $parsed['qty'] !== null && $unitPrice !== null) {
                $expected = round($parsed['colisage'] * $parsed['qty'] * $unitPrice, 2);
                if (abs($expected - $parsed['total']) > 0.02) {
                    $warnings[] = sprintf(
                        'Ligne « %s » : somme incohérente (colisage×qté×PU = %.2f ≠ %.2f), à vérifier.',
                        $parsed['label'],
                        $expected,
                        $parsed['total']
                    );
                }
            }

            $lines[] = [
                'label'      => $parsed['label'],
                'ean'        => $parsed['ean'],
                'article'    => $parsed['article'],
                'unit_price' => $unitPrice,
                'units'      => $parsed['units'],
                'total'      => $parsed['total'],
                'vat_letter' => $vatLetter,
                'vat_rate'   => $vatRate,
                'notes'      => 'EAN ' . $parsed['ean'] . ' · art. ' . $parsed['article'],
            ];
        }

        return $lines;
    }

    /** Une ligne à ignorer (en-tête, total, mention légale, certification) ? */
    private static function isSkippable(string $line): bool
    {
        if (str_starts_with($line, '***')) {
            return true;
        }

        $upper = mb_strtoupper($line, 'UTF-8');
        foreach (self::SKIP_PATTERNS as $pattern) {
            if (str_contains($upper, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Motif STRICT d'une ligne produit METRO :
     *   5449000340085 3162401 MINUTE MAID … 0,720 24 1 17,28 B [P]
     *
     * La lettre TVA finale est capturée (le marqueur promo « P » n'est JAMAIS
     * une lettre TVA : ligne finissant par « 13,92 P » → lettre null).
     *
     * @return array{label:string,ean:string,article:string,unit_price:?float,
     *               colisage:int,qty:int,units:int,total:float,
     *               vat_letter:?string}|null
     */
    private static function matchStrict(string $line): ?array
    {
        if (preg_match(
            '/^(\d{12,14})\s+(\d{5,8})\s+(.+?)\s+(\d{1,3}[.,]\d{3})\s+(\d{1,4})\s+(\d{1,4})\s+(\d{1,6}[.,]\d{2})(?:\s+([A-Z]))?(?:\s+P)?$/',
            $line,
            $m
        ) !== 1) {
            return null;
        }

        $colisage = (int) $m[5];
        $qty = (int) $m[6];
        $vatLetter = ($m[8] ?? '') !== '' && $m[8] !== 'P' ? $m[8] : null;

        return [
            'ean'        => $m[1],
            'article'    => $m[2],
            'label'      => trim($m[3]),
            'unit_price' => round(parseFrenchFloat($m[4]), 3),
            'colisage'   => $colisage,
            'qty'        => $qty,
            'units'      => $colisage * $qty,
            'total'      => round(parseFrenchFloat($m[7]), 2),
            'vat_letter' => $vatLetter,
        ];
    }

    /**
     * Motif RELAXÉ pour les lignes dégradées par l'OCR : la ligne doit
     * commencer par 8-14 chiffres. Le dernier montant décimal (2 décimales)
     * fait foi pour le total ; un prix à 3 décimales avant lui donne le prix
     * unitaire ; 1-2 entiers juste avant donnent colisage et quantité.
     *
     * Sans colisage/qté mais avec un prix > 0, la quantité est déduite
     * (total ÷ PU) avec avertissement. Ligne inexploitable → null (+ warning
     * si un libellé était pourtant présent).
     *
     * La lettre TVA finale isolée (« … 17,28 B » ou « … 17,28 B P ») est
     * retirée avant le scan des montants et renvoyée à part ; « P » seul est
     * un marqueur promo, jamais une lettre TVA.
     *
     * @param list<string> $warnings
     *
     * @return array{label:string,ean:string,article:string,unit_price:?float,
     *               colisage:?int,qty:?int,units:int,total:float,
     *               vat_letter:?string}|null
     */
    private static function matchRelaxed(string $line, array &$warnings): ?array
    {
        if (preg_match('/^(\d{8,14})\s/', $line) !== 1) {
            return null;
        }

        // Lettre TVA finale isolée (optionnellement suivie du promo « P »),
        // retirée de la ligne pour ne pas polluer libellé ni montants.
        $vatLetter = null;
        if (preg_match('/\s([A-Z])(\s+P)?$/', $line, $lm, PREG_UNMATCHED_AS_NULL) === 1 && $lm[1] !== 'P') {
            $vatLetter = $lm[1];
            $line = rtrim(substr($line, 0, -strlen($lm[0])));
        }

        // Préfixe EAN + article : deux blocs numériques en tête de ligne.
        if (preg_match('/^(\d{12,14})\s+(\d{5,8})\s+(.*)$/', $line, $m) !== 1) {
            return null;
        }
        $ean = $m[1];
        $article = $m[2];
        $rest = $m[3];

        // Dernier montant « X,YZ » de la ligne = total HT de la ligne.
        if (preg_match_all('/(\d{1,6})[.,](\d{2})\b/', $rest, $amounts, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }
        // Avec PREG_OFFSET_CAPTURE, chaque élément est [texte, offset].
        $lastAmount = end($amounts[0]);
        $total = round(parseFrenchFloat((string) $lastAmount[0]), 2);
        $tailStart = (int) $lastAmount[1];
        $head = substr($rest, 0, $tailStart);

        // Prix unitaire (3 décimales) le plus proche de la fin, avant le total.
        $unitPrice = null;
        if (preg_match_all('/(\d{1,3})[.,](\d{3})\b/', $head, $prices, PREG_OFFSET_CAPTURE) > 0) {
            $price = end($prices[0]);
            $unitPrice = round(parseFrenchFloat((string) $price[0]), 3);
            $tailStart = (int) $price[1];
            $head = substr($head, 0, $tailStart);
        }

        // Colisage + quantité : 1-2 entiers juste avant le prix (ou le montant).
        $colisage = null;
        $qty = null;
        if (preg_match('/(\d{1,4})\s+(\d{1,4})\s+$/', $head, $q, PREG_OFFSET_CAPTURE) === 1) {
            $colisage = (int) $q[1][0];
            $qty = (int) $q[2][0];
            $tailStart = min($tailStart, (int) $q[0][1]);
        }

        $label = trim(substr($rest, 0, $tailStart));
        if ($label === '') {
            // Rien entre l'article et les chiffres : ligne inexploitable.
            return null;
        }

        // Quantité : colisage×qté fait foi ; sinon déduite du total et du PU.
        $units = ($colisage !== null && $qty !== null) ? $colisage * $qty : 0;
        if ($units < 1 && $unitPrice !== null && $unitPrice > 0) {
            $units = max(1, (int) round($total / $unitPrice));
            $warnings[] = sprintf(
                'Ligne « %s » : quantité déduite du total et du prix unitaire (%d), à vérifier.',
                $label,
                $units
            );
        }
        if ($units < 1) {
            $warnings[] = sprintf('Ligne « %s » ignorée : quantité illisible.', $label);

            return null;
        }
        if ($unitPrice === null) {
            $warnings[] = sprintf('Ligne « %s » : prix unitaire illisible (OCR), à compléter.', $label);
        }

        return [
            'ean'        => $ean,
            'article'    => $article,
            'label'      => $label,
            'unit_price' => $unitPrice,
            'colisage'   => $colisage,
            'qty'        => $qty,
            'units'      => $units,
            'total'      => $total,
            'vat_letter' => $vatLetter,
        ];
    }
}
