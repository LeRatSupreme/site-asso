<?php

declare(strict_types=1);

/**
 * Vérification 2FA (étape de connexion).
 */
use App\Models\Setting;
$siteName = Setting::get('site_name', 'AEIC');
?>
<section class="container narrow auth-card">
    <span class="eyebrow">Sécurité</span>
    <h1 class="hero-title">Vérification en deux étapes</h1>
    <p class="hero-lead">Saisissez le code à 6 chiffres généré par votre application d'authentification.</p>

    <form class="card surface glass form-card" method="post" action="<?= e(url('/login/verify')) ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label for="code">Code d'authentification</label>
            <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*"
                   autocomplete="one-time-code" maxlength="7" required autofocus
                   placeholder="123 456">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Vérifier</button>
        <p class="form-foot">
            <a href="<?= e(url('/login')) ?>">← Revenir à la connexion</a>
        </p>
    </form>
    <p class="card-meta">Code de récupération perdu ? Utilisez un des codes à 8 caractères enregistrés lors de la configuration.</p>
</section>
