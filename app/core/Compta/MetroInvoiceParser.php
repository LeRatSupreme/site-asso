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
 * OCR réel (photos téléphone) : InvoiceOcr renvoie DEUX passes séparées par
 * InvoiceOcr::ALT_MARKER. Les lignes produits sont lues dans la première
 * passe (psm 6, qui restitue le tableau) ; les en-têtes (n°, date, totaux,
 * table TVA) sont cherchés dans tout le texte, la seconde passe rattrapant
 * les champs tronqués par la première. Quand l'OCR éparpille une ligne en
 * deux blocs (« tête » article+libellé puis « colonnes » PU colisage qté
 * montant), les blocs sont réappariés par proximité, avec validation
 * arithmétique (colisage×qté×PU ≈ montant) : une paire douteuse est rejetée
 * avec avertissement, jamais inventée.
 *
 * Classe purement statique, sans dépendance DB ni OCR : robuste aux erreurs
 * de reconnaissance (O/0 confondus, espaces insécables, virgules perdues,
 * puces de notes ①②…, doubles espaces). Les lignes non reconnues sont
 * simplement ignorées avec un avertissement, jamais fatales.
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

        // Lignes produits : première passe OCR seulement (les deux passes
        // décriraient chaque ligne deux fois).
        $parts = preg_split('/^\s*-{3,}\s*OCR alt\s*-+.*$/m', $text) ?: [];
        $linesText = $parts[0] ?? $text;

        $supplier = self::detectSupplier($text, $warnings);
        $invoiceNumber = self::extractInvoiceNumber($text);
        $purchasedAt = self::extractDate($text);
        $vatRates = self::extractVatTable($text);
        $totalHt = self::extractTotalHt($text);
        // Total TTC : « Total à payer » prioritaire ; sinon somme de la
        // table TVA si toutes ses lignes sont complètes ; sinon déduit du
        // ratio HT×(1+taux) lorsqu'un montant cohérent existe dans le texte.
        $ratio = $totalHt !== null ? self::totalTtcByRatio($text, $totalHt) : null;
        $totalTtc = self::extractTotalTtc($text)
            ?? self::totalTtcFromVatTable($vatRates)
            ?? ($ratio['ttc'] ?? null);

        $lines = self::extractLines($linesText, $vatRates, $warnings);
        if ($lines === []) {
            $warnings[] = 'Aucune ligne produit reconnue — collez le texte manuellement.';
        }

        // Taux de TVA global : un seul taux distinct utilisé → ce taux ;
        // plusieurs (ou aucun) → null + avertissement explicite.
        $vatRate = self::resolveGlobalVatRate($lines, $vatRates, $warnings);
        if ($vatRate === null && $ratio !== null && $vatRates === []) {
            $vatRate = $ratio['rate'];
            $warnings[] = sprintf(
                'TVA déduite du rapport TTC/HT (%s %%), table des taux illisible — à vérifier.',
                self::formatRate($ratio['rate'])
            );
        }

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

    /** Fournisseur : nom METRO ou vocabulaire exclusif de ses factures. */
    private static function detectSupplier(string $text, array &$warnings): ?string
    {
        if (preg_match('/METRO/i', $text) === 1
            || preg_match('/Date facture|BRASSERIE|Colisage/i', $text) === 1
        ) {
            return 'METRO';
        }

        $warnings[] = 'Fournisseur METRO non détecté — vérifiez qu\'il s\'agit bien d\'une facture METRO.';

        return null;
    }

    /**
     * Numéro de facture « 0/0(087)0054/033871 ».
     *
     * 1) Structure canonique cherchée ligne à ligne (les deux passes OCR),
     *    en écartant les leurres (« Mise en attente rappelée et facturée… »,
     *    « N° Client : 087… »). Le premier caractère est toujours ramené à
     *    « 0 » (le format imprimé est 0/0…, l'OCR y lit parfois un chiffre) ;
     *    un groupe série tronqué à 3 chiffres est conservé tel quel.
     * 2) Repli : « N° FACTURE » suivi du numéro sur la même ligne (texte
     *    collé, mise en forme différente).
     * 3) Dernier repli : bloc « 4 chiffres/6 chiffres » hors lignes leurres
     *    (n° partiellement perdu par l'OCR).
     */
    public static function extractInvoiceNumber(string $text): ?string
    {
        foreach (explode("\n", $text) as $line) {
            if (self::isDecoyNumberLine($line)) {
                continue;
            }
            if (preg_match(
                '/([0-9OoQ])\s*\/\s*([0OoQ])\s*[(O]\s*([0-9OoQ]{3,4})\s*\)?\s*([0-9OoQ]{3,4})\s*[\/ ]\s*([0-9OoQ]{6})/',
                $line,
                $m
            ) === 1) {
                return '0/0(' . self::ocrDigits($m[3]) . ')' . self::ocrDigits($m[4]) . '/' . self::ocrDigits($m[5]);
            }
        }

        if (preg_match('/N\s*[\x{00B0}\x{00BA}o]?\s*F\s*A\s*C\s*T\s*U\s*R\s*E\s*([0-9OoQ\/().\- ]{6,40})/u', $text, $m) === 1) {
            $token = preg_replace('/[\x{2460}-\x{2473}]/u', '', $m[1]) ?? $m[1];
            $token = strtr(trim($token), ['O' => '0', 'o' => '0', 'Q' => '0']);
            if (preg_match('/^\d[\d\/().\-]{5,39}/', $token, $t) === 1) {
                return $t[0];
            }
        }

        foreach (explode("\n", $text) as $line) {
            if (self::isDecoyNumberLine($line)) {
                continue;
            }
            if (preg_match('/\b(\d{4})\s*\/\s*(\d{6})\b/', $line, $m) === 1) {
                return $m[1] . '/' . $m[2];
            }
        }

        return null;
    }

    /** Ligne à ne jamais considérer comme porteuse du n° de facture. */
    private static function isDecoyNumberLine(string $line): bool
    {
        return preg_match('/attente|facturée|N\s*[\x{00B0}\x{00BA}]?\s*Client/iu', $line) === 1;
    }

    /** Chiffres d'un token numérique : confusions OCR O/o/Q → 0. */
    private static function ocrDigits(string $s): string
    {
        return strtr($s, ['O' => '0', 'o' => '0', 'Q' => '0']);
    }

    /**
     * Date de facture « Date facture : 02-10-2026 » → ISO yyyy-mm-dd.
     *
     * Trois tentatives : libellé et date sur la même ligne ; fenêtre de
     * caractères après le libellé (l'OCR les sépare parfois) ; première date
     * valide du document, toutes passes OCR confondues. Null si aucune date
     * valide (checkdate).
     */
    public static function extractDate(string $text): ?string
    {
        if (preg_match('/facture\s*:?\s*(\d{2})\s*[.\/\- ]\s*(\d{2})\s*[.\/\- ]\s*(\d{4})/i', $text, $m) === 1) {
            $iso = self::toIsoDate((int) $m[1], (int) $m[2], (int) $m[3]);
            if ($iso !== null) {
                return $iso;
            }
        }

        $pos = stripos($text, 'Date facture');
        if ($pos !== false) {
            $window = substr($text, $pos, 400);
            if (preg_match_all('/(\d{2})\s*[.\/\-]\s*(\d{2})\s*[.\/\-]\s*(\d{4})/', $window, $ms, PREG_SET_ORDER) > 0) {
                foreach ($ms as $m) {
                    $iso = self::toIsoDate((int) $m[1], (int) $m[2], (int) $m[3]);
                    if ($iso !== null) {
                        return $iso;
                    }
                }
            }
        }

        if (preg_match_all('/\b(\d{2})\s*[.\/\-]\s*(\d{2})\s*[.\/\-]\s*(\d{4})\b/', $text, $ms, PREG_SET_ORDER) > 0) {
            foreach ($ms as $m) {
                $iso = self::toIsoDate((int) $m[1], (int) $m[2], (int) $m[3]);
                if ($iso !== null) {
                    return $iso;
                }
            }
        }

        return null;
    }

    /** Jour/mois/année → ISO, null si la date n'existe pas (checkdate). */
    private static function toIsoDate(int $day, int $month, int $year): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Table TVA imprimée sur la facture, ligne par ligne :
     *   153,57 B = 5,50% 8,45 162,02
     *   4 179,84 B=  5, 50% 9, 89 189, 73   (OCR réel : junk en tête,
     *   « = » collé ou absent, espaces dans les montants)
     *
     * La lettre et le taux sont repérés ensemble (lettre puis « x,y% », avec
     * au plus trois caractères parasites entre les deux) ; la base HT est le
     * dernier montant AVANT la lettre, la TVA et le TTC sont les montants
     * APRÈS le taux. Ligne dédoublonnée par lettre, ordre d'apparition.
     *
     * @return list<array{letter:string,rate:float,base_ht:?float,
     *                    vat:?float,total_ttc:?float}>
     */
    private static function extractVatTable(string $text): array
    {
        $rates = [];
        $seen = [];

        foreach (explode("\n", $text) as $raw) {
            if (preg_match('/([A-Z])[^A-Z\d]{0,4}(\d{1,2})[.,]\s?(\d{1,2})\s*%/', $raw, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $letter = $m[1][0];
            if (isset($seen[$letter])) {
                continue;
            }

            $rate = round((float) ($m[2][0] . '.' . $m[3][0]), 1);
            $before = substr($raw, 0, (int) $m[0][1]);
            $after = substr($raw, (int) $m[0][1] + strlen((string) $m[0][0]));

            $base = null;
            if (preg_match_all('/(\d{1,6})[.,]\s?(\d{2})/', $before, $bs) > 0) {
                $base = round(parseFrenchFloat((string) end($bs[0])), 2);
            }

            $vat = null;
            $ttc = null;
            if (preg_match_all('/(\d{1,6})[.,]\s?(\d{2})/', $after, $as) > 0) {
                $vat = round(parseFrenchFloat((string) $as[0][0]), 2);
                if (count($as[0]) > 1) {
                    $ttc = round(parseFrenchFloat((string) $as[0][1]), 2);
                }
            }

            $seen[$letter] = true;
            $rates[] = [
                'letter'    => $letter,
                'rate'      => $rate,
                'base_ht'   => $base,
                'vat'       => $vat,
                'total_ttc' => $ttc,
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
     * Couple (TTC, taux) déduit du total HT : cherche dans le texte un
     * montant égal à HT×(1+taux) pour les taux français courants. Utilisé
     * quand ni « Total à payer » ni la table TVA ne sont lisibles.
     *
     * @return array{ttc:float,rate:float}|null
     */
    private static function totalTtcByRatio(string $text, float $totalHt): ?array
    {
        if (preg_match_all('/(\d{1,6})[.,]\s?(\d{2})\b/', $text, $ms) === 0) {
            return null;
        }

        foreach ([5.5, 2.1, 10.0, 20.0] as $rate) {
            $target = round($totalHt * (1 + $rate / 100), 2);
            foreach ($ms[0] as $raw) {
                $value = round(parseFrenchFloat((string) $raw), 2);
                if (abs($value - $target) <= 0.02) {
                    return ['ttc' => $value, 'rate' => $rate];
                }
            }
        }

        return null;
    }

    /**
     * Taux de TVA global du formulaire (un seul champ) :
     *  - une lettre de ligne non résolue par la table → null + avertissement
     *    (taux global dangereux : la facture est peut-être multi-taux) ;
     *  - un seul taux distinct utilisé par les lignes → ce taux (s'il est un
     *    taux français) — cas facture mono-taux, rétrocompatible ;
     *  - plusieurs taux distincts → null + avertissement « TVA multiple » ;
     *  - aucune lettre résolue mais table mono-taux → ce taux ;
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
        $resolved = [];
        foreach ($lines as $line) {
            $letter = $line['vat_letter'];
            if ($letter === null) {
                continue;
            }
            $letters[$letter] = true;
            $rate = $line['vat_rate'];
            if ($rate !== null) {
                $resolved[$letter] = true;
                if (!in_array($rate, $usedRates, true)) {
                    $usedRates[] = $rate;
                }
            }
        }

        $unresolved = array_diff_key($letters, $resolved);
        if ($unresolved !== []) {
            $warnings[] = sprintf(
                'Lettres TVA « %s » non résolues par la table des taux — taux global à vérifier.',
                implode(', ', array_keys($unresolved))
            );

            return null;
        }

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
     * (premier des deux motifs trouvé ; tolère l'espace OCR après la virgule).
     */
    private static function extractTotalHt(string $text): ?float
    {
        foreach ([
            '/Total\s*H\.?\s*T\.?\s*:?\s*([\d\s]+[.,]\s?\d{2})/i',
            '/Montant\s*hors\s*T\.?\s*V\.?\s*A\.?\s*:?\s*([\d\s]+[.,]\s?\d{2})/i',
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
        if (preg_match('/Total\s*[\x{00E0}a]\s*payer\s*:?\s*([\d\s]+[.,]\s?\d{2})/iu', $text, $m) === 1) {
            return round(parseFrenchFloat($m[1]), 2);
        }

        return null;
    }

    // ————————————————————————————————————————————————————————————
    // Lignes produits
    // ————————————————————————————————————————————————————————————

    /**
     * Extrait les lignes produits d'une passe OCR (ou d'un texte collé).
     *
     * Stratégie en trois familles de lignes, dans l'ordre :
     *  1. ligne produit complète (strict puis relaxé) → émise ;
     *  2. « colonnes » seules (PU colisage qté montant [lettre]) → appariées
     *     à la tête produit voisine : la tête suivante si elle est immédiate
     *     (blocs lus en ordre inverse par l'OCR), sinon la plus ancienne tête
     *     en attente (colonnes regroupées en fin de tableau), sinon gardée en
     *     réserve courte pour la prochaine tête ;
     *  3. tête produit sans montants → mise en attente ; si un bloc de
     *     colonnes orphelin la précède immédiatement, il s'y rattache.
     *
     * Les têtes restées sans montants en fin de texte sont ignorées avec un
     * avertissement récapitulatif (montants simplement illisibles sur la
     * photo) ; une paire tête/colonnes arithmétiquement incohérente est
     * rejetée avec avertissement plutôt qu'émission fausse.
     *
     * @param list<array{letter:string,rate:float,base_ht:?float,
     *                   vat:?float,total_ttc:?float}> $vatRates
     * @param list<string> $warnings
     *
     * @return list<array{label:string,ean:string,article:string,
     *                    unit_price:?float,units:int,total:float,
     *                    vat_letter:?string,vat_rate:?float,notes:string}>
     */
    private static function extractLines(string $linesText, array $vatRates, array &$warnings): array
    {
        $lines = [];
        $missingLetters = [];
        /** @var list<array<string,mixed>> $pending têtes en attente de colonnes */
        $pending = [];
        /** @var list<array<string,mixed>> $orphan colonnes en attente de tête */
        $orphan = [];

        $all = explode("\n", $linesText);
        $count = count($all);

        for ($i = 0; $i < $count; $i++) {
            $line = self::normalizeLine($all[$i]);
            if ($line === '') {
                continue;
            }

            // 1) Ligne produit complète (colonnes sur la même ligne).
            $parsed = self::matchStrict($line) ?? self::matchRelaxed($line);
            if ($parsed !== null) {
                $lines[] = self::finalizeLine($parsed, $vatRates, $missingLetters, $warnings);
                continue;
            }

            // 2) Colonnes seules : réappariement par proximité.
            $cols = self::matchColumns($line);
            if ($cols !== null) {
                $ownLabel = preg_match_all('/\p{L}/u', (string) $cols['label']) >= 2
                    && !self::isSkippable((string) $cols['label']);
                if ($ownLabel && self::isSelfValidating($cols)) {
                    // Colonnes complètes avec leur propre libellé tronqué :
                    // la ligne produit est entière, le libellé a juste perdu
                    // son début — émises telles quelles.
                    $solo = self::standaloneFromColumns($cols);
                    if ($solo !== null) {
                        $lines[] = self::finalizeLine($solo, $vatRates, $missingLetters, $warnings);
                    }
                    continue;
                }
                if (self::hasHeadWithin($all, $i + 1, 2)) {
                    // Blocs lus en ordre inverse : les colonnes précèdent
                    // leur libellé — réservées pour la tête qui suit.
                    $orphan[] = $cols;
                    if (count($orphan) > 2) {
                        array_shift($orphan);
                    }
                    continue;
                }
                if ($pending !== [] && !self::isSelfValidating($cols)) {
                    $head = array_shift($pending);
                    $merged = self::mergeHeadColumns($head, $cols, $warnings);
                    if ($merged !== null) {
                        $lines[] = self::finalizeLine($merged, $vatRates, $missingLetters, $warnings);
                    }
                    continue;
                }
                if (self::isSelfValidating($cols)) {
                    $solo = self::standaloneFromColumns($cols);
                    if ($solo !== null) {
                        $lines[] = self::finalizeLine($solo, $vatRates, $missingLetters, $warnings);
                    }
                    continue;
                }
                $orphan[] = $cols;
                if (count($orphan) > 2) {
                    array_shift($orphan);
                }
                continue;
            }

            // 3) Tête produit sans colonnes.
            $head = self::matchProductHead($line);
            if ($head !== null) {
                if ($orphan !== []) {
                    $cols = array_pop($orphan);
                    $merged = self::mergeHeadColumns($head, $cols, $warnings);
                    if ($merged !== null) {
                        $lines[] = self::finalizeLine($merged, $vatRates, $missingLetters, $warnings);
                        continue;
                    }
                }
                $pending[] = $head;
            }
        }

        // Têtes restées sans montants : montants illisibles sur la photo.
        if ($pending !== []) {
            $names = [];
            foreach (array_slice($pending, 0, 3) as $head) {
                $names[] = (string) $head['label'];
            }
            $warnings[] = sprintf(
                '%d ligne(s) produit sans montant lisible (OCR) — ignorée(s) : %s.',
                count($pending),
                implode(', ', $names)
            );
        }

        return $lines;
    }

    /**
     * Normalisation d'une ligne OCR : insécables → espace, puces retirées,
     * espaces multiples réduits, déchets de bordures (| « _ etc.) retirés.
     */
    private static function normalizeLine(string $raw): string
    {
        $line = str_replace(["\u{00a0}", "\u{202f}"], ' ', $raw);
        $line = preg_replace('/[\x{2460}-\x{2473}]/u', '', $line) ?? $line;
        $line = trim((string) preg_replace('/ {2,}/', ' ', $line));
        $line = trim($line, " |\"'`°¨«»“”_");

        return rtrim($line, " |.,");
    }

    /** Retire un court préfixe non numérique collé au n° d'article (« s000… »). */
    private static function stripLeadingJunk(string $line): string
    {
        return (string) preg_replace('/^[^\d]{1,3}(?=\d)/', '', $line);
    }

    /** Une tête produit (article + libellé, sans colonnes) est-elle proche ? */
    private static function hasHeadWithin(array $all, int $from, int $within): bool
    {
        $count = count($all);
        for ($j = $from; $j < min($from + $within, $count); $j++) {
            $line = self::normalizeLine($all[$j]);
            if ($line === '' || self::isSkippable($line)) {
                continue;
            }
            if (self::matchProductHead($line) !== null) {
                return true;
            }
        }

        return false;
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
     * Motif STRICT d'une ligne produit METRO (texte propre, PDF ou bon OCR) :
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
     * Motif RELAXÉ d'une ligne produit complète, dégradée par l'OCR :
     *   [EAN tronqué] article LIBELLÉ … [PU 3 déc.] [colisage qté] montant [lettre] [P]
     *
     * Tolère : article seul à partir de 4 chiffres (EAN perdu ou collé au
     * libellé), espaces insérés dans les montants (« 50, 28 », « 0, 528 »),
     * colisage illisible (quantité déduite du total et du PU, avertissement),
     * lettre TVA suivie de déchets (« B P », « B RE », « BB p »).
     *
     * @return array{label:string,ean:string,article:string,unit_price:?float,
     *               colisage:?int,qty:?int,units:int,total:float,
     *               vat_letter:?string}|null
     */
    private static function matchRelaxed(string $line): ?array
    {
        $line = self::stripLeadingJunk($line);
        $vatLetter = self::stripTrailingVatLetter($line);
        $line = $vatLetter['line'];

        if (preg_match('/^(\d{4,14})(?:\s+(\d{5,14}))?\s+(\S.*)$/', $line, $m) !== 1) {
            return null;
        }
        $ean = (int) strlen($m[1]) >= 12 ? $m[1] : '';
        $article = $m[2] ?? $m[1];
        $rest = $m[3];

        // Libellé plausibles : au moins 2 mots dont un de 3 lettres+, et pas
        // une ligne de vocabulaire METRO (n° client, SIRET…).
        $label = self::cleanLabel($rest);
        if ($label === null) {
            return null;
        }

        // Dernier montant « X,YZ » (espace OCR tolérée) = total HT de la ligne.
        if (preg_match_all('/(\d{1,6})[.,]\s?(\d{2})\b/', $rest, $amounts, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }
        $lastAmount = end($amounts[0]);
        $total = round(parseFrenchFloat((string) $lastAmount[0]), 2);
        $tailStart = (int) $lastAmount[1];
        $head = substr($rest, 0, $tailStart);

        // Prix unitaire (3 décimales) le plus proche de la fin, avant le total.
        $unitPrice = null;
        if (preg_match_all('/(\d{1,3})[.,]\s?(\d{3})\b/', $head, $prices, PREG_OFFSET_CAPTURE) > 0) {
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
            return null;
        }

        $units = ($colisage !== null && $qty !== null) ? $colisage * $qty : 0;
        if ($units < 1 && $unitPrice !== null && $unitPrice > 0) {
            $units = max(1, (int) round($total / $unitPrice));
        }
        if ($units < 1) {
            return null;
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
            'vat_letter' => $vatLetter['letter'],
            'loose_units' => $units !== $colisage * $qty,
        ];
    }

    /**
     * Retire la lettre TVA finale d'une ligne (« … 17,28 B », « … 13,92 B P »,
     * « … 15,21 B LE ») : lettre A-D seule en fin de ligne, éventuellement
     * suivie du promo « P » ou de 1-2 déchets courts. Le « P » seul n'est
     * jamais une lettre TVA.
     *
     * @return array{line:string,letter:?string}
     */
    private static function stripTrailingVatLetter(string $line): array
    {
        if (preg_match('/\s([A-D])[A-D]?(?:\s+\S{1,3}){0,2}\s*$/', $line, $lm) === 1) {
            return [
                'line'  => rtrim(substr($line, 0, -strlen($lm[0]))),
                'letter' => $lm[1],
            ];
        }

        return ['line' => $line, 'letter' => null];
    }

    /**
     * Libellé plausible d'une ligne produit : au moins 2 mots dont un de
     * 3 lettres ou plus ; sinon null (n° client, SIRET, junk OCR).
     */
    private static function cleanLabel(string $rest): ?string
    {
        if (preg_match('/Client|SIRET|Siret|T\.?\s*V\.?\s*A\.|Accises| Agrément/iu', $rest) === 1) {
            return null;
        }

        // Lignes d'en-tête / pied de facture portant une date complète.
        if (preg_match('/\d{2}[.\-\/]\s?\d{2}[.\-\/]\s?\d{4}/', $rest) === 1) {
            return null;
        }

        $words = preg_split('/\s+/', trim($rest)) ?: [];
        $long = 0;
        foreach ($words as $word) {
            if (preg_match_all('/\p{L}/u', $word) >= 3) {
                $long++;
            }
        }
        if (count($words) < 2 || $long < 1) {
            return null;
        }

        return trim($rest);
    }

    /**
     * Tête produit sans colonnes : « [EAN] article LIBELLÉ [PU 3 déc.] ».
     * Le bloc « colonnes » correspondant est sur une ligne voisine.
     *
     * @return array{label:string,ean:string,article:string,unit_price:?float,
     *               colisage:?int,qty:?int}|null
     */
    private static function matchProductHead(string $line): ?array
    {
        $line = self::stripLeadingJunk($line);
        if (preg_match('/^(\d{4,14})(?:\s+(\d{5,14}))?\s+(\S.*)$/', $line, $m) !== 1) {
            return null;
        }

        $label = self::cleanLabel($m[3]);
        if ($label === null) {
            return null;
        }

        // PU (3 décimales) éventuel en fin de tête, suivi ou non des deux
        // entiers colisage/qté (« …PULCO … 0,574 12 1 »).
        $unitPrice = null;
        $colisage = null;
        $qty = null;
        if (preg_match('/\s(\d{1,3})[.,]\s?(\d{3})(?:\s+(\d{1,4})\s+(\d{1,4}))?$/', $label, $p) === 1) {
            $unitPrice = round(parseFrenchFloat($p[1] . '.' . $p[2]), 3);
            if (($p[3] ?? '') !== '' && ($p[4] ?? '') !== '') {
                $colisage = (int) $p[3];
                $qty = (int) $p[4];
            }
            $label = trim(substr($label, 0, -strlen($p[0])));
        }

        if (self::isSkippable($label)) {
            $label = '(ligne OCR partielle)';
        }

        $ean = (int) strlen($m[1]) >= 12 ? $m[1] : '';
        $article = $m[2] ?? $m[1];

        return [
            'label'      => $label,
            'ean'        => $ean,
            'article'    => $article,
            'unit_price' => $unitPrice,
            'colisage'   => $colisage,
            'qty'        => $qty,
        ];
    }

    /**
     * Bloc « colonnes » d'une ligne produit, éventuellement précédé du
     * libellé tronqué (au plus 6 mots) quand l'OCR a coupé la ligne :
     *   … [PU] colisage qté montant [lettre] [P]
     *
     * Variantes tolérées : PU entier à 3 chiffres (« 720 » pour 0,720),
     * montant entier (« 4566 » pour 45,66 — validé par PU×unités), colisage
     * et quantité collés (« 6100 » = 6 × 1 + résidu), un ou deux déchets
     * entre PU et montant. La lettre TVA est cherchée en toute fin.
     *
     * @return array{label:string,unit_price:?float,colisage:?int,qty:?int,
     *               total:?float,vat_letter:?string,self:bool}|null
     */
    private static function matchColumns(string $line): ?array
    {
        // Lettre TVA finale (A-D, doublon OCR possible) + promo P éventuels.
        $vatLetter = null;
        if (preg_match('/\s([A-D])[A-D]?(?:\s+[Pp])?\s*$/', $line, $lm) === 1) {
            $vatLetter = $lm[1];
            $line = rtrim(substr($line, 0, -strlen($lm[0])));
        }

        $prefix = '(?:(?:\S+\s+){0,6}?)';
        $pu = '(?:(\d{1,3})[.,]\s?(\d{3})\s+)?';
        $forms = [
            // PU décimal + colisage + qté + montant décimal (forme canonique).
            ['/^' . $prefix . $pu . '(\d{1,4})\s+(\d{1,4})\s+(\d{1,6})[.,]\s?(\d{2})$/', 'dec_pu'],
            // PU entier 3 chiffres (virgule perdue) + colisage + qté + montant.
            ['/^' . $prefix . '(\d{3})(?![.,\d])\s+(\d{1,4})\s+(\d{1,4})\s+(\d{1,6})[.,]\s?(\d{2})$/', 'int_pu'],
            // PU décimal + colisage + qté + montant ENTIER (virgule perdue).
            ['/^' . $prefix . $pu . '(\d{1,4})\s+(\d{1,4})\s+(\d{3,4})$/', 'int_total'],
            // PU décimal + colisage/qté collés (« 6100 ») + montant.
            ['/^' . $prefix . $pu . '(\d{3,5})\s+(\d{1,6})[.,]\s?(\d{2})$/', 'merged'],
            // PU décimal + 1-2 déchets + montant (colisage/qté illisibles).
            ['/^' . $prefix . $pu . '(?:\S{1,3}\s+){0,2}(\d{1,6})[.,]\s?(\d{2})$/', 'loose'],
            // Montant seul (bloc totalement dégradé).
            ['/^' . $prefix . '(\d{1,6})[.,]\s?(\d{2})$/', 'total_only'],
        ];

        foreach ($forms as [$regex, $kind]) {
            if (preg_match($regex, $line, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
                continue;
            }

            $labelPart = '';
            $unitPrice = null;
            $colisage = null;
            $qty = null;
            $total = null;
            $mergedDigits = null;

            switch ($kind) {
                case 'dec_pu':
                    // Groupes fixes : [pu1,pu2,col,qty,mont1,mont2], le PU
                    // (indices 0-1) null quand absent.
                    $nums = array_values(array_slice($m, 1));
                    if ($nums[0] !== null && $nums[1] !== null) {
                        $unitPrice = round(parseFrenchFloat($nums[0] . '.' . $nums[1]), 3);
                        $colisage = (int) $nums[2];
                        $qty = (int) $nums[3];
                        $total = round(parseFrenchFloat($nums[4] . '.' . $nums[5]), 2);
                        $labelPart = self::prefixBefore($line, $nums[0]);
                    } else {
                        $colisage = (int) $nums[2];
                        $qty = (int) $nums[3];
                        $total = round(parseFrenchFloat($nums[4] . '.' . $nums[5]), 2);
                        $labelPart = self::prefixBefore($line, $nums[2]);
                    }
                    break;

                case 'int_pu':
                    $unitPrice = round(((int) $m[1]) / 1000, 3);
                    $colisage = (int) $m[2];
                    $qty = (int) $m[3];
                    $total = round(parseFrenchFloat($m[4] . '.' . $m[5]), 2);
                    $labelPart = self::prefixBefore($line, $m[1]);
                    break;

                case 'int_total':
                    if ($m[1] !== null && $m[2] !== null) {
                        $unitPrice = round(parseFrenchFloat($m[1] . '.' . $m[2]), 3);
                    }
                    $colisage = (int) $m[3];
                    $qty = (int) $m[4];
                    $total = round(((int) $m[5]) / 100, 2);
                    $labelPart = self::prefixBefore($line, $m[1] ?? $m[3]);
                    break;

                case 'merged':
                    $mergedDigits = $m[3];
                    $total = round(parseFrenchFloat($m[4] . '.' . $m[5]), 2);
                    if ($m[1] !== null && $m[2] !== null) {
                        $unitPrice = round(parseFrenchFloat($m[1] . '.' . $m[2]), 3);
                    }
                    $labelPart = self::prefixBefore($line, $m[1] ?? $m[3]);
                    break;

                case 'loose':
                    if ($m[1] !== null && $m[2] !== null) {
                        $unitPrice = round(parseFrenchFloat($m[1] . '.' . $m[2]), 3);
                    }
                    $mont1 = $m[3] ?? '';
                    $mont2 = $m[4] ?? '';
                    $total = round(parseFrenchFloat($mont1 . '.' . $mont2), 2);
                    $labelPart = self::prefixBefore($line, $mont1);
                    break;

                case 'total_only':
                    $total = round(parseFrenchFloat($m[1] . '.' . $m[2]), 2);
                    $labelPart = self::prefixBefore($line, $m[1]);
                    break;
            }

            // Validation arithmétique + rejet des pseudo-colonnes absurdes.
            if ($colisage !== null && $qty !== null && $unitPrice !== null && $total !== null) {
                $expected = round($colisage * $qty * $unitPrice, 2);
                if (abs($expected - $total) > max(0.02, $total * 0.01)) {
                    // Colisage/qté collés ? (« 6100 » = 6×1 + résidu)
                    if ($mergedDigits === null) {
                        return null;
                    }
                    $split = self::splitMergedColisage($mergedDigits, $unitPrice, $total);
                    if ($split === null) {
                        return null;
                    }
                    [$colisage, $qty] = $split;
                }
            }
            if ($colisage !== null && $qty !== null && $colisage * $qty > 9999) {
                return null;
            }

            $self = $unitPrice !== null && $total !== null && (
                ($colisage !== null && $qty !== null)
                || ($colisage === null && $qty === null && $total / max($unitPrice, 0.001) >= 0.5
                    && abs($total / max($unitPrice, 0.001) - round($total / max($unitPrice, 0.001))) < 0.05)
            );

            return [
                'label'      => $labelPart,
                'unit_price' => $unitPrice,
                'colisage'   => $colisage,
                'qty'        => $qty,
                'total'      => $total,
                'vat_letter' => $vatLetter,
                'self'       => $self,
            ];
        }

        return null;
    }

    /** Texte de la ligne avant l'occurrence de $needle (libellé tronqué). */
    private static function prefixBefore(string $line, ?string $needle): string
    {
        $pos = $needle !== null && $needle !== '' ? strpos($line, $needle) : false;

        return $pos !== false ? trim(substr($line, 0, $pos)) : '';
    }

    /**
     * Colisage et quantité collés en un seul bloc (« 6100 ») : essaie les
     * découpes 1-2 chiffres + 1-2 chiffres (résidu ≤ 2 chiffres ignoré) et
     * valide par PU×colisage×qty ≈ montant.
     *
     * @return array{0:int,1:int}|null
     */
    private static function splitMergedColisage(string $digits, float $unitPrice, float $total): ?array
    {
        $len = strlen($digits);
        for ($a = 1; $a <= 2; $a++) {
            for ($b = 1; $b <= 2; $b++) {
                if ($a + $b > $len) {
                    continue;
                }
                $colisage = (int) substr($digits, 0, $a);
                $qty = (int) substr($digits, $a, $b);
                $rest = substr($digits, $a + $b);
                if ($rest !== '' && strlen($rest) > 2) {
                    continue;
                }
                $expected = round($colisage * $qty * $unitPrice, 2);
                if (abs($expected - $total) <= max(0.02, $total * 0.01)) {
                    return [$colisage, $qty];
                }
            }
        }

        return null;
    }

    /**
     * Colonne autonome émissible : PU + unités cohérentes (colisage×qté ou
     * total÷PU entier plausible). Jamais un simple montant isolé.
     *
     * @param array{label:string,unit_price:?float,colisage:?int,qty:?int,
     *              total:?float,vat_letter:?string,self:bool} $cols
     *
     * @return array{label:string,ean:string,article:string,unit_price:?float,
     *               colisage:?int,qty:?int,units:int,total:float,
     *               vat_letter:?string}|null
     */
    private static function standaloneFromColumns(array $cols): ?array
    {
        $total = $cols['total'];
        $unitPrice = $cols['unit_price'];
        if ($total === null || $unitPrice === null || $unitPrice <= 0) {
            return null;
        }

        if ($cols['colisage'] !== null && $cols['qty'] !== null) {
            $units = $cols['colisage'] * $cols['qty'];
        } else {
            $units = (int) round($total / $unitPrice);
            if ($units < 1) {
                return null;
            }
        }

        $label = trim((string) $cols['label']);
        if (preg_match_all('/\p{L}/u', $label) < 2) {
            $label = '(ligne OCR partielle)';
        }

        return [
            'label'      => $label,
            'ean'        => '',
            'article'    => '',
            'unit_price' => $unitPrice,
            'colisage'   => $cols['colisage'],
            'qty'        => $cols['qty'],
            'units'      => $units,
            'total'      => $total,
            'vat_letter' => $cols['vat_letter'],
            'loose_units' => $cols['colisage'] === null || $cols['qty'] === null,
        ];
    }

    /**
     * Blocs « colonnes » auto-suffisants (vérifiables sans libellé) : ils ne
     * sont appariés à une tête en attente que si l'arithmétique le confirme.
     *
     * @param array{label:string,unit_price:?float,colisage:?int,qty:?int,
     *              total:?float,vat_letter:?string,self:bool} $cols
     */
    private static function isSelfValidating(array $cols): bool
    {
        return (bool) $cols['self'];
    }

    /**
     * Associe une tête produit (article + libellé [+ PU]) à un bloc colonnes
     * (PU [colisage qté] montant [lettre]). Les unités doivent être
     * déterminables ; une incohérence arithmétique franche rejette la paire
     * (avertissement) au lieu d'émettre un montant faux.
     *
     * @param array{label:string,ean:string,article:string,unit_price:?float,
     *               colisage:?int,qty:?int} $head
     * @param array{label:string,unit_price:?float,colisage:?int,qty:?int,
     *              total:?float,vat_letter:?string,self:bool} $cols
     * @param list<string> $warnings
     *
     * @return array{label:string,ean:string,article:string,unit_price:?float,
     *               colisage:?int,qty:?int,units:int,total:float,
     *               vat_letter:?string}|null
     */
    private static function mergeHeadColumns(array $head, array $cols, array &$warnings): ?array
    {
        $total = $cols['total'];
        if ($total === null) {
            $warnings[] = sprintf(
                'Ligne « %s » ignorée : montant illisible (OCR).',
                (string) $head['label']
            );

            return null;
        }

        $unitPrice = $head['unit_price'] ?? $cols['unit_price'];
        $colisage = $head['colisage'] ?? $cols['colisage'];
        $qty = $head['qty'] ?? $cols['qty'];

        $units = ($colisage !== null && $qty !== null) ? $colisage * $qty : 0;
        $deduced = false;
        if ($units < 1 && $unitPrice !== null && $unitPrice > 0) {
            $units = max(1, (int) round($total / $unitPrice));
            $deduced = true;
        }
        if ($units < 1) {
            $warnings[] = sprintf(
                'Ligne « %s » ignorée : quantité illisible (OCR).',
                (string) $head['label']
            );

            return null;
        }

        if ($colisage !== null && $qty !== null && $unitPrice !== null) {
            $expected = round($colisage * $qty * $unitPrice, 2);
            if (abs($expected - $total) > max(0.02, $total * 0.01)) {
                $warnings[] = sprintf(
                    'Ligne « %s » ignorée : colonnes incohérentes (colisage×qté×PU = %.2f ≠ %.2f).',
                    (string) $head['label'],
                    $expected,
                    $total
                );

                return null;
            }
        }

        return [
            'label'      => (string) $head['label'],
            'ean'        => (string) $head['ean'],
            'article'    => (string) $head['article'],
            'unit_price' => $unitPrice,
            'colisage'   => $colisage,
            'qty'        => $qty,
            'units'      => $units,
            'total'      => $total,
            'vat_letter' => $cols['vat_letter'],
            'loose_units' => $deduced,
        ];
    }

    /**
     * Transforme une ligne brute interne en structure finale : lettre TVA →
     * taux via la table, avertissements quantité déduite / lettre sans taux,
     * cohérence colisage×qté×PU vs montant.
     *
     * @param array{label:string,ean:string,article:string,unit_price:?float,
     *               colisage:?int,qty:?int,units:int,total:float,
     *               vat_letter:?string,loose_units?:bool} $parsed
     * @param list<array{letter:string,rate:float,base_ht:?float,
     *                   vat:?float,total_ttc:?float}> $vatRates
     * @param array<string,bool> $missingLetters
     * @param list<string> $warnings
     *
     * @return array{label:string,ean:string,article:string,unit_price:?float,
     *               units:int,total:float,vat_letter:?string,vat_rate:?float,
     *               notes:string}
     */
    private static function finalizeLine(array $parsed, array $vatRates, array &$missingLetters, array &$warnings): array
    {
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

        if (!empty($parsed['loose_units'])) {
            $warnings[] = sprintf(
                'Ligne « %s » : quantité déduite du total et du prix unitaire (%d), à vérifier.',
                (string) $parsed['label'],
                (int) $parsed['units']
            );
        }

        $unitPrice = $parsed['unit_price'];
        if (($parsed['colisage'] ?? null) !== null && ($parsed['qty'] ?? null) !== null && $unitPrice !== null) {
            $expected = round((int) $parsed['colisage'] * (int) $parsed['qty'] * $unitPrice, 2);
            if (abs($expected - (float) $parsed['total']) > max(0.02, (float) $parsed['total'] * 0.01)) {
                $warnings[] = sprintf(
                    'Ligne « %s » : somme incohérente (colisage×qté×PU = %.2f ≠ %.2f), à vérifier.',
                    (string) $parsed['label'],
                    $expected,
                    (float) $parsed['total']
                );
            }
        }

        return [
            'label'      => (string) $parsed['label'],
            'ean'        => (string) ($parsed['ean'] ?? ''),
            'article'    => (string) ($parsed['article'] ?? ''),
            'unit_price' => $unitPrice,
            'units'      => (int) $parsed['units'],
            'total'      => (float) $parsed['total'],
            'vat_letter' => $vatLetter,
            'vat_rate'   => $vatRate,
            'notes'      => 'EAN ' . ($parsed['ean'] ?? '') . ' · art. ' . ($parsed['article'] ?? ''),
        ];
    }
}
