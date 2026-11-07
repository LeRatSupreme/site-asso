Bonjour <?= e($prenom ?? '') ?>,

Bienvenue sur le site de l'<?= e($siteName ?? 'AEIC') ?> ! Pour activer votre compte, confirmez votre adresse e-mail en cliquant sur le lien ci-dessous (valide <?= e((string) ($expiresIn ?? 24)) ?> h) :

<?= e($verifyUrl ?? '') ?>

Tant que vous n'aurez pas confirmé votre adresse, vous ne pourrez pas vous connecter.

Si vous n'êtes pas à l'origine de cette inscription, ignorez cet e-mail.

&copy; <?= date('Y') ?> <?= e($siteName ?? 'AEIC') ?> — 100 % étudiant.
