<?php

declare(strict_types=1);

/**
 * Journal d'audit (/admin/audit) — remplace la carte « Journal d'audit »
 * de l'ancien tableau de bord : mêmes garde et données (Fondateur/ADMIN),
 * mais filtrable, paginée et exportable. Aucun calcul : les lignes
 * viennent de AuditLog::searchFilters(), la date est convertie en heure
 * de Paris par les helpers.
 *
 * @var array<string,mixed> $user
 * @var list<array<string,mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array{user_id:string, action:string, from:string, to:string} $filters
 * @var list<array<string,mixed>> $users
 * @var list<string> $actions
 */

// Requête de pagination conservant les filtres actifs.
$baseQuery = array_filter([
    'user_id' => $filters['user_id'],
    'action'  => $filters['action'],
    'from'    => $filters['from'],
    'to'      => $filters['to'],
], static fn($v): bool => $v !== null && $v !== '');

$prevQuery = http_build_query(array_merge($baseQuery, ['page' => max(1, $page - 1)]));
$nextQuery = http_build_query(array_merge($baseQuery, ['page' => min($pages, $page + 1)]));
$exportQuery = http_build_query(array_merge($baseQuery, ['export' => 'csv']));
?>
<form method="get" class="costs-toolbar" aria-label="Filtres du journal d'audit">
    <select name="user_id" aria-label="Utilisateur">
        <option value="">Tous les utilisateurs</option>
        <?php foreach ($users as $u): ?>
            <option value="<?= e((string) $u['id']) ?>" <?= $filters['user_id'] === (string) $u['id'] ? 'selected' : '' ?>>
                <?= e(trim((string) ($u['prenom'] ?? '') . ' ' . (string) ($u['nom'] ?? '')) . ' — ' . (string) ($u['email'] ?? '')) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="action" aria-label="Action">
        <option value="">Toutes les actions</option>
        <?php foreach ($actions as $a): ?>
            <option value="<?= e($a) ?>" <?= $filters['action'] === $a ? 'selected' : '' ?>><?= e($a) ?></option>
        <?php endforeach; ?>
    </select>

    <input type="date" name="from" value="<?= e($filters['from']) ?>" aria-label="Depuis le" title="Actions réalisées à partir de cette date">
    <input type="date" name="to" value="<?= e($filters['to']) ?>" aria-label="Jusqu'au" title="Actions réalisées jusqu'à cette date">

    <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/audit?' . $exportQuery)) ?>">Exporter CSV</a>
</form>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Qui</th>
                <th>Action</th>
                <th>Détails</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <?php
                    // « kiosque » : enregistrement sans compte (poste à jeton).
                    $qui = ($r['user_id'] ?? null) === null || (string) ($r['user_id'] ?? '') === ''
                        ? 'kiosque'
                        : (trim((string) ($r['prenom'] ?? '') . ' ' . (string) ($r['nom'] ?? '')) !== ''
                            ? trim((string) $r['prenom'] . ' ' . (string) $r['nom'])
                            : 'Compte supprimé');
                    $details = trim((string) ($r['details'] ?? ''));
                    $decoded = $details !== '' ? json_decode($details, true) : null;
                ?>
                <tr>
                    <td><?= e(formatDateTime((string) $r['created_at'])) ?></td>
                    <td><?= e($qui) ?></td>
                    <td><code><?= e((string) $r['action']) ?></code></td>
                    <td class="muted">
                        <?php if (is_array($decoded)): ?>
                            <?php foreach ($decoded as $k => $v): ?>
                                <?php if (is_scalar($v)): ?>
                                    <span title="<?= e((string) $k) ?>"><?= e((string) $v) ?></span><?= ' · ' ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php elseif ($details !== ''): ?>
                            <?= e(mb_strimwidth($details, 0, 120, '…')) ?>
                        <?php endif; ?>
                        <?php if (!empty($r['entity_type'])): ?>
                            <span title="Élément concerné"><?= e((string) $r['entity_type']) ?><?= !empty($r['entity_id']) ? ' #' . e((string) $r['entity_id']) : '' ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="4" class="muted">Aucune action enregistrée pour ces filtres.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<p class="muted"><?= e((string) $total) ?> entrée<?= $total > 1 ? 's' : '' ?> — page <?= e((string) $page) ?> sur <?= e((string) $pages) ?></p>
<div class="admin-actions">
    <?php if ($page > 1): ?>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/audit?' . $prevQuery)) ?>">← Page précédente</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/audit?' . $nextQuery)) ?>">Page suivante →</a>
    <?php endif; ?>
</div>
