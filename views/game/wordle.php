<?php

declare(strict_types=1);

/** @var array<string,mixed>|null $user */
/** @var bool $isLoggedIn */
/** @var string $submitUrl */
/** @var string $getWordUrl */
/** @var string $leaderboardUrl */
/** @var string $csrfToken */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <a class="btn btn-outline" href="<?= e(url('/jeux')) ?>" style="margin-bottom:0.75rem;text-decoration:none;">← Retour aux jeux</a>
        <span class="eyebrow">🔤 Jeu de lettres</span>
        <h1 class="page-title">Wordle</h1>
        <p class="page-lead">3 difficultés · 2 langues · mode quotidien ou libre.</p>
    </div>
</header>

<section class="section">
    <div class="container" style="max-width:560px;">

        <!-- Barre de réglages -->
        <div class="wordle-settings">
            <div class="settings-row">
                <span class="settings-label">Langue</span>
                <div class="settings-pills">
                    <button type="button" class="pill" id="lang-fr" data-lang="fr">🇫🇷 FR</button>
                    <button type="button" class="pill" id="lang-en" data-lang="en">🇬🇧 EN</button>
                </div>
            </div>
            <div class="settings-row">
                <span class="settings-label">Mode</span>
                <div class="settings-pills">
                    <button type="button" class="pill" id="mode-daily" data-mode="daily">📅 Quotidien</button>
                    <button type="button" class="pill" id="mode-free" data-mode="free">🎲 Libre</button>
                </div>
            </div>
            <div class="settings-row">
                <span class="settings-label">Niveau</span>
                <div class="settings-pills">
                    <button type="button" class="pill" id="diff-facile" data-diff="facile">🙂 Facile · 5</button>
                    <button type="button" class="pill" id="diff-moyen" data-diff="moyen">😐 Moyen · 6</button>
                    <button type="button" class="pill" id="diff-difficile" data-diff="difficile">😖 Difficile · 7</button>
                </div>
            </div>
        </div>

        <!-- Chargement -->
        <div id="loader" style="text-align:center;padding:2rem;color:var(--muted);">Chargement…</div>

        <!-- Grille -->
        <div id="grid-wrap" style="display:none;">
            <div id="grid" style="margin-bottom:1.5rem;"></div>
        </div>

        <!-- Message -->
        <div id="msg" style="text-align:center;font-weight:700;min-height:1.5rem;margin-bottom:1rem;"></div>

        <!-- Clavier -->
        <div id="keyboard"></div>

        <!-- Champ invisible : capte le clavier natif sur mobile -->
        <input type="text" id="wordle-input" autocomplete="off" autocorrect="off"
               autocapitalize="characters" spellcheck="false" inputmode="text"
               aria-hidden="true" tabindex="-1"
               style="position:absolute;left:-9999px;top:50%;opacity:0;width:1px;height:1px;font-size:16px;" />

        <!-- Actions fin -->
        <div id="end-actions" style="display:none;text-align:center;margin-top:1.5rem;gap:0.5rem;justify-content:center;flex-wrap:wrap;">
            <button type="button" class="btn btn-primary btn-sm" id="btn-replay">🔄 Rejouer</button>
            <button type="button" class="btn btn-outline btn-sm" id="btn-share">📋 Partager</button>
            <a class="btn btn-outline btn-sm" href="<?= e($leaderboardUrl) ?>">🏆 Classement</a>
        </div>
    </div>
</section>

<style>
.wordle-settings {
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border-strong);
    border-radius: 0.75rem;
    padding: 1rem;
    margin-bottom: 1.5rem;
}
.settings-row { display:flex; align-items:center; gap:0.75rem; margin-bottom:0.75rem; }
.settings-row:last-child { margin-bottom:0; }
.settings-label { font-size:0.8rem; font-weight:700; color:var(--muted); min-width:60px; }
.settings-pills { display:flex; gap:0.4rem; flex-wrap:wrap; }
.pill {
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    cursor: pointer;
    border: 2px solid var(--border-strong);
    background: rgba(255,255,255,0.02);
    color: var(--muted);
    font-weight: 700;
    font-size: 0.78rem;
    transition: all 0.15s;
}
.pill.active {
    background: var(--primary);
    color: #0a1628;
    border-color: var(--primary);
}
.pill:disabled {
    cursor: not-allowed !important;
    pointer-events: none;
}

.row-cell {
    display: grid; place-items: center;
    font-size: 1.4rem; font-weight: 900;
    text-transform: uppercase;
    border: 2px solid rgba(255,255,255,0.15);
    border-radius: 8px;
    background: transparent;
    color: var(--foreground);
}
.row-cell.filled { border-color: rgba(255,255,255,0.4); }
.row-cell.green  { background: var(--primary); border-color: var(--primary); color:#0a1628; }
.row-cell.yellow { background: var(--accent-warning); border-color: var(--accent-warning); color:#0a1628; }
.row-cell.gray   { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.06); color: var(--muted); }
.row-cell.reveal { animation: cellReveal 0.5s ease forwards; }
@keyframes cellReveal {
    0% { transform: rotateX(0deg); }
    50% { transform: rotateX(90deg); }
    100% { transform: rotateX(0deg); }
}

.kb-row { display: flex; justify-content: center; gap: 5px; margin-bottom: 5px; }
.kb-key {
    min-width: 28px; height: 46px;
    flex: 1; max-width: 40px;
    border: none; border-radius: 6px;
    background: rgba(255,255,255,0.1);
    color: var(--foreground);
    font-weight: 800; font-size: 0.85rem;
    cursor: pointer; text-transform: uppercase;
    transition: background 0.15s;
}
.kb-key:hover { background: rgba(255,255,255,0.2); }
.kb-key:active { transform: translateY(1px); }
.kb-key.wide { max-width: 58px; font-size: 0.68rem; }
.kb-key.green { background: var(--primary); color:#0a1628; }
.kb-key.yellow { background: var(--accent-warning); color:#0a1628; }
.kb-key.gray { background: rgba(255,255,255,0.05); color: var(--muted); }
</style>

<script>
(function() {
    'use strict';

    // ===================== CONFIG =====================
    var GET_WORD_URL = <?= json_encode($getWordUrl) ?>;
    var SUBMIT_URL   = <?= json_encode($submitUrl) ?>;
    var CSRF_TOKEN   = <?= json_encode($csrfToken) ?>;
    var IS_LOGGED_IN = <?= json_encode($isLoggedIn) ?>;

    var KEYBOARDS = {
        fr: ["AZERTYUIOP", "QSDFGHJKLM", "WXCVBN"],
        en: ["QWERTYUIOP", "ASDFGHJKL", "ZXCVBN"]
    };

    var STORE_KEY = 'aeic-wordle-settings';
    var MAX_ROWS = 6;

    // ===================== ÉTAT =====================
    var settings = loadSettings(); // {lang, mode, difficulty}
    var answer = '';
    var guess = '';
    var row = 0;
    var wordLen = 5;
    var over = false;
    var busy = false;
    var keyState = {};

    function loadSettings() {
        try {
            var s = JSON.parse(localStorage.getItem(STORE_KEY) || '{}');
            return {
                lang: s.lang === 'en' ? 'en' : 'fr',
                mode: s.mode === 'daily' ? 'daily' : 'free',
                difficulty: ['facile','moyen','difficile'].indexOf(s.difficulty) !== -1 ? s.difficulty : 'facile'
            };
        } catch (e) {
            return { lang: 'fr', mode: 'free', difficulty: 'facile' };
        }
    }
    function saveSettings() {
        localStorage.setItem(STORE_KEY, JSON.stringify(settings));
    }

    // ===================== ALGORITHME =====================
    function evaluate(guess, answer) {
        var n = answer.length;
        var result = [];
        for (var i = 0; i < n; i++) result.push('gray');

        var count = {};
        for (var i = 0; i < n; i++) {
            var c = answer[i];
            count[c] = (count[c] || 0) + 1;
        }
        for (var i = 0; i < n; i++) {
            if (guess[i] === answer[i]) {
                result[i] = 'green';
                count[guess[i]]--;
            }
        }
        for (var j = 0; j < n; j++) {
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
    var loaderEl = document.getElementById('loader');
    var gridWrap = document.getElementById('grid-wrap');
    var cells = [];

    function cellSize() {
        // Grilles plus larges pour les mots longs.
        return wordLen >= 7 ? 50 : (wordLen >= 6 ? 54 : 58);
    }

    function buildGrid() {
        gridEl.innerHTML = '';
        cells = [];
        var sz = cellSize();
        for (var r = 0; r < MAX_ROWS; r++) {
            var rowEl = document.createElement('div');
            rowEl.style.display = 'grid';
            rowEl.style.gridTemplateColumns = 'repeat(' + wordLen + ', ' + sz + 'px)';
            rowEl.style.gap = '6px';
            rowEl.style.justifyContent = 'center';
            rowEl.style.marginBottom = '6px';
            var rowCells = [];
            for (var c = 0; c < wordLen; c++) {
                var cell = document.createElement('div');
                cell.className = 'row-cell';
                cell.style.width = sz + 'px';
                cell.style.height = sz + 'px';
                rowEl.appendChild(cell);
                rowCells.push(cell);
            }
            gridEl.appendChild(rowEl);
            cells.push(rowCells);
        }
    }

    function buildKeyboard() {
        kbEl.innerHTML = '';
        var rows = KEYBOARDS[settings.lang];
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
        for (var i = 0; i < wordLen; i++) {
            var ch = guess[i] || '';
            rowCells[i].textContent = ch;
            rowCells[i].classList.toggle('filled', ch !== '');
        }
    }

    // ===================== LOGIQUE =====================
    function handleKey(key) {
        if (over || busy) return;
        if (key === 'BACK') {
            guess = guess.slice(0, -1);
        } else if (key === 'ENTER') {
            submitGuess();
        } else if (guess.length < wordLen && /^[A-Z]$/.test(key)) {
            guess += key;
        }
        renderCurrentRow();
        // Re-focalise l'input invisible (garde le clavier natif mobile ouvert).
        focusInput();
    }

    function submitGuess() {
        if (guess.length !== wordLen) {
            showMessage(wordLen + ' lettres requises !');
            return;
        }

        var result = evaluate(guess, answer);
        var rowCells = cells[row];

        for (var i = 0; i < wordLen; i++) {
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

        var i = 0;
        function revealNext() {
            if (i >= wordLen) {
                applyKeyColors();
                afterRow(result);
                return;
            }
            var cell = rowCells[i];
            cell.classList.add('reveal');
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
        var allGreen = true;
        for (var i = 0; i < wordLen; i++) {
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

        if (row >= MAX_ROWS) {
            over = true;
            showMessage('💀 Le mot était : ' + answer, 'lose');
            showEnd(false, MAX_ROWS);
        }
    }

    function showMessage(text, type) {
        msgEl.textContent = text;
        msgEl.style.color = type === 'win' ? 'var(--primary)' : (type === 'lose' ? 'var(--accent-danger)' : 'var(--muted)');
    }

    function showEnd(won, attempts) {
        endEl.style.display = 'flex';

        // Sauvegarde du résultat (mode daily seulement).
        if (IS_LOGGED_IN && settings.mode === 'daily') {
            fetch(SUBMIT_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    mode: settings.mode,
                    lang: settings.lang,
                    difficulty: settings.difficulty,
                    won: won,
                    attempts: attempts
                })
            }).catch(function() {});
        }

        var btnShare = document.getElementById('btn-share');
        btnShare.onclick = function() {
            var emojis = { green: '🟩', yellow: '🟨', gray: '⬛' };
            var text = 'Wordle AEIC (' + settings.lang.toUpperCase() + '/' + settings.difficulty + ') ' +
                (won ? attempts + '/' + MAX_ROWS : 'X/' + MAX_ROWS) + '\n';
            for (var r = 0; r <= row && r < MAX_ROWS; r++) {
                if (!cells[r]) continue;
                var line = '';
                for (var c = 0; c < wordLen; c++) {
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

    // ===================== CHARGEMENT DU MOT =====================
    function newGame() {
        loaderEl.style.display = 'block';
        gridWrap.style.display = 'none';
        kbEl.innerHTML = '';
        msgEl.textContent = '';
        endEl.style.display = 'none';
        over = false;
        busy = true;
        guess = '';
        row = 0;
        keyState = {};

        var url = GET_WORD_URL + '?lang=' + encodeURIComponent(settings.lang) +
            '&difficulty=' + encodeURIComponent(settings.difficulty) +
            '&mode=' + encodeURIComponent(settings.mode) + '&_t=' + Date.now();

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) {
                    showMessage('Erreur : ' + data.error, 'lose');
                    loaderEl.textContent = 'Aucun mot disponible pour ces réglages.';
                    return;
                }
                answer = data.word;
                wordLen = data.length || answer.length;
                loaderEl.style.display = 'none';
                gridWrap.style.display = 'block';
                buildGrid();
                buildKeyboard();
                busy = false;
                // Focalise l'input invisible pour faire apparaître le clavier
                // natif sur mobile (sans effet visible sur desktop).
                focusInput();
            })
            .catch(function() {
                loaderEl.textContent = 'Impossible de charger le mot. Recharge la page.';
            });
    }

    // ===================== RÉGLAGES UI =====================
    function syncPills() {
        document.getElementById('lang-fr').classList.toggle('active', settings.lang === 'fr');
        document.getElementById('lang-en').classList.toggle('active', settings.lang === 'en');
        document.getElementById('mode-daily').classList.toggle('active', settings.mode === 'daily');
        document.getElementById('mode-free').classList.toggle('active', settings.mode === 'free');
        document.getElementById('diff-facile').classList.toggle('active', settings.difficulty === 'facile');
        document.getElementById('diff-moyen').classList.toggle('active', settings.difficulty === 'moyen');
        document.getElementById('diff-difficile').classList.toggle('active', settings.difficulty === 'difficile');
    }

    function bindPill(id, key, val) {
        document.getElementById(id).addEventListener('click', function() {
            if (busy) return;
            settings[key] = val;
            saveSettings();
            applyDailyLock();
            syncPills();
            newGame();
        });
    }

    // Le mode quotidien est toujours en 5 lettres (facile) : on verrouille
    // le sélecteur de difficulté quand le mode quotidien est actif.
    function applyDailyLock() {
        var isDaily = settings.mode === 'daily';
        ['diff-facile','diff-moyen','diff-difficile'].forEach(function(id) {
            var el = document.getElementById(id);
            el.disabled = isDaily;
            el.style.opacity = isDaily ? '0.4' : '';
            el.style.cursor = isDaily ? 'not-allowed' : '';
        });
        if (isDaily) {
            settings.difficulty = 'facile';
        }
    }

    bindPill('lang-fr', 'lang', 'fr');
    bindPill('lang-en', 'lang', 'en');
    bindPill('mode-daily', 'mode', 'daily');
    bindPill('mode-free', 'mode', 'free');
    bindPill('diff-facile', 'difficulty', 'facile');
    bindPill('diff-moyen', 'difficulty', 'moyen');
    bindPill('diff-difficile', 'difficulty', 'difficile');

    document.getElementById('btn-replay').addEventListener('click', function() {
        newGame();
    });

    // ===================== CLAVIER (physique + mobile natif) =====================
    // Sur mobile, document.addEventListener('keydown') ne se déclenche pas car il
    // n'y a pas de champ focalisé. On ajoute un <input> invisible qui, une fois
    // focalisé, fait apparaître le clavier natif du téléphone. On intercepte
    // ensuite les caractères tapés via l'événement 'input' (plus fiable que
    // 'keydown' sur mobile) et on vide l'input après chaque frappe.

    var hiddenInput = document.getElementById('wordle-input');

    // Focalise l'input invisible (fait apparaître le clavier natif sur mobile).
    function focusInput() {
        if (!over && !busy && hiddenInput) {
            try { hiddenInput.focus({ preventScroll: true }); } catch (e) {
                hiddenInput.focus();
            }
        }
    }

    // Clavier physique (desktop).
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { handleKey('ENTER'); }
        else if (e.key === 'Backspace') { handleKey('BACK'); }
        else {
            var k = e.key.toUpperCase();
            if (/^[A-Z]$/.test(k)) { handleKey(k); }
        }
    });

    // Clavier natif mobile : on intercepte les caractères via 'input'.
    // On utilise aussi 'keydown' sur l'input pour Backspace/Enter.
    if (hiddenInput) {
        hiddenInput.addEventListener('input', function(e) {
            // Récupère tout le texte tapé (l'autocapitalize met en majuscules).
            var typed = hiddenInput.value.toUpperCase();
            // Traite chaque caractère valide (A-Z), ignore le reste.
            for (var i = 0; i < typed.length; i++) {
                var ch = typed[i];
                if (/^[A-Z]$/.test(ch)) {
                    handleKey(ch);
                }
            }
            // Vide l'input pour la prochaine frappe.
            hiddenInput.value = '';
        });

        hiddenInput.addEventListener('keydown', function(e) {
            // IMPORTANT : stoppe la propagation pour éviter le double traitement
            // (ce keydown ne doit PAS aussi remonter jusqu'au listener document).
            e.stopPropagation();
            if (e.key === 'Enter') {
                e.preventDefault();
                handleKey('ENTER');
            } else if (e.key === 'Backspace') {
                // L'input est vidé après chaque lettre : un backspace sur input
                // vide déclenche notre propre effacement de la grille.
                if (hiddenInput.value === '') {
                    handleKey('BACK');
                }
            }
        });

        // Re-focalise l'input quand on tape sur la grille (mobile).
        var gridWrapEl = document.getElementById('grid-wrap');
        if (gridWrapEl) {
            gridWrapEl.addEventListener('click', focusInput);
            gridWrapEl.addEventListener('touchstart', focusInput, { passive: true });
        }
    }

    // ===================== INIT =====================
    applyDailyLock();
    syncPills();
    newGame();

})();
</script>
