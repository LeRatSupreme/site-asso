<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\AdminLedgerController;
use PHPUnit\Framework\TestCase;

/**
 * Tests purs du tri de la vignette « Derniers tickets & dépenses » du
 * livre comptable (AdminLedgerController::sortRecentItems et
 * recentItemDate) : dépenses et groupes d'achats fusionnés en une
 * seule liste chronologique, du jour le plus récent au plus ancien ;
 * à date égale, la saisie la plus récente d'abord (created_at DESC).
 *
 * C'est le correctif du bug « je ne vois pas toutes les dépenses / ça
 * ne se met pas à jour » : les anciennes listes séparées (8 achats
 * triés par date d'achat PUIS 8 dépenses triées par date de saisie)
 * cachaient les saisies datées d'un ancien jour sous les achats.
 *
 * Méthodes pures (aucun accès POST/DB) : le contrôleur n'est jamais
 * instancié, seuls les appels statiques sont exercés (même pattern que
 * LedgerPurchaseGroupKeyTest).
 */
final class LedgerRecentItemsSortTest extends TestCase
{
    /**
     * Élément de la liste fusionnée, forme minimale (type + jour +
     * saisie) — la vue n'utilise que ces clés pour le tri.
     *
     * @return array<string,mixed>
     */
    private static function item(string $type, string $day, string $createdAt): array
    {
        $item = [
            'type'       => $type,
            'created_at' => $createdAt,
        ];
        if ($type === 'purchase') {
            $item['date'] = $day;
        } else {
            $item['spent_at'] = $day;
        }

        return $item;
    }

    /**
     * Entrelacement chronologique : une dépense datée d'un jour plus
     * ancien se place ENTRE les achats, à sa place — visible, comme
     * dans le livre (c'était le bug : cachée sous le bloc des achats).
     */
    public function test_depense_datee_ancien_jour_entre_les_achats(): void
    {
        $sorted = AdminLedgerController::sortRecentItems([
            self::item('purchase', '2026-10-14', '2026-10-14 08:00:00'),
            self::item('purchase', '2026-10-12', '2026-10-14 09:00:00'),
            self::item('expense', '2026-10-13', '2026-10-19 10:00:00'),
        ]);

        self::assertSame(
            ['purchase:2026-10-14', 'expense:2026-10-13', 'purchase:2026-10-12'],
            array_map(
                static fn (array $i): string => $i['type'] . ':' . AdminLedgerController::recentItemDate($i),
                $sorted
            ),
            'Une seule liste chronologique DESC, dépenses et achats mélangés.'
        );
    }

    /**
     * À date égale : la saisie la plus récente (created_at) d'abord.
     */
    public function test_meme_jour_la_saisie_la_plus_recente_dabord(): void
    {
        $sorted = AdminLedgerController::sortRecentItems([
            self::item('expense', '2026-10-14', '2026-10-19 18:00:00'),
            self::item('purchase', '2026-10-14', '2026-10-14 08:00:00'),
            self::item('expense', '2026-10-14', '2026-10-15 09:30:00'),
        ]);

        self::assertSame(
            ['2026-10-19 18:00:00', '2026-10-15 09:30:00', '2026-10-14 08:00:00'],
            array_map(static fn (array $i): string => (string) $i['created_at'], $sorted),
            'created_at DESC départage les ex æquo de date.'
        );
    }

    /**
     * Ex æquo complets (même jour, même created_at) : l'ordre
     * d'insertion est conservé (usort stable, PHP ≥ 8).
     */
    public function test_ex_aequo_complets_stables(): void
    {
        $first = self::item('purchase', '2026-10-14', '2026-10-14 08:00:00');
        $first['supplier'] = 'METRO';
        $second = self::item('expense', '2026-10-14', '2026-10-14 08:00:00');
        $second['label'] = 'Gobelets';

        $sorted = AdminLedgerController::sortRecentItems([$first, $second]);

        self::assertSame('METRO', $sorted[0]['supplier'] ?? null, 'Ordre d\'insertion conservé (achat d\'abord).');
        self::assertSame('Gobelets', $sorted[1]['label'] ?? null);
    }

    /**
     * created_at absent (« ») : départage par chaînes vides, sans
     * erreur — l'ordre d'insertion reste la référence.
     */
    public function test_created_at_absent_sans_erreur(): void
    {
        $a = self::item('purchase', '2026-10-14', '');
        $b = self::item('expense', '2026-10-14', '');
        unset($b['created_at']);

        $sorted = AdminLedgerController::sortRecentItems([$a, $b]);

        self::assertCount(2, $sorted, 'Aucune exception, liste intacte.');
        self::assertSame('purchase', $sorted[0]['type']);
    }

    /**
     * recentItemDate : jour d'achat pour un groupe ('date'), jour de
     * dépense pour une dépense ('spent_at'), bornes trimées.
     */
    public function test_recent_item_date_selon_le_type(): void
    {
        self::assertSame(
            '2026-10-14',
            AdminLedgerController::recentItemDate(['type' => 'purchase', 'date' => ' 2026-10-14 '])
        );
        self::assertSame(
            '2026-10-13',
            AdminLedgerController::recentItemDate(['type' => 'expense', 'spent_at' => ' 2026-10-13 '])
        );
    }

    /**
     * Robustesse : type inconnu ou absent → lu comme une dépense
     * ('spent_at', même branche que la vue pour le rendu) ; clé de
     * jour absente → '' sans exception.
     */
    public function test_recent_item_date_replis_robustes(): void
    {
        self::assertSame('2026-10-14', AdminLedgerController::recentItemDate(['type' => 'divers', 'spent_at' => '2026-10-14']));
        self::assertSame('', AdminLedgerController::recentItemDate(['type' => 'purchase']));
        self::assertSame('2026-10-14', AdminLedgerController::recentItemDate(['spent_at' => '2026-10-14']));
        self::assertSame('', AdminLedgerController::recentItemDate([]));
    }
}
