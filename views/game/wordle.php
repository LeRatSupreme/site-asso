<?php

declare(strict_types=1);

/** @var array<string,mixed>|null $user */
/** @var bool $isLoggedIn */
/** @var array{fr:bool, en:bool} $playedToday */
/** @var array{fr:list<string>, en:list<string>} $words */
/** @var string $submitUrl */
/** @var string $leaderboardUrl */
/** @var string $csrfToken */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <a class="btn btn-outline" href="<?= e(url('/jeux')) ?>" style="margin-bottom:0.75rem;text-decoration:none;">← Retour aux jeux</a>
        <span class="eyebrow">🔤 Jeu de lettres</span>
        <h1 class="page-title">Wordle</h1>
        <p class="page-lead">Devine le mot en 6 essais. Un nouveau mot chaque jour.</p>
    </div>
</header>

<section class="section">
    <div class="container" style="max-width:500px;">

        <!-- Sélecteur FR/EN -->
        <div style="display:flex;justify-content:center;gap:0.5rem;margin-bottom:1.5rem;">
            <button type="button" class="mode-pill" id="mode-fr" data-mode="fr">🇫🇷 FR</button>
            <button type="button" class="mode-pill" id="mode-en" data-mode="en">🇬🇧 EN</button>
        </div>

        <!-- Grille -->
        <div id="grid" style="display:grid;gap:6px;margin-bottom:1.5rem;"></div>

        <!-- Message -->
        <div id="msg" style="text-align:center;font-weight:700;min-height:1.5rem;margin-bottom:1rem;"></div>

        <!-- Clavier -->
        <div id="keyboard"></div>

        <!-- Actions fin -->
        <div id="end-actions" style="display:none;text-align:center;margin-top:1.5rem;gap:0.5rem;justify-content:center;">
            <button type="button" class="btn btn-outline btn-sm" id="btn-share">📋 Partager</button>
            <a class="btn btn-primary btn-sm" href="<?= e($leaderboardUrl) ?>">🏆 Classement</a>
        </div>
    </div>
</section>

<style>
.mode-pill {
    padding: 0.5rem 1.2rem;
    border-radius: 999px;
    cursor: pointer;
    border: 2px solid var(--border-strong);
    background: rgba(255,255,255,0.03);
    color: var(--muted);
    font-weight: 700;
    font-size: 0.9rem;
    transition: all 0.15s;
}
.mode-pill.active {
    background: var(--primary);
    color: #0a1628;
    border-color: var(--primary);
}

.row-cell {
    width: 56px; height: 56px;
    display: grid; place-items: center;
    font-size: 1.5rem; font-weight: 900;
    text-transform: uppercase;
    border: 2px solid rgba(255,255,255,0.15);
    border-radius: 8px;
    background: transparent;
    color: var(--foreground);
    transition: transform 0.1s;
}
.row-cell.filled { border-color: rgba(255,255,255,0.4); }

/* Couleurs de résultat */
.row-cell.green {
    background: var(--primary);
    border-color: var(--primary);
    color: #0a1628;
}
.row-cell.yellow {
    background: var(--accent-warning);
    border-color: var(--accent-warning);
    color: #0a1628;
}
.row-cell.gray {
    background: rgba(255,255,255,0.06);
    border-color: rgba(255,255,255,0.06);
    color: var(--muted);
}

/* Animation reveal */
.row-cell.reveal {
    animation: cellReveal 0.5s ease forwards;
}
@keyframes cellReveal {
    0% { transform: rotateX(0deg); }
    50% { transform: rotateX(90deg); }
    100% { transform: rotateX(0deg); }
}

/* Clavier */
.kb-row { display: flex; justify-content: center; gap: 5px; margin-bottom: 5px; }
.kb-key {
    min-width: 30px; height: 48px;
    flex: 1; max-width: 42px;
    border: none; border-radius: 6px;
    background: rgba(255,255,255,0.1);
    color: var(--foreground);
    font-weight: 800; font-size: 0.9rem;
    cursor: pointer; text-transform: uppercase;
    transition: background 0.15s;
}
.kb-key:hover { background: rgba(255,255,255,0.2); }
.kb-key:active { transform: translateY(1px); }
.kb-key.wide { max-width: 62px; font-size: 0.7rem; }
.kb-key.green { background: var(--primary); color: #0a1628; }
.kb-key.yellow { background: var(--accent-warning); color: #0a1628; }
.kb-key.gray { background: rgba(255,255,255,0.05); color: var(--muted); }
</style>

<script>
(function() {
    'use strict';

    // ===================== MOTS 5 LETTRES (depuis la base de données) =====================
    var WORDS = <?= json_encode($words) ?>;

    // ===================== CLAVIERS =====================
    var KEYBOARDS = {
        fr: ["AZERTYUIOP", "QSDFGHJKLM", "WXCVBN"],
        en: ["QWERTYUIOP", "ASDFGHJKL", "ZXCVBN"]
    };

    // ===================== ÉTAT =====================
    var MODE_KEY = 'aeic-wordle-mode';
    var SUBMIT_URL = <?= json_encode($submitUrl) ?>;
    var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    var IS_LOGGED_IN = <?= json_encode($isLoggedIn) ?>;

    var mode = localStorage.getItem(MODE_KEY) || 'fr';
    var answer = '';
    var guess = '';
    var row = 0;
    var over = false;
    var keyState = {}; // 'A' -> 'green'|'yellow'|'gray'

    // ===================== MOT DU JOUR =====================
    // Index déterministe basé sur le nombre de jours écoulés depuis
    // l'epoch Unix (UTC), modulo la taille de la liste. Tous les joueurs
    // ont ainsi le même mot un jour donné, et aucun mot ne se répète tant
    // que toute la liste n'est pas parcourue (plus d'un an avec ~400 mots).
    function dayIndex() {
        return Math.floor(Date.now() / 86400000);
    }
    function pickWord() {
        var list = WORDS[mode];
        if (!list || list.length === 0) return '?????';
        return list[dayIndex() % list.length];
    }

    // ===================== ALGORITHME D'ÉVALUATION =====================
    // Renvoie un tableau de 5 résultats : 'green', 'yellow', 'gray'
    function evaluate(guess, answer) {
        var result = ['gray','gray','gray','gray','gray'];

        // Compter les lettres disponibles dans la réponse.
        var count = {};
        for (var i = 0; i < 5; i++) {
            var c = answer[i];
            count[c] = (count[c] || 0) + 1;
        }

        // 1er passage : lettres bien placées (green).
        for (var i = 0; i < 5; i++) {
            if (guess[i] === answer[i]) {
                result[i] = 'green';
                count[guess[i]]--;
            }
        }

        // 2e passage : lettres présentes mais mal placées (yellow).
        for (var j = 0; j < 5; j++) {
            if (result[j] === 'green') continue;
            var letter = guess[j];
            if (count[letter] > 0) {
                result[j] = 'yellow';
                count[letter]--;
            }
        }

        return result;
    }

    // ===================== RENDU =====================
    var gridEl = document.getElementById('grid');
    var kbEl = document.getElementById('keyboard');
    var msgEl = document.getElementById('msg');
    var endEl = document.getElementById('end-actions');

    var cells = []; // cells[row][col]

    function buildGrid() {
        gridEl.innerHTML = '';
        cells = [];
        for (var r = 0; r < 6; r++) {
            var rowEl = document.createElement('div');
            rowEl.style.display = 'grid';
            rowEl.style.gridTemplateColumns = 'repeat(5, 56px)';
            rowEl.style.gap = '6px';
            rowEl.style.justifyContent = 'center';
            rowEl.style.marginBottom = '6px';
            var rowCells = [];
            for (var c = 0; c < 5; c++) {
                var cell = document.createElement('div');
                cell.className = 'row-cell';
                rowEl.appendChild(cell);
                rowCells.push(cell);
            }
            gridEl.appendChild(rowEl);
            cells.push(rowCells);
        }
    }

    function buildKeyboard() {
        kbEl.innerHTML = '';
        var rows = KEYBOARDS[mode];
        for (var r = 0; r < rows.length; r++) {
            var rowEl = document.createElement('div');
            rowEl.className = 'kb-row';
            var letters = rows[r];
            for (var i = 0; i < letters.length; i++) {
                rowEl.appendChild(makeKey(letters[i], letters[i]));
            }
            if (r === rows.length - 1) {
                rowEl.insertBefore(makeKey('↵', 'ENTER', true), rowEl.firstChild);
                rowEl.appendChild(makeKey('⌫', 'BACK', true));
            }
            kbEl.appendChild(rowEl);
        }
        applyKeyColors();
    }

    function makeKey(label, value, wide) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'kb-key' + (wide ? ' wide' : '');
        btn.textContent = label;
        btn.dataset.key = value;
        btn.addEventListener('click', function() { handleKey(value); });
        return btn;
    }

    function applyKeyColors() {
        var keys = kbEl.querySelectorAll('.kb-key');
        for (var i = 0; i < keys.length; i++) {
            var k = keys[i];
            var val = k.dataset.key;
            k.classList.remove('green', 'yellow', 'gray');
            if (val.length === 1 && keyState[val]) {
                k.classList.add(keyState[val]);
            }
        }
    }

    function renderCurrentRow() {
        var rowCells = cells[row];
        if (!rowCells) return;
        for (var i = 0; i < 5; i++) {
            var ch = guess[i] || '';
            rowCells[i].textContent = ch;
            rowCells[i].classList.toggle('filled', ch !== '');
        }
    }

    // ===================== LOGIQUE =====================
    function handleKey(key) {
        if (over) return;
        if (key === 'BACK') {
            guess = guess.slice(0, -1);
        } else if (key === 'ENTER') {
            submitGuess();
        } else if (guess.length < 5 && /^[A-Z]$/.test(key)) {
            guess += key;
        }
        renderCurrentRow();
    }

    function submitGuess() {
        if (guess.length !== 5) {
            showMessage('5 lettres requises !');
            return;
        }

        var result = evaluate(guess, answer);
        var rowCells = cells[row];

        // Met à jour les couleurs du clavier (priorité green > yellow > gray).
        for (var i = 0; i < 5; i++) {
            var letter = guess[i];
            var st = result[i];
            var current = keyState[letter];
            if (!current) {
                keyState[letter] = st;
            } else if (current === 'gray' && (st === 'green' || st === 'yellow')) {
                keyState[letter] = st;
            } else if (current === 'yellow' && st === 'green') {
                keyState[letter] = st;
            }
        }

        // Animation : révèle chaque case une par une.
        var i = 0;
        function revealNext() {
            if (i >= 5) {
                applyKeyColors();
                afterRow(result);
                return;
            }
            var cell = rowCells[i];
            cell.classList.add('reveal');
            // Applique la couleur à mi-animation (quand la case est plate).
            setTimeout(function(idx) {
                return function() {
                    cell.classList.remove('filled');
                    cell.classList.add(result[idx]);
                };
            }(i), 240);
            i++;
            setTimeout(revealNext, 300);
        }
        revealNext();
    }

    function afterRow(result) {
        // Vérifie si gagné.
        var allGreen = true;
        for (var i = 0; i < 5; i++) {
            if (result[i] !== 'green') { allGreen = false; break; }
        }

        if (allGreen) {
            over = true;
            showMessage('🎉 Bravo ! Trouvé en ' + (row + 1) + ' essai(s) !', 'win');
            showEnd(true, row + 1);
            return;
        }

        row++;
        guess = '';

        if (row >= 6) {
            over = true;
            showMessage('💀 Le mot était : ' + answer, 'lose');
            showEnd(false, 6);
        }
    }

    function showMessage(text, type) {
        msgEl.textContent = text;
        msgEl.style.color = type === 'win' ? 'var(--primary)' : (type === 'lose' ? 'var(--accent-danger)' : 'var(--muted)');
    }

    function showEnd(won, attempts) {
        endEl.style.display = 'flex';

        if (IS_LOGGED_IN) {
            fetch(SUBMIT_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    mode: mode,
                    won: won,
                    word: answer,
                    attempts: attempts
                })
            }).catch(function() {});
        }

        // Bouton partager.
        document.getElementById('btn-share').onclick = function() {
            var emojis = { green: '🟩', yellow: '🟨', gray: '⬛' };
            var text = 'Wordle AEIC (' + mode.toUpperCase() + ') ' + (won ? attempts + '/6' : 'X/6') + '\n';
            for (var r = 0; r < row + 1; r++) {
                if (!cells[r]) continue;
                var line = '';
                for (var c = 0; c < 5; c++) {
                    var cls = cells[r][c].className;
                    if (cls.indexOf('green') !== -1) line += emojis.green;
                    else if (cls.indexOf('yellow') !== -1) line += emojis.yellow;
                    else line += emojis.gray;
                }
                text += line + '\n';
            }
            navigator.clipboard.writeText(text).then(function() {
                showMessage('📋 Résultat copié !', 'win');
            });
        };
    }

    // ===================== MODE FR/EN =====================
    function setMode(m) {
        mode = m;
        localStorage.setItem(MODE_KEY, m);
        document.getElementById('mode-fr').classList.toggle('active', m === 'fr');
        document.getElementById('mode-en').classList.toggle('active', m === 'en');
        newGame();
    }

    function newGame() {
        answer = pickWord();
        guess = '';
        row = 0;
        over = false;
        keyState = {};
        showMessage('');
        endEl.style.display = 'none';
        buildGrid();
        buildKeyboard();
    }

    // ===================== CLAVIER PHYSIQUE =====================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { handleKey('ENTER'); }
        else if (e.key === 'Backspace') { handleKey('BACK'); }
        else {
            var k = e.key.toUpperCase();
            if (/^[A-Z]$/.test(k)) { handleKey(k); }
        }
    });

    // ===================== INIT =====================
    document.getElementById('mode-fr').addEventListener('click', function() { setMode('fr'); });
    document.getElementById('mode-en').addEventListener('click', function() { setMode('en'); });
    setMode(mode);

})();
</script>
