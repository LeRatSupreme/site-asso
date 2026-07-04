<?php

declare(strict_types=1);

/** @var array<string,mixed>|null $enigma */
/** @var string $lang */
/** @var string $submitUrl */
/** @var string $csrfToken */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <a class="btn btn-outline" href="<?= e(url('/jeux')) ?>" style="margin-bottom:0.75rem;text-decoration:none;">← Retour aux jeux</a>
        <span class="eyebrow">🧩 Défi du jour</span>
        <h1 class="page-title">Énigme quotidienne</h1>
        <p class="page-lead">Une devinette par jour, identique pour tous. Elle change à minuit (heure de Paris).</p>
    </div>
</header>

<section class="section">
    <div class="container" style="max-width:640px;">

        <?php if ($enigma === null): ?>
            <p style="text-align:center;color:var(--muted);">Aucune énigme n'est disponible pour le moment. Revenez demain !</p>
        <?php else: ?>
            <?php
                $isFr = ($lang !== 'en');
                $question = $isFr ? ($enigma['question_fr'] ?? '') : ($enigma['question_en'] ?? '');
                $hint = $isFr ? ($enigma['hint_fr'] ?? null) : ($enigma['hint_en'] ?? null);
            ?>

            <!-- Carte de l'énigme -->
            <div class="enigma-card">
                <div class="enigma-badge">📅 Énigme du <?= e(date('d/m/Y')) ?></div>
                <p class="enigma-question"><?= e($question) ?></p>

                <?php if ($hint !== null && $hint !== ''): ?>
                    <details class="enigma-hint">
                        <summary>💡 Indice</summary>
                        <p><?= e($hint) ?></p>
                    </details>
                <?php endif; ?>
            </div>

            <!-- Formulaire de réponse -->
            <div class="enigma-form">
                <label class="enigma-label" for="enigma-answer">Ta réponse</label>
                <div class="enigma-input-row">
                    <input
                        type="text"
                        id="enigma-answer"
                        class="enigma-input"
                        autocomplete="off"
                        placeholder="<?= $isFr ? 'Écris ta réponse ici…' : 'Type your answer here…' ?>"
                    />
                    <button type="button" class="btn btn-primary" id="enigma-submit">Valider</button>
                </div>
                <div id="enigma-result" style="display:none;margin-top:1rem;text-align:center;font-weight:700;font-size:1.1rem;"></div>
            </div>
        <?php endif; ?>

    </div>
</section>

<style>
.enigma-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border-strong);
    border-radius: 1rem;
    padding: 1.75rem;
    margin-bottom: 1.5rem;
}
.enigma-badge {
    display: inline-block;
    background: var(--primary);
    color: #0a1628;
    font-weight: 800;
    font-size: 0.8rem;
    padding: 0.3rem 0.8rem;
    border-radius: 999px;
    margin-bottom: 1rem;
}
.enigma-question {
    font-size: 1.35rem;
    line-height: 1.6;
    font-weight: 600;
    margin: 0 0 1rem 0;
    color: var(--foreground);
}
.enigma-hint {
    margin-top: 1rem;
    border-top: 1px solid var(--border-strong);
    padding-top: 1rem;
}
.enigma-hint summary {
    cursor: pointer;
    font-weight: 700;
    color: var(--muted);
}
.enigma-hint p {
    margin-top: 0.6rem;
    color: var(--muted);
    font-style: italic;
}

.enigma-form {
    background: rgba(255,255,255,0.02);
    border: 1px solid var(--border-strong);
    border-radius: 1rem;
    padding: 1.5rem;
}
.enigma-label {
    display: block;
    font-weight: 700;
    margin-bottom: 0.5rem;
    color: var(--muted);
    font-size: 0.85rem;
}
.enigma-input-row {
    display: flex;
    gap: 0.5rem;
}
.enigma-input {
    flex: 1;
    padding: 0.7rem 1rem;
    border-radius: 0.5rem;
    border: 2px solid var(--border-strong);
    background: rgba(255,255,255,0.04);
    color: var(--foreground);
    font-size: 1rem;
}
.enigma-input:focus {
    outline: none;
    border-color: var(--primary);
}
</style>

<script>
(function() {
    'use strict';

    var SUBMIT_URL = <?= json_encode($submitUrl) ?>;
    var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

    var input = document.getElementById('enigma-answer');
    var btn = document.getElementById('enigma-submit');
    var result = document.getElementById('enigma-result');

    function check() {
        var answer = (input.value || '').trim();
        if (answer === '') return;

        btn.disabled = true;
        btn.textContent = '…';
        result.style.display = 'none';

        fetch(SUBMIT_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            credentials: 'same-origin',
            body: JSON.stringify({ answer: answer, _csrf: CSRF_TOKEN })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.textContent = 'Valider';
            result.style.display = 'block';
            if (data.correct) {
                result.textContent = '🎉 Bravo ! Bonne réponse !';
                result.style.color = 'var(--primary)';
                btn.disabled = true;
            } else {
                result.textContent = '❌ Ce n\'est pas la bonne réponse. Réessaie !';
                result.style.color = 'var(--accent-danger)';
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Valider';
            result.style.display = 'block';
            result.textContent = 'Erreur de connexion. Réessaie.';
            result.style.color = 'var(--accent-danger)';
        });
    }

    btn.addEventListener('click', check);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') check();
    });
})();
</script>
