Bienvenue <?= e($prenom ?? '') ?> !

Votre compte a bien été créé sur le site de l'<?= e($siteName ?? 'AEIC') ?>.

Voici votre mot de passe temporaire pour vous connecter :

<?= e($password ?? '') ?>

Pour votre sécurité, nous vous conseillons de le changer dès votre première connexion depuis votre profil :

<?= e($siteUrl ?? '') ?>/login

&copy; <?= date('Y') ?> <?= e($siteName ?? 'AEIC') ?> — 100 % étudiant.
