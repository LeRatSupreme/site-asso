/*
 * AEIC — Widget assistant public (chat flottant bas-droite).
 * Chargé (defer) par views/layouts/public.php ET views/layouts/kiosk.php
 * (le présent script s'adapte au contexte, cf. détection kiosque ci-dessous).
 *
 * Sécurité rendu : les messages (utilisateur, LLM, erreurs) sont
 * construits EXCLUSIVEMENT via createElement + createTextNode —
 * JAMAIS innerHTML, même si le modèle répète du HTML.
 *
 * Contrat serveur (POST form-urlencoded) :
 *   - site public : POST /assistant/ask — question (1..500),
 *     history (JSON 0..6 × {role, content 1..800}), _csrf
 *     (window.AEIC_CSRF) ;
 *   - kiosque (body.kiosk-body) : POST /kiosque/assistant/ask/{jeton}
 *     — mêmes champs SANS _csrf (le jeton secret de l'URL EST
 *     l'authentification) ;
 *   réponses identiques : 200 {ok,reply} | 400/404/429/503 {ok,error}.
 * Latence LLM jusqu'à 60 s : indicateur « réfléchit… » + bouton
 * désactivé + timeout client 65 s (AbortController).
 */
(function () {
    'use strict';

    // Contexte d'exécution : le layout kiosque pose body.kiosk-body. Sur
    // une page kiosque, toutes les URLs sont de la forme /kiosque/.../{jeton}
    // (cf. config/routes.php) : le jeton est le DERNIER segment non vide de
    // location.pathname, repris tel quel (déjà sous forme URL). Hors kiosque
    // (site public), le comportement reste STRICTEMENT inchangé : même
    // endpoint /assistant/ask avec _csrf.
    var IS_KIOSK = document.body.classList.contains('kiosk-body');
    var ENDPOINT = '/assistant/ask';
    var USE_CSRF = true;
    if (IS_KIOSK) {
        var segments = location.pathname.split('/').filter(function (s) { return s !== ''; });
        var kioskToken = segments.length > 0 ? segments[segments.length - 1] : '';
        ENDPOINT = '/kiosque/assistant/ask/' + kioskToken;
        USE_CSRF = false;
    }

    var MAX_QUESTION = 500;
    var MAX_HISTORY  = 6;   // items envoyés dans `history` (le serveur valide)
    var MAX_CONTENT  = 800; // limite serveur par contenu d'item d'historique
    var TIMEOUT_MS   = 65000;
    var MSG_INDISPO  = "L'assistant est indisponible, réessaie dans un instant.";
    var WELCOME      = "Salut, je suis l'assistant de l'AEIC — pose-moi tes questions sur l'asso, les événements, la cafétéria ou les jeux du site !";

    var bubble = document.getElementById('assistant-bubble');
    var panel  = document.getElementById('assistant-panel');
    if (!bubble || !panel) { return; }

    var log   = document.getElementById('assistant-log');
    var form  = document.getElementById('assistant-form');
    var input = document.getElementById('assistant-input');
    var send  = document.getElementById('assistant-send');
    var count = document.getElementById('assistant-count');
    var clear = document.getElementById('assistant-clear');
    var close = document.getElementById('assistant-close');
    if (!log || !form || !input || !send || !count || !clear || !close) { return; }

    var history = []; // mémoire de la page courante : [{role, content}]
    var busy    = false;

    // ------------------------------------------------------------------
    // Rendu — textContent / createTextNode uniquement
    // ------------------------------------------------------------------
    function scrollLog() { log.scrollTop = log.scrollHeight; }

    function appendMessage(role, text, isError) {
        var wrap = document.createElement('div');
        wrap.className = 'assistant-msg assistant-msg-' + role;

        var bulle = document.createElement('div');
        bulle.className = 'assistant-msg-bubble' + (isError ? ' is-error' : '');
        bulle.appendChild(document.createTextNode(text));

        wrap.appendChild(bulle);
        log.appendChild(wrap);
        scrollLog();
    }

    function showThinking() {
        hideThinking();

        var wrap = document.createElement('div');
        wrap.className = 'assistant-msg assistant-msg-assistant';
        wrap.id = 'assistant-thinking';

        var bulle = document.createElement('div');
        bulle.className = 'assistant-msg-bubble assistant-thinking';

        var dots = document.createElement('span');
        dots.className = 'assistant-dots';
        dots.setAttribute('aria-hidden', 'true');
        dots.appendChild(document.createElement('span'));
        dots.appendChild(document.createElement('span'));
        dots.appendChild(document.createElement('span'));

        var label = document.createElement('span');
        label.className = 'assistant-thinking-label';
        label.textContent = 'L\u2019assistant r\u00e9fl\u00e9chit\u2026';

        bulle.appendChild(dots);
        bulle.appendChild(label);
        wrap.appendChild(bulle);
        log.appendChild(wrap);
        scrollLog();
    }

    function hideThinking() {
        var t = document.getElementById('assistant-thinking');
        if (t && t.parentNode) { t.parentNode.removeChild(t); }
    }

    // ------------------------------------------------------------------
    // Ouverture / fermeture (clic, Échap, gestion du focus)
    // ------------------------------------------------------------------
    function openPanel() {
        panel.hidden = false;
        bubble.setAttribute('aria-expanded', 'true');
        bubble.setAttribute('aria-label', 'Fermer le chat assistant');
        scrollLog();
        try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); }
    }

    function closePanel() {
        panel.hidden = true;
        bubble.setAttribute('aria-expanded', 'false');
        bubble.setAttribute('aria-label', 'Ouvrir le chat assistant');
        bubble.focus();
    }

    bubble.addEventListener('click', function () {
        if (panel.hidden) { openPanel(); } else { closePanel(); }
    });
    close.addEventListener('click', closePanel);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !panel.hidden) { closePanel(); }
    });

    // ------------------------------------------------------------------
    // Saisie : compteur 500 caractères, autosize, Entrée = envoyer
    // ------------------------------------------------------------------
    function updateCount() {
        var n = input.value.length;
        count.textContent = n + ' / ' + MAX_QUESTION;
        count.classList.toggle('is-max', n >= MAX_QUESTION);
    }

    function autosize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 110) + 'px';
    }

    input.addEventListener('input', function () {
        updateCount();
        autosize();
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (busy) { return; }
            if (form.requestSubmit) { form.requestSubmit(); }
            else { form.dispatchEvent(new Event('submit', { cancelable: true })); }
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) { return; }
        var question = input.value.trim();
        if (question === '') { input.focus(); return; } // refus d'envoi vide
        ask(question);
    });

    // ------------------------------------------------------------------
    // Envoi POST /assistant/ask
    // ------------------------------------------------------------------
    function ask(question) {
        // Historique envoyé : 6 derniers messages AVANT la question
        // courante (le serveur reconstruit système + history + question).
        var payloadHistory = history.slice(-MAX_HISTORY).map(function (m) {
            return { role: m.role, content: String(m.content).slice(0, MAX_CONTENT) };
        });

        appendMessage('user', question);
        history.push({ role: 'user', content: question });

        busy = true;
        send.disabled = true;
        input.value = '';
        updateCount();
        autosize();
        showThinking();

        var ctrl  = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, TIMEOUT_MS) : null;

        var params = new URLSearchParams();
        params.set('question', question);
        params.set('history', JSON.stringify(payloadHistory));
        // Kiosque : PAS de champ _csrf — le jeton de l'URL est l'auth.
        if (USE_CSRF) {
            params.set('_csrf', window.AEIC_CSRF || '');
        }

        fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: params.toString(),
            signal: ctrl ? ctrl.signal : undefined
        }).then(function (r) {
            // Corps non-JSON (page d'erreur HTML…) → data null → message générique.
            return r.json().then(
                function (data) { return data; },
                function () { return null; }
            );
        }).then(function (data) {
            hideThinking();
            if (data && data.ok === true && typeof data.reply === 'string' && data.reply !== '') {
                appendMessage('assistant', data.reply);
                history.push({ role: 'assistant', content: data.reply.slice(0, MAX_CONTENT) });
            } else if (data && typeof data.error === 'string' && data.error !== '') {
                // Messages courts et sûrs du contrat serveur, affichés tels quels.
                appendMessage('assistant', data.error, true);
            } else {
                appendMessage('assistant', MSG_INDISPO, true);
            }
        }).catch(function () {
            hideThinking();
            appendMessage('assistant', MSG_INDISPO, true);
        }).then(function () {
            if (timer) { clearTimeout(timer); }
            busy = false;
            send.disabled = false;
            if (!panel.hidden) { input.focus(); }
        });
    }

    // ------------------------------------------------------------------
    // Effacer la conversation (mémoire + affichage), puis ré-accueil
    // ------------------------------------------------------------------
    clear.addEventListener('click', function () {
        history = [];
        while (log.firstChild) { log.removeChild(log.firstChild); }
        appendMessage('assistant', WELCOME);
        input.focus();
    });

    // ------------------------------------------------------------------
    // Init : accueil rendu en local (jamais via LLM)
    // ------------------------------------------------------------------
    appendMessage('assistant', WELCOME);
    updateCount();
})();
