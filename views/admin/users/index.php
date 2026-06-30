<?php

declare(strict_types=1);

use App\Core\Auth;

/**
 * @var list<array<string,mixed>> $users
 * @var string $currentId
 * @var array<string,true> $memberIds
 * @var string $currentSeason
 */
$roleLabels = [
    Auth::ROLE_ADMIN      => 'Administrateur',
    Auth::ROLE_TRESORERIE => 'Trésorerie',
    Auth::ROLE_ELEVE      => 'Élève',
];
?>
<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Membre</th><th>Statut</th><th>Inscription</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <?php $isSelf = ($u['id'] ?? '') === $currentId; ?>
                <?php $isMember = isset($memberIds[(string) ($u['id'] ?? '')]); ?>
                <tr<?= $isSelf ? ' class="row-self"' : '' ?>>
                    <td>
                        <strong><?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?></strong>
                        <?= $isSelf ? '<span class="badge badge-info">vous</span>' : '' ?>
                    </td>
                    <td><?= e($u['email'] ?? '') ?></td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/role')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <select name="role" onchange="this.form.submit()" <?= $isSelf ? 'disabled title="Vous ne pouvez pas modifier votre propre rôle"' : '' ?>>
                                <?php foreach ($roleLabels as $val => $label): ?>
                                    <option value="<?= e($val) ?>" <?= ($u['role'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td>
                        <?php if ($isMember): ?>
                            <span class="badge badge-success" title="Cotisation <?= e($currentSeason) ?>">Membre ✅</span>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/membership')) ?>" class="inline-form"
                                  data-confirm="Marquer l'adhésion <?= e($currentSeason) ?> de <?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?> comme payée ?">
                                <?= csrf_field() ?>
                                <input type="number" step="0.01" min="0" name="amount" value="" placeholder="€"
                                       class="input-sm" style="width:5rem;" aria-label="Montant cotisation">
                                <button type="submit" class="btn btn-outline btn-sm">Marquer payée</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($u['is_active'])): ?>
                            <span class="badge badge-success">Actif</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Désactivé</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(formatDate((string) ($u['created_at'] ?? ''))) ?></td>
                    <td class="row-actions">
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/toggle-active')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline btn-sm" <?= $isSelf ? 'disabled title="Vous ne pouvez pas vous désactiver"' : '' ?>>
                                <?= !empty($u['is_active']) ? 'Désactiver' : 'Activer' ?>
                            </button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/reset-password')) ?>" class="inline-form"
                              data-confirm="Réinitialiser le mot de passe de <?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?> ? Un mot de passe temporaire sera généré et affiché ici.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline btn-sm">Reset MDP</button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/users/' . rawurlencode((string) $u['id']) . '/delete')) ?>" class="inline-form"
                              data-confirm="Supprimer définitivement le compte de <?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?> ? Les commandes (comptabilité) sont conservées mais anonymisées. Action irréversible.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm" <?= $isSelf ? 'disabled title="Vous ne pouvez pas supprimer votre propre compte ici"' : '' ?>>
                                Supprimer
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="card-meta">Le dernier administrateur actif ne peut être ni rétrogradé, ni désactivé, ni supprimé. Vous ne pouvez pas supprimer votre propre compte depuis ici. Chaque action est journalisée (audit log). La suppression conserve les commandes (anonymisées) pour la comptabilité.</p>
