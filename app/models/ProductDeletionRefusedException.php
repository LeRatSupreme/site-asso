<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Refus de suppression d'une fiche produit qui porte un historique
 * (RÈGLE « L'INVENTAIRE EST SACRÉ » : comptages inventory_counts ou
 * ventes sales rattachés à la clé de la fiche).
 *
 * Une fiche/clé avec historique n'est JAMAIS supprimée durablement :
 * elle se désactive (is_active = 0) ou se FUSIONNE dans une clé cible
 * via ProductKeyMerge (déplacement complet et audité de toutes les
 * données AVANT suppression — chemin Product::deleteRowAfterMerge,
 * seule exception documentée à la garde).
 */
final class ProductDeletionRefusedException extends \RuntimeException
{
}
