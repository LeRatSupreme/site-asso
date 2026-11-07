<?php

declare(strict_types=1);

namespace App\Core\Compta;

use App\Models\InventoryCount;
use App\Models\ProductDiscontinued;

/**
 * Cycle de vie automatique « pause / reprise » des produits selon le stock.
 *
 * Règles (exécutées à chaque affichage de l'Inventaire, voir
 * AdminStockController::inventory) :
 *
 *   1. Un produit mis en pause AUTOMATIQUEMENT (produit_discontinued
 *      .updated_by = « auto ») qui redevient disponible (stock théorique
 *      > 0) est remis en vente aussitôt. Les pauses MANUELLES (saisonnières,
 *      posées par un membre du bureau) ne sont jamais levées ici.
 *
 *   2. Un produit EN VENTE dont le stock théorique reste à 0 (ou négatif =
 *      survente) pendant au moins 7 jours est mis en pause automatiquement :
 *      plus rien à compter ni à commander, retour en un clic si besoin.
 *
 * Le suivi « à zéro depuis quand ? » vit dans la table `product_zero_since`,
 * alimentée par le sweep lui-même (idempotent : ne écrit que sur changement).
 *
 * @return array{resumed:list<string>, paused:list<string>} Clés touchées.
 */
final class ProductLifecycle
{
    /** Durée (jours) au-delà de laquelle un stock resté à 0 met le produit en pause. */
    public const ZERO_DAYS_BEFORE_PAUSE = 7;

    /**
     * Applique les règles et renvoie les changements effectués.
     *
     * @return array{resumed:list<string>, paused:list<string>}
     */
    public static function sweep(): array
    {
        $resumed = [];
        $paused  = [];

        $theoretical = InventoryCount::theoreticalStocksMap();

        // ── 1) Pauses automatiques avec du stock : retour en vente. ──
        foreach (ProductDiscontinued::autoPausedKeys() as $key) {
            $stock = $theoretical[$key] ?? null;
            if ($stock !== null && $stock > 0) {
                ProductDiscontinued::resume($key);
                $resumed[] = $key;
            }
        }

        // ── 2) Produits en vente à stock nul depuis ≥ 7 jours : pause. ──
        $zeroSince   = self::zeroSinceMap();
        $now         = time();
        $stillPaused = array_flip(ProductDiscontinued::keys());

        foreach ($theoretical as $key => $stock) {
            $key = (string) $key;

            // En pause (manuel ou auto non repris) : hors périmètre.
            if (isset($stillPaused[$key])) {
                continue;
            }

            // Stock positif : le produit vit — on oublie tout suivi à zéro.
            if ($stock !== null && $stock > 0) {
                if (isset($zeroSince[$key])) {
                    self::forgetZero($key);
                }
                continue;
            }

            // Jamais compté ni établi (stock inconnu, pas « 0 ») : ignoré.
            if ($stock === null) {
                continue;
            }

            // Stock à 0 (ou négatif = survente) : date de début, puis pause
            // automatique si ça dure depuis au moins 7 jours.
            $since = $zeroSince[$key] ?? null;
            if ($since === null) {
                self::markZero($key);
                continue;
            }

            $sinceTs = strtotime((string) $since);
            if ($sinceTs !== false && $now - $sinceTs >= self::ZERO_DAYS_BEFORE_PAUSE * 86400) {
                ProductDiscontinued::mark($key, 'auto');
                self::forgetZero($key);
                $paused[] = $key;
            }
        }

        return ['resumed' => $resumed, 'paused' => $paused];
    }

    // -----------------------------------------------------------------
    //  Suivi « à zéro depuis » (table product_zero_since)
    // -----------------------------------------------------------------

    /**
     * @return array<string,string> Clé = produit, valeur = datetime « zero_since ».
     */
    private static function zeroSinceMap(): array
    {
        try {
            $rows = ProductDiscontinued::connection()
                ->query('SELECT product_key, zero_since FROM product_zero_since')
                ->fetchAll();
        } catch (\Throwable) {
            // Table pas encore migrée : aucune mise en pause automatique.
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['product_key']] = (string) $r['zero_since'];
        }

        return $out;
    }

    private static function markZero(string $key): void
    {
        try {
            ProductDiscontinued::connection()->prepare(
                'INSERT INTO product_zero_since (product_key, zero_since, updated_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE zero_since = VALUES(zero_since), updated_at = NOW()'
            )->execute([$key, date('Y-m-d H:i:s')]);
        } catch (\Throwable) {
            // Table pas encore migrée : pas de suivi, pas de pause auto.
        }
    }

    private static function forgetZero(string $key): void
    {
        try {
            ProductDiscontinued::connection()->prepare(
                'DELETE FROM product_zero_since WHERE product_key = ?'
            )->execute([$key]);
        } catch (\Throwable) {
            // Table pas encore migrée : rien à oublier.
        }
    }
}
