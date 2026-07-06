<?php

declare(strict_types=1);

/**
 * Page menu des jeux AEIC.
 *
 * @var array<string,mixed>|null $user
 * @var array{fr:array, en:array}|null $stats
 * @var list<array<string,mixed>> $leaderboard
 * @var string|null $currentId
 * @var string $setPseudoUrl
 * @var string $csrfToken
 */

use App\Core\Auth;

$userPseudo = ($user !== null && !empty($user['pseudo'])) ? (string) $user['pseudo'] : null;
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">🎮 Zone jeux</span>
        <h1 class="page-title">Zone jeux</h1>
        <p class="page-lead">Un mot par jour, une série à construire, un classement à gravir. Connecte-toi pour sauvegarder tes scores&nbsp;!</p>
    </div>
</header>

<section class="section">
    <div class="container">

        <?php if ($user === null): ?>
            <div class="surface card panel-brand" style="margin-bottom:1.5rem; align-items:flex-start;">
                <p style="margin:0;">🔒 Tu joues en <strong>mode démo</strong>. <a href="<?= e(url('/login?callbackUrl=' . rawurlencode('/jeux'))) ?>">Connecte-toi</a> pour sauvegarder tes parties, suivre ta série de victoires et apparaître dans le classement.</p>
            </div>
        <?php endif; ?>

        <?php if ($user !== null && $stats !== null): ?>
            <h2 class="section-title" style="font-size:1.3rem; margin-bottom:1rem;">Tes statistiques</h2>
            <div class="grid grid-4" style="margin-bottom:2rem;">
                <?php foreach (['fr' => '🇫🇷 FR', 'en' => '🇬🇧 EN'] as $code => $label):
                    $s = $stats[$code]; ?>
                    <div class="surface card card-hover">
                        <span class="badge badge-gradient"><?= $label ?></span>
                        <div class="stat-card">
                            <span class="stat-value"><?= (int) $s['played'] ?></span>
                            <span class="stat-label">Parties</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?= (int) $s['won'] ?></span>
                            <span class="stat-label">Victoires</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value">🔥 <?= (int) $s['currentStreak'] ?></span>
                            <span class="stat-label">Série en cours</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value">🏆 <?= (int) $s['maxStreak'] ?></span>
                            <span class="stat-label">Record</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($user !== null && $userPseudo === null): ?>
            <!-- Demande de pseudo -->
            <div class="surface card panel-brand" id="pseudo-prompt" style="margin-bottom:1.5rem; align-items:flex-start;">
                <div style="display:flex; gap:0.75rem; align-items:flex-start; width:100%;">
                    <span style="font-size:1.5rem;">🎮</span>
                    <div style="flex:1;">
                        <p style="margin:0 0 0.5rem; font-weight:700;">Choisis ton pseudo de joueur</p>
                        <p style="margin:0 0 0.75rem; color:var(--muted); font-size:0.9rem;">Ton pseudo apparaîtra dans le classement ci-dessous. 3 à 20 caractères (lettres, chiffres, espaces, - _ .).</p>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                            <input type="text" id="pseudo-input" maxlength="20" placeholder="ex : MotusMaster" autocomplete="off"
                                   style="flex:1; min-width:200px; padding:0.6rem 0.8rem; border-radius:0.5rem; border:2px solid var(--border-strong); background:rgba(255,255,255,0.04); color:var(--foreground); font-size:1rem;" />
                            <button type="button" class="btn btn-primary btn-sm" id="pseudo-save">Enregistrer</button>
                        </div>
                        <p id="pseudo-msg" style="margin:0.5rem 0 0; font-size:0.85rem; min-height:1.1rem;"></p>
                    </div>
                </div>
            </div>
        <?php elseif ($user !== null && $userPseudo !== null): ?>
            <div class="surface card" style="margin-bottom:1.5rem; flex-direction:row; align-items:center; gap:0.75rem;">
                <span style="font-size:1.3rem;">🎮</span>
                <span style="color:var(--muted);">Ton pseudo&nbsp;:</span>
                <strong style="color:var(--primary);"><?= e($userPseudo) ?></strong>
            </div>
        <?php endif; ?>

        <!-- ===================== CLASSEMENT (tout le monde) ===================== -->
        <h2 class="section-title" style="font-size:1.3rem; margin-bottom:1rem;">🏆 Classement</h2>
        <?php if ($leaderboard === []): ?>
            <div class="surface card" style="text-align:center; padding:2rem; color:var(--muted); margin-bottom:2rem;">
                <p style="margin:0 0 0.5rem;">🗂️ Aucune partie enregistrée pour le moment.</p>
                <p style="margin:0;"><a class="btn btn-primary btn-sm" href="<?= e(url('/jeux/wordle?mode=daily')) ?>">Jouer au Wordle quotidien →</a></p>
            </div>
        <?php else: ?>
            <div class="table-wrap surface" style="padding:0.5rem 0.5rem 0; margin-bottom:2rem; overflow-x:auto;">
                <table class="lb-table">
                    <thead>
                        <tr>
                            <th class="num" style="width:3rem;">#</th>
                            <th>Joueur</th>
                            <th class="num">Série 🔥</th>
                            <th class="num">Record 🏆</th>
                            <th class="num">Parties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaderboard as $i => $row):
                            $rank = $i + 1;
                            $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                            $isCurrent = $currentId !== null && (string) $row['id'] === $currentId;
                            $streak = (int) ($row['currentStreak'] ?? 0);
                        ?>
                            <tr class="lb-row <?= $isCurrent ? 'is-current' : '' ?>">
                                <td class="num"><span class="lb-rank <?= $rankClass ?>"><?= $rank ?></span></td>
                                <td>
                                    <div class="lb-user-cell">
                                        <span class="lb-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $row['displayName'], 0, 1) ?: '?')) ?></span>
                                        <span class="lb-user-name"><?= e($row['displayName']) ?></span>
                                        <?php if ($isCurrent): ?> <span class="badge badge-info" style="margin-left:0.3rem;">Toi</span><?php endif; ?>
                                    </div>
                                </td>
                                <td class="num">
                                    <strong style="color:var(--primary); font-size:1.05rem;"><?= $streak ?> jour<?= $streak > 1 ? 's' : '' ?></strong>
                                </td>
                                <td class="num"><?= (int) ($row['maxStreak'] ?? 0) ?></td>
                                <td class="num"><?= (int) ($row['played'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- ===================== JEUX DISPONIBLES (en bas) ===================== -->
        <h2 class="section-title" style="font-size:1.3rem; margin-bottom:1rem;">Jeux disponibles</h2>
        <div class="grid grid-3">
            <a class="surface card card-hover" href="<?= e(url('/jeux/wordle')) ?>" style="text-decoration:none; color:inherit;">
                <span class="badge badge-secondary">3 difficultés</span>
                <h3 class="card-title">🔤 Wordle</h3>
                <p class="card-excerpt">Devine le mot en 6 essais. 3 niveaux (5, 6 ou 7 lettres), mode quotidien commun ou libre illimité, en français ou en anglais&nbsp;!</p>
                <span class="btn btn-primary btn-sm" style="align-self:flex-start;">Jouer →</span>
            </a>

            <a class="surface card card-hover" href="<?= e(url('/jeux/enigme')) ?>" style="text-decoration:none; color:inherit;">
                <span class="badge badge-secondary">Quotidien</span>
                <h3 class="card-title">🧩 Énigme du jour</h3>
                <p class="card-excerpt">Une devinette par jour, identique pour tous les joueurs. Saurez-vous la résoudre&nbsp;? Change chaque jour à minuit&nbsp;!</p>
                <span class="btn btn-primary btn-sm" style="align-self:flex-start;">Réfléchir →</span>
            </a>

            <div class="surface card" style="opacity:0.6;">
                <span class="badge badge-muted">Bientôt</span>
                <h3 class="card-title">🧠 Memory</h3>
                <p class="card-excerpt">Le memory cafétéria reviendra bientôt dans la zone jeux.</p>
            </div>
        </div>
    </div>
</section>

<style>
.lb-table { width:100%; border-collapse:collapse; }
.lb-table th, .lb-table td { padding:0.7rem 0.6rem; text-align:left; border-bottom:1px solid var(--border); }
.lb-table th { color:var(--muted); text-transform:uppercase; letter-spacing:0.08em; font-size:0.72rem; font-weight:800; }
.lb-table td.num { text-align:center; }
.lb-rank { font-weight:900; font-size:1.05rem; }
.lb-rank.gold { color:#f5c518; }
.lb-rank.silver { color:#c0c5ce; }
.lb-rank.bronze { color:#cd7f32; }
.lb-avatar {
    width:34px; height:34px; border-radius:50%;
    display:inline-grid; place-items:center; flex:0 0 auto;
    background:linear-gradient(135deg, var(--secondary), var(--primary));
    color:#fff; font-weight:900; font-size:0.85rem;
}
.lb-user-cell { display:flex; align-items:center; gap:0.6rem; }
.lb-user-name { font-weight:700; }
.lb-row.is-current { background: rgba(72,189,211,0.12); }
.lb-row.is-current td { border-bottom-color: rgba(72,189,211,0.25); }
.lb-row.is-current td:first-child { border-left:3px solid var(--primary); }
</style>

<script>
(function() {
    var input = document.getElementById('pseudo-input');
    var btn = document.getElementById('pseudo-save');
    var msg = document.getElementById('pseudo-msg');
    if (!input || !btn) return;

    var SUBMIT_URL = <?= json_encode($setPseudoUrl) ?>;
    var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

    function save() {
        var val = input.value.trim();
        if (val.length < 3) {
            msg.textContent = 'Minimum 3 caractères.';
            msg.style.color = 'var(--accent-danger)';
            return;
        }
        btn.disabled = true;
        btn.textContent = '…';
        msg.textContent = '';

        fetch(SUBMIT_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            credentials: 'same-origin',
            body: JSON.stringify({ pseudo: val, _csrf: CSRF_TOKEN })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.textContent = 'Enregistrer';
            if (data.success) {
                msg.textContent = '✅ Pseudo enregistré : ' + data.pseudo;
                msg.style.color = 'var(--primary)';
                btn.disabled = true;
                // Recharge la page pour mettre à jour le classement.
                setTimeout(function() { window.location.reload(); }, 1000);
            } else {
                msg.textContent = data.message || 'Erreur.';
                msg.style.color = 'var(--accent-danger)';
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Enregistrer';
            msg.textContent = 'Erreur de connexion.';
            msg.style.color = 'var(--accent-danger)';
        });
    }

    btn.addEventListener('click', save);
    input.addEventListener('keydown', function(e) { if (e.key === 'Enter') save(); });
})();
</script>
