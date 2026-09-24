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
    <div class="ae-aurora" aria-hidden="true"></div>
    <div class="ae-dots dot-grid" aria-hidden="true"></div>
    <div class="container">
        <div class="ae-hero-grid">
            <div>
                <span class="ae-pill">
                    <span class="ae-pill-dot" aria-hidden="true"></span>
                    Zone jeux
                </span>
                <h1 class="page-title ae-title-grad">Zone jeux</h1>
                <p class="page-lead">Un mot par jour, une série à construire, un classement à gravir. Connecte-toi pour sauvegarder tes scores&nbsp;!</p>
            </div>

            <!-- Carte joueur décorative et interactive -->
            <?php
            $frStats = ($user !== null && is_array($stats) && isset($stats['fr'])) ? $stats['fr'] : null;
            ?>
            <div class="ae-idcard" data-tilt data-tilt-base="rotate(-1.6deg)" aria-hidden="true">
                <div class="ae-idcard-band">
                    <span class="ae-idcard-logo">AE</span>
                    <span class="ae-idcard-id">
                        <span class="ae-idcard-label">CARTE / PLAYER</span>
                        <span class="ae-idcard-name"><?= $userPseudo !== null ? e($userPseudo) : 'AEIC' ?></span>
                    </span>
                    <span class="ae-idcard-badge"><?= $frStats !== null ? (int) $frStats['currentStreak'] : 'DEMO' ?></span>
                </div>
                <div class="ae-idcard-body">
                    <?php if ($frStats !== null): ?>
                        <div class="ae-idcard-row">
                            <span><?= (int) $frStats['played'] ?> parties jouées</span>
                        </div>
                        <div class="ae-idcard-row">
                            <span><?= (int) $frStats['won'] ?> victoires · record <?= (int) $frStats['maxStreak'] ?></span>
                        </div>
                        <div class="ae-idcard-row">
                            <span>Wordle FR · EN</span>
                        </div>
                    <?php else: ?>
                        <div class="ae-idcard-row">
                            <span>Mode démo</span>
                        </div>
                        <div class="ae-idcard-row">
                            <span>Connexion pour sauvegarder</span>
                        </div>
                        <div class="ae-idcard-row">
                            <span>Classement ouvert à tous</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="ae-idcard-code">||&#124; ||||| &#124; |||&#124; |||| &#124;&#124;| ||| &#124;</div>
            </div>
        </div>
    </div>
</header>

<section class="section">
    <div class="container">

        <?php if ($user === null): ?>
            <div class="surface card ae-panel gm-notice" style="margin-bottom:1.75rem; align-items:flex-start;">
                <p style="margin:0;">Tu joues en <strong>mode démo</strong>. <a href="<?= e(url('/login?callbackUrl=' . rawurlencode('/jeux'))) ?>">Connecte-toi</a> pour sauvegarder tes parties, suivre ta série de victoires et apparaître dans le classement.</p>
            </div>
        <?php endif; ?>

        <?php if ($user !== null && $stats !== null): ?>
            <h2 class="section-title gm-h2 ae-reveal">Tes statistiques</h2>
            <div class="grid grid-2 gm-stats" style="margin-bottom:2.5rem;">
                <?php foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $label):
                    $s = $stats[$code]; ?>
                    <div class="surface card ae-panel ae-reveal">
                        <span class="badge badge-gradient"><?= $label ?></span>
                        <div class="gm-stats-grid">
                            <div class="stat-card">
                                <span class="stat-value gm-stat"><?= (int) $s['played'] ?></span>
                                <span class="stat-label">Parties</span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-value gm-stat"><?= (int) $s['won'] ?></span>
                                <span class="stat-label">Victoires</span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-value gm-stat"><?= (int) $s['currentStreak'] ?></span>
                                <span class="stat-label">Série en cours</span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-value gm-stat"><?= (int) $s['maxStreak'] ?></span>
                                <span class="stat-label">Record</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($user !== null && $userPseudo === null): ?>
            <!-- Demande de pseudo -->
            <div class="surface card ae-panel" id="pseudo-prompt" style="margin-bottom:1.75rem; align-items:flex-start;">
                <div style="display:flex; gap:0.75rem; align-items:flex-start; width:100%;">
                    <div style="flex:1;">
                        <p style="margin:0 0 0.5rem; font-weight:700;">Choisis ton pseudo de joueur</p>
                        <p style="margin:0 0 0.75rem; color:var(--muted); font-size:0.9rem;">Ton pseudo apparaîtra dans le classement ci-dessous. 3 à 20 caractères (lettres, chiffres, espaces, - _ .).</p>
                        <div class="pseudo-edit-row">
                            <input type="text" id="pseudo-input" class="pseudo-input" maxlength="20" placeholder="ex : MotusMaster" autocomplete="off" value="<?= e($userPseudo ?? '') ?>" />
                            <button type="button" class="btn btn-primary btn-sm" id="pseudo-save">Enregistrer</button>
                        </div>
                        <p id="pseudo-msg" class="pseudo-msg"></p>
                    </div>
                </div>
            </div>
        <?php elseif ($user !== null && $userPseudo !== null): ?>
            <!-- Pseudo existant + bouton modifier -->
            <div class="surface card ae-panel" id="pseudo-display" style="margin-bottom:1.75rem; flex-direction:row; align-items:center; gap:0.75rem; flex-wrap:wrap;">
                <span style="color:var(--muted);">Ton pseudo&nbsp;:</span>
                <strong id="pseudo-current" style="color:var(--primary);"><?= e($userPseudo) ?></strong>
                <button type="button" class="btn btn-outline btn-sm" id="pseudo-edit-btn" style="margin-left:auto;">Modifier</button>
            </div>
            <!-- Formulaire de modification (caché par défaut) -->
            <div class="surface card" id="pseudo-edit" style="display:none; margin-bottom:1.75rem; flex-direction:column; gap:0.5rem; align-items:stretch;">
                <div class="pseudo-edit-row">
                    <input type="text" id="pseudo-input" class="pseudo-input" maxlength="20" autocomplete="off" value="<?= e($userPseudo) ?>" />
                    <button type="button" class="btn btn-primary btn-sm" id="pseudo-save">Enregistrer</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="pseudo-cancel">Annuler</button>
                </div>
                <p id="pseudo-msg" class="pseudo-msg"></p>
            </div>
        <?php endif; ?>

        <!-- ===================== CLASSEMENT (tout le monde) ===================== -->
        <h2 class="section-title gm-h2 ae-reveal">Classement</h2>
        <?php if ($leaderboard === []): ?>
            <div class="surface card" style="text-align:center; padding:2rem; color:var(--muted); margin-bottom:2.5rem;">
                <p style="margin:0 0 0.5rem;">Aucune partie enregistrée pour le moment.</p>
                <p style="margin:0;"><a class="btn btn-primary btn-sm" href="<?= e(url('/jeux/wordle?mode=daily')) ?>">Jouer au Wordle quotidien →</a></p>
            </div>
        <?php else: ?>
            <div class="table-wrap surface ae-panel ae-reveal" style="padding:0.5rem 0.5rem 0; margin-bottom:2.5rem; overflow-x:auto;">
                <table class="lb-table">
                    <thead>
                        <tr>
                            <th class="num" style="width:3rem;">#</th>
                            <th>Joueur</th>
                            <th class="num">Série</th>
                            <th class="num">Record</th>
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
        <h2 class="section-title gm-h2 ae-reveal">Jeux disponibles</h2>
        <div class="gm-tickets">
            <a class="gm-ticket ae-reveal" href="<?= e(url('/jeux/wordle')) ?>">
                <span class="gm-stub" aria-hidden="true">W</span>
                <span class="gm-body">
                    <span class="gm-meta"><span class="badge badge-secondary">3 difficultés</span></span>
                    <span class="gm-title">Wordle</span>
                    <span class="gm-desc">Devine le mot en 6 essais. 3 niveaux (5, 6 ou 7 lettres), mode quotidien commun ou libre illimité, en français ou en anglais&nbsp;!</span>
                    <span class="gm-cta">Jouer →</span>
                </span>
            </a>

            <a class="gm-ticket gm-ticket-violet ae-reveal" href="<?= e(url('/jeux/enigme')) ?>">
                <span class="gm-stub" aria-hidden="true">E</span>
                <span class="gm-body">
                    <span class="gm-meta"><span class="badge badge-secondary">Quotidien</span></span>
                    <span class="gm-title">Énigme du jour</span>
                    <span class="gm-desc">Une devinette par jour, identique pour tous les joueurs. Saurez-vous la résoudre&nbsp;? Change chaque jour à minuit&nbsp;!</span>
                    <span class="gm-cta">Réfléchir →</span>
                </span>
            </a>

            <div class="gm-ticket gm-ticket-muted ae-reveal">
                <span class="gm-stub" aria-hidden="true">M</span>
                <span class="gm-body">
                    <span class="gm-meta"><span class="badge badge-muted">Bientôt</span></span>
                    <span class="gm-title">Memory</span>
                    <span class="gm-desc">Le memory cafétéria reviendra bientôt dans la zone jeux.</span>
                </span>
            </div>
        </div>
    </div>
</section>

<style>
/* ============ Jeux — spécifique ============ */
.gm-h2 {
    font-size: 1.35rem;
    margin: 0 0 1.1rem;
    letter-spacing: -0.02em;
}

/* Statistiques joueur : chiffres en dégradé */
.gm-stats .gm-stat {
    font-size: 1.9rem;
    background: linear-gradient(135deg, #8b7ae0, #3a9bb8 60%, #7fd0e4);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
}
.gm-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
}
@media (max-width: 640px) {
    .gm-stats-grid { grid-template-columns: repeat(2, 1fr); }
}

/* Jeux en cartes « tickets » (même modèle que la page association) */
.gm-tickets {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.1rem;
}
.gm-ticket {
    display: flex;
    align-items: stretch;
    border-radius: 18px;
    border: 1px solid var(--border);
    background: rgba(255, 255, 255, 0.03);
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    transition: transform 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease;
}
.gm-ticket:hover {
    transform: translateY(-4px);
    border-color: rgba(72, 189, 211, 0.35);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.35), 0 0 26px rgba(58, 155, 184, 0.15);
}
.gm-stub {
    flex: 0 0 76px;
    display: grid;
    place-items: center;
    font-size: 2rem;
    line-height: 1;
    background: linear-gradient(160deg, rgba(58, 155, 184, 0.38), rgba(58, 155, 184, 0.1));
    border-right: 2px dashed rgba(255, 255, 255, 0.14);
    transition: font-size 0.25s ease;
}
[data-theme="light"] .gm-stub { border-right-color: rgba(15, 23, 42, 0.14); }
.gm-ticket:hover .gm-stub { font-size: 2.4rem; }
.gm-ticket-violet .gm-stub { background: linear-gradient(160deg, rgba(139, 122, 224, 0.38), rgba(139, 122, 224, 0.1)); }
.gm-ticket-muted { opacity: 0.65; }
.gm-ticket-muted .gm-stub { background: rgba(255, 255, 255, 0.04); }
.gm-ticket-muted:hover { transform: none; box-shadow: none; border-color: var(--border); }
.gm-ticket-muted:hover .gm-stub { font-size: 2rem; }
.gm-body {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 1.15rem 1.25rem;
    min-width: 0;
}
.gm-title {
    font-size: 1.1rem;
    font-weight: 900;
    text-transform: none;
    letter-spacing: -0.01em;
    color: var(--foreground);
    line-height: 1.2;
}
.gm-ticket:hover .gm-title { color: var(--primary); }
.gm-desc {
    font-size: 0.87rem;
    color: var(--muted);
    line-height: 1.6;
}
.gm-cta {
    margin-top: auto;
    padding-top: 0.35rem;
    font-size: 0.88rem;
    font-weight: 800;
    color: var(--primary);
}
@media (max-width: 980px) {
    .gm-tickets { grid-template-columns: 1fr; }
}

/* Classement : retouches */
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
.pseudo-edit-row { display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; }
.pseudo-input {
    flex:1; min-width:200px; padding:0.6rem 0.8rem; border-radius:0.5rem;
    border:2px solid var(--border-strong); background:rgba(255,255,255,0.04);
    color:var(--foreground); font-size:1rem;
}
.pseudo-input:focus { outline:none; border-color:var(--primary); }
.pseudo-msg { margin:0.5rem 0 0; font-size:0.85rem; min-height:1.1rem; }
</style>

<script>
(function() {
    var input = document.getElementById('pseudo-input');
    var btn = document.getElementById('pseudo-save');
    var msg = document.getElementById('pseudo-msg');
    var SUBMIT_URL = <?= json_encode($setPseudoUrl) ?>;
    var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

    // --- Mode "définir" (pas encore de pseudo) ---
    if (input && btn) {
        wireSave();
    }

    // --- Mode "modifier" (pseudo déjà existant) ---
    var editBtn = document.getElementById('pseudo-edit-btn');
    var editBox = document.getElementById('pseudo-edit');
    var cancelBtn = document.getElementById('pseudo-cancel');
    var display = document.getElementById('pseudo-current');

    if (editBtn && editBox) {
        editBtn.addEventListener('click', function() {
            editBox.style.display = 'flex';
            editBtn.style.display = 'none';
            // Re-branche les handlers sur les nouveaux éléments.
            input = document.getElementById('pseudo-input');
            btn = document.getElementById('pseudo-save');
            msg = document.getElementById('pseudo-msg');
            wireSave();
            if (input) { input.focus(); input.select(); }
        });
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            editBox.style.display = 'none';
            if (editBtn) editBtn.style.display = '';
            if (msg) msg.textContent = '';
        });
    }

    function wireSave() {
        if (!input || !btn) return;
        btn.onclick = save;
        input.onkeydown = function(e) { if (e.key === 'Enter') save(); };
    }

    function save() {
        var val = (input.value || '').trim();
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
                msg.textContent = 'Pseudo enregistré : ' + data.pseudo;
                msg.style.color = 'var(--primary)';
                // Recharge la page pour mettre à jour le classement.
                setTimeout(function() { window.location.reload(); }, 800);
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
})();
</script>
