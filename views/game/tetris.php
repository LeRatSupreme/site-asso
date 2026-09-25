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
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <a class="btn btn-outline" href="<?= e(url('/jeux')) ?>" style="margin-bottom:0.75rem;text-decoration:none;">← Retour aux jeux</a>
        <span class="eyebrow">Jeu d'arcade</span>
        <h1 class="page-title">Tetris</h1>
        <p class="page-lead">Empile les pièces, complète les lignes et va le plus loin possible&nbsp;!</p>
    </div>
</header>

<section class="section">
    <div class="container game-zone">

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
                    <span class="game-stat-value" id="t-best">—</span>
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
        <div class="tetris-pad" id="t-pad" aria-hidden="true">
            <div class="tetris-pad-row">
                <button type="button" data-act="left" class="tetris-pad-btn">◀</button>
                <button type="button" data-act="rotate" class="tetris-pad-btn">⟳</button>
                <button type="button" data-act="right" class="tetris-pad-btn">▶</button>
            </div>
            <div class="tetris-pad-row">
                <button type="button" data-act="down" class="tetris-pad-btn">▼</button>
                <button type="button" data-act="drop" class="tetris-pad-btn">⤓</button>
                <button type="button" data-act="hold" class="tetris-pad-btn">⇄</button>
            </div>
        </div>

        <p class="tetris-help">P&nbsp;: pause · Un niveau tous les 10 lignes, la vitesse augmente&nbsp;!</p>
    </div>
</section>

<style>
.game-zone { max-width: 720px; }

.game-bar {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
    gap: 1rem; margin-bottom: 1.5rem;
}
.game-stats { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.game-stat {
    background: rgba(255,255,255,0.03); border: 1px solid var(--border);
    border-radius: 10px; padding: 0.5rem 0.85rem; text-align: center; min-width: 70px;
}
.game-stat-label { display: block; font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); font-weight: 700; }
.game-stat-value { display: block; font-size: 1.2rem; font-weight: 800; color: var(--primary); }
.game-controls { display: flex; gap: 0.5rem; align-items: center; }

.tetris-layout { display: flex; gap: 1.25rem; justify-content: center; align-items: flex-start; }
.tetris-wrap { position: relative; }
#t-canvas {
    display: block; width: min(360px, 88vw); height: auto; border-radius: 14px;
    border: 2px solid var(--border);
    touch-action: none;
    box-shadow: 0 14px 44px rgba(0,0,0,0.35);
}

.tetris-overlay {
    position: absolute; inset: 0; display: grid; place-items: center;
    background: rgba(5, 12, 24, 0.72); backdrop-filter: blur(3px);
    border-radius: 14px; animation: tFade 0.25s;
}
.tetris-overlay[hidden] { display: none; }
.tetris-overlay-card { text-align: center; padding: 1rem; }
.tetris-overlay-card h2 { font-size: 1.6rem; font-weight: 900; color: var(--primary); margin: 0 0 0.5rem; }
.tetris-overlay-card p { color: var(--muted); margin: 0 0 1rem; line-height: 1.6; font-size: 0.88rem; }
@keyframes tFade { from { opacity: 0; } }

.tetris-side { display: flex; flex-direction: column; gap: 1rem; }
.tetris-mini {
    background: rgba(255,255,255,0.03); border: 1px solid var(--border);
    border-radius: 12px; padding: 0.6rem; text-align: center;
}
.tetris-mini-label { display: block; font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 800; margin-bottom: 0.35rem; }
.tetris-mini canvas { display: block; margin: 0 auto; width: 84px; height: 84px; }

.tetris-pad { display: none; margin: 1.25rem auto 0; width: max-content; }
.tetris-pad-row { display: flex; gap: 0.5rem; justify-content: center; margin-bottom: 0.5rem; }
.tetris-pad-btn {
    width: 58px; height: 52px; border-radius: 14px; font-size: 1.1rem;
    border: 1px solid var(--border-strong); background: rgba(255,255,255,0.05);
    color: var(--foreground); cursor: pointer;
}
.tetris-pad-btn:active { background: var(--primary); color: #0a1628; }
@media (pointer: coarse) { .tetris-pad { display: block; } }

.tetris-help { text-align: center; color: var(--muted); font-size: 0.85rem; margin-top: 1.25rem; }

@media (max-width: 520px) {
    .tetris-side { gap: 0.6rem; }
    .tetris-mini { padding: 0.4rem; }
    .tetris-mini canvas { width: 56px; height: 56px; }
}
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
    function shade(hex, f) {
        // f > 0 éclaircit, f < 0 assombrit.
        var n = parseInt(hex.slice(1), 16);
        var r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
        if (f >= 0) {
            r = Math.round(lerp(r, 255, f)); g = Math.round(lerp(g, 255, f)); b = Math.round(lerp(b, 255, f));
        } else {
            r = Math.round(lerp(r, 0, -f)); g = Math.round(lerp(g, 0, -f)); b = Math.round(lerp(b, 0, -f));
        }
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }

    function lerp(a, b, t) { return a + (b - a) * t; }

    // Bloc brillant : dégradé vertical, reflet spéculaire, éclat et liseré interne.
    // alpha : opacité (blocs posés atténués) · glow : halo lumineux (pièce qui tombe).
    function drawBlock(c2, x, y, size, color, alpha, glow) {
        c2.save();
        if (alpha !== undefined) { c2.globalAlpha = alpha; }

        var inset = Math.max(1, size * 0.05);
        var r = size * 0.24;
        var bw = size - inset * 2;

        // Halo réservé à la pièce qui tombe.
        if (glow) {
            c2.shadowColor = color;
            c2.shadowBlur = size * 0.26;
        }

        // Corps : dégradé doux, presque uni.
        var grad = c2.createLinearGradient(x, y, x, y + size);
        grad.addColorStop(0, shade(color, 0.20));
        grad.addColorStop(0.5, color);
        grad.addColorStop(1, shade(color, -0.16));
        c2.fillStyle = grad;
        roundPath(c2, x + inset, y + inset, bw, bw, r);
        c2.fill();
        c2.shadowBlur = 0;

        // Fine arête claire en haut.
        c2.fillStyle = 'rgba(255,255,255,0.28)';
        roundPath(c2, x + inset + r * 0.4, y + inset + bw * 0.05, bw - r * 0.8, bw * 0.10, bw * 0.05);
        c2.fill();

        // Ombre douce en bas.
        c2.fillStyle = 'rgba(0,0,0,0.22)';
        roundPath(c2, x + inset + r * 0.4, y + inset + bw * 0.85, bw - r * 0.8, bw * 0.10, bw * 0.05);
        c2.fill();

        // Liseré net.
        c2.strokeStyle = shade(color, -0.42);
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

        // Pièce qui tombe : pleine luminosité + halo coloré → toujours lisible.
        if (cur) {
            for (var y2 = 0; y2 < cur.m.length; y2++) {
                for (var x2 = 0; x2 < cur.m[y2].length; x2++) {
                    if (cur.m[y2][x2] && cur.y + y2 >= 0) {
                        drawBlock(ctx, (cur.x + x2) * CELL, (cur.y + y2) * CELL, CELL, cur.c, undefined, true);
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

    // ================== PAD TACTILE ==================
    var repeatInt = null;
    function padAct(act) {
        switch (act) {
            case 'left': move(-1); break;
            case 'right': move(1); break;
            case 'down': softDrop(); break;
            case 'drop': hardDrop(); break;
            case 'rotate': if (running && !paused && !flashRows) { tryRotate(1); draw(); } break;
            case 'hold': hold(); break;
        }
    }
    pad.addEventListener('click', function (e) {
        var btn = e.target.closest('.tetris-pad-btn');
        if (btn) { padAct(btn.dataset.act); }
    });
    pad.addEventListener('touchstart', function (e) {
        var btn = e.target.closest('.tetris-pad-btn');
        if (!btn) return;
        var act = btn.dataset.act;
        if (act === 'left' || act === 'right' || act === 'down') {
            clearInterval(repeatInt);
            repeatInt = setInterval(function () { padAct(act); }, 110);
        }
    }, { passive: true });
    ['touchend', 'touchcancel'].forEach(function (ev) {
        pad.addEventListener(ev, function () { clearInterval(repeatInt); }, { passive: true });
    });

    // ================== SWIPE ==================
    var touchStart = null;
    canvas.addEventListener('touchstart', function (e) {
        touchStart = { x: e.touches[0].clientX, y: e.touches[0].clientY };
    }, { passive: true });
    canvas.addEventListener('touchmove', function (e) { e.preventDefault(); }, { passive: false });
    canvas.addEventListener('touchend', function (e) {
        if (!touchStart) return;
        var dx = e.changedTouches[0].clientX - touchStart.x;
        var dy = e.changedTouches[0].clientY - touchStart.y;
        if (Math.abs(dx) < 24 && Math.abs(dy) < 24) {
            if (running && !paused && !flashRows) { tryRotate(1); draw(); } // tap = rotation
        } else if (Math.abs(dx) > Math.abs(dy)) {
            move(dx > 0 ? 1 : -1);
        } else if (dy > 40) {
            hardDrop();
        }
        touchStart = null;
    });

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
