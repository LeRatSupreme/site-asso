<?php

/**
 * AEIC — Synchronisation automatique de la carte cafétéria.
 *
 * Crée les fiches produits manquantes de la carte publique à partir des
 * libellés détectés dans la compta : ventes SumUp importées, achats,
 * pertes, comptages d'inventaire et stocks saisis en Réappro.
 * Catégorie, prix et stock sont déduits (voir ProductAutoSync).
 *
 * Usage (depuis la racine du projet) :
 *   php scripts/sync_menu_products.php
 *
 * Utilisable en cron (sort 0 en cas de succès, 1 en cas d'échec fatal) :
 *   0 9 * * * cd /chemin/vers/site_final && php scripts/sync_menu_products.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Charge la config DB + la fonction db() (PDO).
require_once __DIR__ . '/../app/config/database.php';

use App\Core\Compta\ProductAutoSync;

try {
    $sync = ProductAutoSync::sync();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Erreur fatale : ' . $e->getMessage() . "\n");
    exit(1);
}

if ($sync['created'] === []) {
    echo "Carte déjà à jour — aucun nouveau produit.\n";
} else {
    echo count($sync['created']) . " nouveau(x) produit(s) créé(s) :\n";
    foreach ($sync['created'] as $name) {
        echo '  - ' . $name . "\n";
    }
}

echo $sync['skipped'] . " libellé(s) ignoré(s) (déjà couverts, exclus ou en erreur).\n";

exit(0);
