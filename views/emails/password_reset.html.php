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
<head><meta charset="utf-8"></head>
<body style="margin:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1a1d24;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;overflow:hidden;">
        <tr style="background:#161a22;">
          <td style="padding:24px 32px;">
            <span style="display:inline-block;width:40px;height:40px;line-height:40px;text-align:center;border-radius:10px;background:#c8102e;color:#fff;font-weight:700;">AE</span>
            <span style="margin-left:12px;color:#fff;font-size:18px;font-weight:700;"><?= e($siteName) ?></span>
          </td>
        </tr>
        <tr><td style="padding:32px;">
          <h1 style="margin:0 0 12px;font-size:24px;">Réinitialiser ton mot de passe</h1>
          <p style="margin:0 0 16px;line-height:1.6;">Bonjour <?= e($prenom) ?>, tu as demandé à réinitialiser ton mot de passe.</p>
          <p style="margin:0 0 24px;line-height:1.6;">Clique sur le bouton ci-dessous pour en choisir un nouveau. Ce lien expire dans <?= e((string) $expiresIn) ?> h.</p>
          <p style="margin:0 0 16px;">
            <a href="<?= e($resetUrl) ?>" style="display:inline-block;background:#c8102e;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:700;">Réinitialiser mon mot de passe</a>
          </p>
          <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.5;">Si tu n'es pas à l'origine de cette demande, ignore cet e-mail : ton mot de passe restera inchangé.</p>
          <p style="margin:16px 0 0;font-size:13px;color:#6b7280;line-height:1.5;">Lien de secours : <?= e($resetUrl) ?></p>
        </td></tr>
        <tr><td style="padding:16px 32px;background:#f0f2f6;color:#6b7280;font-size:13px;text-align:center;">
          © <?= e(date('Y')) ?> <?= e($siteName) ?>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
