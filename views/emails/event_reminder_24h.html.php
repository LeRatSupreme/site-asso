<?php
/** @var string $prenom */
/** @var string $eventTitle */
/** @var string $eventDate */
/** @var string $eventUrl */
/** @var string $location */
/** @var string $siteName */
/** @var string $siteUrl */
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#08172d;font-family:Inter,system-ui,sans-serif;color:#e8eef5;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#08172d;min-height:100vh;">
<tr><td align="center" style="padding:2rem 1rem;">
<table width="560" cellpadding="0" cellspacing="0" style="background:#0c1d36;border-radius:1rem;border:1px solid rgba(255,255,255,0.08);">
<tr><td style="padding:2rem;">
<h1 style="margin:0 0 0.5rem;font-size:1.3rem;color:#48bdd3;">📅 Plus que 24h !</h1>
<p style="font-size:1rem;line-height:1.6;">Bonjour <strong><?= e($prenom) ?></strong>,</p>
<p style="font-size:1rem;line-height:1.6;">
C'est <strong>demain</strong> ! Vous êtes inscrit·e à l'événement :
</p>
<div style="background:rgba(72,189,211,0.10);border:1px solid rgba(72,189,211,0.25);border-radius:0.6rem;padding:1rem;margin:1rem 0;">
<p style="margin:0 0 0.5rem;font-size:1.1rem;font-weight:700;color:#48bdd3;"><?= e($eventTitle) ?></p>
<p style="margin:0;font-size:0.95rem;color:#9fb3c8;">
📅 <?= e(date('d/m/Y à H:i', strtotime($eventDate))) ?><br>
<?php if (!empty($location)): ?>📍 <?= e($location) ?><?php endif; ?>
</p>
</div>
<a href="<?= e($eventUrl) ?>" style="display:inline-block;background:#48bdd3;color:#08172d;font-weight:700;padding:0.7rem 1.4rem;border-radius:0.5rem;text-decoration:none;">Voir l'événement</a>
</td></tr>
<tr><td style="padding:1rem 2rem 2rem;border-top:1px solid rgba(255,255,255,0.08);">
<p style="font-size:0.8rem;color:#6b7280;margin:0;">&copy; <?= date('Y') ?> <?= e($siteName) ?></p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
