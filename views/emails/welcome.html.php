<?php

declare(strict_types=1);

/**
 * Template e-mail : bienvenue.
 *
 * @var string $siteName
 * @var string $siteUrl
 * @var string $prenom
 */
$siteName = $siteName ?? 'AEIC';
$siteUrl  = $siteUrl ?? '';
$prenom   = $prenom ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#08172d;font-family:Inter,system-ui,sans-serif;color:#e8eef5;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#08172d;min-height:100vh;">
<tr><td align="center" style="padding:2rem 1rem;">
<table width="560" cellpadding="0" cellspacing="0" style="background:#0c1d36;border-radius:1rem;border:1px solid rgba(255,255,255,0.08);">
<tr><td style="padding:2rem 2rem 1rem;">
<h1 style="margin:0 0 1rem;font-size:1.4rem;color:#48bdd3;">Bienvenue <?= e($prenom) ?> 👋</h1>
<p style="font-size:1rem;line-height:1.6;color:#e8eef5;">
Votre compte a bien été créé. Bienvenue dans l'<strong><?= e($siteName) ?></strong> — l'association étudiante en informatique de Calais.
</p>
<p style="font-size:1rem;line-height:1.6;color:#9fb3c8;">
Vous pouvez désormais vous inscrire aux événements et commander à la cafétéria.
</p>
</td></tr>
<tr><td style="padding:0 2rem 2rem;">
<a href="<?= e($siteUrl . '/eleve') ?>" style="display:inline-block;background:#48bdd3;color:#08172d;font-weight:700;padding:0.8rem 1.5rem;border-radius:0.5rem;text-decoration:none;">Accéder à mon espace</a>
</td></tr>
<tr><td style="padding:1rem 2rem 2rem;border-top:1px solid rgba(255,255,255,0.08);">
<p style="font-size:0.8rem;color:#6b7280;margin:0;">&copy; <?= date('Y') ?> <?= e($siteName) ?> — 100 % étudiant.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
