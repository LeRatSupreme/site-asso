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
          <h1 style="margin:0 0 12px;font-size:24px;">Votre compte a été supprimé</h1>
          <p style="margin:0 0 16px;line-height:1.6;">Bonjour <?= e($prenom) ?>, votre demande de suppression de compte a bien été prise en compte.</p>
          <p style="margin:0 0 16px;line-height:1.6;">Conformément au RGPD, vos données personnelles (nom, adresse e-mail, mot de passe, photo de profil) ont été <strong>anonymisées et effacées</strong>. Vous ne pouvez plus vous connecter.</p>
          <p style="margin:0 0 16px;line-height:1.6;">Les traces à obligation comptable (commandes à la cafétéria) sont conservées à des fins légales mais sont désormais déliées de votre identité.</p>
          <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.5;">Si vous n'êtes pas à l'origine de cette demande, contactez l'<?= e($siteName) ?> dès que possible.</p>
        </td></tr>
        <tr><td style="padding:16px 32px;background:#f0f2f6;color:#6b7280;font-size:13px;text-align:center;">
          © <?= e(date('Y')) ?> <?= e($siteName) ?> · Cet e-mail est automatique, merci de ne pas répondre.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
