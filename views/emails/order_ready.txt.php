Bonjour <?= e($prenom ?? '') ?>,

Votre commande <?= e($orderId ?? '') ?> (<?= e($total ?? '') ?>) est prête à être récupérée à la cafétéria.

Merci de la retirer dès que possible.

&copy; <?= date('Y') ?> <?= e($siteName ?? 'AEIC') ?> — 100 % étudiant.
