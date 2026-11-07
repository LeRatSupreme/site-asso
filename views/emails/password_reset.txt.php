Bonjour <?= e($prenom ?? '') ?>,

Vous avez demandé à réinitialiser votre mot de passe sur le site de l'<?= e($siteName ?? 'AEIC') ?>.

Cliquez sur le lien ci-dessous pour en choisir un nouveau (valide <?= e((string) ($expiresIn ?? 1)) ?> h) :

<?= e($resetUrl ?? '') ?>

Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail : votre mot de passe restera inchangé.

&copy; <?= date('Y') ?> <?= e($siteName ?? 'AEIC') ?> — 100 % étudiant.
