Bonjour <?= e($prenom ?? '') ?>,

C'est demain ! Vous êtes inscrit·e à : <?= e($eventTitle ?? '') ?>
📅 <?= e(isset($eventDate) ? formatDate($eventDate, 'd/m/Y \à H:i') : '') ?>
<?php if (!empty($location)): ?>📍 <?= e($location) ?><?php endif; ?>

Lien : <?= e($eventUrl ?? '') ?>

© <?= date('Y') ?> <?= e($siteName ?? 'AEIC') ?>
