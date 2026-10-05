<?php

declare(strict_types=1);

/**
 * Layout « kiosque » : coquille minimale pour les accès par lien secret
 * (ex. Réapprovisionnement sur le téléphone).
 *
 * Contrairement au layout admin, il n'affiche AUCUNE navigation : pas de
 * sidebar, pas de menu, pas de déconnexion, pas de lien vers le site.
 * Seul le contenu de la vue est rendu — rien d'autre n'est visible ni
 * accessible depuis un lien kiosque.
 *
 * @var string $content
 * @var string $title
 */

use App\Models\Setting;

$siteName = Setting::get('site_name', 'AEIC');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Consultation') ?> — <?= e($siteName) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(assetVersioned('css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/admin.css')) ?>">
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/payments.css')) ?>">
    <!-- Toujours chargée : les pages kiosque sont des pages compta. -->
    <link rel="stylesheet" href="<?= e(rootAssetVersioned('/css/compta.css')) ?>">
</head>
<body class="admin-body">
    <main class="admin-main" style="max-width: 1100px; margin: 0 auto; padding: 1.1rem 1rem 2.5rem;">
        <?php require AEIC_VIEWS . '/partials/flash_messages.php'; ?>
        <div class="admin-content">
            <?= $content ?>
        </div>
        <p class="card-meta" style="text-align: center; margin-top: 1.75rem;">
            <?= e($siteName) ?> — accès limité à cette page
        </p>
    </main>
</body>
</html>
