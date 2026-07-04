<?php

declare(strict_types=1);

/**
 * Page de jeu — Memory cafétéria AEIC.
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">🎮 Zone jeux</span>
        <h1 class="page-title">Memory Cafétéria</h1>
        <p class="page-lead">Retrouve les paires de produits le plus vite possible !</p>
    </div>
</header>

<section class="section">
    <div class="container game-zone">

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
                    <span class="game-stat-value" id="g-best">—</span>
                </div>
            </div>
            <div class="game-controls">
                <select id="g-difficulty">
                    <option value="4x4">Facile (4×4)</option>
                    <option value="6x4" selected>Moyen (6×4)</option>
                    <option value="6x6">Difficile (6×6)</option>
                </select>
                <button class="btn btn-primary btn-sm" id="g-new">🔄 Nouvelle partie</button>
            </div>
        </div>

        <!-- Grille -->
        <div class="game-board" id="g-board"></div>

        <!-- Win overlay -->
        <div class="game-win" id="g-win" hidden>
            <div class="game-win-card">
                <span class="game-win-emoji">🎉</span>
                <h2>Bravo !</h2>
                <p id="g-win-text"></p>
                <button class="btn btn-primary" id="g-win-replay">🔄 Rejouer</button>
            </div>
        </div>
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
.game-controls select {
    background: rgba(255,255,255,0.05); border: 1px solid var(--border);
    border-radius: 8px; color: var(--foreground); padding: 0.42rem 0.6rem; font-size: 0.85rem;
}

.game-board {
    display: grid; gap: 0.5rem; perspective: 800px;
    justify-content: center;
}

.g-card {
    aspect-ratio: 1; position: relative; cursor: pointer;
    transform-style: preserve-3d; transition: transform 0.4s cubic-bezier(0.4,0,0.2,1);
}
.g-card.is-flipped { transform: rotateY(180deg); }
.g-card.is-matched { cursor: default; }

.g-face {
    position: absolute; inset: 0; border-radius: 12px;
    display: grid; place-items: center; font-size: 2rem;
    backface-visibility: hidden; -webkit-backface-visibility: hidden;
}
.g-front {
    background: linear-gradient(135deg, rgba(72,189,211,0.15), rgba(97,80,170,0.15));
    border: 2px solid rgba(72,189,211,0.2);
    font-size: 1.2rem; color: rgba(255,255,255,0.3); font-weight: 900;
}
.g-card:hover .g-front { border-color: var(--primary); }
.g-back {
    background: var(--card, #0f1e35); border: 2px solid var(--border);
    transform: rotateY(180deg);
}
.g-card.is-matched .g-back {
    border-color: var(--accent-success, #2d9a5f);
    background: rgba(45,154,95,0.1);
    box-shadow: 0 0 12px rgba(45,154,95,0.2);
}

.game-win {
    position: fixed; inset: 0; z-index: 9000;
    background: rgba(0,0,0,0.6); backdrop-filter: blur(4px);
    display: grid; place-items: center; animation: fadeIn 0.3s;
}
.game-win[hidden] { display: none; }
.game-win-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 20px; padding: 2.5rem; text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
    animation: pop 0.4s cubic-bezier(0.34,1.56,0.64,1);
}
.game-win-emoji { font-size: 3.5rem; display: block; margin-bottom: 0.5rem; }
.game-win-card h2 { font-size: 1.8rem; font-weight: 900; color: var(--primary); margin: 0 0 0.5rem; }
.game-win-card p { font-size: 1rem; color: var(--muted); margin: 0 0 1.25rem; }
@keyframes fadeIn { from { opacity: 0; } }
@keyframes pop { from { transform: scale(0.8); opacity: 0; } }
</style>

<script>
(function () {
    var PRODUCTS = ['🥤','🍫','⚡','💧','🍟','🍬','🧃','🍵','🥪','☕','🍕','🍩','🍦','🍔','🐍','🎮','💻','🧩'];
    var board = document.getElementById('g-board');
    var movesEl = document.getElementById('g-moves');
    var pairsEl = document.getElementById('g-pairs');
    var timeEl = document.getElementById('g-time');
    var bestEl = document.getElementById('g-best');
    var diffEl = document.getElementById('g-difficulty');
    var newBtn = document.getElementById('g-new');
    var winEl = document.getElementById('g-win');
    var winText = document.getElementById('g-win-text');
    var winReplay = document.getElementById('g-win-replay');

    var cards = [], flipped = [], matched = 0, total = 0, moves = 0, lock = false;
    var startTime = 0, timerInt = null;

    function cols() {
        var v = diffEl.value;
        return v === '4x4' ? 4 : v === '6x6' ? 6 : 6;
    }
    function rows() {
        var v = diffEl.value;
        return v === '4x4' ? 4 : v === '6x6' ? 6 : 4;
    }
    function bestKey() { return 'aeic_memory_best_' + diffEl.value; }

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
        winText.textContent = 'Tu as trouvé les ' + total + ' paires en ' + moves + ' coups et ' + fmtTime(sec) + ' !' + (isBest ? ' 🏆 Nouveau record !' : '');
        winEl.hidden = false;
    }

    newBtn.addEventListener('click', newGame);
    winReplay.addEventListener('click', newGame);
    diffEl.addEventListener('change', newGame);

    // Responsive card size.
    function adjustSize() {
        var c = cols();
        var maxWidth = Math.min(720, window.innerWidth - 48);
        var gap = 8;
        var size = Math.floor((maxWidth - gap * (c - 1)) / c);
        cards.forEach(function (card) {
            card.style.width = size + 'px';
            card.style.height = size + 'px';
        });
        board.style.gridTemplateColumns = 'repeat(' + c + ', ' + size + 'px)';
    }

    newGame();
    setTimeout(adjustSize, 50);
    window.addEventListener('resize', adjustSize);
})();
</script>
