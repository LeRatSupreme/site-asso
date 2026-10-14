<?php

declare(strict_types=1);

/**
 * Libellés français des statuts de commande cafétéria.
 * La valeur brute stockée en base reste inchangée : seule
 * l'affichage est traduit.
 *
 * @var array<string,string> $orderStatusLabels
 */

$orderStatusLabels = [
    'PENDING'   => 'En attente',
    'CONFIRMED' => 'Confirmée',
    'PREPARING' => 'En préparation',
    'READY'     => 'Prête',
    'DELIVERED' => 'Livrée',
    'CANCELLED' => 'Annulée',
];
