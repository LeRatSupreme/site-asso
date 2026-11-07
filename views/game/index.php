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
            <div class="surface card ae-panel gm-notice">
                <p class="gm-notice-text">Tu joues en <strong>mode démo</strong>. <a href="<?= e(url('/login?callbackUrl=' . rawurlencode('/jeux'))) ?>">Connecte-toi</a> pour sauvegarder tes parties, suivre ta série de victoires et apparaître dans le classement.</p>
            </div>
        <?php endif; ?>

        <?php if ($user !== null && $stats !== null): ?>
            <h2 class="section-title gm-h2 ae-reveal">Tes statistiques</h2>
            <div class="grid grid-2 gm-stats gm-section">
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
            <div class="surface card ae-panel gm-pseudo" id="pseudo-prompt">
                <p class="gm-pseudo-title">Choisis ton pseudo de joueur</p>
                <p class="gm-pseudo-hint">Ton pseudo apparaîtra dans le classement ci-dessous. 3 à 20 caractères (lettres, chiffres, espaces, - _ .).</p>
                <div class="pseudo-edit-row">
                    <input type="text" id="pseudo-input" class="pseudo-input" maxlength="20" placeholder="ex : MotusMaster" autocomplete="off" value="<?= e($userPseudo ?? '') ?>" />
                    <button type="button" class="btn btn-primary btn-sm" id="pseudo-save">Enregistrer</button>
                </div>
                <p id="pseudo-msg" class="pseudo-msg"></p>
            </div>
        <?php elseif ($user !== null && $userPseudo !== null): ?>
            <!-- Pseudo existant + bouton modifier -->
            <div class="surface card ae-panel gm-pseudo gm-pseudo-row" id="pseudo-display">
                <span class="gm-pseudo-label">Ton pseudo&nbsp;:</span>
                <strong class="gm-pseudo-value" id="pseudo-current"><?= e($userPseudo) ?></strong>
                <button type="button" class="btn btn-outline btn-sm gm-pseudo-edit-btn" id="pseudo-edit-btn">Modifier</button>
            </div>
            <!-- Formulaire de modification (caché par défaut) -->
            <div class="surface card gm-pseudo gm-pseudo-edit" id="pseudo-edit">
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
            <div class="surface card gm-empty ae-reveal">
                <p>Aucune partie enregistrée pour le moment.</p>
                <p><a class="btn btn-primary btn-sm" href="<?= e(url('/jeux/wordle?mode=daily')) ?>">Jouer au Wordle quotidien →</a></p>
            </div>
        <?php else: ?>
            <div class="table-wrap surface ae-panel gm-lb ae-reveal">
                <table class="lb-table">
                    <thead>
                        <tr>
                            <th class="num lb-rank-col">#</th>
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
                                        <?php if ($isCurrent): ?> <span class="badge badge-info lb-you">Toi</span><?php endif; ?>
                                    </div>
                                </td>
                                <td class="num">
                                    <strong class="lb-streak"><?= $streak ?> jour<?= $streak > 1 ? 's' : '' ?></strong>
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

            <a class="gm-ticket ae-reveal" href="<?= e(url('/jeux/memory')) ?>">
                <span class="gm-stub" aria-hidden="true">M</span>
                <span class="gm-body">
                    <span class="gm-meta"><span class="badge badge-secondary">7 tailles</span></span>
                    <span class="gm-title">Memory</span>
                    <span class="gm-desc">Le memory cafétéria&nbsp;: retrouve toutes les paires de produits le plus vite possible. 7 tailles de grille, du 4×3 au 10×8&nbsp;!</span>
                    <span class="gm-cta">Jouer →</span>
                </span>
            </a>

            <a class="gm-ticket gm-ticket-violet ae-reveal" href="<?= e(url('/jeux/snake')) ?>">
                <span class="gm-stub" aria-hidden="true">S</span>
                <span class="gm-body">
                    <span class="gm-meta"><span class="badge badge-secondary">3 vitesses</span></span>
                    <span class="gm-title">Snake</span>
                    <span class="gm-desc">Le classique intemporel&nbsp;: mange un max de fruits sans te mordre ni percuter les murs. Clavier ou tactile, 3 vitesses&nbsp;!</span>
                    <span class="gm-cta">Jouer →</span>
                </span>
            </a>

            <a class="gm-ticket ae-reveal" href="<?= e(url('/jeux/tetris')) ?>">
                <span class="gm-stub" aria-hidden="true">T</span>
                <span class="gm-body">
                    <span class="gm-meta"><span class="badge badge-secondary">Marathon</span></span>
                    <span class="gm-title">Tetris</span>
                    <span class="gm-desc">Empile les pièces et complète les lignes&nbsp;! Niveaux progressifs, pièce suivante, réserve et chute rapide. Jusqu'où irez-vous&nbsp;?</span>
                    <span class="gm-cta">Jouer →</span>
                </span>
            </a>
        </div>
    </div>
</section>

<style>
/* ============ Zone jeux — habillage charte AEIC ============
   Les titres reprennent la typo du site (900, uppercase, resserrée),
   les tickets gardent l'idée « carte perforée » mais dans la palette
   teal / violet de l'association. */
.gm-h2 {
    font-size: clamp(1.35rem, 2.6vw, 1.8rem);
    margin: 0 0 1.1rem;
    line-height: 1;
}
.gm-section { margin-bottom: 2.5rem; }
.gm-notice { margin-bottom: 1.75rem; align-items: flex-start; }
.gm-notice-text { margin: 0; overflow-wrap: anywhere; }

/* Statistiques joueur : chiffres tabulaires (pas de tremblement). */
.gm-stats .gm-stat {
    font-size: 1.9rem;
    font-variant-numeric: tabular-nums;
}
.gm-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
}
@media (max-width: 640px) {
    .gm-stats-grid { grid-template-columns: repeat(2, 1fr); }
}

/* Blocs pseudo (définition / affichage / édition). */
.gm-pseudo { margin-bottom: 1.75rem; }
.gm-pseudo-title { margin: 0 0 0.4rem; font-weight: 700; }
.gm-pseudo-hint { margin: 0 0 0.75rem; color: var(--muted); font-size: 0.9rem; overflow-wrap: anywhere; }
.gm-pseudo-row { flex-direction: row; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.gm-pseudo-label { color: var(--muted); }
.gm-pseudo-value { color: var(--primary); overflow-wrap: anywhere; }
.gm-pseudo-edit-btn { margin-left: auto; }
#pseudo-edit { display: none; flex-direction: column; gap: 0.5rem; align-items: stretch; }
.pseudo-edit-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
.pseudo-input {
    flex: 1; min-width: 200px; min-height: 44px;
    padding: 0.6rem 0.85rem;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-strong);
    background: var(--input-bg);
    color: var(--foreground); font-size: 1rem;
}
.pseudo-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(58, 155, 184, 0.18); }
[data-theme="light"] .pseudo-input:focus { box-shadow: 0 0 0 3px rgba(45, 122, 148, 0.15); }
.pseudo-msg { margin: 0.5rem 0 0; font-size: 0.85rem; min-height: 1.1rem; overflow-wrap: anywhere; }

/* Classement */
.gm-empty {
    text-align: center; padding: 2rem; color: var(--muted);
    margin-bottom: 2.5rem; gap: 0.75rem; align-items: center;
}
.gm-empty p { margin: 0; }
.gm-lb { padding: 0.5rem 0.5rem 0; margin-bottom: 2.5rem; overflow-x: auto; }
.lb-table { width: 100%; border-collapse: collapse; }
.lb-table th, .lb-table td { padding: 0.7rem 0.6rem; text-align: left; border-bottom: 1px solid var(--border); }
.lb-rank-col { width: 3rem; }
.lb-table th {
    color: var(--muted); text-transform: uppercase;
    letter-spacing: 0.18em; font-size: 0.62rem; font-weight: 700;
}
.lb-table td.num { text-align: center; font-variant-numeric: tabular-nums; }
.lb-rank { font-weight: 900; font-size: 1.05rem; }
.lb-rank.gold { color: var(--accent-warning); }
.lb-rank.silver { color: #9aa7b8; }
.lb-rank.bronze { color: #c98a4b; }
.lb-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    display: inline-grid; place-items: center; flex: 0 0 auto;
    background: rgba(58, 155, 184, 0.16);
    border: 1px solid rgba(58, 155, 184, 0.35);
    color: var(--primary); font-weight: 900; font-size: 0.85rem;
}
[data-theme="light"] .lb-avatar { background: rgba(45, 122, 148, 0.1); }
.lb-user-cell { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
.lb-user-name { font-weight: 700; overflow-wrap: anywhere; }
.lb-you { margin-left: 0.35rem; }
.badge-info { background: rgba(58, 155, 184, 0.16); color: #7fd0e4; }
[data-theme="light"] .badge-info { background: rgba(14, 116, 144, 0.1); color: #0e7490; }
.lb-streak { color: var(--primary); font-size: 1.05rem; font-variant-numeric: tabular-nums; }
.lb-row.is-current { background: rgba(58, 155, 184, 0.1); }
.lb-row.is-current td { border-bottom-color: rgba(58, 155, 184, 0.25); }
.lb-row.is-current td:first-child { border-left: 3px solid var(--primary); }

/* Jeux en cartes « tickets » : tampon monogramme + perforation,
   palette teal / violet de l'association. */
.gm-tickets {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.1rem;
}
.gm-ticket {
    display: flex;
    align-items: stretch;
    min-width: 0;
    border-radius: 18px;
    border: 1px solid var(--border);
    background: rgba(255, 255, 255, 0.03);
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}
.gm-ticket:hover {
    transform: translateY(-3px);
    border-color: rgba(58, 155, 184, 0.45);
    box-shadow: 0 14px 34px rgba(2, 8, 20, 0.4);
}
.gm-ticket:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
[data-theme="light"] .gm-ticket { background: #ffffff; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04); }
[data-theme="light"] .gm-ticket:hover { box-shadow: 0 12px 26px rgba(15, 23, 42, 0.12); }
.gm-stub {
    flex: 0 0 72px;
    display: grid;
    place-items: center;
    font-size: 1.7rem;
    font-weight: 900;
    line-height: 1;
    letter-spacing: -0.04em;
    text-transform: uppercase;
    color: var(--primary);
    background: rgba(58, 155, 184, 0.12);
    border-right: 1px dashed rgba(255, 255, 255, 0.16);
}
[data-theme="light"] .gm-stub {
    background: rgba(45, 122, 148, 0.08);
    border-right-color: rgba(15, 23, 42, 0.16);
}
.gm-ticket:hover .gm-stub { color: #5fc9de; }
.gm-ticket-violet .gm-stub {
    background: rgba(74, 61, 143, 0.3);
    color: #c4b8f0;
}
[data-theme="light"] .gm-ticket-violet .gm-stub { background: rgba(97, 80, 170, 0.1); color: #4a3d8a; }
.gm-body {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 1.1rem 1.2rem;
    min-width: 0;
}
.gm-title {
    font-size: 1.05rem;
    font-weight: 900;
    letter-spacing: -0.02em;
    color: var(--foreground);
    line-height: 1.25;
    overflow-wrap: anywhere;
}
.gm-ticket:hover .gm-title { color: var(--primary); }
.gm-desc {
    font-size: 0.87rem;
    color: var(--muted);
    line-height: 1.6;
    overflow-wrap: anywhere;
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
