<?php

declare(strict_types=1);

/**
 * Page du jeu Wordle (FR / EN).
 *
 * @var array<string,mixed>|null $user
 * @var bool $isLoggedIn
 * @var array{fr:bool, en:bool} $playedToday
 * @var string $submitUrl
 * @var string $leaderboardUrl
 * @var string $csrfToken
 */

$wordleCss = <<<CSS
.wordle-wrap { max-width: 520px; margin: 0 auto; }
.wordle-mode { display:flex; justify-content:center; gap:0.5rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.mode-pill {
    padding:0.5rem 1.1rem; border-radius:999px; cursor:pointer;
    border:1px solid var(--border-strong); background:rgba(255,255,255,0.03);
    color:var(--foreground); font-weight:700; font-size:0.9rem;
    transition: background .15s, border-color .15s, color .15s;
}
.mode-pill:hover { background: rgba(72,189,211,0.12); }
.mode-pill.is-active {
    background: var(--primary); color: var(--primary-foreground);
    border-color: var(--primary);
}
.wordle-grid {
    display:grid; grid-template-rows: repeat(6, 1fr); gap:0.4rem;
    margin:0 auto 1.25rem;
}
.wordle-row { display:grid; grid-template-columns: repeat(5, 1fr); gap:0.4rem; }
.wordle-cell {
    aspect-ratio:1/1; display:grid; place-items:center;
    font-size:1.6rem; font-weight:900; text-transform:uppercase;
    border:2px solid var(--border-strong); border-radius:0.4rem;
    color:var(--foreground); background:rgba(255,255,255,0.02);
    transition: transform .1s ease; user-select:none;
}
.wordle-cell.has-letter { border-color: rgba(72,189,211,0.5); }
.wordle-cell.flipping { animation: flip .5s ease forwards; }
.wordle-cell.correct {
    background:#3a9bb8; border-color:#3a9bb8; color:#0a1628;
}
.wordle-cell.present {
    background:#d4941a; border-color:#d4941a; color:#0a1628;
}
.wordle-cell.absent {
    background:#1a2a3e; border-color:#1a2a3e; color: var(--muted);
}
.wordle-cell.pop { animation: pop .12s ease; }
@keyframes flip {
    0% { transform: rotateX(0); }
    50% { transform: rotateX(90deg); }
    100% { transform: rotateX(0); }
}
@keyframes pop {
    0% { transform: scale(1); }
    50% { transform: scale(1.08); }
    100% { transform: scale(1); }
}
.wordle-keyboard { display:flex; flex-direction:column; gap:0.4rem; }
.kb-row { display:flex; justify-content:center; gap:0.3rem; }
.kb-key {
    flex:1; max-width:42px; min-width:28px;
    padding:0.7rem 0; border:none; border-radius:0.35rem;
    background:rgba(255,255,255,0.08); color:var(--foreground);
    font-weight:800; font-size:0.95rem; cursor:pointer;
    text-transform:uppercase; transition: background .15s, transform .05s;
}
.kb-key:hover { background:rgba(255,255,255,0.16); }
.kb-key:active { transform: translateY(1px); }
.kb-key.wide { flex:1.6; max-width:64px; font-size:0.72rem; }
.kb-key.correct { background:#3a9bb8; color:#0a1628; }
.kb-key.present { background:#d4941a; color:#0a1628; }
.kb-key.absent { background:#1a2a3e; color:var(--muted); }
.wordle-msg { text-align:center; min-height:1.6rem; margin-bottom:0.75rem; font-weight:700; }
.wordle-msg.win { color: var(--primary); }
.wordle-msg.lose { color: var(--accent-danger); }
.wordle-actions { display:flex; justify-content:center; gap:0.5rem; flex-wrap:wrap; margin-top:1rem; }
@media (max-width: 480px) {
    .wordle-cell { font-size: 1.25rem; }
    .kb-key { font-size:0.85rem; padding:0.6rem 0; }
}
CSS;
?>

<style><?= $wordleCss ?></style>

<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">🎮 Zone jeux</span>
        <h1 class="page-title">Wordle AEIC</h1>
        <p class="page-lead">Devine le mot de 5 lettres du jour. Choisis ta langue et c'est parti&nbsp;!</p>
    </div>
</header>

<section class="section">
    <div class="container wordle-wrap">

        <?php if (!$isLoggedIn): ?>
            <div class="surface card panel-brand" style="margin-bottom:1.25rem; align-items:flex-start;">
                <p style="margin:0; font-size:0.9rem;">🔒 <strong>Mode démo</strong> — tes parties ne sont pas sauvegardées. <a href="<?= e(url('/login?callbackUrl=' . rawurlencode('/jeux/wordle'))) ?>">Connecte-toi</a> pour enregistrer ton score et grimper dans le classement.</p>
            </div>
        <?php endif; ?>

        <div class="wordle-mode" role="tablist" aria-label="Langue du Wordle">
            <button type="button" class="mode-pill" data-mode="fr" role="tab">🇫🇷 Français</button>
            <button type="button" class="mode-pill" data-mode="en" role="tab">🇬🇧 English</button>
        </div>

        <div class="wordle-msg" id="w-msg" role="status" aria-live="polite"></div>

        <div class="wordle-grid" id="w-grid"></div>

        <div class="wordle-keyboard" id="w-keyboard"></div>

        <div class="wordle-actions" id="w-actions" hidden>
            <button type="button" class="btn btn-outline btn-sm" id="w-share">📋 Partager</button>
            <a class="btn btn-primary btn-sm" href="<?= e($leaderboardUrl) ?>">🏆 Classement</a>
        </div>
    </div>
</section>

<script>
(function () {
    // ===================== Dictionnaires (150+ mots/langue) =====================
    var WORDS = {
        fr: [
            "MAISON","TABLE","CHIEN","ARBRE","FLEUR","PLAGE","ROUTE","PORTE","TOITS","JARDIN",
            "AVION","BATEAU","METRO","ECOLE","LIVRE","STYLO","REGLE","POMME","RAISIN","TOMATE",
            "PATATE","BONBON","GATEAU","BISCUIT","PAINS","CREPE","PIZZA","ROUGE","BLANC","JAUNE",
            "ORANGE","VIOLET","GRAND","PETIT","CHAUD","FROID","VITESSE","RAPIDE","LENT","JOIE",
            "PEURS","AMOUR","COLERE","HONTE","REVES","DORMIR","MANGEZ","BOIRE","LIREZ","JOUER",
            "COURIR","NAGER","LUNDI","MARDI","MATIN","SOIRS","NUITS","MOIS","PERES","MERES",
            "FRERE","SOEUR","ONCLE","TANTE","AMIES","HEURE","MINUT","TEMPS","VIEUX","JEUNE",
            "ARGENT","METAL","PIERRE","BRIQUE","VERRE","FER","NEIGE","PLUIE","SOLEIL","NUAGE",
            "TIGRE","LOUPS","OURS","LIONS","SINGE","CHEVAL","VACHE","MERES","MONDE","TERRE",
            "MARS","LUNE","ETOILE","CIEUX","LACS","POULE","CANARD","AIGLE","REQUIN","LAPIN"
        ].filter(function(w){ return w.length === 5; }),
        en: [
            "HOUSE","TABLE","WATER","BREAD","APPLE","CHAIR","MOUSE","LIGHT","NIGHT","MUSIC",
            "RIVER","OCEAN","CLOUD","STORM","BEACH","FIELD","TOWER","TRAIN","PLANE","TRUCK",
            "SCHOOL","CLASS","BOOKS","PAPER","STUDY","TEACH","LEARN","WRITE","GRAPE","LEMON",
            "MANGO","PEACH","MELON","BERRY","SALAD","ONION","BEANS","JUICE","CANDY","SUGAR",
            "COLOR","GREEN","BLACK","WHITE","BROWN","SILVER","BRONZE","COPPER","HAPPY","BRAVE",
            "PROUD","QUIET","SMART","FUNNY","SILLY","DREAM","MONDAY","FRIDAY","SUNDAY","WEEK",
            "MONTH","YEARS","FATHER","MOTHER","SISTER","UNCLE","FRIEND","CHILD","WORLD","EARTH",
            "MONEY","METAL","STONE","BRICK","GLASS","STEEL","IRONS","SNOWS","RAINS","WINDS",
            "SUNNY","FOGGY","FROST","TIGER","WOLF","HORSE","SHEEP","EAGLE","SHARK","WHALE",
            "MOUSE","RABBIT","SNAKE","MONKEY","ZEBRA","PANDA","PARTY","MAGIC","EARTH","HEART"
        ].filter(function(w){ return w.length === 5; })
    };

    // Nettoyage : on ne garde que des mots de 5 lettres A-Z (pour la cohérence du jeu).
    function clean(list) {
        var out = [];
        for (var i = 0; i < list.length; i++) {
            var w = list[i].toUpperCase().replace(/[^A-Z]/g, '');
            if (w.length === 5) { out.push(w); }
        }
        return out;
    }
    WORDS.fr = clean(WORDS.fr);
    WORDS.en = clean(WORDS.en);

    var KEYBOARDS = {
        fr: ['AZERTYUIOP', 'QSDFGHJKLM', 'WXCVBN'],
        en: ['QWERTYUIOP', 'ASDFGHJKL',  'ZXCVBN']
    };

    var MODE_KEY = 'aeic-wordle-mode';
    var SUBMIT_URL = <?= json_encode($submitUrl) ?>;
    var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    var IS_LOGGED_IN = <?= json_encode($isLoggedIn) ?>;
    var PLAYED_TODAY = <?= json_encode($playedToday) ?>;
    var LEADERBOARD_URL = <?= json_encode($leaderboardUrl) ?>;

    // ===================== Mot du jour (déterministe) =====================
    function dayOfYear(d) {
        var start = new Date(d.getFullYear(), 0, 0);
        var diff = d - start;
        return Math.floor(diff / 86400000);
    }
    function wordOfDay(mode) {
        var list = WORDS[mode];
        if (!list.length) { return '?????'; }
        var now = new Date();
        var seed = 2654435761; // Knuth
        var idx = Math.floor((dayOfYear(now) * seed) % list.length);
        return list[(idx + list.length) % list.length];
    }

    // ===================== État =====================
    var state = {
        mode: localStorage.getItem(MODE_KEY) || 'fr',
        answer: '',
        grid: [],          // lignes saisies
        current: '',       // ligne en cours
        row: 0,
        over: false,
        won: false,
        keyStates: {}      // lettre -> 'correct'|'present'|'absent'
    };

    var gridEl = document.getElementById('w-grid');
    var kbEl = document.getElementById('w-keyboard');
    var msgEl = document.getElementById('w-msg');
    var actionsEl = document.getElementById('w-actions');

    // ===================== Rendu grille =====================
    function buildGrid() {
        gridEl.innerHTML = '';
        state.grid = [];
        for (var r = 0; r < 6; r++) {
            var row = document.createElement('div');
            row.className = 'wordle-row';
            var cells = [];
            for (var c = 0; c < 5; c++) {
                var cell = document.createElement('div');
                cell.className = 'wordle-cell';
                row.appendChild(cell);
                cells.push(cell);
            }
            gridEl.appendChild(row);
            state.grid.push(cells);
        }
    }

    // ===================== Rendu clavier =====================
    function buildKeyboard() {
        kbEl.innerHTML = '';
        var rows = KEYBOARDS[state.mode];
        for (var r = 0; r < rows.length; r++) {
            var line = rows[r];
            var rowEl = document.createElement('div');
            rowEl.className = 'kb-row';
            if (r === 1) {
                var spacer = document.createElement('div');
                spacer.style.flex = '0.5';
                rowEl.appendChild(spacer);
            }
            for (var i = 0; i < line.length; i++) {
                rowEl.appendChild(makeKey(line[i], line[i]));
            }
            if (r === 1) {
                var spacer2 = document.createElement('div');
                spacer2.style.flex = '0.5';
                rowEl.appendChild(spacer2);
            }
            if (r === rows.length - 1) {
                rowEl.appendChild(makeKey('ENTRER', 'ENTER', true));
                rowEl.appendChild(makeKey('EFFACER', 'BACK', true));
            }
            kbEl.appendChild(rowEl);
        }
        applyKeyStates();
    }
    function makeKey(label, value, wide) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'kb-key' + (wide ? ' wide' : '');
        btn.textContent = label;
        btn.setAttribute('data-key', value);
        btn.addEventListener('click', function () { handleKey(value); });
        return btn;
    }

    // ===================== Logique de jeu =====================
    function setMode(mode) {
        if (mode !== 'fr' && mode !== 'en') { return; }
        state.mode = mode;
        localStorage.setItem(MODE_KEY, mode);
        document.querySelectorAll('.mode-pill').forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-mode') === mode);
        });
        resetGame();
    }

    function resetGame() {
        state.answer = wordOfDay(state.mode);
        state.current = '';
        state.row = 0;
        state.over = false;
        state.won = false;
        state.keyStates = {};
        msgEl.textContent = '';
        msgEl.className = 'wordle-msg';
        actionsEl.hidden = true;
        buildGrid();
        buildKeyboard();
    }

    function handleKey(key) {
        if (state.over) { return; }
        if (key === 'BACK') {
            state.current = state.current.slice(0, -1);
        } else if (key === 'ENTER') {
            submitGuess();
        } else if (/^[A-Z]$/.test(key) && state.current.length < 5) {
            state.current += key;
        }
        renderCurrentRow();
    }

    function renderCurrentRow() {
        var cells = state.grid[state.row];
        if (!cells) { return; }
        for (var i = 0; i < 5; i++) {
            var ch = state.current[i] || '';
            cells[i].textContent = ch;
            cells[i].classList.toggle('has-letter', ch !== '');
            if (ch) { cells[i].classList.add('pop'); setTimeout((function(c){return function(){c.classList.remove('pop');};})(cells[i]), 130); }
        }
    }

    function evaluate(guess, answer) {
        console.log('[WORDLE] guess=' + guess + ' answer=' + answer);
        var res = new Array(5).fill('absent');
        var remaining = {};
        for (var k = 0; k < 5; k++) {
            var ch = answer[k];
            remaining[ch] = (remaining[ch] || 0) + 1;
        }
        console.log('[WORDLE] remaining init=', JSON.stringify(remaining));
        // 1er passage : correspondances exactes.
        for (var i = 0; i < 5; i++) {
            if (guess[i] === answer[i]) {
                res[i] = 'correct';
                remaining[guess[i]]--;
                console.log('[WORDLE] pos ' + i + ' CORRECT (' + guess[i] + ')');
            }
        }
        console.log('[WORDLE] remaining after greens=', JSON.stringify(remaining));
        // 2e passage : lettres présentes mal placées.
        for (var j = 0; j < 5; j++) {
            if (res[j] === 'correct') { continue; }
            var letter = guess[j];
            if (remaining[letter] > 0) {
                res[j] = 'present';
                remaining[letter]--;
            }
        }
        console.log('[WORDLE] result=', JSON.stringify(res));
        return res;
    }

    function submitGuess() {
        if (state.current.length !== 5) {
            flash('Il faut 5 lettres.');
            return;
        }
        var guess = state.current;
        var result = evaluate(guess, state.answer);
        var cells = state.grid[state.row];

        // Animation flip séquentielle + màj états clavier.
        var i = 0;
        function flipNext() {
            if (i >= 5) {
                updateKeyStates(guess, result);
                afterRow();
                return;
            }
            var cell = cells[i];
            cell.classList.add('flipping');
            setTimeout(function () {
                cell.classList.add(result[i]);
                cell.classList.remove('has-letter');
            }, 250);
            i++;
            setTimeout(flipNext, 280);
        }
        flipNext();
    }

    function afterRow() {
        if (state.current === state.answer) {
            state.won = true;
            state.over = true;
            showEnd(true);
            return;
        }
        state.row++;
        state.current = '';
        if (state.row >= 6) {
            state.over = true;
            state.won = false;
            showEnd(false);
        }
    }

    function showEnd(won) {
        var attempts = state.row + 1;
        if (won) {
            msgEl.textContent = '🎉 Bravo ! Trouvé en ' + attempts + ' essai' + (attempts > 1 ? 's' : '') + ' !';
            msgEl.className = 'wordle-msg win';
        } else {
            msgEl.textContent = '💀 Le mot était : ' + state.answer;
            msgEl.className = 'wordle-msg lose';
        }
        actionsEl.hidden = false;

        if (IS_LOGGED_IN) {
            submit(won, attempts);
        }
    }

    function submit(won, attempts) {
        if (PLAYED_TODAY[state.mode]) {
            // Déjà joué aujourd'hui : on n'enregistre pas.
            return;
        }
        try {
            var body = new URLSearchParams();
            body.append('_csrf', CSRF_TOKEN);
            body.append('mode', state.mode);
            body.append('won', won ? '1' : '0');
            body.append('word', state.answer);
            body.append('attempts', String(attempts));
            fetch(SUBMIT_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data && data.saved && data.stats) {
                    PLAYED_TODAY[state.mode] = true;
                    var s = data.stats;
                    msgEl.textContent += ' — Série : ' + s.currentStreak + ' 🔥 (record ' + s.maxStreak + ' 🏆)';
                }
            }).catch(function () {});
        } catch (e) {}
    }

    function updateKeyStates(guess, result) {
        var rank = { correct: 3, present: 2, absent: 1 };
        for (var i = 0; i < 5; i++) {
            var ch = guess[i];
            var cur = state.keyStates[ch];
            if (!cur || rank[result[i]] > rank[cur]) {
                state.keyStates[ch] = result[i];
            }
        }
        applyKeyStates();
    }
    function applyKeyStates() {
        var keys = kbEl.querySelectorAll('.kb-key');
        keys.forEach(function (k) {
            var v = k.getAttribute('data-key');
            k.classList.remove('correct', 'present', 'absent');
            if (state.keyStates[v]) { k.classList.add(state.keyStates[v]); }
        });
    }

    function flash(text) {
        msgEl.textContent = text;
        msgEl.className = 'wordle-msg';
        gridEl.classList.add('pop');
        setTimeout(function () { gridEl.classList.remove('pop'); }, 120);
    }

    // ===================== Partage =====================
    function emojiForResult() {
        var out = [];
        for (var r = 0; r <= state.row && r < 6; r++) {
            var cells = state.grid[r];
            var line = '';
            for (var c = 0; c < 5; c++) {
                if (cells[c].classList.contains('correct')) { line += '🟩'; }
                else if (cells[c].classList.contains('present')) { line += '🟨'; }
                else { line += '⬛'; }
            }
            out.push(line);
        }
        return out.join('\n');
    }
    document.getElementById('w-share').addEventListener('click', function () {
        var score = state.won ? (state.row + 1) + '/6' : 'X/6';
        var text = 'Wordle AEIC (' + state.mode.toUpperCase() + ') ' + score + '\n' + emojiForResult() + '\n' + LEADERBOARD_URL;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                msgEl.textContent = '📋 Résultat copié !';
            }, function () { fallbackCopy(text); });
        } else {
            fallbackCopy(text);
        }
    });
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); msgEl.textContent = '📋 Résultat copié !'; }
        catch (e) { msgEl.textContent = 'Impossible de copier.'; }
        document.body.removeChild(ta);
    }

    // ===================== Entrée clavier physique =====================
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) { return; }
        if (e.key === 'Enter') { handleKey('ENTER'); e.preventDefault(); }
        else if (e.key === 'Backspace') { handleKey('BACK'); e.preventDefault(); }
        else if (/^[a-zA-Z]$/.test(e.key)) { handleKey(e.key.toUpperCase()); }
    });

    // ===================== Pills de langue =====================
    document.querySelectorAll('.mode-pill').forEach(function (p) {
        p.addEventListener('click', function () { setMode(p.getAttribute('data-mode')); });
    });

    // ===================== Init =====================
    if (PLAYED_TODAY.fr && PLAYED_TODAY.en && IS_LOGGED_IN) {
        // Les deux modes déjà joués : on laisse quand même jouer en démo visuelle
        // mais submit() ne renverra rien.
    }
    setMode(state.mode);
})();
</script>
