<?php

declare(strict_types=1);

/**
 * Affichage des messages flash.
 *
 * @var list<array{type:string,message:string}> $flash
 */

use App\Core\Controller;

$flash = Controller::getFlash();

foreach ($flash as $message):
    $type = $message['type'] ?? 'info';
?>
    <div class="flash flash-<?= e($type) ?>" role="status">
        <?= e($message['message']) ?>
    </div>
<?php endforeach; ?>
