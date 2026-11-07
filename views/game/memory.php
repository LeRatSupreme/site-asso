<?php

declare(strict_types=1);

/**
 * Page de jeu — Memory cafétéria AEIC.
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
        <span class="ae-pill"><span class="ae-pill-dot" aria-hidden="true"></span>Cafétéria · 7 tailles de grille</span>
        <h1 class="page-title">Memory Cafétéria</h1>
        <p class="page-lead">Retrouve les paires de produits le plus vite possible&nbsp;!</p>
    </div>
</header>

<section class="section">
    <div class="container game-zone">

        <p class="back-link"><a href="<?= e(url('/jeux')) ?>">← Tous les jeux</a></p>

        <!-- Taille de grille : ligne dédiée au-dessus du plateau -->
        <div class="memory-sizes">
            <span class="memory-sizes-label">Taille</span>
            <div class="memory-pills" id="g-diffs" role="group" aria-label="Taille de la grille">
                <button type="button" class="memory-pill" data-size="4x3">4×3</button>
                <button type="button" class="memory-pill" data-size="4x4">4×4</button>
                <button type="button" class="memory-pill" data-size="6x4">6×4</button>
                <button type="button" class="memory-pill" data-size="6x6">6×6</button>
                <button type="button" class="memory-pill" data-size="8x6">8×6</button>
                <button type="button" class="memory-pill" data-size="8x8">8×8</button>
                <button type="button" class="memory-pill" data-size="10x8">10×8</button>
            </div>
        </div>

        <!-- Barre de jeu -->
        <div class="game-bar">
            <div class="game-stats">
                <div class="game-stat">
                    <span class="game-stat-label">Coups</span>
                    <span class="game-stat-value" id="g-moves">0</span>
                </div>
                <div class="game-stat">
                    <span class="game-stat-label">Paires</span>
                    <span class="game-stat-value" id="g-pairs">0 / 8</span>
                </div>
                <div class="game-stat">
                    <span class="game-stat-label">Temps</span>
                    <span class="game-stat-value" id="g-time">0:00</span>
                </div>
                <div class="game-stat">
                    <span class="game-stat-label">Record</span>
                    <span class="game-stat-value game-stat-value-muted" id="g-best">—</span>
                </div>
            </div>
            <div class="game-controls">
                <button class="btn btn-primary btn-sm" id="g-new">Nouvelle partie</button>
            </div>
        </div>

        <!-- Grille -->
        <div class="game-board" id="g-board"></div>

        <!-- Win overlay -->
        <div class="game-win" id="g-win" hidden>
            <div class="game-win-card">
                <h2>Bravo !</h2>
                <p id="g-win-text"></p>
                <button class="btn btn-primary" id="g-win-replay">Rejouer</button>
            </div>
        </div>
    </div>
</section>

<style>
/* ============ Memory cafétéria — habillage charte AEIC ============
   Réutilise les verbes graphiques du site : pilules teal, trame de
   points (dot-grid), monogramme « AE », typo Inter 900 uppercase. */
.game-zone { max-width: 900px; }

/* Choix de la taille */
.memory-sizes {
    display: flex; align-items: center; gap: 0.85rem; flex-wrap: wrap;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 0.75rem 1rem;
    margin-bottom: 1.25rem;
}
.memory-sizes-label {
    font-size: 0.65rem; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.18em;
}
.memory-pills { display: flex; gap: 0.45rem; flex-wrap: wrap; }
.memory-pill {
    display: inline-flex; align-items: center; justify-content: center;
    min-height: 44px;
    padding: 0.35rem 0.95rem;
    border-radius: 999px;
    cursor: pointer;
    border: 1px solid var(--border-strong);
    background: transparent;
    color: var(--muted);
    font-weight: 800;
    font-size: 0.8rem;
    letter-spacing: 0.02em;
    transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}
.memory-pill:hover:not(.active) { border-color: var(--primary); color: var(--foreground); }
.memory-pill:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.memory-pill.active { background: var(--primary); color: var(--primary-foreground); border-color: var(--primary); }

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

/* Plateau */
.game-board { display: grid; gap: 0.5rem; perspective: 900px; justify-content: center; }

.g-card {
    aspect-ratio: 1; position: relative; cursor: pointer;
    transform-style: preserve-3d; transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}
.g-card.is-flipped { transform: rotateY(180deg); }
.g-card.is-matched { cursor: default; }

.g-face {
    position: absolute; inset: 0; border-radius: 12px;
    display: grid; place-items: center;
    font-size: inherit;
    backface-visibility: hidden; -webkit-backface-visibility: hidden;
}

/* Dos de carte : monogramme AE de l'asso sur trame de points —
   la signature graphique du site, à toutes les tailles de grille. */
.g-front {
    color: transparent; /* le « ? » injecté par le jeu reste présent, masqué */
    background-color: rgba(58, 155, 184, 0.13);
    background-image: radial-gradient(circle, rgba(58, 155, 184, 0.3) 1px, transparent 1.4px);
    background-size: 25% 25%;
    border: 2px solid rgba(58, 155, 184, 0.38);
    box-shadow: inset 0 0 0 3px rgba(58, 155, 184, 0.07);
}
.g-front::after {
    content: "AE";
    font-size: 0.5em;
    font-weight: 900;
    letter-spacing: -0.04em;
    color: rgba(58, 155, 184, 0.62);
}
.g-card:not(.is-flipped):not(.is-matched):hover .g-front { border-color: var(--primary); }

/* Face produit */
.g-back {
    background: var(--card);
    border: 2px solid var(--border-strong);
    transform: rotateY(180deg);
}
.g-card.is-matched .g-back {
    border-color: var(--accent-success);
    background: rgba(45, 154, 95, 0.12);
}

[data-theme="light"] .memory-sizes,
[data-theme="light"] .game-bar { background: #ffffff; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04); }
[data-theme="light"] .g-front {
    background-color: rgba(45, 122, 148, 0.07);
    background-image: radial-gradient(circle, rgba(45, 122, 148, 0.28) 1px, transparent 1.4px);
    border-color: rgba(45, 122, 148, 0.35);
    box-shadow: inset 0 0 0 3px rgba(45, 122, 148, 0.05);
}
[data-theme="light"] .g-front::after { color: rgba(45, 122, 148, 0.55); }

/* Écran de victoire : carte charte, pas d'alerte navigateur. */
.game-win {
    position: fixed; inset: 0; z-index: 9000;
    background: rgba(10, 22, 40, 0.82);
    display: grid; place-items: center; animation: gFadeIn 0.25s ease;
}
.game-win[hidden] { display: none; }
.game-win-card {
    background: var(--card); border: 1px solid var(--border-strong);
    border-radius: var(--radius-lg); padding: 2.5rem; text-align: center;
    box-shadow: 0 24px 60px rgba(2, 8, 20, 0.5);
    animation: gPop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    max-width: min(420px, calc(100vw - 2rem));
}
.game-win-card h2 {
    font-size: 1.6rem; font-weight: 900; color: var(--primary);
    text-transform: uppercase; letter-spacing: -0.04em; margin: 0 0 0.5rem;
}
.game-win-card p { font-size: 1rem; color: var(--muted); margin: 0 0 1.25rem; overflow-wrap: anywhere; }
@keyframes gFadeIn { from { opacity: 0; } }
@keyframes gPop { from { transform: scale(0.9); opacity: 0; } }

@media (prefers-reduced-motion: reduce) {
    .g-card { transition-duration: 0.01s; }
    .game-win, .game-win-card { animation: none; }
}
</style>

<script>
(function () {
    var PRODUCTS = ['🥤','🍫','⚡','💧','🍟','🍬','🧃','🍵','🥪','☕','🍕','🍩','🍦','🍔','🥨','🧋','💻','🧩','🍎','🍪','🧀','🥐','🍇','🔥','🍋','🌶️','🥗','🌮','🧁','🍯','🥛','🍹','🎯','🎸','🎮','🎧','📚','✏️','🧠','🏆'];
    var SIZES = ['4x3', '4x4', '6x4', '6x6', '8x6', '8x8', '10x8'];
    var SUBMIT_URL = <?= json_encode($submitUrl) ?>;
    var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    var IS_LOGGED_IN = <?= json_encode((bool) $isLoggedIn) ?>;

    var board = document.getElementById('g-board');
    var movesEl = document.getElementById('g-moves');
    var pairsEl = document.getElementById('g-pairs');
    var timeEl = document.getElementById('g-time');
    var bestEl = document.getElementById('g-best');
    var diffsEl = document.getElementById('g-diffs');
    var newBtn = document.getElementById('g-new');
    var winEl = document.getElementById('g-win');
    var winText = document.getElementById('g-win-text');
    var winReplay = document.getElementById('g-win-replay');

    var cards = [], flipped = [], matched = 0, total = 0, moves = 0, lock = false;
    var startTime = 0, timerInt = null;

    // Taille choisie, mémorisée localement (défaut : 6×4).
    var currentSize = '6x4';
    try {
        var savedSize = localStorage.getItem('aeic_memory_size');
        if (SIZES.indexOf(savedSize) >= 0) { currentSize = savedSize; }
    } catch (e) { /* valeur par défaut */ }

    function reflectPills() {
        diffsEl.querySelectorAll('.memory-pill').forEach(function (b) {
            b.classList.toggle('active', b.dataset.size === currentSize);
        });
    }

    function sizeDims() {
        var p = currentSize.split('x');
        return { c: parseInt(p[0], 10), r: parseInt(p[1], 10) };
    }
    function cols() { return sizeDims().c; }
    function rows() { return sizeDims().r; }
    function bestKey() { return 'aeic_memory_best_' + currentSize; }

    function shuffle(a) {
        for (var i = a.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var t = a[i]; a[i] = a[j]; a[j] = t;
        }
        return a;
    }

    function fmtTime(s) {
        var m = Math.floor(s / 60), sec = s % 60;
        return m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    function startTimer() {
        startTime = Date.now();
        clearInterval(timerInt);
        timerInt = setInterval(function () {
            var sec = Math.floor((Date.now() - startTime) / 1000);
            timeEl.textContent = fmtTime(sec);
        }, 1000);
    }

    function newGame() {
        var c = cols(), r = rows();
        var count = c * r;
        var pairCount = Math.floor(count / 2);
        total = pairCount;
        matched = 0; moves = 0; lock = false; flipped = [];

        movesEl.textContent = '0';
        pairsEl.textContent = '0 / ' + pairCount;
        winEl.hidden = true;

        var emojis = shuffle(PRODUCTS.slice()).slice(0, pairCount);
        var deck = shuffle(emojis.concat(emojis));
        if (count % 2 === 1) deck.push(null); // case vide si impair

        board.style.gridTemplateColumns = 'repeat(' + c + ', 1fr)';
        board.innerHTML = '';

        deck.forEach(function (emoji, i) {
            if (emoji === null) { return; }
            var card = document.createElement('div');
            card.className = 'g-card';
            card.dataset.emoji = emoji;
            card.dataset.idx = i;
            card.innerHTML =
                '<div class="g-face g-front">?</div>' +
                '<div class="g-face g-back">' + emoji + '</div>';
            card.addEventListener('click', function () { flip(card); });
            board.appendChild(card);
        });

        cards = Array.prototype.slice.call(board.querySelectorAll('.g-card'));

        // Best score
        var best = localStorage.getItem(bestKey());
        bestEl.textContent = best ? fmtTime(parseInt(best, 10)) : '—';

        startTimer();
    }

    function flip(card) {
        if (lock || card.classList.contains('is-flipped') || card.classList.contains('is-matched')) return;
        card.classList.add('is-flipped');
        flipped.push(card);

        if (flipped.length === 2) {
            moves++;
            movesEl.textContent = moves;
            lock = true;

            if (flipped[0].dataset.emoji === flipped[1].dataset.emoji) {
                setTimeout(function () {
                    flipped.forEach(function (c) { c.classList.add('is-matched'); });
                    flipped = []; lock = false;
                    matched++;
                    pairsEl.textContent = matched + ' / ' + total;
                    if (matched === total) { win(); }
                }, 400);
            } else {
                setTimeout(function () {
                    flipped.forEach(function (c) { c.classList.remove('is-flipped'); });
                    flipped = []; lock = false;
                }, 800);
            }
        }
    }

    function win() {
        clearInterval(timerInt);
        var sec = Math.floor((Date.now() - startTime) / 1000);
        var prev = localStorage.getItem(bestKey());
        var isBest = !prev || sec < parseInt(prev, 10);
        if (isBest) { localStorage.setItem(bestKey(), String(sec)); }
        winText.textContent = 'Tu as trouvé les ' + total + ' paires en ' + moves + ' coups et ' + fmtTime(sec) + ' !' + (isBest ? ' Nouveau record !' : '');
        winEl.hidden = false;
        submitScore(sec);
    }

    // Score serveur : points (plus c'est haut, mieux c'est), basé sur le temps,
    // les coups et la taille de la grille. Uniquement si connecté.
    function submitScore(sec) {
        if (!IS_LOGGED_IN) return;
        var points = Math.max(50, total * 1000 - moves * 25 - sec * 10);
        fetch(SUBMIT_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            credentials: 'same-origin',
            body: JSON.stringify({
                game: 'memory',
                mode: currentSize,
                score: points,
                moves: moves,
                _csrf: CSRF_TOKEN
            })
        }).catch(function () { /* silencieux : le score local reste fiable */ });
    }

    newBtn.addEventListener('click', newGame);
    winReplay.addEventListener('click', newGame);
    diffsEl.addEventListener('click', function (e) {
        var pill = e.target.closest('.memory-pill');
        if (!pill || pill.dataset.size === currentSize) return;
        currentSize = pill.dataset.size;
        try { localStorage.setItem('aeic_memory_size', currentSize); } catch (err) { /* ignore */ }
        reflectPills();
        newGame();
    });

    // Responsive card size.
    function adjustSize() {
        var c = cols();
        var maxWidth = Math.min(860, window.innerWidth - 48);
        var gap = 8;
        var size = Math.floor((maxWidth - gap * (c - 1)) / c);
        cards.forEach(function (card) {
            card.style.width = size + 'px';
            card.style.height = size + 'px';
            // L'emoji suit la taille de la carte (grilles 8×8 / 10×8).
            card.style.fontSize = Math.floor(size * 0.52) + 'px';
        });
        board.style.gridTemplateColumns = 'repeat(' + c + ', ' + size + 'px)';
    }

    reflectPills();
    newGame();
    setTimeout(adjustSize, 50);
    window.addEventListener('resize', adjustSize);
})();
</script>
