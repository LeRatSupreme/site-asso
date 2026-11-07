<?php

declare(strict_types=1);

/**
 * Page de jeu — Tetris AEIC (mode marathon).
 *
 * @var array<string,mixed>|null $user
 * @var bool $isLoggedIn
 * @var string $submitUrl
 * @var string $csrfToken
 */
?>
<header class="page-hero">
    <div class="ae-dots dot-grid" aria-hidden="true"></div>
    <div class="container">
        <span class="ae-pill"><span class="ae-pill-dot" aria-hidden="true"></span>Arcade · Mode marathon</span>
        <h1 class="page-title">Tetris</h1>
        <p class="page-lead">Empile les pièces, complète les lignes et va le plus loin possible&nbsp;!</p>
    </div>
</header>

<section class="section">
    <div class="container game-zone">

        <p class="back-link"><a href="<?= e(url('/jeux')) ?>">← Tous les jeux</a></p>

        <!-- Barre de jeu -->
        <div class="game-bar">
            <div class="game-stats">
                <div class="game-stat">
                    <span class="game-stat-label">Score</span>
                    <span class="game-stat-value" id="t-score">0</span>
                </div>
                <div class="game-stat">
                    <span class="game-stat-label">Lignes</span>
                    <span class="game-stat-value" id="t-lines">0</span>
                </div>
                <div class="game-stat">
                    <span class="game-stat-label">Niveau</span>
                    <span class="game-stat-value" id="t-level">1</span>
                </div>
                <div class="game-stat">
                    <span class="game-stat-label">Record</span>
                    <span class="game-stat-value game-stat-value-muted" id="t-best">—</span>
                </div>
            </div>
            <div class="game-controls">
                <button class="btn btn-primary btn-sm" id="t-new">Nouvelle partie</button>
            </div>
        </div>

        <div class="tetris-layout">
            <!-- Plateau -->
            <div class="tetris-wrap">
                <canvas id="t-canvas" aria-label="Plateau de Tetris"></canvas>
                <div class="tetris-overlay" id="t-overlay">
                    <div class="tetris-overlay-card">
                        <h2 id="t-overlay-title">Tetris</h2>
                        <p id="t-overlay-text">← → déplacer · ↑ tourner · ↓ descendre<br>Espace&nbsp;: chute rapide · C&nbsp;: réserve · P&nbsp;: pause</p>
                        <button class="btn btn-primary" id="t-overlay-btn">Jouer</button>
                    </div>
                </div>
            </div>

            <!-- Panneau latéral -->
            <div class="tetris-side">
                <div class="tetris-mini">
                    <span class="tetris-mini-label">Suivante</span>
                    <canvas id="t-next"></canvas>
                </div>
                <div class="tetris-mini">
                    <span class="tetris-mini-label">Réserve (C)</span>
                    <canvas id="t-hold"></canvas>
                </div>
            </div>
        </div>

        <!-- Contrôles tactiles -->
        <div class="tetris-pad" id="t-pad" aria-label="Contrôles tactiles">
            <div class="tetris-pad-row">
                <button type="button" data-act="left" class="tetris-pad-btn" aria-label="Déplacer à gauche">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <button type="button" data-act="rotate" class="tetris-pad-btn tetris-pad-btn-rot" aria-label="Tourner">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M23 4v6h-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <button type="button" data-act="right" class="tetris-pad-btn" aria-label="Déplacer à droite">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 5.5 16 12l-6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
            <div class="tetris-pad-row">
                <button type="button" data-act="down" class="tetris-pad-btn" aria-label="Descendre">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <button type="button" data-act="drop" class="tetris-pad-btn" aria-label="Chute instantanée">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v10" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 9l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 20h14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
                </button>
                <button type="button" data-act="hold" class="tetris-pad-btn tetris-pad-btn-hold" aria-label="Mettre en réserve">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 1l4 4-4 4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 11V9a4 4 0 0 1 4-4h14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 23l-4-4 4-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 13v2a4 4 0 0 1-4 4H3" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
        </div>

        <p class="tetris-help">P&nbsp;: pause · Un niveau tous les 10 lignes, la vitesse augmente&nbsp;!</p>
    </div>
</section>

<style>
/* ============ Tetris — habillage charte AEIC ============
   Même langage « console » que le Snake : cadre net, cartes surface,
   typo du site, ombres discrètes. */
.game-zone { max-width: 720px; }

/* Barre de score : bandeau unique, séparateurs fins, chiffres tabulaires. */
.game-bar {
    display: flex; flex-wrap: wrap; align-items: stretch; justify-content: space-between;
    gap: 0.5rem 1rem; margin-bottom: 1.5rem;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 0.7rem 1rem;
}
.game-stats { display: flex; gap: 0; flex-wrap: wrap; align-items: stretch; min-width: 0; }
.game-stat {
    display: flex; flex-direction: column; justify-content: center; gap: 0.2rem;
    padding: 0.2rem 1.1rem; text-align: center; min-width: 0;
}
.game-stat + .game-stat { border-left: 1px solid var(--border); }
.game-stat-label {
    display: block; font-size: 0.62rem; text-transform: uppercase;
    letter-spacing: 0.18em; color: var(--muted); font-weight: 700;
}
.game-stat-value {
    display: block; font-size: 1.3rem; font-weight: 900; color: var(--primary);
    font-variant-numeric: tabular-nums; letter-spacing: -0.02em; line-height: 1.15;
    overflow-wrap: anywhere;
}
.game-stat-value-muted { color: var(--muted); }
.game-controls { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }

.tetris-layout { display: flex; gap: 1.25rem; justify-content: center; align-items: flex-start; }

/* Plateau : console nette autour du canvas du jeu. */
.tetris-wrap {
    position: relative; margin: 0 auto;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border);
    border-radius: calc(var(--radius) + 8px);
    padding: 10px;
}
#t-canvas {
    display: block; width: min(360px, 88vw); height: auto;
    border-radius: var(--radius);
    background: #0b1626; /* raccord avec le fond de grille dessiné par le jeu */
    border: 1px solid var(--border-strong);
    touch-action: none;
}

/* Écrans accueil / pause / fin : carte charte, pas d'alerte navigateur. */
.tetris-overlay {
    position: absolute; inset: 0; display: grid; place-items: center;
    background: rgba(10, 22, 40, 0.78);
    border-radius: calc(var(--radius) + 8px);
    animation: tFade 0.25s ease;
}
.tetris-overlay[hidden] { display: none; }
.tetris-overlay-card {
    text-align: center; padding: 1rem;
    background: var(--card); border: 1px solid var(--border-strong);
    border-radius: var(--radius-lg);
    max-width: min(340px, calc(100% - 2rem));
    box-shadow: 0 24px 60px rgba(2, 8, 20, 0.45);
}
.tetris-overlay-card h2 {
    font-size: 1.4rem; font-weight: 900; color: var(--primary);
    text-transform: uppercase; letter-spacing: -0.04em; margin: 0 0 0.5rem;
}
.tetris-overlay-card p { color: var(--muted); margin: 0 0 1rem; line-height: 1.6; font-size: 0.88rem; overflow-wrap: anywhere; }
@keyframes tFade { from { opacity: 0; } }

/* Panneau latéral : cartes surface, labels façon statistiques du site. */
.tetris-side { display: flex; flex-direction: column; gap: 1rem; }
.tetris-mini {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 0.75rem 0.9rem; text-align: center; min-width: 0;
}
.tetris-mini-label {
    display: block; font-size: 0.62rem; text-transform: uppercase;
    letter-spacing: 0.18em; color: var(--muted); font-weight: 700; margin-bottom: 0.45rem;
}
.tetris-mini canvas { display: block; margin: 0 auto; width: 84px; height: 84px; }

/* Pad tactile : boutons larges (≥ 44 px), pictos vectoriels du site. */
.tetris-pad { display: none; margin: 1rem auto 0; width: max-content; user-select: none; -webkit-user-select: none; }
.tetris-pad.is-touch { display: block; }
.tetris-pad-row { display: flex; gap: 0.6rem; justify-content: center; margin-bottom: 0.6rem; }
.tetris-pad-btn {
    min-width: 64px; height: 58px;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-strong);
    background: rgba(255, 255, 255, 0.04);
    color: var(--foreground); cursor: pointer;
    touch-action: none; -webkit-tap-highlight-color: transparent;
}
.tetris-pad-btn svg { width: 22px; height: 22px; }
.tetris-pad-btn:active { background: var(--primary); color: var(--primary-foreground); border-color: var(--primary); }
.tetris-pad-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.tetris-pad-btn-rot { color: var(--primary); border-color: rgba(58, 155, 184, 0.45); }
.tetris-pad-btn-hold { color: var(--accent-warning); border-color: rgba(212, 148, 26, 0.4); }
@media (pointer: coarse) { .tetris-pad { display: block; } }

[data-theme="light"] .game-bar,
[data-theme="light"] .tetris-wrap,
[data-theme="light"] .tetris-mini,
[data-theme="light"] .tetris-pad-btn { background: #ffffff; }
[data-theme="light"] .game-bar,
[data-theme="light"] .tetris-wrap,
[data-theme="light"] .tetris-mini { box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04); }

.tetris-help { text-align: center; color: var(--muted); font-size: 0.85rem; margin-top: 1.25rem; overflow-wrap: anywhere; }

/* Mobile : plateau centré, panneau Suivante/Réserve en ligne sous le plateau. */
@media (max-width: 560px) {
    .tetris-layout { flex-direction: column; align-items: center; gap: 0.75rem; }
    .tetris-wrap { order: 1; }
    .tetris-side { order: 2; flex-direction: row; justify-content: center; gap: 0.6rem; }
    /* Hauteur bornée pour garder stats + plateau + pad dans l'écran. */
    #t-canvas { width: min(330px, 86vw, 40vh); }
    .tetris-mini { padding: 0.4rem 0.6rem; }
    .tetris-mini canvas { width: 60px; height: 60px; }
    .tetris-overlay-card p { font-size: 0.8rem; }
}

@media (prefers-reduced-motion: reduce) { .tetris-overlay { animation: none; } }
</style>

<script>
(function () {
    var SUBMIT_URL = <?= json_encode($submitUrl) ?>;
    var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    var IS_LOGGED_IN = <?= json_encode((bool) $isLoggedIn) ?>;

    var COLS = 10, ROWS = 20;
    var CELL = 36;                 // cases larges : plateau 360 × 720
    var W = COLS * CELL, H = ROWS * CELL;
    var FLASH_MS = 200;            // flash blanc quand une ligne est complétée

    var canvas = document.getElementById('t-canvas');
    var ctx = canvas.getContext('2d');
    var nextCv = document.getElementById('t-next');
    var nextCtx = nextCv.getContext('2d');
    var holdCv = document.getElementById('t-hold');
    var holdCtx = holdCv.getContext('2d');

    // HiDPI : rendu net sur écrans denses.
    var dpr = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = W * dpr;
    canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    [nextCv, holdCv].forEach(function (cv) {
        cv.width = 100 * dpr;
        cv.height = 100 * dpr;
        var c2 = cv.getContext('2d');
        c2.scale(dpr, dpr);
        c2._size = 100;   // taille logique portée par le contexte (reçue par drawMini)
    });

    var scoreEl = document.getElementById('t-score');
    var linesEl = document.getElementById('t-lines');
    var levelEl = document.getElementById('t-level');
    var bestEl = document.getElementById('t-best');
    var overlay = document.getElementById('t-overlay');
    var overlayTitle = document.getElementById('t-overlay-title');
    var overlayText = document.getElementById('t-overlay-text');
    var overlayBtn = document.getElementById('t-overlay-btn');
    var newBtn = document.getElementById('t-new');
    var pad = document.getElementById('t-pad');

    // Pièces : matrices, rotation par transposition + inversion.
    var PIECES = {
        I: { m: [[0,0,0,0],[1,1,1,1],[0,0,0,0],[0,0,0,0]], c: '#48bdd3' },
        O: { m: [[1,1],[1,1]],                             c: '#f5c518' },
        T: { m: [[0,1,0],[1,1,1],[0,0,0]],                 c: '#8b7ae0' },
        S: { m: [[0,1,1],[1,1,0],[0,0,0]],                 c: '#2d9a5f' },
        Z: { m: [[1,1,0],[0,1,1],[0,0,0]],                 c: '#e2555c' },
        J: { m: [[1,0,0],[1,1,1],[0,0,0]],                 c: '#3a7bd5' },
        L: { m: [[0,0,1],[1,1,1],[0,0,0]],                 c: '#e8873a' }
    };
    var BAG = Object.keys(PIECES);

    var board, cur, curType, nextType, holdType, canHold;
    var score = 0, lines = 0, level = 1;
    var dropTimer = null, running = false, paused = false;
    var flashRows = null, flashUntil = 0, pops = [];

    function bestKey() { return 'aeic_tetris_best'; }

    function showBest() {
        var best = localStorage.getItem(bestKey());
        bestEl.textContent = best ? best : '—';
    }

    // Gravité : intervalle en ms selon le niveau (courbe classique adoucie).
    function gravityMs() {
        return Math.max(80, 800 * Math.pow(0.82, level - 1));
    }

    function emptyBoard() {
        var b = [];
        for (var y = 0; y < ROWS; y++) { b.push(new Array(COLS).fill(null)); }
        return b;
    }

    function nextFromBag() {
        return BAG[Math.floor(Math.random() * BAG.length)];
    }

    function spawn(type) {
        var def = PIECES[type];
        var m = def.m.map(function (row) { return row.slice(); });
        return {
            m: m,
            c: def.c,
            x: Math.floor((COLS - m[0].length) / 2),
            y: type === 'I' ? -1 : 0
        };
    }

    function rotate(m, dir) {
        var n = m.length;
        var r = [];
        for (var y = 0; y < n; y++) {
            r.push(new Array(n).fill(0));
        }
        for (var yy = 0; yy < n; yy++) {
            for (var xx = 0; xx < n; xx++) {
                if (dir > 0) { r[xx][n - 1 - yy] = m[yy][xx]; }
                else { r[n - 1 - xx][yy] = m[yy][xx]; }
            }
        }
        return r;
    }

    function collides(piece, ox, oy, mat) {
        var m = mat || piece.m;
        for (var y = 0; y < m.length; y++) {
            for (var x = 0; x < m[y].length; x++) {
                if (!m[y][x]) continue;
                var bx = piece.x + x + ox, by = piece.y + y + oy;
                if (bx < 0 || bx >= COLS || by >= ROWS) return true;
                if (by >= 0 && board[by][bx]) return true;
            }
        }
        return false;
    }

    // Wall kicks simples : position, décalages latéraux, puis vers le haut.
    function tryRotate(dir) {
        var r = rotate(cur.m, dir);
        var kicks = [0, -1, 1, -2, 2, -3];
        for (var i = 0; i < kicks.length; i++) {
            if (!collides(cur, kicks[i], 0, r)) {
                cur.m = r;
                cur.x += kicks[i];
                return true;
            }
        }
        if (!collides(cur, 0, -1, r)) {
            cur.m = r;
            cur.y -= 1;
            return true;
        }
        return false;
    }

    function merge() {
        for (var y = 0; y < cur.m.length; y++) {
            for (var x = 0; x < cur.m[y].length; x++) {
                if (cur.m[y][x] && cur.y + y >= 0) {
                    board[cur.y + y][cur.x + x] = cur.c;
                }
            }
        }
    }

    function findFullRows() {
        var full = [];
        for (var y = 0; y < ROWS; y++) {
            if (board[y].every(function (c) { return c; })) { full.push(y); }
        }
        return full;
    }

    function lockPiece() {
        merge();
        var full = findFullRows();
        if (full.length > 0) {
            // Flash blanc sur les lignes avant de les effacer.
            var pts = [0, 100, 300, 500, 800][full.length] * level;
            score += pts;
            lines += full.length;
            var newLevel = Math.floor(lines / 10) + 1;
            if (newLevel > level) {
                level = newLevel;
                pops.push({ text: 'Niveau ' + level + ' !', until: performance.now() + 1100, level: true });
            } else if (full.length === 4) {
                pops.push({ text: 'TETRIS !', until: performance.now() + 1100, level: true });
            }
            scoreEl.textContent = score;
            linesEl.textContent = lines;
            levelEl.textContent = level;
            cur = null;
            flashRows = full;
            flashUntil = performance.now() + FLASH_MS;
            clearInterval(dropTimer);
            animateFlash();
            setTimeout(function () {
                collapseRows(full);
                flashRows = null;
                afterLock();
            }, FLASH_MS);
        } else {
            cur = null;
            afterLock();
        }
    }

    function collapseRows(full) {
        // Supprime de bas en haut pour garder les index valides.
        full.slice().sort(function (a, b) { return b - a; }).forEach(function (y) {
            board.splice(y, 1);
            board.unshift(new Array(COLS).fill(null));
        });
    }

    function afterLock() {
        curType = nextType;
        nextType = nextFromBag();
        canHold = true;
        cur = spawn(curType);
        drawNext();
        if (collides(cur, 0, 0)) { gameOver(); return; }
        restartGravity();
        draw();
    }

    function hold() {
        if (!running || paused || !canHold || flashRows) return;
        var t = curType;
        curType = holdType !== null ? holdType : nextType;
        if (holdType === null) { nextType = nextFromBag(); drawNext(); }
        holdType = t;
        cur = spawn(curType);
        canHold = false;
        drawHold();
        if (collides(cur, 0, 0)) { gameOver(); return; }
        draw();
    }

    function softDrop() {
        if (!running || paused || flashRows || !cur) return;
        if (!collides(cur, 0, 1)) {
            cur.y++;
            score += 1;
            scoreEl.textContent = score;
        } else {
            lockPiece();
        }
        draw();
    }

    function hardDrop() {
        if (!running || paused || flashRows || !cur) return;
        var d = 0;
        while (!collides(cur, 0, 1)) { cur.y++; d++; }
        score += d * 2;
        scoreEl.textContent = score;
        lockPiece();
        if (!flashRows) { draw(); }
    }

    function move(dx) {
        if (!running || paused || flashRows || !cur) return;
        if (!collides(cur, dx, 0)) { cur.x += dx; draw(); }
    }

    function restartGravity() {
        clearInterval(dropTimer);
        dropTimer = setInterval(function () { softDrop(); }, gravityMs());
    }

    // ================== RENDU ==================
    // Bloc simple : carré arrondi uni. Les blocs posés sont légèrement atténués,
    // la pièce qui tombe est pleine avec un liseré clair.
    function drawBlock(c2, x, y, size, color, alpha) {
        c2.save();
        if (alpha !== undefined) { c2.globalAlpha = alpha; }
        var inset = Math.max(1, size * 0.05);
        var r = size * 0.22;
        var bw = size - inset * 2;

        c2.fillStyle = color;
        roundPath(c2, x + inset, y + inset, bw, bw, r);
        c2.fill();

        c2.strokeStyle = alpha !== undefined ? 'rgba(0,0,0,0.30)' : 'rgba(255,255,255,0.55)';
        c2.lineWidth = 1.5;
        roundPath(c2, x + inset, y + inset, bw, bw, r);
        c2.stroke();

        c2.restore();
    }

    function roundPath(c2, x, y, w, h, r) {
        c2.beginPath();
        c2.moveTo(x + r, y);
        c2.arcTo(x + w, y, x + w, y + h, r);
        c2.arcTo(x + w, y + h, x, y + h, r);
        c2.arcTo(x, y + h, x, y, r);
        c2.arcTo(x, y, x + w, y, r);
        c2.closePath();
    }

    function draw() {
        var now = performance.now();

        // Efface vraiment le canvas (les pièces ne laissent plus de traces).
        ctx.clearRect(0, 0, W, H);

        // Fond opaque + grille discrète.
        ctx.fillStyle = '#0b1626';
        ctx.fillRect(0, 0, W, H);
        ctx.strokeStyle = 'rgba(255,255,255,0.05)';
        ctx.lineWidth = 1;
        for (var i = 1; i < COLS; i++) {
            ctx.beginPath(); ctx.moveTo(i * CELL, 0); ctx.lineTo(i * CELL, H); ctx.stroke();
        }
        for (var j = 1; j < ROWS; j++) {
            ctx.beginPath(); ctx.moveTo(0, j * CELL); ctx.lineTo(W, j * CELL); ctx.stroke();
        }

        // Blocs posés (légèrement atténués pour distinguer la pièce qui tombe).
        for (var y = 0; y < ROWS; y++) {
            for (var x = 0; x < COLS; x++) {
                if (board[y][x]) { drawBlock(ctx, x * CELL, y * CELL, CELL, board[y][x], 0.82); }
            }
        }

        // Fantôme : silhouette de la position d'atterrissage.
        if (cur) {
            var gy = 0;
            while (!collides(cur, 0, gy + 1)) { gy++; }
            ctx.save();
            for (var yy = 0; yy < cur.m.length; yy++) {
                for (var xx = 0; xx < cur.m[yy].length; xx++) {
                    if (cur.m[yy][xx] && cur.y + yy + gy >= 0) {
                        var gx = (cur.x + xx) * CELL, gyy = (cur.y + yy + gy) * CELL;
                        ctx.globalAlpha = 0.10;
                        ctx.fillStyle = cur.c;
                        roundPath(ctx, gx + 3, gyy + 3, CELL - 6, CELL - 6, CELL * 0.22);
                        ctx.fill();
                        ctx.globalAlpha = 1;
                        ctx.setLineDash([5, 4]);
                        ctx.strokeStyle = 'rgba(255,255,255,0.30)';
                        ctx.lineWidth = 1.5;
                        ctx.strokeRect(gx + 3, gyy + 3, CELL - 6, CELL - 6);
                        ctx.setLineDash([]);
                    }
                }
            }
            ctx.restore();
        }

        // Pièce qui tombe : pleine luminosité + liseré clair → toujours lisible.
        if (cur) {
            for (var y2 = 0; y2 < cur.m.length; y2++) {
                for (var x2 = 0; x2 < cur.m[y2].length; x2++) {
                    if (cur.m[y2][x2] && cur.y + y2 >= 0) {
                        drawBlock(ctx, (cur.x + x2) * CELL, (cur.y + y2) * CELL, CELL, cur.c);
                    }
                }
            }
        }

        // Flash des lignes complétées.
        if (flashRows) {
            var t = (flashUntil - now) / FLASH_MS;
            ctx.fillStyle = 'rgba(255,255,255,' + (0.35 + 0.5 * Math.max(0, t)).toFixed(2) + ')';
            flashRows.forEach(function (ry) {
                ctx.fillRect(0, ry * CELL, W, CELL);
            });
        }

        // Messages flottants (niveau, tetris).
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        for (var i = pops.length - 1; i >= 0; i--) {
            var p = pops[i];
            if (now > p.until) { pops.splice(i, 1); continue; }
            var age = 1 - (p.until - now) / 1100;
            ctx.globalAlpha = Math.max(0, 1 - age * age);
            ctx.font = '900 ' + (CELL * 0.9) + 'px system-ui, sans-serif';
            ctx.fillStyle = '#7fd0e4';
            ctx.shadowColor = 'rgba(0,0,0,0.5)';
            ctx.shadowBlur = 8;
            ctx.fillText(p.text, W / 2, H * 0.35 - age * CELL);
        }
        ctx.restore();
    }

    // Boucle courte pendant le flash pour animer le blanc.
    function animateFlash() {
        draw();
        if (flashRows) { requestAnimationFrame(animateFlash); }
    }

    function drawMini(c2, type) {
        var size = c2._size || 100;
        c2.clearRect(0, 0, size, size);
        if (!type) return;
        var def = PIECES[type];
        var m = def.m;
        var s = size / 4.8;
        var offX = (size - m[0].length * s) / 2;
        var offY = (size - m.length * s) / 2;
        for (var y = 0; y < m.length; y++) {
            for (var x = 0; x < m[y].length; x++) {
                if (m[y][x]) { drawBlock(c2, offX + x * s, offY + y * s, s, def.c); }
            }
        }
    }

    function drawNext() { drawMini(nextCtx, nextType); }
    function drawHold() { drawMini(holdCtx, holdType); }

    // ================== CYCLE DE VIE ==================
    function newGame() {
        clearInterval(dropTimer);
        board = emptyBoard();
        holdType = null; canHold = true;
        score = 0; lines = 0; level = 1;
        flashRows = null; pops = [];
        scoreEl.textContent = '0';
        linesEl.textContent = '0';
        levelEl.textContent = '1';
        curType = nextFromBag();
        nextType = nextFromBag();
        cur = spawn(curType);
        drawHold();
        drawNext();
        running = true; paused = false;
        overlay.hidden = true;
        showBest();
        restartGravity();
        draw();
    }

    function togglePause() {
        if (!running) return;
        paused = !paused;
        if (paused) {
            overlayTitle.textContent = 'Pause';
            overlayText.textContent = 'P ou le bouton pour reprendre.';
            overlayBtn.textContent = 'Reprendre';
            overlay.hidden = false;
        } else {
            overlay.hidden = true;
        }
    }

    function gameOver() {
        running = false;
        clearInterval(dropTimer);
        var prev = parseInt(localStorage.getItem(bestKey()) || '0', 10);
        var isBest = score > prev;
        if (isBest) { localStorage.setItem(bestKey(), String(score)); }
        overlayTitle.textContent = isBest ? 'Nouveau record !' : 'Game over';
        overlayText.innerHTML = 'Score : <strong>' + score + '</strong> · ' + lines + ' ligne' + (lines > 1 ? 's' : '') +
            ' · niveau ' + level + (isBest ? ' 🏆' : '');
        overlayBtn.textContent = 'Rejouer';
        overlay.hidden = false;
        showBest();
        submitScore();
    }

    function submitScore() {
        if (!IS_LOGGED_IN || score <= 0) return;
        fetch(SUBMIT_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            credentials: 'same-origin',
            body: JSON.stringify({
                game: 'tetris',
                mode: 'marathon',
                score: score,
                lines: lines,
                _csrf: CSRF_TOKEN
            })
        }).catch(function () { /* silencieux */ });
    }

    // ================== CLAVIER ==================
    document.addEventListener('keydown', function (e) {
        switch (e.key) {
            case 'ArrowLeft': e.preventDefault(); move(-1); break;
            case 'ArrowRight': e.preventDefault(); move(1); break;
            case 'ArrowDown': e.preventDefault(); softDrop(); break;
            case 'ArrowUp':
            case 'x': case 'X': e.preventDefault(); if (running && !paused && !flashRows) { tryRotate(1); draw(); } break;
            case 'z': case 'Z': case 'w': case 'W': e.preventDefault(); if (running && !paused && !flashRows) { tryRotate(-1); draw(); } break;
            case ' ': e.preventDefault(); hardDrop(); break;
            case 'c': case 'C': e.preventDefault(); hold(); break;
            case 'p': case 'P': e.preventDefault(); togglePause(); break;
        }
    });

    // ================== TACTILE ==================
    var isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
    if (isTouchDevice) {
        pad.classList.add('is-touch');
        overlayText.innerHTML = 'Glisse : déplacer · Tap : tourner · Balaye vers le bas : tomber<br>' +
            '⇄ ou glisse vers le haut : réserve · ▼ maintenu : descente rapide';
    }

    function canAct() { return running && !paused && !flashRows && cur; }

    function padAct(act) {
        switch (act) {
            case 'left': move(-1); break;
            case 'right': move(1); break;
            case 'down': softDrop(); break;
            case 'drop': hardDrop(); break;
            case 'rotate': if (canAct()) { tryRotate(1); draw(); } break;
            case 'hold': hold(); break;
        }
    }

    // — Pad : pointerdown direct (pas de click synthétisé, zéro latence/double feu).
    var repeatTimer = null;
    function stopRepeat() {
        if (repeatTimer) { clearInterval(repeatTimer); repeatTimer = null; }
    }
    Array.prototype.forEach.call(pad.querySelectorAll('.tetris-pad-btn'), function (btn) {
        btn.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            padAct(btn.dataset.act);
            var act = btn.dataset.act;
            if (act === 'left' || act === 'right' || act === 'down') {
                stopRepeat();
                repeatTimer = setInterval(function () { padAct(act); }, 110);
            }
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
            btn.addEventListener(ev, stopRepeat);
        });
        btn.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    });

    // — Gestes directs sur le plateau :
    //    tap = tourner · glisser horizontal = déplacer · glisser bas = descendre
    //    (geste vif et long = chute instantanée) · glisser haut = réserve.
    var G = { active: false, startX: 0, startY: 0, lastX: 0, lastY: 0, t: 0, moved: false };
    canvas.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse') return;
        G.active = true; G.moved = false;
        G.startX = G.lastX = e.clientX;
        G.startY = G.lastY = e.clientY;
        G.t = Date.now();
    });
    canvas.addEventListener('pointermove', function (e) {
        if (!G.active) return;
        var dx = e.clientX - G.lastX, dy = e.clientY - G.lastY;
        G.lastX = e.clientX; G.lastY = e.clientY;
        // Pas de déplacement ≈ 0,8 case : suit le doigt case par case.
        var stepX = Math.max(16, canvas.clientWidth / COLS * 0.8);
        if (dx > 0) { while (dx >= stepX) { move(1); dx -= stepX; G.moved = true; } }
        else if (dx < 0) { while (-dx >= stepX) { move(-1); dx += stepX; G.moved = true; } }
        var stepY = 24;
        if (dy > 0) { while (dy >= stepY) { softDrop(); dy -= stepY; G.moved = true; } }
    });
    canvas.addEventListener('pointerup', function (e) {
        if (!G.active) return;
        G.active = false;
        var dx = e.clientX - G.startX, dy = e.clientY - G.startY;
        var dt = Date.now() - G.t;
        if (!G.moved && Math.abs(dx) < 14 && Math.abs(dy) < 14 && dt < 400) {
            if (canAct()) { tryRotate(1); draw(); } // tap = rotation
        } else if (dy < -44) {
            hold(); // glisser vers le haut = réserve
        } else if (dy > 90 && dt < 280) {
            hardDrop(); // coup sec vers le bas = chute instantanée
        }
    });
    canvas.addEventListener('pointercancel', function () { G.active = false; });
    canvas.addEventListener('contextmenu', function (e) { e.preventDefault(); });

    // ================== DÉMARRAGE ==================
    overlayBtn.addEventListener('click', function () {
        if (paused) { togglePause(); } else { newGame(); }
    });
    newBtn.addEventListener('click', newGame);

    showBest();
    board = emptyBoard();
    cur = spawn('T');
    drawNext();
    draw();
    cur = null;
})();
</script>
