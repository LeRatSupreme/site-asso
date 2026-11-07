Bienvenue <?= e($prenom ?? '') ?> !

Votre compte a bien été créé sur le site de l'<?= e($siteName ?? 'AEIC') ?>.

Vous pouvez désormais vous inscrire aux événements et commander à la cafétéria depuis votre espace membre :

<?= e($siteUrl ?? '') ?>/eleve

&copy; <?= date('Y') ?> <?= e($siteName ?? 'AEIC') ?> — 100 % étudiant.
