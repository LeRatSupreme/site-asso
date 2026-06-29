<?php

declare(strict_types=1);

/**
 * Template e-mail : commande prête.
 *
 * @var string $siteName
 * @var string $siteUrl
 * @var string $prenom
 * @var string $orderId
 * @var string $total
 */
$siteName = $siteName ?? 'AEIC';
$siteUrl  = $siteUrl ?? '';
$prenom   = $prenom ?? '';
$orderId  = $orderId ?? '';
$total    = $total ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"></head>
<body style="margin:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1a1d24;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;overflow:hidden;">
        <tr style="background:#161a22;">
          <td style="padding:24px 32px;">
            <span style="display:inline-block;width:40px;height:40px;line-height:40px;text-align:center;border-radius:10px;background:#c8102e;color:#fff;font-weight:700;">AE</span>
            <span style="margin-left:12px;color:#fff;font-size:18px;font-weight:700;"><?= e($siteName) ?> — Cafétéria</span>
          </td>
        </tr>
        <tr><td style="padding:32px;">
          <h1 style="margin:0 0 12px;font-size:24px;">Ta commande est prête !</h1>
          <p style="margin:0 0 16px;line-height:1.6;">Bonjour <?= e($prenom) ?>, ta commande <strong><?= e($orderId) ?></strong> (<?= e($total) ?>) est prête à être récupérée à la cafétéria.</p>
          <p style="margin:0;line-height:1.6;">Merci de la retirer dès que possible.</p>
        </td></tr>
        <tr><td style="padding:16px 32px;background:#f0f2f6;color:#6b7280;font-size:13px;text-align:center;">
          © <?= e(date('Y')) ?> <?= e($siteName) ?>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
