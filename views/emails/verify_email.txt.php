Bonjour <?= $prenom ?? '' ?>,

Bienvenue sur le site de l'<?= $siteName ?? 'AEIC' ?> ! Pour activer ton compte, confirme ton adresse e-mail en cliquant sur le lien ci-dessous (valide <?= $expiresIn ?? 24 ?> h) :

<?= $verifyUrl ?? '' ?>

Tant que tu n'auras pas confirmé ton adresse, tu ne pourras pas te connecter.

Si tu n'es pas à l'origine de cette inscription, ignore cet e-mail.

— L'équipe <?= $siteName ?? 'AEIC' ?>
