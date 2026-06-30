Bonjour <?= e($prenom ?? '') ?>,

Un administrateur de <?= e($siteName ?? 'AEIC') ?> a réinitialisé votre mot de passe.
Voici votre mot de passe temporaire :

<?= e($password ?? '') ?>

Connectez-vous avec ce mot de passe, puis changez-le dès que possible dans
Mon compte → Changer mon mot de passe.

Lien de connexion : <?= e($siteUrl ?? '') ?>/login

Si vous n'avez pas demandé cette réinitialisation, contactez l'AEIC.

© <?= date('Y') ?> <?= e($siteName ?? 'AEIC') ?> — 100 % étudiant.
