<?php

declare(strict_types=1);

/**
 * AEIC — Synchronisation des catégories du mapping des libellés vers
 * les ventes (Sale::syncAliasCategories()).
 *
 * À relancer après un import SumUp (CSV manuel ou synchro API/cron) :
 *  1. correspondance exacte libellé brut -> catégorie d'alias (écrase) ;
 *  2. complément par clé canonique : remplit uniquement les ventes
 *     encore sans catégorie (NULL/''), sans jamais écraser un
 *     classement existant ni un reclassement par variantes de prix.
 * Re-exécutable sans effet de bord.
 *
 * Usage : php scripts/sync_alias_categories.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';

use App\Models\Sale;

echo "— Appellations : synchronisation des catégories vers les ventes —\n";

$count = Sale::syncAliasCategories();

echo 'Terminé : ' . $count . " vente(s) catégorisée(s)\n";
exit(0);
