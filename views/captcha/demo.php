<?php

declare(strict_types=1);

/**
 * Page de démonstration du CAPTCHA auto-hébergé.
 *
 * @var bool   $success
 * @var string $error
 * @var string $errorId
 */
?>
<header class="page-hero">
    <div class="container">
        <span class="eyebrow">Anti-robot</span>
        <h1 class="page-title">Démo CAPTCHA</h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <style>
            /* Leçon iOS : une police de saisie < 16px déclenche le zoom au focus. */
            #captcha-input { font-size: 16px; }
            #captcha-demo .success { color: var(--accent-success, #22c55e); margin: 0 0 12px; }
            #captcha-demo .error { color: var(--accent-danger, #ef4444); margin: 0 0 12px; }
        </style>

        <form id="captcha-demo" class="auth-form surface glass" method="post" action="<?= e(url('/captcha-demo')) ?>">
            <?= csrf_field() ?>

            <?php if (!empty($success)): ?>
                <p class="success" role="status" data-marker="captcha-success">
                    Code vérifié : vous n'êtes pas un robot. Merci&nbsp;!
                </p>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <p id="<?= e($errorId !== '' ? $errorId : 'captcha-error') ?>" class="error" role="alert" data-marker="captcha-error">
                    <?= e($error) ?>
                </p>
            <?php endif; ?>

            <div class="field">
                <img id="captcha-img" src="<?= e(url('/captcha/image')) ?>"
                     alt="Code de vérification à recopier" width="220" height="74">
                <p class="field-meta">
                    <button type="button" class="btn btn-outline btn-sm"
                            onclick="document.getElementById('captcha-img').src='<?= e(url('/captcha/image')) ?>?ts='+Date.now()">
                        Nouvelle image
                    </button>
                </p>
            </div>

            <div class="field">
                <label for="captcha-input">Recopiez le code affiché ci-dessus</label>
                <input type="text" id="captcha-input" name="captcha" maxlength="8"
                       inputmode="latin" autocapitalize="characters" spellcheck="false"
                       autocomplete="off" required
                       <?php if ($error !== ''): ?>aria-describedby="captcha-error"<?php endif; ?>>
            </div>

            <!-- Honeypot : invisible pour un humain, rempli par les robots. -->
            <div aria-hidden="true" style="position:absolute; left:-9999px;">
                <label for="entreprise-site">Site web de votre entreprise</label>
                <input type="text" id="entreprise-site" name="entreprise_site"
                       tabindex="-1" autocomplete="off">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Vérifier</button>
        </form>
    </div>
</section>
