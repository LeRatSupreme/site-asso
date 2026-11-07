<?php

declare(strict_types=1);

/**
 * Template e-mail : réinitialisation de mot de passe.
 *
 * @var string $siteName
 * @var string $siteUrl
 * @var string $prenom
 * @var string $resetUrl
 * @var int    $expiresIn heures
 */
$siteName  = $siteName ?? 'AEIC';
$siteUrl   = $siteUrl ?? '';
$prenom    = $prenom ?? '';
$resetUrl  = $resetUrl ?? '';
$expiresIn = $expiresIn ?? 1;
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#08172d;font-family:Inter,system-ui,sans-serif;color:#e8eef5;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#08172d;min-height:100vh;">
<tr><td align="center" style="padding:2rem 1rem;">
<table width="560" cellpadding="0" cellspacing="0" style="background:#0c1d36;border-radius:1rem;border:1px solid rgba(255,255,255,0.08);">
<tr><td style="padding:2rem 2rem 1rem;">
<h1 style="margin:0 0 1rem;font-size:1.4rem;color:#48bdd3;">Réinitialiser votre mot de passe</h1>
<p style="font-size:1rem;line-height:1.6;color:#e8eef5;">Bonjour <strong><?= e($prenom) ?></strong>,</p>
<p style="font-size:1rem;line-height:1.6;color:#e8eef5;">
Vous avez demandé à réinitialiser votre mot de passe. Cliquez sur le bouton ci-dessous pour en choisir un nouveau. Ce lien expire dans <?= e((string) $expiresIn) ?> h.
</p>
</td></tr>
<tr><td style="padding:0 2rem 2rem;">
<a href="<?= e($resetUrl) ?>" style="display:inline-block;background:#48bdd3;color:#08172d;font-weight:700;padding:0.8rem 1.5rem;border-radius:0.5rem;text-decoration:none;">Réinitialiser mon mot de passe</a>
<p style="font-size:0.8rem;line-height:1.6;color:#9fb3c8;margin:1rem 0 0;">
Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail : votre mot de passe restera inchangé.
</p>
<p style="font-size:0.8rem;line-height:1.6;color:#9fb3c8;margin:0.75rem 0 0;">
Lien de secours : <?= e($resetUrl) ?>
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
