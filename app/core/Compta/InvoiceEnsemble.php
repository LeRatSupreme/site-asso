<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Fusion ensembliste des OCR d'une même photo (facture METRO).
 *
 * InvoiceOcr produit, pour une photo, PLUSIEURS textes : une variante de
 * prétraitement image (originale, contraste/seuillage, redressement,
 * upscale) × deux modes de segmentation Tesseract (psm 6 + passe par
 * défaut, séparées par InvoiceOcr::ALT_MARKER). Chaque lecture rattrape ce
 * que les autres perdent : c'est la fusion qui fait passer 15/20 lignes à
 * 20/20 sur de vraies photos.
 *
 * Principe (jamais d'invention) :
 *  1. chaque texte (variante × passe) est dépouillé de ses candidats par
 *     MetroInvoiceParser::extractCandidates (parseurs historiques strict +
 *     relaxé, réappariement tête/colonnes inclus) ;
 *  2. chaque candidat est NOTÉ : validation arithmétique colisage×qté×PU,
 *     clé de contrôle EAN-13 (réparation d'un chiffre possible, contextée),
 *     cohérence Σ lignes ↔ Total H.T. du texte source ;
 *  3. les candidats sont DÉDOUBLONNÉS par EAN valide, sinon par similarité
 *     de libellé ≥ 80 % à montant égal ; le meilleur survivant de chaque
 *     groupe est complété par les champs lus ailleurs dans le groupe ;
 *  4. l'ordre final est un vote majoritaire (Copeland) sur les positions
 *     dans chaque passe — robuste aux passes qui lisent le tableau dans un
 *     ordre ou à une échelle différents ;
 *  5. en-têtes (n°, date, table TVA, totaux) : valeur la plus fréquente
 *     entre variantes, à égalité celle du texte le plus validé ;
 *  6. un texte CONSOLIDÉ est reconstruit ligne par ligne puis re-parse par
 *     MetroInvoiceParser::parse() : TOUT l'aval (contrat JSON, dépenses,
 *     achats, tests) fonctionne sans changement.
 *
 * Cas-limites assumés :
 *  - un seul texte fourni → comportement historique EXACT (parse direct,
 *    aucune reconstruction) : zéro régression sans ImageMagick ;
 *  - document « ticket » (Auchan…) → pas de reconstruction METRO : la
 *    meilleure variante (la plus de lignes) fait foi, textes concaténés ;
 *  - une zone non photographiée (bloc TVA coupé) reste un AVERTISSEMENT :
 *    aucune valeur n'est inventée, aucune ligne absente de toutes les
 *    passes n'apparaît.
 *
 * Classe purement statique, sans DB ni OCR : testable avec des fixtures.
 */
final class InvoiceEnsemble
{
    /** Similarité minimale de libellé (0-1) pour fusionner deux candidats sans EAN. */
    private const LABEL_SIMILARITY = 0.8;

    /** Tolérance (€) sur les montants : même ligne, arithmétique, totaux. */
    private const MONEY_EPSILON = 0.02;

    /**
     * Tolérance arithmétique d'une ligne, IDENTIQUE à celle du parseur
     * METRO (matchColumns / finalizeLine) : ±2 c. ou ±1 % du montant —
     * une ligne que le parseur accepte (24×2×1,233 = 59,184 ≈ 59,16) ne
     * doit jamais être déclassée par la fusion.
     */
    private static function amountTolerance(float $total): float
    {
        return max(self::MONEY_EPSILON, abs($total) * 0.01);
    }

    /**
     * Nombre minimal de candidats FORTEMENT validés (arithmétique ET EAN)
     * en dessous duquel InvoiceScan tente la variante « dernier recours »
     * (upscale 150 % + seuillage).
     */
    public const LAST_RESORT_MIN_VALIDATED = 5;

    /** Libellé générique du parseur pour une ligne sans nom lisible. */
    private const GENERIC_LABEL = '(ligne OCR partielle)';

    /**
     * Fusionne les textes OCR d'une même photo et produit le résultat
     * consolidé.
     *
     * @param list<array{name:string, text:string}> $texts Un texte par
     *        variante (chaque texte contient ses deux passes séparées par
     *        InvoiceOcr::ALT_MARKER).
     *
     * @return array{
     *     text:string,             texte consolidé (toutes variantes, pour
     *                              la textarea et l'audit) ;
     *     invoice:array,           structure « invoice » (contrat MetroInvoiceParser,
     *                              kind ajouté par InvoiceParser en ticket) ;
     *     variants:int,            nombre de variantes effectivement fusionnées ;
     *     validated:int,           candidats fortement validés (score ≥ 4) ;
     *     groups:int               groupes dédoublonnés émis dans la facture.
     * }
     */
    public static function consolidate(array $texts): array
    {
        $texts = array_values(array_filter(
            $texts,
            static fn ($t): bool => is_array($t) && trim((string) ($t['text'] ?? '')) !== ''
        ));

        if ($texts === []) {
            return [
                'text'     => '',
                'invoice'  => MetroInvoiceParser::parse(''),
                'variants' => 0,
                'validated' => 0,
                'groups'   => 0,
            ];
        }

        // Un seul texte : comportement historique EXACT (aucune reconstruction,
        // zéro régression quand ImageMagick est absent).
        if (count($texts) === 1) {
            $text = (string) $texts[0]['text'];
            $invoice = InvoiceParser::parse($text);

            return [
                'text'     => $text,
                'invoice'  => $invoice,
                'variants' => 1,
                'validated' => self::countStrongCandidates($text),
                'groups'   => count($invoice['lines']),
            ];
        }

        /** @var list<array{name:string,text:string,invoice:array,kind:string}> $variants */
        $variants = [];
        foreach ($texts as $t) {
            $invoice = InvoiceParser::parse((string) $t['text']);
            $variants[] = [
                'name'    => (string) ($t['name'] ?? 'v0'),
                'text'    => (string) $t['text'],
                'invoice' => $invoice,
                'kind'    => (string) ($invoice['kind'] ?? 'metro'),
            ];
        }

        // Majorité METRO (la structure la plus riche) ; sinon document
        // « ticket » : pas de reconstruction, meilleure variante fait foi.
        $metroCount = count(array_filter($variants, static fn ($v): bool => $v['kind'] === 'metro'));
        if ($metroCount * 2 <= count($variants)) {
            return self::consolidateTicket($variants);
        }

        return self::consolidateMetro($variants);
    }

    /**
     * La consolidation est-elle assez pauvre pour justifier la variante
     * « dernier recours » (upscale + seuillage) ? Décision du routeur
     * (InvoiceScan), ici pour rester testable.
     *
     * @param array{validated?:int} $result
     */
    public static function needsLastResort(array $result): bool
    {
        return (int) ($result['validated'] ?? 0) < self::LAST_RESORT_MIN_VALIDATED;
    }

    // ————————————————————————————————————————————————————————————
    // Tickets (Auchan…) : pas de reconstruction, meilleure variante
    // ————————————————————————————————————————————————————————————

    /**
     * Ticket de caisse : la meilleure variante fait foi ; les textes
     * restent concaténés pour l'audit/l'utilisateur. « Meilleure » : parmi
     * les variantes dont Σ lignes reste compatible avec le Total TTC lu
     * (un fragment OCR isolé qui « vaudrait » 92,92 € sur un ticket à
     * 37,24 € est un parasite, pas une lecture plus riche), celle qui a le
     * plus de lignes produits ; sans TTC lisible, simplement le plus de
     * lignes (comportement historique).
     *
     * @param list<array{name:string,text:string,invoice:array,kind:string}> $variants
     * @return array{text:string,invoice:array,variants:int,validated:int,groups:int}
     */
    private static function consolidateTicket(array $variants): array
    {
        $best = null;
        $bestCoherent = false;
        foreach ($variants as $v) {
            $ttc = $v['invoice']['total_ttc'] ?? null;
            $sum = 0.0;
            foreach ($v['invoice']['lines'] as $line) {
                $sum += (float) $line['total'];
            }
            $coherent = $ttc === null || $sum <= (float) $ttc + self::MONEY_EPSILON;
            $better = $best === null
                || ($coherent && !$bestCoherent)
                || ($coherent === $bestCoherent
                    && count($v['invoice']['lines']) > count($best['invoice']['lines']));
            if ($better) {
                $best = $v;
                $bestCoherent = $coherent;
            }
        }

        return [
            'text'     => self::concatTexts($variants),
            'invoice'  => $best['invoice'],
            'variants' => count($variants),
            'validated' => self::countStrongCandidates($best['text']),
            'groups'   => count($best['invoice']['lines']),
        ];
    }

    // ————————————————————————————————————————————————————————————
    // Factures METRO : le vrai travail
    // ————————————————————————————————————————————————————————————

    /**
     * @param list<array{name:string,text:string,invoice:array,kind:string}> $variants
     * @return array{text:string,invoice:array,variants:int,validated:int,groups:int}
     */
    private static function consolidateMetro(array $variants): array
    {
        // 1) Candidats par variante × passe, avec leur position.
        /** @var list<array<string,mixed>> $candidates */
        $candidates = [];
        $knownEans = [];
        foreach ($variants as $vi => $v) {
            foreach (self::splitPasses($v['text']) as $pi => $passText) {
                $passLines = count(explode("\n", $passText));
                foreach (MetroInvoiceParser::extractCandidates($passText) as $c) {
                    $c['variant'] = $vi;
                    $c['pass'] = $pi;
                    $c['pass_lines'] = $passLines;
                    $c['score'] = 0;
                    $c['repaired'] = false;
                    $candidates[] = $c;
                    $ean = (string) ($c['ean'] ?? '');
                    if (Ean13::isValid($ean)) {
                        $knownEans[$ean] = true;
                    }
                }
            }
        }

        // 2) Notation (EAN réparé contexté par les EAN valides des autres lignes).
        $validatedPerVariant = array_fill(0, count($variants), 0);
        foreach ($candidates as $ci => $c) {
            $candidates[$ci] = self::scoreCandidate($c, array_keys($knownEans));
            if ($candidates[$ci]['score'] >= 4) {
                $validatedPerVariant[(int) $c['variant']]++;
            }
        }
        $validatedTotal = array_sum($validatedPerVariant);

        // Bonus collectif : une variante dont Σ candidats ≈ Total H.T. lu
        // est une lecture complète — tous ses candidats gagnent +1.
        foreach ($variants as $vi => $v) {
            $ht = $v['invoice']['total_ht'] ?? null;
            if ($ht === null) {
                continue;
            }
            $sum = 0.0;
            $any = false;
            foreach ($candidates as $c) {
                if ((int) $c['variant'] === $vi) {
                    $sum += (float) $c['total'];
                    $any = true;
                }
            }
            if ($any && abs($sum - (float) $ht) <= self::MONEY_EPSILON) {
                foreach ($candidates as $ci => $c) {
                    if ((int) $c['variant'] === $vi) {
                        $wasStrong = (int) $candidates[$ci]['score'] >= 4;
                        $candidates[$ci]['score']++;
                        if (!$wasStrong && (int) $candidates[$ci]['score'] >= 4) {
                            $validatedPerVariant[$vi]++;
                            $validatedTotal++;
                        }
                    }
                }
            }
        }

        // 3) Déduplication en groupes.
        $groups = self::groupCandidates($candidates);

        // 4) Meilleur survivant par groupe + complétion par les membres.
        $winners = [];
        foreach ($groups as $group) {
            $winner = self::pickWinner($group, $validatedPerVariant);
            if ($winner !== null) {
                $winners[] = $winner;
            }
        }

        // 5) Ordre final : vote majoritaire des positions (Copeland).
        $winners = self::orderWinners($winners);

        // 6) En-têtes : valeur la plus fréquente entre variantes. La table
        // TVA ne garde que les lettres réellement portées par les lignes
        // gagnantes (une lettre parasite « E » lue à la place de « B » par
        // une seule variante ne doit pas dupliquer l'entrée 5,5 %).
        $usedLetters = [];
        foreach ($winners as $w) {
            $letter = (string) ($w['vat_letter'] ?? '');
            if (preg_match('/^[A-Z]$/', $letter) === 1) {
                $usedLetters[$letter] = true;
            }
        }
        $headers = self::majorityHeaders($variants, $validatedPerVariant, array_keys($usedLetters));

        // 7) Texte consolidé reconstruit, puis re-parse (contrat inchangé).
        $reconstructed = self::reconstruct($headers, $winners);
        $invoice = MetroInvoiceParser::parse($reconstructed);

        return [
            'text'     => self::concatTexts($variants),
            'invoice'  => $invoice,
            'variants' => count($variants),
            'validated' => $validatedTotal,
            'groups'   => count($winners),
        ];
    }

    /**
     * Découpe un texte de variante en passes (même séparateur que le
     * parseur : « ----- OCR alt ----- »).
     *
     * @return list<string>
     */
    private static function splitPasses(string $text): array
    {
        $parts = preg_split('/^\s*-{3,}\s*OCR alt\s*-+.*$/m', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($p): bool => $p !== ''));
    }

    /**
     * Notation d'un candidat :
     *  - +2 arithmétique stricte : colisage×qté×PU ≈ total (±0,02) ;
     *  - +2 clé de contrôle EAN-13 valide ;
     *  - +1 EAN-13 « réparé » (substitution d'UN chiffre, acceptée si elle
     *    est unique ou confirmée par un EAN valide lu sur une autre ligne) ;
     *  - (+1 collectif Σ lignes ↔ Total H.T., appliqué plus haut).
     *
     * @param array<string,mixed> $c
     * @param list<string> $knownEans EAN valides lus sur les autres lignes
     * @return array<string,mixed>
     */
    private static function scoreCandidate(array $c, array $knownEans): array
    {
        $score = 0;
        $eanKey = null;

        // Arithmétique colisage × qté × PU (même tolérance que le parseur).
        $colisage = isset($c['colisage']) ? (int) $c['colisage'] : null;
        $qty = isset($c['qty']) ? (int) $c['qty'] : null;
        $pu = isset($c['unit_price']) ? (float) $c['unit_price'] : null;
        $total = (float) $c['total'];
        if ($colisage !== null && $qty !== null && $pu !== null
            && abs(round($colisage * $qty * $pu, 2) - $total) <= self::amountTolerance($total)
        ) {
            $score += 2;
        }

        // EAN : valide, réparé, ou illisible (aucune invention).
        $ean = (string) ($c['ean'] ?? '');
        if ($ean !== '' && Ean13::isValid($ean)) {
            $score += 2;
            $eanKey = $ean;
        } elseif (preg_match('/^\d{13}$/', $ean) === 1) {
            $fixes = Ean13::repairOneDigit($ean);
            $confirmed = array_values(array_intersect($fixes, $knownEans));
            $fix = null;
            if (count($fixes) === 1) {
                $fix = $fixes[0];
            } elseif (count($confirmed) === 1) {
                $fix = $confirmed[0];
            }
            if ($fix !== null) {
                $score += 1;
                $c['ean'] = $fix;
                $c['repaired'] = true;
                $eanKey = $fix;
            }
        }

        $c['score'] = $score;
        $c['ean_key'] = $eanKey;

        return $c;
    }

    /**
     * Regroupe les candidats (toutes variantes confondues) : même EAN de
     * clé valide, sinon même montant ET libellés similaires à ≥ 80 %.
     * Deux EAN-13 différents (même non valides) interdisent la fusion par
     * libellé (produits distincts : RED BULL ICE vs PEACH à montants
     * identiques). Les candidats sans identité (colonnes orphelines à
     * libellé générique) ne créent jamais de groupe : ils rejoignent un
     * groupe au montant et au PU identiques, ou sont abandonnés — jamais
     * émis comme ligne inconnue.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<list<array<string,mixed>>>
     */
    private static function groupCandidates(array $candidates): array
    {
        /** @var list<list<array<string,mixed>>> $groups */
        $groups = [];
        /** @var list<string> $groupEans clé EAN (ou '') par groupe */
        $groupEans = [];

        foreach ($candidates as $c) {
            // Fragment : libellé sans véritable identité produit (aucun ou
            // un seul jeton alphabétique — débris de colonnes OCR type
            // « MMERSAGRUM 250| 1,233 24 »). Il ne crée jamais de groupe
            // propre : rattachement numérique à une ligne existante (même
            // montant, même PU, mêmes unités) ou abandon — jamais une ligne
            // fantôme émise sur la seule foi d'un montant isolé.
            $generic = self::isGenericCandidate($c) || self::countAlphaTokens((string) $c['label']) < 2;
            $matched = false;

            foreach ($groups as $gi => $members) {
                $ref = $members[0];
                if ($generic) {
                    // Rattachement purement numérique (backfill de colonnes).
                    if (self::sameMoney((float) $ref['total'], (float) $c['total'])
                        && self::sameMoneyOrNull($ref['unit_price'] ?? null, $c['unit_price'] ?? null)
                        && self::sameUnitsOrNull($ref, $c)
                    ) {
                        $groups[$gi][] = $c;
                        $matched = true;
                    }
                    continue;
                }

                $refEan = (string) ($groupEans[$gi] ?? '');
                $candEan = (string) ($c['ean_key'] ?? '');
                if ($refEan !== '' && $candEan !== '') {
                    if ($refEan === $candEan) {
                        $groups[$gi][] = $c;
                        $matched = true;
                    }
                    continue; // EAN de clés différentes : jamais fusionnés
                }
                if (self::sameMoney((float) $ref['total'], (float) $c['total'])
                    && self::sameUnitsOrNull($ref, $c)
                    && self::differentHardEans($ref, $c) === false
                    && (self::labelSimilarity((string) $ref['label'], (string) $c['label']) >= self::LABEL_SIMILARITY
                        || self::labelContained((string) $ref['label'], (string) $c['label']))
                ) {
                    $groups[$gi][] = $c;
                    $matched = true;
                }
            }

            if (!$matched && !$generic) {
                $groups[] = [$c];
                $groupEans[] = (string) ($c['ean_key'] ?? '');
            }
        }

        return $groups;
    }

    /**
     * L'un des deux libellés contient-il l'autre (mêmes jetons alphabétiques
     * présents, au moins deux) ? « OASIS TROPICAL 2L » est contenu dans la
     * lecture dégradée « 9 OASIS TROPICAL 2L 2,080 » : même ligne — alors
     * que la similarité de Levenshtein reste sous le seuil. Deux produits
     * différents au même montant (RED BULL ICE vs SUMMER AGRUM) ne se
     * contiennent jamais.
     */
    private static function labelContained(string $a, string $b): bool
    {
        $ta = self::labelTokens($a);
        $tb = self::labelTokens($b);
        if (count($ta) < 2 || count($tb) < 2) {
            return false;
        }

        return count(array_diff($ta, $tb)) === 0 || count(array_diff($tb, $ta)) === 0;
    }

    /**
     * Jetons alphabétiques (≥ 2 lettres) d'un libellé normalisé — chiffres
     * et déchets OCR exclus (« 9 », « 2,080 » lus « 2 080 »…).
     *
     * @return list<string>
     */
    private static function labelTokens(string $label): array
    {
        $norm = self::normalizeLabel($label);
        $tokens = explode(' ', $norm);
        $alpha = [];
        foreach ($tokens as $token) {
            if ($token !== '' && preg_match('/[a-z]{2}/', $token) === 1) {
                $alpha[] = $token;
            }
        }

        return $alpha;
    }

    /** Nombre de jetons alphabétiques d'un libellé (identité produit). */
    private static function countAlphaTokens(string $label): int
    {
        return count(self::labelTokens($label));
    }

    /**
     * Unités comparables : les deux connues (≥ 1) doivent être égales —
     * un fragment à colisage×qté lu ne rejoint pas une ligne d'un autre
     * format (« 24 » vs « 48 » = deux lignes différentes au même montant).
     */
    private static function sameUnitsOrNull(array $a, array $b): bool
    {
        $ua = (int) ($a['units'] ?? 0);
        $ub = (int) ($b['units'] ?? 0);
        if ($ua < 1 || $ub < 1) {
            return true;
        }

        return $ua === $ub;
    }

    /** Deux candidats portent-ils des EAN-13 (non valides) différents ? */
    private static function differentHardEans(array $a, array $b): bool
    {
        $ea = (string) ($a['ean'] ?? '');
        $eb = (string) ($b['ean'] ?? '');

        return preg_match('/^\d{13}$/', $ea) === 1
            && preg_match('/^\d{13}$/', $eb) === 1
            && $ea !== $eb;
    }

    /** Candidat sans identité produit (colonnes orphelines génériques). */
    private static function isGenericCandidate(array $c): bool
    {
        $label = trim((string) ($c['label'] ?? ''));

        return $label === '' || $label === self::GENERIC_LABEL
            || (string) ($c['ean'] ?? '') === '' && (string) ($c['article'] ?? '') === ''
            && preg_match_all('/\p{L}/u', $label) < 2;
    }

    /**
     * Meilleur candidat d'un groupe : score maximal, à égalité celui du
     * texte le plus validé, à égalité le premier vu (variante la plus
     * proche de l'originale). Les champs manquants sont complétés par les
     * autres membres du groupe (lecture complémentaire de la MÊME ligne,
     * jamais d'invention).
     *
     * @param list<array<string,mixed>> $members
     * @param list<int> $validatedPerVariant
     * @return array<string,mixed>|null null = groupe sans identité émissible
     */
    private static function pickWinner(array $members, array $validatedPerVariant): ?array
    {
        $winner = null;
        $bestScore = -1;
        $bestVariantRank = PHP_INT_MAX;
        foreach ($members as $m) {
            $score = (int) $m['score'];
            $rank = $validatedPerVariant[(int) $m['variant']] ?? 0;
            if ($score > $bestScore || ($score === $bestScore && $rank > $bestVariantRank)) {
                $winner = $m;
                $bestScore = $score;
                $bestVariantRank = $rank;
            }
        }

        if ($winner === null) {
            return null;
        }

        $line = $winner; // copie de travail (tableaux PHP par valeur)

        // Libellé : jamais générique en sortie.
        if (self::isGenericCandidate($line)) {
            foreach ($members as $m) {
                if (!self::isGenericCandidate($m)) {
                    $line['label'] = $m['label'];
                    break;
                }
            }
        }
        if (self::isGenericCandidate($line)) {
            return null; // aucune identité lisible : ligne non émise
        }

        // Compléments : EAN valide > EAN brut lu ; article, lettre TVA,
        // PU, colisage/qté quand absents du gagnant.
        if ((string) ($line['ean'] ?? '') === '' || !Ean13::isValid((string) $line['ean'])) {
            foreach ($members as $m) {
                $ean = (string) ($m['ean_key'] ?? '');
                if ($ean !== '') {
                    $line['ean'] = $ean;
                    break;
                }
            }
        }
        foreach (['article', 'vat_letter', 'unit_price'] as $field) {
            if (($line[$field] ?? null) === null || (string) ($line[$field] ?? '') === '') {
                foreach ($members as $m) {
                    if (($m[$field] ?? null) !== null && (string) ($m[$field] ?? '') !== '') {
                        $line[$field] = $m[$field];
                        break;
                    }
                }
            }
        }

        // Colisage/qté manquants : repris d'un membre arithmétiquement
        // compatible avec le montant retenu.
        if (((int) ($line['colisage'] ?? 0) < 1 || (int) ($line['qty'] ?? 0) < 1)) {
            $pu = isset($line['unit_price']) ? (float) $line['unit_price'] : null;
            $total = (float) $line['total'];
            foreach ($members as $m) {
                $col = isset($m['colisage']) ? (int) $m['colisage'] : null;
                $qty = isset($m['qty']) ? (int) $m['qty'] : null;
                if ($col === null || $qty === null || $col < 1 || $qty < 1) {
                    continue;
                }
                if ($pu !== null && abs(round($col * $qty * $pu, 2) - $total) > self::amountTolerance($total)) {
                    continue; // incohérent avec le montant retenu : ignoré
                }
                $line['colisage'] = $col;
                $line['qty'] = $qty;
                break;
            }
        }

        // Cohérence finale : des colonnes incompatibles avec le montant
        // sont retirées (le parseur déduira les unités du total et du PU,
        // avec avertissement) — jamais de ligne mathématiquement fausse.
        // Même tolérance que le parseur (±2 c. ou ±1 %) : 24×2×1,233 =
        // 59,184 pour un total lu 59,16 doit rester accepté.
        $col = isset($line['colisage']) ? (int) $line['colisage'] : null;
        $qty = isset($line['qty']) ? (int) $line['qty'] : null;
        $pu = isset($line['unit_price']) ? (float) $line['unit_price'] : null;
        if ($col !== null && $qty !== null && $pu !== null
            && abs(round($col * $qty * $pu, 2) - (float) $line['total']) > self::amountTolerance((float) $line['total'])
        ) {
            $line['colisage'] = null;
            $line['qty'] = null;
        }

        return $line;
    }

    /**
     * Ordre final des lignes : vote majoritaire (méthode de Copeland) sur
     * les paires de lignes co-présentes dans chaque passe — robuste aux
     * passes qui éparpillent le tableau ou le lisent à une autre échelle.
     * À égalité : position relative minimale (proxy du Y de la photo),
     * puis ordre de première apparition.
     *
     * @param list<array<string,mixed>> $winners
     * @return list<array<string,mixed>>
     */
    private static function orderWinners(array $winners): array
    {
        $n = count($winners);
        if ($n < 2) {
            return $winners;
        }

        // Ordre des groupes dans chaque passe (par position de ligne).
        /** @var array<string, array<int, int>> $passOrder passId => pos => indexGagnant */
        $passOrder = [];
        foreach ($winners as $wi => $w) {
            $passId = $w['variant'] . ':' . $w['pass'];
            $passOrder[$passId][(int) $w['pos']] = $wi;
        }
        foreach ($passOrder as $passId => $order) {
            ksort($order);
            $passOrder[$passId] = array_values($order);
        }

        $wins = array_fill(0, $n, 0);
        $losses = array_fill(0, $n, 0);
        foreach ($passOrder as $order) {
            $count = count($order);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $wins[$order[$i]]++;
                    $losses[$order[$j]]++;
                }
            }
        }

        // Position relative minimale (proxy du Y) pour départager les ex æquo.
        $minRatio = array_fill(0, $n, INF);
        foreach ($winners as $wi => $w) {
            $passLines = max(1, (int) ($w['pass_lines'] ?? 1));
            $minRatio[$wi] = ((int) $w['pos']) / $passLines;
        }

        $indices = range(0, $n - 1);
        usort($indices, static function (int $a, int $b) use ($wins, $losses, $minRatio): int {
            $netA = $wins[$a] - $losses[$a];
            $netB = $wins[$b] - $losses[$b];
            if ($netA !== $netB) {
                return $netB <=> $netA;
            }
            if (abs($minRatio[$a] - $minRatio[$b]) > 0.000001) {
                return $minRatio[$a] <=> $minRatio[$b];
            }

            return $a <=> $b;
        });

        return array_map(static fn (int $i): array => $winners[$i], $indices);
    }

    /**
     * En-têtes : valeur la plus fréquente entre variantes (les null ne
     * votent pas — une variante qui n'a pas lu ne masque pas celle qui a
     * lu) ; à égalité, la valeur de la variante la plus validée, puis la
     * première. Table TVA : vote par LETTRE sur l'entrée complète, ordre
     * par meilleur vote puis première apparition.
     *
     * @param list<array{name:string,text:string,invoice:array,kind:string}> $variants
     * @param list<int> $validatedPerVariant
     * @param list<string> $usedLetters lettres TVA portées par les lignes
     *        gagnantes (liste vide : aucune restriction)
     * @return array{invoice_number:?string, purchased_at:?string,
     *               total_ht:?float, total_ttc:?float,
     *               vat_rates:list<array{letter:string,rate:float,base_ht:?float,vat:?float,total_ttc:?float}>}
     */
    private static function majorityHeaders(array $variants, array $validatedPerVariant, array $usedLetters = []): array
    {
        $maxValidated = max(1, max($validatedPerVariant));
        $pick = static function (string $field) use ($variants, $validatedPerVariant, $maxValidated): ?string {
            $tally = [];
            foreach ($variants as $vi => $v) {
                $value = $v['invoice'][$field] ?? null;
                if ($value === null || (string) $value === '') {
                    continue;
                }
                $key = (string) $value;
                $tally[$key] = ($tally[$key] ?? 0) + 1 + $validatedPerVariant[$vi] / ($maxValidated + 1);
            }
            if ($tally === []) {
                return null;
            }
            arsort($tally);

            return (string) array_key_first($tally);
        };

        $pickFloat = static function (string $field) use ($variants, $validatedPerVariant, $maxValidated): ?float {
            $tally = [];
            foreach ($variants as $vi => $v) {
                $value = $v['invoice'][$field] ?? null;
                if ($value === null) {
                    continue;
                }
                $key = number_format(round((float) $value, 2), 2, '.', '');
                $tally[$key] = ($tally[$key] ?? 0) + 1 + $validatedPerVariant[$vi] / ($maxValidated + 1);
            }
            if ($tally === []) {
                return null;
            }
            arsort($tally);

            return round((float) array_key_first($tally), 2);
        };

        // Table TVA : vote par lettre.
        /** @var array<string, array<string, array{count:float, entry:array}>> $byLetter */
        $byLetter = [];
        foreach ($variants as $vi => $v) {
            foreach (($v['invoice']['vat_rates'] ?? []) as $entry) {
                $letter = (string) ($entry['letter'] ?? '');
                if ($letter === '') {
                    continue;
                }
                $key = $letter . '|' . number_format((float) $entry['rate'], 1, '.', '')
                    . '|' . number_format((float) ($entry['base_ht'] ?? 0), 2, '.', '')
                    . '|' . number_format((float) ($entry['vat'] ?? 0), 2, '.', '')
                    . '|' . number_format((float) ($entry['total_ttc'] ?? 0), 2, '.', '');
                $byLetter[$letter][$key] ??= ['count' => 0.0, 'entry' => $entry];
                $byLetter[$letter][$key]['count'] += 1 + $validatedPerVariant[$vi] / ($maxValidated + 1);
            }
        }
        $letters = array_keys($byLetter);
        if ($usedLetters !== []) {
            // Lettres parasites (lues à la place de la bonne par une seule
            // variante) : jamais conservées à côté de la vraie lettre.
            $letters = array_values(array_intersect($letters, $usedLetters));
        }
        // Ordre de présentation : taux croissant (usage des factures
        // françaises — B = 5,5 % avant D = 20 %), à égalité par lettre.
        // Le VOTE ne décide que de l'entrée retenue PAR LETTRE (ci-dessous),
        // jamais de l'ordre d'affichage : une variante qui a perdu le « B »
        // ne doit pas inverser la table reconstruite.
        $ratesByLetter = [];
        foreach ($letters as $letter) {
            $best = null;
            foreach ($byLetter[$letter] as $cell) {
                if ($best === null || $cell['count'] > $best['count']) {
                    $best = $cell;
                }
            }
            $ratesByLetter[$letter] = ['rate' => (float) $best['entry']['rate'], 'best' => $best];
        }
        usort($letters, static function (string $a, string $b) use ($ratesByLetter): int {
            if (abs($ratesByLetter[$a]['rate'] - $ratesByLetter[$b]['rate']) > 0.000001) {
                return $ratesByLetter[$a]['rate'] <=> $ratesByLetter[$b]['rate'];
            }

            return $a <=> $b;
        });
        $vatRates = [];
        foreach ($letters as $letter) {
            $best = $ratesByLetter[$letter]['best']['entry'];
            $vatRates[] = [
                'letter'    => (string) $best['letter'],
                'rate'      => round((float) $best['rate'], 1),
                'base_ht'   => $best['base_ht'] !== null ? round((float) $best['base_ht'], 2) : null,
                'vat'       => $best['vat'] !== null ? round((float) $best['vat'], 2) : null,
                'total_ttc' => $best['total_ttc'] !== null ? round((float) $best['total_ttc'], 2) : null,
            ];
        }

        return [
            'invoice_number' => $pick('invoice_number'),
            'purchased_at'   => $pick('purchased_at'),
            'total_ht'       => $pickFloat('total_ht'),
            'total_ttc'      => $pickFloat('total_ttc'),
            'vat_rates'      => $vatRates,
        ];
    }

    /**
     * Reconstruction d'un texte METRO propre : en-têtes votés + une ligne
     * canonique par groupe gagnant (« EAN article LIBELLE PU COL QTE
     * MONTANT [LETTRE] »), puis table TVA et totaux. MetroInvoiceParser::
     * parse() refait toute l'analyse (lettres → taux, cohérences,
     * avertissements) sur ce texte sans surprise.
     *
     * @param array{invoice_number:?string, purchased_at:?string,
     *              total_ht:?float, total_ttc:?float, vat_rates:list<array{letter:string,rate:float,base_ht:?float,vat:?float,total_ttc:?float}>} $headers
     * @param list<array<string,mixed>> $winners
     */
    private static function reconstruct(array $headers, array $winners): string
    {
        $out = ["METRO"];

        if ($headers['invoice_number'] !== null) {
            $out[] = 'N° FACTURE ' . $headers['invoice_number'];
        }
        if ($headers['purchased_at'] !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $headers['purchased_at']) === 1) {
            [$y, $m, $d] = explode('-', $headers['purchased_at']);
            $out[] = "Date facture : $d-$m-$y";
        }

        foreach ($winners as $w) {
            $parts = [];
            $ean = preg_match('/^\d{12,14}$/', (string) ($w['ean'] ?? '')) === 1 ? (string) $w['ean'] : '';
            $article = (string) ($w['article'] ?? '');
            if ($ean !== '') {
                $parts[] = $ean;
                if ($article !== '' && preg_match('/^\d{4,8}$/', $article) === 1) {
                    $parts[] = $article;
                }
            } elseif ($article !== '' && preg_match('/^\d{4,14}$/', $article) === 1) {
                $parts[] = $article;
            }
            $parts[] = (string) $w['label'];
            $pu = $w['unit_price'] ?? null;
            if ($pu !== null) {
                $parts[] = number_format((float) $pu, 3, ',', '');
            }
            $col = $w['colisage'] ?? null;
            $qty = $w['qty'] ?? null;
            if ($col !== null && $qty !== null && (int) $col > 0 && (int) $qty > 0) {
                $parts[] = (string) (int) $col;
                $parts[] = (string) (int) $qty;
            }
            $parts[] = number_format((float) $w['total'], 2, ',', '');
            $letter = (string) ($w['vat_letter'] ?? '');
            if (preg_match('/^[A-Z]$/', $letter) === 1) {
                $parts[] = $letter;
            }
            $out[] = implode(' ', $parts);
        }

        foreach ($headers['vat_rates'] as $entry) {
            $parts = [];
            if ($entry['base_ht'] !== null) {
                $parts[] = number_format((float) $entry['base_ht'], 2, ',', '');
            }
            $parts[] = $entry['letter'] . ' = ' . number_format((float) $entry['rate'], 2, ',', '') . '%';
            if ($entry['vat'] !== null) {
                $parts[] = number_format((float) $entry['vat'], 2, ',', '');
            }
            if ($entry['total_ttc'] !== null) {
                $parts[] = number_format((float) $entry['total_ttc'], 2, ',', '');
            }
            $out[] = implode(' ', $parts);
        }

        if ($headers['total_ht'] !== null) {
            $out[] = 'Total H.T. : ' . number_format((float) $headers['total_ht'], 2, ',', '');
        }
        if ($headers['total_ttc'] !== null) {
            $out[] = 'Total à payer ' . number_format((float) $headers['total_ttc'], 2, ',', '');
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Texte consolidé pour la textarea / l'audit : première variante puis
     * les suivantes, chacune introduite par un séparateur nommé (distinct
     * du marqueur ALT du parseur, qui ne coupe que « OCR alt »).
     *
     * @param list<array{name:string,text:string,invoice:array,kind:string}> $variants
     */
    private static function concatTexts(array $variants): string
    {
        $out = $variants[0]['text'];
        for ($i = 1; $i < count($variants); $i++) {
            $out .= "\n----- OCR variante " . $variants[$i]['name'] . " -----\n" . $variants[$i]['text'];
        }

        return $out;
    }

    /**
     * Nombre de candidats FORTEMENT validés d'un texte (les deux passes) :
     * arithmétique stricte ET EAN valide — métrique du « dernier recours ».
     */
    private static function countStrongCandidates(string $text): int
    {
        $knownEans = [];
        $raw = [];
        foreach (self::splitPasses($text) as $passText) {
            foreach (MetroInvoiceParser::extractCandidates($passText) as $c) {
                $raw[] = $c;
                $ean = (string) ($c['ean'] ?? '');
                if (Ean13::isValid($ean)) {
                    $knownEans[$ean] = true;
                }
            }
        }
        $count = 0;
        foreach ($raw as $c) {
            if (self::scoreCandidate($c, array_keys($knownEans))['score'] >= 4) {
                $count++;
            }
        }

        return $count;
    }

    /** Même montant (±0,02) ? */
    private static function sameMoney(float $a, float $b): bool
    {
        return abs($a - $b) <= self::MONEY_EPSILON;
    }

    /** Même montant, en tolérant deux « inconnus » (null). */
    private static function sameMoneyOrNull(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return true;
        }

        return self::sameMoney((float) $a, (float) $b);
    }

    /**
     * Similarité de libellé (0-1) : Levenshtein sur une forme normalisée
     * (minuscules, sans accents ni ponctuation). Chaque bloc est
     * individuellement borné : « RED BULL ICE » ≠ « RED BULL PEACH » à
     * montants égaux reste sous le seuil de fusion.
     */
    private static function labelSimilarity(string $a, string $b): float
    {
        $na = self::normalizeLabel($a);
        $nb = self::normalizeLabel($b);
        if ($na === '' || $nb === '') {
            return 0.0;
        }

        $distance = levenshtein($na, $nb);

        return 1 - $distance / max(strlen($na), strlen($nb));
    }

    /** Forme normalisée d'un libellé (ASCII, alphanumérique + espaces). */
    private static function normalizeLabel(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}
