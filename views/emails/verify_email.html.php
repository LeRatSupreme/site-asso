<?php

declare(strict_types=1);

/**
 * Template e-mail : confirmation d'adresse e-mail à l'inscription.
 *
 * @var string $siteName
 * @var string $siteUrl
 * @var string $prenom
 * @var string $verifyUrl
 * @var int    $expiresIn
 */
$siteName  = $siteName ?? 'AEIC';
$siteUrl   = $siteUrl ?? '';
$prenom    = $prenom ?? '';
$verifyUrl = $verifyUrl ?? '';
$expiresIn = $expiresIn ?? 24;
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
          <h1 style="margin:0 0 12px;font-size:24px;">Confirme ton adresse e-mail <?= e($prenom) ?></h1>
          <p style="margin:0 0 16px;line-height:1.6;">Bienvenue dans l'<?= e($siteName) ?> ! Pour activer ton compte et pouvoir te connecter, confirme ton adresse e-mail en cliquant sur le bouton ci-dessous.</p>
          <p style="margin:0;">
            <a href="<?= e($verifyUrl) ?>" style="display:inline-block;background:#c8102e;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:700;">Confirmer mon adresse e-mail</a>
          </p>
          <p style="margin:16px 0 0;line-height:1.6;color:#6b7280;font-size:13px;">Ce lien est valide <?= e((string) $expiresIn) ?> h. Si le bouton ne fonctionne pas, copie-colle cette adresse dans ton navigateur :<br><?= e($verifyUrl) ?></p>
          <p style="margin:16px 0 0;line-height:1.6;color:#6b7280;font-size:13px;">Si tu n'es pas à l'origine de cette inscription, tu peux ignorer cet e-mail.</p>
        </td></tr>
        <tr><td style="padding:16px 32px;background:#f0f2f6;color:#6b7280;font-size:13px;text-align:center;">
          © <?= e(date('Y')) ?> <?= e($siteName) ?> · Cet e-mail est automatique, merci de ne pas répondre.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
