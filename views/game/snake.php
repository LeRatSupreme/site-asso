<?php

declare(strict_types=1);

/**
 * Page de jeu — Snake AEIC.
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
        <h1 class="page-title">Snake</h1>
        <p class="page-lead">Mange un maximum de fruits sans te mordre ni percuter les murs&nbsp;!</p>
    </div>
</header>

<section class="section">
    <div class="container game-zone">

        <!-- Barre de jeu -->
        <div class="game-bar">
            <div class="game-stats">
                <div class="game-stat">
                    <span class="game-stat-label">Score</span>
                    <span class="game-stat-value" id="s-score">0</span>
                </div>
                <div class="game-stat">
                    <span class="game-stat-label">Longueur</span>
                    <span class="game-stat-value" id="s-len">3</span>
                </div>
                <div class="game-stat">
                    <span class="game-stat-label">Record</span>
                    <span class="game-stat-value" id="s-best">—</span>
                </div>
            </div>
            <div class="game-controls">
                <select id="s-speed" aria-label="Vitesse">
                    <option value="lent">Lent</option>
                    <option value="normal" selected>Normal</option>
                    <option value="rapide">Rapide</option>
                </select>
                <button class="btn btn-primary btn-sm" id="s-new">Nouvelle partie</button>
            </div>
        </div>

        <!-- Plateau -->
        <div class="snake-wrap">
            <canvas id="s-canvas" width="420" height="420" aria-label="Plateau de Snake"></canvas>
            <div class="snake-overlay" id="s-overlay">
                <div class="snake-overlay-card">
                    <h2 id="s-overlay-title">Snake</h2>
                    <p id="s-overlay-text">Flèches ou ZQSD pour jouer.<br>Sur mobile&nbsp;: glisse ton doigt ou utilise le pad.</p>
                    <button class="btn btn-primary" id="s-overlay-btn">Jouer</button>
                </div>
            </div>
        </div>

        <!-- Contrôles tactiles -->
        <div class="snake-pad" id="s-pad" aria-hidden="true">
            <button type="button" data-dir="up" class="snake-pad-btn">▲</button>
            <div class="snake-pad-row">
                <button type="button" data-dir="left" class="snake-pad-btn">◀</button>
                <button type="button" data-dir="down" class="snake-pad-btn">▼</button>
                <button type="button" data-dir="right" class="snake-pad-btn">▶</button>
            </div>
        </div>

        <p class="snake-help">Espace ou P&nbsp;: pause · Change de vitesse entre les parties.</p>
    </div>
</section>

<style>
.game-zone { max-width: 560px; }

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
.game-controls select {
    background: rgba(255,255,255,0.05); border: 1px solid var(--border);
    border-radius: 8px; color: var(--foreground); padding: 0.42rem 0.6rem; font-size: 0.85rem;
}

.snake-wrap { position: relative; max-width: 420px; margin: 0 auto; }
#s-canvas {
    display: block; width: 100%; height: auto; border-radius: 14px;
    background: rgba(255,255,255,0.03);
    border: 2px solid var(--border);
    touch-action: none;
}

.snake-overlay {
    position: absolute; inset: 0; display: grid; place-items: center;
    background: rgba(5, 12, 24, 0.72); backdrop-filter: blur(3px);
    border-radius: 14px; animation: sFade 0.25s;
}
.snake-overlay[hidden] { display: none; }
.snake-overlay-card { text-align: center; padding: 1.5rem; }
.snake-overlay-card h2 {
    font-size: 1.7rem; font-weight: 900; color: var(--primary); margin: 0 0 0.5rem;
}
.snake-overlay-card p { color: var(--muted); margin: 0 0 1.1rem; line-height: 1.6; }
@keyframes sFade { from { opacity: 0; } }

.snake-pad { display: none; margin: 1.25rem auto 0; width: max-content; text-align: center; }
.snake-pad-row { display: flex; gap: 0.5rem; justify-content: center; margin-top: 0.5rem; }
.snake-pad-btn {
    width: 58px; height: 58px; border-radius: 14px; font-size: 1.15rem;
    border: 1px solid var(--border-strong); background: rgba(255,255,255,0.05);
    color: var(--foreground); cursor: pointer;
}
.snake-pad-btn:active { background: var(--primary); color: #0a1628; }
@media (pointer: coarse) { .snake-pad { display: block; } }

.snake-help { text-align: center; color: var(--muted); font-size: 0.85rem; margin-top: 1.25rem; }
</style>

<script>
(function () {
    var SUBMIT_URL = <?= json_encode($submitUrl) ?>;
    var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    var IS_LOGGED_IN = <?= json_encode((bool) $isLoggedIn) ?>;

    var GRID = 20;                 // 20 × 20 cases
    var canvas = document.getElementById('s-canvas');
    var ctx = canvas.getContext('2d');
    var CELL = canvas.width / GRID;

    var scoreEl = document.getElementById('s-score');
    var lenEl = document.getElementById('s-len');
    var bestEl = document.getElementById('s-best');
    var speedEl = document.getElementById('s-speed');
    var newBtn = document.getElementById('s-new');
    var overlay = document.getElementById('s-overlay');
    var overlayTitle = document.getElementById('s-overlay-title');
    var overlayText = document.getElementById('s-overlay-text');
    var overlayBtn = document.getElementById('s-overlay-btn');
    var pad = document.getElementById('s-pad');

    var snake = [], dir = {x:1,y:0}, nextDir = null, food = null;
    var score = 0, timer = null, running = false, paused = false;
    var BASE_SPEED = { lent: 200, normal: 140, rapide: 90 };
    var tickMs = 140;

    function bestKey() { return 'aeic_snake_best_' + speedEl.value; }

    function showBest() {
        var best = localStorage.getItem(bestKey());
        bestEl.textContent = best ? best : '—';
    }

    function freeCell() {
        var p;
        do {
            p = { x: Math.floor(Math.random() * GRID), y: Math.floor(Math.random() * GRID) };
        } while (snake.some(function (s) { return s.x === p.x && s.y === p.y; }));
        return p;
    }

    function newGame() {
        clearInterval(timer);
        snake = [{x:10,y:10},{x:9,y:10},{x:8,y:10}];
        dir = {x:1,y:0}; nextDir = null;
        score = 0; paused = false;
        tickMs = BASE_SPEED[speedEl.value] || 140;
        scoreEl.textContent = '0';
        lenEl.textContent = snake.length;
        food = freeCell();
        running = true;
        overlay.hidden = true;
        showBest();
        draw();
        timer = setInterval(tick, tickMs);
    }

    function tick() {
        if (!running || paused) return;
        if (nextDir && (nextDir.x !== -dir.x || nextDir.y !== -dir.y)) {
            dir = nextDir; nextDir = null;
        }
        var head = { x: snake[0].x + dir.x, y: snake[0].y + dir.y };

        // Murs ou soi-même : game over.
        if (head.x < 0 || head.y < 0 || head.x >= GRID || head.y >= GRID ||
            snake.some(function (s) { return s.x === head.x && s.y === head.y; })) {
            return gameOver();
        }

        snake.unshift(head);

        if (head.x === food.x && head.y === food.y) {
            score++;
            scoreEl.textContent = score;
            lenEl.textContent = snake.length;
            food = freeCell();
            // Accélération douce tous les 3 fruits (plancher 60 ms).
            if (score % 3 === 0 && tickMs > 60) {
                tickMs = Math.max(60, tickMs - 6);
                clearInterval(timer);
                timer = setInterval(tick, tickMs);
            }
        } else {
            snake.pop();
        }
        draw();
    }

    function draw() {
        // Fond.
        ctx.fillStyle = 'rgba(255,255,255,0.02)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // Grille discrète.
        ctx.strokeStyle = 'rgba(255,255,255,0.04)';
        ctx.lineWidth = 1;
        for (var i = 1; i < GRID; i++) {
            ctx.beginPath(); ctx.moveTo(i * CELL, 0); ctx.lineTo(i * CELL, canvas.height); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(0, i * CELL); ctx.lineTo(canvas.width, i * CELL); ctx.stroke();
        }

        // Fruit.
        if (food) {
            ctx.fillStyle = '#e2555c';
            ctx.beginPath();
            ctx.arc(food.x * CELL + CELL / 2, food.y * CELL + CELL / 2, CELL * 0.32, 0, Math.PI * 2);
            ctx.fill();
        }

        // Serpent.
        for (var j = snake.length - 1; j >= 0; j--) {
            var s = snake[j];
            var t = 1 - j / (snake.length + 4);
            ctx.fillStyle = j === 0 ? '#7fd0e4' : 'rgba(72,189,211,' + (0.35 + 0.55 * t).toFixed(2) + ')';
            roundRect(s.x * CELL + 1.5, s.y * CELL + 1.5, CELL - 3, CELL - 3, 4);
        }
    }

    function roundRect(x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
        ctx.fill();
    }

    function gameOver() {
        running = false;
        clearInterval(timer);
        var prev = parseInt(localStorage.getItem(bestKey()) || '0', 10);
        var isBest = score > prev;
        if (isBest) { localStorage.setItem(bestKey(), String(score)); }
        overlayTitle.textContent = isBest ? 'Nouveau record !' : 'Game over';
        overlayText.innerHTML = 'Score : <strong>' + score + '</strong> fruit' + (score > 1 ? 's' : '') +
            ' · longueur ' + snake.length + (isBest ? ' 🏆' : '');
        overlayBtn.textContent = 'Rejouer';
        overlay.hidden = false;
        showBest();
        submitScore();
    }

    function setDir(name) {
        var map = {
            up:    {x:0,y:-1},
            down:  {x:0,y:1},
            left:  {x:-1,y:0},
            right: {x:1,y:0}
        };
        if (map[name]) { nextDir = map[name]; }
    }

    function togglePause() {
        if (!running) return;
        paused = !paused;
        if (paused) {
            overlayTitle.textContent = 'Pause';
            overlayText.textContent = 'Espace ou P pour reprendre.';
            overlayBtn.textContent = 'Reprendre';
            overlay.hidden = false;
        } else {
            overlay.hidden = true;
        }
    }

    function submitScore() {
        if (!IS_LOGGED_IN || score <= 0) return;
        fetch(SUBMIT_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            credentials: 'same-origin',
            body: JSON.stringify({
                game: 'snake',
                mode: speedEl.value,
                score: score,
                _csrf: CSRF_TOKEN
            })
        }).catch(function () { /* silencieux */ });
    }

    // --- Entrées clavier ---
    var KEY_DIRS = {
        ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right',
        w: 'up', s: 'down', a: 'left', d: 'right',
        z: 'up', q: 'left'
    };
    document.addEventListener('keydown', function (e) {
        if (KEY_DIRS[e.key]) {
            e.preventDefault();
            if (running && !paused) { setDir(KEY_DIRS[e.key]); }
        } else if (e.key === ' ' || e.key === 'p' || e.key === 'P') {
            e.preventDefault();
            togglePause();
        }
    });

    // --- Pad tactile ---
    pad.addEventListener('click', function (e) {
        var btn = e.target.closest('.snake-pad-btn');
        if (btn && running && !paused) { setDir(btn.dataset.dir); }
    });

    // --- Swipe ---
    var touchStart = null;
    canvas.addEventListener('touchstart', function (e) {
        touchStart = { x: e.touches[0].clientX, y: e.touches[0].clientY };
    }, { passive: true });
    canvas.addEventListener('touchmove', function (e) {
        e.preventDefault();
    }, { passive: false });
    canvas.addEventListener('touchend', function (e) {
        if (!touchStart || !running || paused) return;
        var dx = e.changedTouches[0].clientX - touchStart.x;
        var dy = e.changedTouches[0].clientY - touchStart.y;
        if (Math.abs(dx) < 24 && Math.abs(dy) < 24) return;
        setDir(Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up'));
        touchStart = null;
    });

    // --- Overlay / boutons ---
    overlayBtn.addEventListener('click', function () {
        if (paused) { togglePause(); } else { newGame(); }
    });
    newBtn.addEventListener('click', newGame);
    speedEl.addEventListener('change', showBest);

    showBest();
    draw();
})();
</script>
