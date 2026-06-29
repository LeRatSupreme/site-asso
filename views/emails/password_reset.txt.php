Bonjour <?= $prenom ?? '' ?>,

Tu as demandé à réinitialiser ton mot de passe sur le site de l'<?= $siteName ?? 'AEIC' ?>.

Clique sur le lien ci-dessous pour en choisir un nouveau (valide <?= $expiresIn ?? 1 ?> h) :

<?= $resetUrl ?? '' ?>

Si tu n'es pas à l'origine de cette demande, ignore cet e-mail : ton mot de passe restera inchangé.

— L'équipe <?= $siteName ?? 'AEIC' ?>
