<?php

declare(strict_types=1);

/**
 * Classement Wordle AEIC (FR / EN).
 *
 * @var string $mode
 * @var list<array<string,mixed>> $rows
 * @var string|null $currentId
 */

use App\Core\Auth;

function wordleInitials(string $prenom, string $nom): string
{
    $a = mb_substr(trim($prenom), 0, 1);
    $b = mb_substr(trim($nom), 0, 1);
    $init = mb_strtoupper($a . $b);

    return $init === '' ? '?' : $init;
}
?>
<style>
.lb-tabs { display:flex; gap:0.5rem; margin-bottom:1.5rem; justify-content:center; }
.lb-pill {
    padding:0.55rem 1.3rem; border-radius:999px; cursor:pointer;
    border:1px solid var(--border-strong); background:rgba(255,255,255,0.03);
    color:var(--foreground); font-weight:800; text-decoration:none;
    transition: background .15s, border-color .15s, color .15s;
}
.lb-pill:hover { background: rgba(72,189,211,0.12); }
.lb-pill.is-active { background: var(--primary); color: var(--primary-foreground); border-color: var(--primary); }
.lb-table { width:100%; border-collapse:collapse; }
.lb-table th, .lb-table td { padding:0.85rem 0.7rem; text-align:left; border-bottom:1px solid var(--border); }
.lb-table th { color:var(--muted); text-transform:uppercase; letter-spacing:0.08em; font-size:0.72rem; font-weight:800; }
.lb-table td.num { text-align:center; }
.lb-rank { font-weight:900; font-size:1.1rem; }
.lb-rank.gold { color:#f5c518; }
.lb-rank.silver { color:#c0c5ce; }
.lb-rank.bronze { color:#cd7f32; }
.lb-avatar {
    width:36px; height:36px; border-radius:50%;
    display:inline-grid; place-items:center; flex:0 0 auto;
    background:linear-gradient(135deg, var(--secondary), var(--primary));
    color:#fff; font-weight:900; font-size:0.9rem;
}
.lb-user-cell { display:flex; align-items:center; gap:0.6rem; }
.lb-user-name { font-weight:700; }
.lb-user-email { display:block; font-size:0.75rem; color:var(--muted); }
.lb-row.is-current { background: rgba(72,189,211,0.12); }
.lb-row.is-current td { border-bottom-color: rgba(72,189,211,0.25); }
.lb-row.is-current td:first-child { border-left:3px solid var(--primary); }
.lb-empty { text-align:center; padding:3rem 1rem; color:var(--muted); }
@media (max-width:520px) {
    .lb-hide-sm { display:none; }
}
</style>

<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">🎮 Zone jeux</span>
        <h1 class="page-title">🏆 Classement Wordle</h1>
        <p class="page-lead">Les meilleurs joueurs de l'AEIC, classés par plus longue série de victoires consécutives.</p>
    </div>
</header>

<section class="section">
    <div class="container">

        <div class="lb-tabs">
            <a class="lb-pill <?= $mode === 'fr' ? 'is-active' : '' ?>" href="<?= e(url('/jeux/leaderboard?mode=fr')) ?>">🇫🇷 Français</a>
            <a class="lb-pill <?= $mode === 'en' ? 'is-active' : '' ?>" href="<?= e(url('/jeux/leaderboard?mode=en')) ?>">🇬🇧 English</a>
        </div>

        <?php if ($rows === []): ?>
            <div class="surface lb-empty">
                <p style="font-size:1.1rem; margin-bottom:0.5rem;">🗂️ Aucune partie enregistrée pour le moment en mode <?= $mode === 'fr' ? 'français' : 'anglais' ?>.</p>
                <p><a class="btn btn-primary btn-sm" href="<?= e(url('/jeux/wordle')) ?>">Jouer au Wordle →</a></p>
            </div>
        <?php else: ?>
            <div class="table-wrap surface" style="padding:0.5rem 0.5rem 0;">
                <table class="lb-table">
                    <thead>
                        <tr>
                            <th class="num" style="width:3.5rem;">#</th>
                            <th>Joueur</th>
                            <th class="num">Série max 🏆</th>
                            <th class="num lb-hide-sm">Victoires</th>
                            <th class="num lb-hide-sm">Parties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $i => $row):
                            $rank = $i + 1;
                            $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                            $isCurrent = $currentId !== null && (string) $row['id'] === $currentId; ?>
                            <tr class="lb-row <?= $isCurrent ? 'is-current' : '' ?>">
                                <td class="num"><span class="lb-rank <?= $rankClass ?>"><?= $rank ?></span></td>
                                <td>
                                    <div class="lb-user-cell">
                                        <span class="lb-avatar" aria-hidden="true"><?= e(wordleInitials((string) $row['prenom'], (string) $row['nom'])) ?></span>
                                        <span>
                                            <span class="lb-user-name"><?= e($row['prenom'] . ' ' . $row['nom']) ?></span>
                                            <?php if ($isCurrent): ?> <span class="badge badge-info" style="margin-left:0.4rem;">Toi</span><?php endif; ?>
                                            <span class="lb-user-email lb-hide-sm"><?= e($row['email']) ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td class="num"><strong style="color:var(--primary);"><?= (int) $row['maxStreak'] ?></strong></td>
                                <td class="num lb-hide-sm"><?= (int) $row['won'] ?></td>
                                <td class="num lb-hide-sm"><?= (int) $row['played'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="section-more">
            <a class="btn btn-primary" href="<?= e(url('/jeux/wordle')) ?>">🎮 Jouer au Wordle</a>
            <a class="btn btn-outline" href="<?= e(url('/jeux')) ?>">← Retour à la zone jeux</a>
        </div>
    </div>
</section>
