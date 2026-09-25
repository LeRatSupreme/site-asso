<?php

declare(strict_types=1);

/**
 * Page de jeu — Snake AEIC.
 *
 * Modes façon Google Snake : murs / portail / obstacles,
 * 3 vitesses, 1 à 3 fruits simultanés, fruits variés et dorés.
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
        <span class="eyebrow">Jeu d'arcade</span>
        <h1 class="page-title">Snake</h1>
        <p class="page-lead">Mange un maximum de fruits sans te mordre&nbsp;! Murs, portails ou obstacles&nbsp;: à toi de choisir ton terrain.</p>
    </div>
</header>

<section class="section">
    <div class="container game-zone">

        <a class="btn btn-outline btn-sm" style="display:inline-block;margin-bottom:1.25rem;text-decoration:none;" href="<?= e(url('/jeux')) ?>">← Retour aux jeux</a>

        <!-- Réglages façon Google Snake -->
        <div class="snake-settings">
            <div class="snake-setting-row">
                <span class="snake-setting-label">Terrain</span>
                <div class="snake-pills" id="pills-mode">
                    <button type="button" class="snake-pill" data-mode="murs">🧱 Murs</button>
                    <button type="button" class="snake-pill" data-mode="portail">🌀 Portail</button>
                    <button type="button" class="snake-pill" data-mode="obstacles">🚧 Obstacles</button>
                </div>
            </div>
            <div class="snake-setting-row">
                <span class="snake-setting-label">Vitesse</span>
                <div class="snake-pills" id="pills-speed">
                    <button type="button" class="snake-pill" data-speed="lent">🐌 Lent</button>
                    <button type="button" class="snake-pill" data-speed="normal">🐍 Normal</button>
                    <button type="button" class="snake-pill" data-speed="rapide">⚡ Rapide</button>
                </div>
            </div>
            <div class="snake-setting-row">
                <span class="snake-setting-label">Fruits</span>
                <div class="snake-pills" id="pills-fruits">
                    <button type="button" class="snake-pill" data-fruits="1">1</button>
                    <button type="button" class="snake-pill" data-fruits="2">2</button>
                    <button type="button" class="snake-pill" data-fruits="3">3</button>
                </div>
            </div>
        </div>

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
                <button class="btn btn-primary btn-sm" id="s-new">Nouvelle partie</button>
            </div>
        </div>

        <!-- Plateau -->
        <div class="snake-wrap">
            <canvas id="s-canvas" aria-label="Plateau de Snake"></canvas>
            <div class="snake-overlay" id="s-overlay">
                <div class="snake-overlay-card">
                    <h2 id="s-overlay-title">Snake</h2>
                    <p id="s-overlay-text">Flèches ou ZQSD pour jouer.<br>Sur mobile&nbsp;: glisse ton doigt ou utilise le pad.<br>Les fruits dorés ⭐ rapportent 5 points, mais disparaissent vite&nbsp;!</p>
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

        <p class="snake-help">Espace ou P&nbsp;: pause · Les records sont sauvegardés par terrain, vitesse et nombre de fruits.</p>
    </div>
</section>

<style>
.game-zone { max-width: 760px; }

/* Réglages */
.snake-settings {
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border-strong);
    border-radius: 0.75rem;
    padding: 0.9rem 1rem;
    margin-bottom: 1.5rem;
    display: flex; flex-direction: column; gap: 0.65rem;
}
.snake-setting-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.snake-setting-label {
    font-size: 0.72rem; font-weight: 800; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.06em; min-width: 64px;
}
.snake-pills { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.snake-pill {
    padding: 0.35rem 0.8rem;
    border-radius: 999px;
    cursor: pointer;
    border: 2px solid var(--border-strong);
    background: rgba(255,255,255,0.02);
    color: var(--muted);
    font-weight: 700;
    font-size: 0.78rem;
    transition: all 0.15s;
}
.snake-pill.active {
    background: var(--primary);
    color: #0a1628;
    border-color: var(--primary);
}

/* Barre de jeu */
.game-bar {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
    gap: 1rem; margin-bottom: 1.25rem;
}
.game-stats { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.game-stat {
    background: rgba(255,255,255,0.03); border: 1px solid var(--border);
    border-radius: 10px; padding: 0.5rem 0.85rem; text-align: center; min-width: 72px;
}
.game-stat-label { display: block; font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); font-weight: 700; }
.game-stat-value { display: block; font-size: 1.25rem; font-weight: 800; color: var(--primary); }
.game-controls { display: flex; gap: 0.5rem; align-items: center; }

/* Plateau agrandi */
.snake-wrap { position: relative; max-width: 620px; margin: 0 auto; }
#s-canvas {
    display: block; width: 100%; height: auto; border-radius: 16px;
    background: rgba(255,255,255,0.03);
    border: 2px solid var(--border);
    touch-action: none;
    box-shadow: 0 14px 44px rgba(0,0,0,0.35);
}

.snake-overlay {
    position: absolute; inset: 0; display: grid; place-items: center;
    background: rgba(5, 12, 24, 0.72); backdrop-filter: blur(3px);
    border-radius: 16px; animation: sFade 0.25s;
}
.snake-overlay[hidden] { display: none; }
.snake-overlay-card { text-align: center; padding: 1.5rem; }
.snake-overlay-card h2 {
    font-size: 1.7rem; font-weight: 900; color: var(--primary); margin: 0 0 0.5rem;
}
.snake-overlay-card p { color: var(--muted); margin: 0 0 1.1rem; line-height: 1.65; }
@keyframes sFade { from { opacity: 0; } }

.snake-pad { display: none; margin: 1.25rem auto 0; width: max-content; text-align: center; }
.snake-pad-row { display: flex; gap: 0.5rem; justify-content: center; margin-top: 0.5rem; }
.snake-pad-btn {
    width: 60px; height: 60px; border-radius: 14px; font-size: 1.15rem;
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

    // ================== CONFIG ==================
    var BOARD = 600;               // taille logique du canvas (px)
    var GRID = 24;                 // 24 × 24 cases
    var CELL = BOARD / GRID;
    var BASE_SPEED = { lent: 210, normal: 145, rapide: 95 };
    var GOLD_LIFETIME = 6500;      // ms avant disparition du fruit doré
    var GOLD_VALUE = 5;

    // Fruits variés : emoji + valeur en points.
    var FRUITS = [
        { e: '🍎', p: 1 }, { e: '🍊', p: 1 }, { e: '🍌', p: 1 },
        { e: '🍓', p: 2 }, { e: '🍇', p: 2 }, { e: '🥝', p: 2 }, { e: '🍑', p: 2 },
        { e: '🍒', p: 3 }, { e: '🍍', p: 3 }, { e: '🍉', p: 3 }
    ];

    // ================== DOM ==================
    var canvas = document.getElementById('s-canvas');
    var ctx = canvas.getContext('2d');
    var scoreEl = document.getElementById('s-score');
    var lenEl = document.getElementById('s-len');
    var bestEl = document.getElementById('s-best');
    var newBtn = document.getElementById('s-new');
    var overlay = document.getElementById('s-overlay');
    var overlayTitle = document.getElementById('s-overlay-title');
    var overlayText = document.getElementById('s-overlay-text');
    var overlayBtn = document.getElementById('s-overlay-btn');
    var pad = document.getElementById('s-pad');
    var pillsMode = document.getElementById('pills-mode');
    var pillsSpeed = document.getElementById('pills-speed');
    var pillsFruits = document.getElementById('pills-fruits');

    // HiDPI : netteté du rendu.
    var dpr = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = BOARD * dpr;
    canvas.height = BOARD * dpr;
    ctx.scale(dpr, dpr);

    // ================== ÉTAT ==================
    var settings = loadSettings();
    var snake = [], dir = { x: 1, y: 0 }, nextDir = null;
    var prevSnake = [], lastTick = 0;   // interpolation fluide entre deux ticks
    var foods = [], golden = null, obstacles = [];
    var fx = [], chompUntil = 0;        // éclaboussures, anneaux, mâchonnement
    var score = 0, timer = null, running = false, paused = false, tickMs = 145;
    var pops = [];                 // textes flottants « +2 »

    function loadSettings() {
        var s = { mode: 'murs', speed: 'normal', fruits: 1 };
        try {
            var raw = localStorage.getItem('aeic-snake-settings');
            if (raw) {
                var p = JSON.parse(raw);
                if (['murs', 'portail', 'obstacles'].indexOf(p.mode) >= 0) s.mode = p.mode;
                if (['lent', 'normal', 'rapide'].indexOf(p.speed) >= 0) s.speed = p.speed;
                if ([1, 2, 3].indexOf(p.fruits) >= 0) s.fruits = p.fruits;
            }
        } catch (e) { /* réglages par défaut */ }
        return s;
    }
    function saveSettings() {
        try { localStorage.setItem('aeic-snake-settings', JSON.stringify(settings)); } catch (e) {}
    }

    function bestKey() {
        return 'aeic_snake_best_' + settings.mode + '_' + settings.speed + '_' + settings.fruits;
    }
    function showBest() {
        var best = localStorage.getItem(bestKey());
        bestEl.textContent = best ? best : '—';
    }

    function reflectSettings() {
        pillsMode.querySelectorAll('.snake-pill').forEach(function (b) {
            b.classList.toggle('active', b.dataset.mode === settings.mode);
        });
        pillsSpeed.querySelectorAll('.snake-pill').forEach(function (b) {
            b.classList.toggle('active', b.dataset.speed === settings.speed);
        });
        pillsFruits.querySelectorAll('.snake-pill').forEach(function (b) {
            b.classList.toggle('active', parseInt(b.dataset.fruits, 10) === settings.fruits);
        });
    }

    // ================== PLATEAU ==================
    function cellBlocked(x, y) {
        if (snake.some(function (s) { return s.x === x && s.y === y; })) return true;
        if (foods.some(function (f) { return f.x === x && f.y === y; })) return true;
        if (obstacles.some(function (o) { return o.x === x && o.y === y; })) return true;
        if (golden && golden.x === x && golden.y === y) return true;
        return false;
    }

    function freeCell() {
        var p, guard = 0;
        do {
            p = { x: Math.floor(Math.random() * GRID), y: Math.floor(Math.random() * GRID) };
            guard++;
        } while (cellBlocked(p.x, p.y) && guard < 500);
        return p;
    }

    function makeFood() {
        var f = freeCell();
        f.type = FRUITS[Math.floor(Math.random() * FRUITS.length)];
        f.born = performance.now();
        return f;
    }

    // Obstacles : petits murs de 1 à 3 cases, éloignés du départ du serpent.
    function makeObstacles() {
        obstacles = [];
        var target = 12, guard = 0;
        while (obstacles.length < target && guard < 200) {
            guard++;
            var horiz = Math.random() < 0.5;
            var len = 1 + Math.floor(Math.random() * 3);
            var x = 1 + Math.floor(Math.random() * (GRID - 2));
            var y = 1 + Math.floor(Math.random() * (GRID - 2));
            var cells = [];
            for (var i = 0; i < len; i++) {
                cells.push({ x: horiz ? x + i : x, y: horiz ? y : y + i });
            }
            var ok = cells.every(function (c) {
                return c.x > 0 && c.x < GRID - 1 && c.y > 0 && c.y < GRID - 1 &&
                    Math.abs(c.y - 12) > 1 && !cellBlocked(c.x, c.y);
            });
            if (ok) { obstacles = obstacles.concat(cells); }
        }
    }

    // ================== CYCLE DE JEU ==================
    function newGame() {
        clearInterval(timer);
        snake = [{ x: 10, y: 12 }, { x: 9, y: 12 }, { x: 8, y: 12 }];
        dir = { x: 1, y: 0 }; nextDir = null;
        prevSnake = snake.map(function (s) { return { x: s.x, y: s.y }; });
        lastTick = performance.now();
        score = 0; paused = false; pops = [];
        fx = []; chompUntil = 0;
        golden = null; foods = [];
        tickMs = BASE_SPEED[settings.speed] || 145;
        obstacles = settings.mode === 'obstacles' ? [] : [];
        if (settings.mode === 'obstacles') { makeObstacles(); }
        for (var i = 0; i < settings.fruits; i++) { foods.push(makeFood()); }
        scoreEl.textContent = '0';
        lenEl.textContent = snake.length;
        running = true;
        overlay.hidden = true;
        showBest();
        draw();
        timer = setInterval(tick, tickMs);
    }

    function wrap(v) {
        return settings.mode === 'murs' ? v : ((v % GRID) + GRID) % GRID;
    }

    function tick() {
        if (!running || paused) return;
        // Position avant le déplacement : sert de point de départ à l'interpolation.
        prevSnake = snake.map(function (s) { return { x: s.x, y: s.y }; });
        if (nextDir && (nextDir.x !== -dir.x || nextDir.y !== -dir.y)) {
            dir = nextDir; nextDir = null;
        }
        var head = { x: wrap(snake[0].x + dir.x), y: wrap(snake[0].y + dir.y) };

        // Murs : game over en mode « murs » uniquement.
        if (settings.mode === 'murs' &&
            (head.x < 0 || head.y < 0 || head.x >= GRID || head.y >= GRID)) {
            return gameOver();
        }
        if (snake.some(function (s) { return s.x === head.x && s.y === head.y; })) {
            return gameOver();
        }
        if (obstacles.some(function (o) { return o.x === head.x && o.y === head.y; })) {
            return gameOver();
        }

        snake.unshift(head);

        // Fruit normal mangé ?
        var idx = foods.findIndex(function (f) { return f.x === head.x && f.y === head.y; });
        if (idx >= 0) {
            var f = foods[idx];
            addScore(f.type.p, f);
            foods.splice(idx, 1);
            if (foods.length < settings.fruits) { foods.push(makeFood()); }
            maybeGolden();
            speedUp();
        } else if (golden && golden.x === head.x && golden.y === head.y) {
            addScore(GOLD_VALUE, golden);
            golden = null;
            speedUp();
        } else {
            snake.pop();
        }

        // Fruit doré expiré ?
        if (golden && performance.now() > golden.until) { golden = null; }

        lastTick = performance.now();
        lenEl.textContent = snake.length;
        draw();
    }

    function addScore(points, at) {
        score += points;
        scoreEl.textContent = score;
        pops.push({
            x: at.x * CELL + CELL / 2,
            y: at.y * CELL,
            text: '+' + points,
            until: performance.now() + 750,
            gold: points >= GOLD_VALUE
        });

        // Animations de « dégustation » : anneau, éclaboussures, mâchonnement.
        var cx = at.x * CELL + CELL / 2, cy = at.y * CELL + CELL / 2;
        var col = points >= GOLD_VALUE ? '#f5c518' : '#7fd0e4';
        var now = performance.now();
        fx.push({ kind: 'ring', x: cx, y: cy, born: now, color: col });
        for (var k = 0; k < 8; k++) {
            var ang = Math.random() * Math.PI * 2;
            var sp = CELL * (0.9 + Math.random() * 1.5);
            fx.push({
                kind: 'p', x: cx, y: cy,
                vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp,
                r: 1.5 + Math.random() * 2,
                born: now, color: col
            });
        }
        chompUntil = now + 170;
    }

    function speedUp() {
        if (score % 3 === 0 && tickMs > 65) {
            tickMs = Math.max(65, tickMs - 6);
            clearInterval(timer);
            timer = setInterval(tick, tickMs);
        }
    }

    function maybeGolden() {
        if (golden || Math.random() > 0.22) return;
        var p = freeCell();
        golden = {
            x: p.x, y: p.y,
            until: performance.now() + GOLD_LIFETIME
        };
    }

    // ================== RENDU ==================
    function draw() {
        var now = performance.now();

        // Fond opaque (jamais de calque translucide : aucune trace résiduelle).
        ctx.fillStyle = '#0c1728';
        ctx.fillRect(0, 0, BOARD, BOARD);

        // Damier discret.
        ctx.fillStyle = '#101f33';
        for (var y = 0; y < GRID; y++) {
            for (var x = 0; x < GRID; x++) {
                if ((x + y) % 2 === 0) { ctx.fillRect(x * CELL, y * CELL, CELL, CELL); }
            }
        }

        drawObstacles();
        for (var i = 0; i < foods.length; i++) { drawFood(foods[i], now); }
        drawGolden(now);
        drawSnake(now);
        drawFx(now);
        drawPops(now);
    }

    // Boucle de rendu continue : le serpent glisse entre les ticks logiques,
    // les fruits pulsent et les textes flottants s'animent à 60 fps.
    function renderLoop() {
        draw();
        requestAnimationFrame(renderLoop);
    }

    function drawObstacles() {
        for (var i = 0; i < obstacles.length; i++) {
            var o = obstacles[i];
            ctx.fillStyle = '#5b6b85';
            rr(o.x * CELL + 1, o.y * CELL + 1, CELL - 2, CELL - 2, 5);
            ctx.fillStyle = 'rgba(255,255,255,0.15)';
            ctx.fillRect(o.x * CELL + 3, o.y * CELL + 3, CELL - 6, (CELL - 6) * 0.3);
        }
    }

    function drawFood(f, now) {
        var pulse = 1 + 0.07 * Math.sin((now - f.born) / 260);
        var size = CELL * 0.82 * pulse;
        ctx.save();
        ctx.font = size + 'px "Segoe UI Emoji","Apple Color Emoji","Noto Color Emoji",sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.shadowColor = 'rgba(0,0,0,0.45)';
        ctx.shadowBlur = 6;
        ctx.shadowOffsetY = 2;
        ctx.fillText(f.type.e, f.x * CELL + CELL / 2, f.y * CELL + CELL / 2 + 1);
        ctx.restore();
    }

    function drawGolden(now) {
        if (!golden) return;
        var left = golden.until - now;
        if (left <= 0) return;
        var cx = golden.x * CELL + CELL / 2;
        var cy = golden.y * CELL + CELL / 2;

        ctx.save();
        // Halo pulsé.
        var glow = 0.25 + 0.18 * Math.sin(now / 120);
        ctx.fillStyle = 'rgba(245,197,24,' + glow.toFixed(2) + ')';
        ctx.beginPath();
        ctx.arc(cx, cy, CELL * 0.62, 0, Math.PI * 2);
        ctx.fill();

        ctx.font = (CELL * 0.72) + 'px "Segoe UI Emoji","Apple Color Emoji","Noto Color Emoji",sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('⭐', cx, cy + 1);
        ctx.restore();

        // Anneau de compte à rebours.
        ctx.save();
        ctx.strokeStyle = '#f5c518';
        ctx.lineWidth = 2.5;
        ctx.beginPath();
        ctx.arc(cx, cy, CELL * 0.52, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * (left / GOLD_LIFETIME));
        ctx.stroke();
        ctx.restore();
    }

    function lerp(a, b, t) { return a + (b - a) * t; }

    function bodyColor(t) {
        // t = 0 tête → 1 queue : dégradé cyan → bleu profond.
        var r = Math.round(lerp(127, 46, t));
        var g = Math.round(lerp(208, 111, t));
        var b = Math.round(lerp(228, 143, t));
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }

    // Positions interpolées (en pixels) entre le tick précédent et le tick courant :
    // chaque segment glisse d'une case vers l'avant → mouvement fluide et continu.
    function interpPoints(now) {
        var raw = Math.min(1, (now - lastTick) / tickMs);
        var t = raw * raw * (3 - 2 * raw); // smoothstep : départs/arrivées doux
        return snake.map(function (s, i) {
            var prev = prevSnake[i] || prevSnake[prevSnake.length - 1] || s;
            var dx = s.x - prev.x, dy = s.y - prev.y;
            // Téléportation (portail / croissance) : pas d'interpolation.
            if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
                return { x: s.x * CELL + CELL / 2, y: s.y * CELL + CELL / 2 };
            }
            return {
                x: (prev.x + dx * t) * CELL + CELL / 2,
                y: (prev.y + dy * t) * CELL + CELL / 2
            };
        });
    }

    function drawSnake(now) {
        var n = snake.length;
        if (n === 0) return;

        var pts = interpPoints(now || performance.now());

        // Corps : segments du bout de la queue vers la tête, largeur décroissante
        // et dégradé de couleur → la queue est clairement visible.
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        for (var i = n - 1; i >= 1; i--) {
            var a = pts[i], b = pts[i - 1];
            // Téléportation (portail) : on ne relie pas les deux bords.
            if (Math.abs(a.x - b.x) > CELL * 1.5 || Math.abs(a.y - b.y) > CELL * 1.5) { continue; }
            var t = n > 1 ? i / (n - 1) : 0;                 // 1 = queue, 0 = tête
            var w = CELL * (0.66 - 0.34 * t);
            ctx.strokeStyle = bodyColor(t);
            ctx.lineWidth = w;
            ctx.beginPath();
            ctx.moveTo(a.x, a.y);
            ctx.lineTo(b.x, b.y);
            ctx.stroke();
        }

        // Tête animée : mâchonnement, langue fourchue, clignement, regard.
        var h = pts[0];

        // Fruit le plus proche : excite la langue et attire le regard.
        var target = null, bestD = 1e9;
        foods.forEach(function (f) {
            var d = Math.abs(f.x - snake[0].x) + Math.abs(f.y - snake[0].y);
            if (d < bestD) { bestD = d; target = f; }
        });
        if (golden) {
            var gd = Math.abs(golden.x - snake[0].x) + Math.abs(golden.y - snake[0].y);
            if (gd < bestD) { bestD = gd; target = golden; }
        }
        var excited = target !== null && bestD <= 3 && running && !paused;

        // Mâchonnement : la tête se resserre brièvement après avoir mangé.
        var headR = CELL * 0.46;
        var chompT = (now - (chompUntil - 170)) / 170;
        if (chompT >= 0 && chompT <= 1) { headR *= 1 - 0.16 * Math.sin(chompT * Math.PI); }

        ctx.fillStyle = '#8fe0f0';
        ctx.beginPath();
        ctx.arc(h.x, h.y, headR, 0, Math.PI * 2);
        ctx.fill();

        // Langue fourchue : sortie continue près d'un fruit, sinon tressaillement périodique.
        var flick = 0;
        if (excited) {
            flick = 0.75 + 0.25 * Math.sin(now / 90);
        } else {
            var cyc = now % 2600;
            if (cyc < 420) { flick = Math.sin((cyc / 420) * Math.PI); }
        }
        if (flick > 0.05) {
            var len = CELL * (0.26 + 0.36 * flick);
            var bx = h.x + dir.x * CELL * 0.34, by = h.y + dir.y * CELL * 0.34;
            var tx = bx + dir.x * len, ty = by + dir.y * len;
            var fork = len * 0.4;
            var mx = -dir.y, my = dir.x;
            ctx.strokeStyle = '#e2555c';
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(bx, by);
            ctx.lineTo(tx, ty);
            ctx.moveTo(tx, ty);
            ctx.lineTo(tx + dir.x * fork + mx * fork, ty + dir.y * fork + my * fork);
            ctx.moveTo(tx, ty);
            ctx.lineTo(tx + dir.x * fork - mx * fork, ty + dir.y * fork - my * fork);
            ctx.stroke();
        }

        // Yeux : clignement périodique + pupilles orientées vers le fruit proche.
        var px = -dir.y, py = dir.x; // perpendiculaire
        var blink = (now % 3800) < 150;
        var lookX = 0, lookY = 0;
        if (target) {
            var ldx = target.x - snake[0].x, ldy = target.y - snake[0].y;
            var ll = Math.max(1, Math.abs(ldx) + Math.abs(ldy));
            lookX = ldx / ll; lookY = ldy / ll;
        }
        for (var side = -1; side <= 1; side += 2) {
            var ex = h.x + dir.x * CELL * 0.14 + px * side * CELL * 0.2;
            var ey = h.y + dir.y * CELL * 0.14 + py * side * CELL * 0.2;
            if (blink) {
                ctx.strokeStyle = '#0a1628';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.moveTo(ex - px * CELL * 0.1, ey - py * CELL * 0.1);
                ctx.lineTo(ex + px * CELL * 0.1, ey + py * CELL * 0.1);
                ctx.stroke();
                continue;
            }
            ctx.fillStyle = '#ffffff';
            ctx.beginPath();
            ctx.arc(ex, ey, CELL * 0.13, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#0a1628';
            ctx.beginPath();
            ctx.arc(
                ex + dir.x * CELL * 0.05 + lookX * CELL * 0.045,
                ey + dir.y * CELL * 0.05 + lookY * CELL * 0.045,
                CELL * 0.06, 0, Math.PI * 2
            );
            ctx.fill();
        }
    }

    function drawPops(now) {
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'bottom';
        for (var i = pops.length - 1; i >= 0; i--) {
            var p = pops[i];
            if (now > p.until) { pops.splice(i, 1); continue; }
            var age = 1 - (p.until - now) / 750;
            ctx.globalAlpha = 1 - age;
            ctx.font = '800 ' + (CELL * 0.55) + 'px system-ui, sans-serif';
            ctx.fillStyle = p.gold ? '#f5c518' : '#7fd0e4';
            ctx.fillText(p.text, p.x, p.y - age * CELL * 0.9);
        }
        ctx.restore();
    }

    // Anneau + éclaboussures générés à chaque fruit mangé.
    function drawFx(now) {
        for (var i = fx.length - 1; i >= 0; i--) {
            var p = fx[i];
            var life = p.kind === 'ring' ? 420 : 560;
            var age = (now - p.born) / life;
            if (age >= 1) { fx.splice(i, 1); continue; }
            ctx.save();
            if (p.kind === 'ring') {
                ctx.globalAlpha = (1 - age) * 0.8;
                ctx.strokeStyle = p.color;
                ctx.lineWidth = 2.5;
                ctx.beginPath();
                ctx.arc(p.x, p.y, CELL * (0.3 + 0.9 * age), 0, Math.PI * 2);
                ctx.stroke();
            } else {
                var ease = 1 - Math.pow(1 - age, 2);
                ctx.globalAlpha = 1 - age;
                ctx.fillStyle = p.color;
                ctx.beginPath();
                ctx.arc(p.x + p.vx * ease, p.y + p.vy * ease, p.r * (1 - age * 0.5), 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.restore();
        }
    }

    function rr(x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
        ctx.fill();
    }

    // ================== FIN / PAUSE / SCORE ==================
    function gameOver() {
        running = false;
        clearInterval(timer);
        var prev = parseInt(localStorage.getItem(bestKey()) || '0', 10);
        var isBest = score > prev;
        if (isBest) { localStorage.setItem(bestKey(), String(score)); }
        overlayTitle.textContent = isBest ? 'Nouveau record !' : 'Game over';
        overlayText.innerHTML = 'Score : <strong>' + score + '</strong> · longueur ' + snake.length +
            '<br><span style="font-size:0.85rem;">' + modeLabel() + ' · ' + settings.speed + ' · ' + settings.fruits + ' fruit' + (settings.fruits > 1 ? 's' : '') + '</span>' +
            (isBest ? ' 🏆' : '');
        overlayBtn.textContent = 'Rejouer';
        overlay.hidden = false;
        showBest();
        submitScore();
    }

    function modeLabel() {
        return { murs: 'Murs', portail: 'Portail', obstacles: 'Obstacles' }[settings.mode] || settings.mode;
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
                mode: settings.mode + '-' + settings.speed,
                score: score,
                _csrf: CSRF_TOKEN
            })
        }).catch(function () { /* silencieux */ });
    }

    // ================== ENTRÉES ==================
    function setDir(name) {
        var map = {
            up: { x: 0, y: -1 },
            down: { x: 0, y: 1 },
            left: { x: -1, y: 0 },
            right: { x: 1, y: 0 }
        };
        if (map[name]) { nextDir = map[name]; }
    }

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

    pad.addEventListener('click', function (e) {
        var btn = e.target.closest('.snake-pad-btn');
        if (btn && running && !paused) { setDir(btn.dataset.dir); }
    });

    var touchStart = null;
    canvas.addEventListener('touchstart', function (e) {
        touchStart = { x: e.touches[0].clientX, y: e.touches[0].clientY };
    }, { passive: true });
    canvas.addEventListener('touchmove', function (e) { e.preventDefault(); }, { passive: false });
    canvas.addEventListener('touchend', function (e) {
        if (!touchStart || !running || paused) return;
        var dx = e.changedTouches[0].clientX - touchStart.x;
        var dy = e.changedTouches[0].clientY - touchStart.y;
        if (Math.abs(dx) < 24 && Math.abs(dy) < 24) return;
        setDir(Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up'));
        touchStart = null;
    });

    // ================== PILLS ==================
    function onPills(container, apply) {
        container.addEventListener('click', function (e) {
            var pill = e.target.closest('.snake-pill');
            if (!pill) return;
            apply(pill);
            saveSettings();
            reflectSettings();
            showBest();
            newGame(); // un changement de réglage démarre une nouvelle partie
        });
    }
    onPills(pillsMode, function (pill) { settings.mode = pill.dataset.mode; });
    onPills(pillsSpeed, function (pill) { settings.speed = pill.dataset.speed; });
    onPills(pillsFruits, function (pill) { settings.fruits = parseInt(pill.dataset.fruits, 10); });

    // ================== DÉMARRAGE ==================
    overlayBtn.addEventListener('click', function () {
        if (paused) { togglePause(); } else { newGame(); }
    });
    newBtn.addEventListener('click', newGame);

    reflectSettings();
    showBest();
    snake = [{ x: 10, y: 12 }, { x: 9, y: 12 }, { x: 8, y: 12 }];
    prevSnake = snake.map(function (s) { return { x: s.x, y: s.y }; });
    lastTick = performance.now();
    foods = [makeFood()];
    renderLoop();
})();
</script>
