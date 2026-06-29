<?php

declare(strict_types=1);

/**
 * Configuration du 2FA.
 *
 * @var bool       $enabled
 * @var string     $secret
 * @var list<string> $recovery
 * @var bool       $required
 */
use App\Core\Auth;
use App\Core\Security\Totp;

$user = Auth::user();
$otpauth = $secret !== '' ? Totp::uri($secret, (string) ($user['email'] ?? '')) : '';
?>
<section class="container narrow">
    <span class="eyebrow">Sécurité du compte</span>
    <h1 class="hero-title">Authentification à deux facteurs</h1>

    <?php if ($required && !$enabled): ?>
        <div class="flash flash-warning" role="status">
            Le 2FA est <strong>obligatoire</strong> pour votre rôle. Configurez-le maintenant pour accéder à votre espace.
        </div>
    <?php endif; ?>

    <?php if ($enabled): ?>
        <div class="card surface glass">
            <p class="flash-success-text">✓ Le 2FA est <strong>activé</strong> sur votre compte.</p>
            <?php if (!$required): ?>
                <form method="post" action="<?= e(url('/account/2fa/disable')) ?>" onsubmit="return confirm('Désactiver le 2FA ?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline">Désactiver le 2FA</button>
                </form>
            <?php else: ?>
                <p class="card-meta">Le 2FA est obligatoire pour votre rôle : il ne peut pas être désactivé.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card surface glass">
            <h2 class="card-title">1. Ajoutez votre compte</h2>
            <p class="card-meta">Dans votre application d'authentification (Google Authenticator, Authy, Aegis…), ajoutez un nouveau compte avec la clé ci-dessous (saisie manuelle, type « clé secrète base32 ») :</p>
            <p><code class="totp-secret"><?= e(chunk_split($secret, 4, ' ')) ?></code></p>

            <p class="card-meta">Ou scannez / ouvrez ce lien (otpauth) :</p>
            <p><code class="totp-uri"><?= e($otpauth) ?></code></p>

            <h2 class="card-title">2. Confirmez avec un code</h2>
            <form method="post" action="<?= e(url('/account/2fa/confirm')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="code">Code à 6 chiffres affiché par l'application</label>
                    <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required>
                </div>
                <button type="submit" class="btn btn-primary">Activer le 2FA</button>
            </form>

            <h2 class="card-title">3. Codes de récupération</h2>
            <p class="card-meta">Conservez-les en lieu sûr (une seule utilisation chacun) :</p>
            <ul class="recovery-codes">
                <?php foreach ($recovery as $code): ?>
                    <li><code><?= e($code) ?></code></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</section>
