<?php

declare(strict_types=1);

/**
 * Template e-mail : confirmation de suppression de compte (RGPD).
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
<tr><td style="padding:2rem;">
<h1 style="margin:0 0 1rem;font-size:1.4rem;color:#48bdd3;">Votre compte a été supprimé</h1>
<p style="font-size:1rem;line-height:1.6;color:#e8eef5;">Bonjour <strong><?= e($prenom) ?></strong>,</p>
<p style="font-size:1rem;line-height:1.6;color:#e8eef5;">
Votre demande de suppression de compte a bien été prise en compte.
</p>
<p style="font-size:1rem;line-height:1.6;color:#e8eef5;">
Conformément au RGPD, vos données personnelles (nom, adresse e-mail, mot de passe, photo de profil) ont été <strong>anonymisées et effacées</strong>. Vous ne pouvez plus vous connecter.
</p>
<p style="font-size:1rem;line-height:1.6;color:#e8eef5;">
Les traces à obligation comptable (commandes à la cafétéria) sont conservées à des fins légales mais sont désormais déliées de votre identité.
</p>
<p style="font-size:0.8rem;line-height:1.6;color:#9fb3c8;margin:0.75rem 0 0;">
Si vous n'êtes pas à l'origine de cette demande, contactez l'<?= e($siteName) ?> dès que possible.
</p>
</td></tr>
<tr><td style="padding:1rem 2rem 2rem;border-top:1px solid rgba(255,255,255,0.08);">
<p style="font-size:0.8rem;color:#6b7280;margin:0;">&copy; <?= date('Y') ?> <?= e($siteName) ?> — 100 % étudiant.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
