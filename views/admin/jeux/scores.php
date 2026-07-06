<?php

declare(strict_types=1);

/**
 * Joueurs & Pseudos (admin) — modifier les pseudos, voir les stats, réinitialiser.
 *
 * @var string $title
 * @var list<array<string,mixed>> $players
 */
?>
<div class="admin-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/jeux')) ?>">← Retour</a>
</div>

<?php if ($players === []): ?>
    <div class="card surface glass" style="text-align:center;padding:2rem;color:var(--muted);">
        Aucun joueur n'a encore joué au Wordle quotidien.
    </div>
<?php else: ?>
    <div class="card surface glass table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Joueur</th>
                    <th>Pseudo</th>
                    <th class="num">Série 🔥</th>
                    <th class="num">Record 🏆</th>
                    <th class="num">Victoires</th>
                    <th class="num">Parties</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($players as $p):
                    $pseudo = ($p['pseudo'] ?? null) !== null && $p['pseudo'] !== '' ? (string) $p['pseudo'] : ''; ?>
                    <tr data-name="<?= e(strtolower($p['prenom'] . ' ' . $p['nom'] . ' ' . $p['email'] . ' ' . $pseudo)) ?>">
                        <td>
                            <strong><?= e($p['prenom'] . ' ' . $p['nom']) ?></strong><br>
                            <span style="color:var(--muted);font-size:0.8rem;"><?= e($p['email']) ?></span>
                        </td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/jeux/set-pseudo')) ?>" class="inline-form" style="display:flex;gap:0.3rem;align-items:center;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= e((string) $p['id']) ?>">
                                <input type="text" name="pseudo" value="<?= e($pseudo) ?>" maxlength="20"
                                       placeholder="—" style="width:130px;padding:0.3rem 0.5rem;border-radius:0.3rem;border:1px solid var(--border-strong);background:rgba(255,255,255,0.04);color:var(--foreground);font-size:0.85rem;" />
                                <button type="submit" class="btn btn-outline btn-sm" title="Enregistrer le pseudo">💾</button>
                            </form>
                        </td>
                        <td class="num"><strong style="color:var(--primary);"><?= (int) $p['currentStreak'] ?></strong></td>
                        <td class="num"><?= (int) $p['maxStreak'] ?></td>
                        <td class="num"><?= (int) $p['won'] ?></td>
                        <td class="num"><?= (int) $p['played'] ?></td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/jeux/reset-player')) ?>" class="inline-form"
                                  data-confirm="Réinitialiser tous les scores de <?= e($p['prenom'] . ' ' . $p['nom']) ?> ?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= e((string) $p['id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Réinitialiser les scores">🗑️ Scores</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p style="color:var(--muted);font-size:0.85rem;margin-top:1rem;">
        💡 Astuce : pour <strong>effacer</strong> un pseudo, vide le champ puis clique sur 💾.
        Le pseudo doit faire 3 à 20 caractères (lettres, chiffres, espaces, <code>- _ .</code>) et être unique.
    </p>
<?php endif; ?>
