<?php

declare(strict_types=1);

/**
 * Page de maintenance (503) — affichée quand maintenance_mode = on et visiteur non admin.
 *
 * @var string $siteName
 */
$siteName = $siteName ?? 'AEIC';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Maintenance — <?= e($siteName) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
</head>
<body>
    <main class="container" style="min-height:70vh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:1rem;padding:3rem 1rem;">
        <span class="brand-logo aeic-gradient" aria-hidden="true" style="width:64px;height:64px;font-size:1.6rem;">AE</span>
        <span class="eyebrow">Maintenance</span>
        <h1 class="hero-title" style="font-size:clamp(2rem,5vw,3rem);">On revient très vite.</h1>
        <p class="hero-lead">Le site de l'<?= e($siteName) ?> est en maintenance. Merci de revenir dans quelques instants.</p>
    </main>
</body>
</html>
