Bonjour <?= e($prenom ?? '') ?>,

Votre événement commence dans 1 heure : <?= e($eventTitle ?? '') ?>
📅 <?= e(isset($eventDate) ? date('d/m/Y à H:i', strtotime($eventDate)) : '') ?>
<?php if (!empty($location)): ?>📍 <?= e($location) ?><?php endif; ?>

Lien : <?= e($eventUrl ?? '') ?>

© <?= date('Y') ?> <?= e($siteName ?? 'AEIC') ?>
