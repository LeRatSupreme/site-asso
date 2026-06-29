<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderWorkflow;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du workflow de statut des commandes cafétéria (sans DB).
 */
final class OrderWorkflowTest extends TestCase
{
    public function test_transition_valide_est_autorisee(): void
    {
        self::assertTrue(OrderWorkflow::canTransition('PENDING', 'CONFIRMED'));
        self::assertTrue(OrderWorkflow::canTransition('CONFIRMED', 'PREPARING'));
        self::assertTrue(OrderWorkflow::canTransition('PREPARING', 'READY'));
        self::assertTrue(OrderWorkflow::canTransition('READY', 'DELIVERED'));
    }

    public function test_annulation_autorisee_depuis_les_etats_non_terminaux(): void
    {
        self::assertTrue(OrderWorkflow::canTransition('PENDING', 'CANCELLED'));
        self::assertTrue(OrderWorkflow::canTransition('CONFIRMED', 'CANCELLED'));
        self::assertTrue(OrderWorkflow::canTransition('PREPARING', 'CANCELLED'));
    }

    public function test_transition_interdite_est_rejetee(): void
    {
        // Sens inverse interdit.
        self::assertFalse(OrderWorkflow::canTransition('READY', 'PENDING'));
        // Saut d'étape interdit.
        self::assertFalse(OrderWorkflow::canTransition('PENDING', 'READY'));
        // Annulation impossible depuis READY.
        self::assertFalse(OrderWorkflow::canTransition('READY', 'CANCELLED'));
    }

    public function test_etats_terminaux_n_ont_aucune_sortie(): void
    {
        self::assertFalse(OrderWorkflow::canTransition('DELIVERED', 'PENDING'));
        self::assertFalse(OrderWorkflow::canTransition('CANCELLED', 'PENDING'));
        self::assertFalse(OrderWorkflow::canTransition('DELIVERED', 'CANCELLED'));
    }

    public function test_is_terminal(): void
    {
        self::assertTrue(OrderWorkflow::isTerminal('DELIVERED'));
        self::assertTrue(OrderWorkflow::isTerminal('CANCELLED'));
        self::assertFalse(OrderWorkflow::isTerminal('READY'));
        self::assertFalse(OrderWorkflow::isTerminal('PENDING'));
    }

    public function test_insensible_a_la_casse(): void
    {
        self::assertTrue(OrderWorkflow::canTransition('pending', 'confirmed'));
    }

    public function test_statut_inconnu_rejete(): void
    {
        self::assertFalse(OrderWorkflow::canTransition('INCONNU', 'READY'));
    }

    public function test_statuses_contient_tous_les_statuts(): void
    {
        self::assertContains('PENDING', OrderWorkflow::statuses());
        self::assertContains('DELIVERED', OrderWorkflow::statuses());
        self::assertSame(6, count(OrderWorkflow::statuses()));
    }
}
