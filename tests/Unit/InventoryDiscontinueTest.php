<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * La vue Inventaire doit exposer les formulaires « plus en vente »
 * (boutons 🚫) pointant vers la route discontinue avec retour inventaire.
 */
final class InventoryDiscontinueTest extends TestCase
{
    public function test_la_vue_inventaire_contient_les_formulaires_discontinue(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/views/admin/compta/inventory.php');
        self::assertNotFalse($view);
        self::assertStringContainsString("'/discontinue'", $view);
        self::assertStringContainsString('name="back"', $view);
        self::assertStringContainsString('data-confirm=', $view);
    }
}
