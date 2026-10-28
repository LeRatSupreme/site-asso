<?php

declare(strict_types=1);

/**
 * Page « Assistant » (T4-G) — interface admin du pont local (T4-F) :
 * un champ de question, un bouton « Poser », la réponse et ses sources.
 *
 * Zéro framework : un fetch vers POST /admin/assistant/ask (CSRF par
 * en-tête X-CSRF-Token), puis insertion par textContent UNIQUEMENT —
 * aucun innerHTML sur une donnée du serveur (échappement systématique).
 *
 * Le champ de question est en 16 px (leçon n°12 : iOS ne zoome pas si
 * font-size ≥ 16 px) ; la mise en page est fluide, sans largeur fixe
 * (leçon n°13 : mobile-friendly). Caractères UTF-8 directs (leçon n°10).
 *
 * Mémoire de conversation (T4-P1-B) : l'historique de la session est
 * affiché en bulles sobres (rendu serveur échappé e() au chargement,
 * puis reconstruction par textContent après chaque réponse) ; le bouton
 * « Nouvelle conversation » purge la mémoire (POST reset=1).
 *
 * @var array<string,mixed> $user        Utilisateur connecté (garde inventory).
 * @var string              $askUrl      URL du endpoint POST interne (ask).
 * @var list<array{role:string,texte:string}> $historique Bulles de la session.
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Assistant</h1>
        <p class="muted">Pose une question sur les ventes, le stock ou les commandes : l'assistant choisit l'outil adapté et répond en citant ses sources.</p>
    </div>
</div>

<section class="card surface glass assistant-carton" id="assistant-carton" data-ask-url="<?= e($askUrl) ?>">
    <h2 class="card-title">Ta question</h2>

    <form id="assistant-forme" method="post" action="<?= e($askUrl) ?>">
        <?= csrf_field() ?>
        <div class="assistant-saisie">
            <label class="assistant-libelle" for="assistant-question">Ta question à l'assistant</label>
            <input type="text" id="assistant-question" name="question" class="assistant-champ"
                   maxlength="500" autocomplete="off" enterkeyhint="send"
                   placeholder="Ex. : Il me reste combien de Bueno ?" required>
            <button type="submit" id="assistant-poser" class="btn btn-primary">Poser</button>
        </div>
    </form>

    <?php $bulles = is_array($historique ?? null) ? $historique : []; ?>
    <div id="assistant-conversation" class="assistant-conversation"<?php if ($bulles === []): ?> hidden<?php endif; ?>>
        <div class="assistant-conversation-tete">
            <p class="assistant-conversation-titre">Conversation de cette session</p>
            <button type="button" id="assistant-reset" class="btn btn-ghost btn-sm">Nouvelle conversation</button>
        </div>
        <div id="assistant-bulles" class="assistant-bulles">
            <?php foreach ($bulles as $bulle): ?>
                <div class="assistant-bulle <?= $bulle['role'] === 'user' ? 'assistant-bulle-user' : 'assistant-bulle-assistant' ?>">
                    <p class="assistant-bulle-role"><?= $bulle['role'] === 'user' ? 'Toi' : 'Assistant' ?></p>
                    <p class="assistant-bulle-texte"><?= e((string) $bulle['texte']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="assistant-resultat" class="assistant-resultat" hidden aria-live="polite"></div>

    <p class="card-meta">
        Modèle local (rien n'est envoyé à l'extérieur) — une réponse peut prendre de 20 secondes à 2 minutes.
        Tout chiffre affiché est sourcé : l'outil appelé et ses paramètres sont listés sous la réponse.
    </p>
</section>

<style>
    .assistant-libelle {
        position: absolute; width: 1px; height: 1px; margin: -1px;
        overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;
    }

    /* Mémoire de conversation (T4-P1-B) : bulles sobres façon cartes glass,
       sans framework, fluides en mobile (leçon n°13). */
    .assistant-conversation { margin-top: 1.2rem; }
    .assistant-conversation-tete {
        display: flex; flex-wrap: wrap; align-items: center;
        justify-content: space-between; gap: 0.5rem;
    }
    .assistant-conversation-titre {
        margin: 0; font-size: 0.75rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.06em;
        color: var(--muted, #8892a6);
    }
    .assistant-bulles {
        display: flex; flex-direction: column; gap: 0.55rem; margin-top: 0.6rem;
    }
    .assistant-bulle {
        max-width: min(92%, 46rem);
        padding: 0.6rem 0.85rem; border-radius: 14px;
        border: 1px solid rgba(255, 255, 255, 0.08);
        background: rgba(255, 255, 255, 0.04);
    }
    .assistant-bulle-user {
        align-self: flex-end;
        background: rgba(72, 189, 211, 0.10);
        border-color: rgba(72, 189, 211, 0.25);
    }
    .assistant-bulle-assistant { align-self: flex-start; }
    .assistant-bulle-role {
        margin: 0 0 0.2rem; font-size: 0.7rem; font-weight: 700;
        color: var(--muted, #8892a6);
    }
    .assistant-bulle-texte {
        margin: 0; white-space: pre-wrap; overflow-wrap: anywhere;
        font-size: 0.95rem; line-height: 1.5;
    }
    .assistant-saisie { display: flex; flex-wrap: wrap; gap: 0.6rem; }
    .assistant-champ {
        flex: 1 1 240px; min-width: 0;
        font-size: 16px; /* leçon n°12 : pas de zoom automatique iOS */
        padding: 0.7rem 0.9rem;
        border-radius: 12px;
        border: 1px solid var(--border, rgba(255, 255, 255, 0.15));
        background: rgba(255, 255, 255, 0.05);
        color: var(--foreground, inherit);
        font-family: inherit;
    }
    .assistant-champ:focus {
        outline: 2px solid var(--primary, #48bdd3); outline-offset: 1px;
        border-color: var(--primary, #48bdd3);
    }
    #assistant-poser { flex: 0 0 auto; }

    .assistant-resultat {
        margin-top: 1rem; padding: 1rem 1.1rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 14px;
    }
    .assistant-question-posee { margin: 0 0 0.6rem; font-size: 0.82rem; color: var(--muted, #8892a6); }
    .assistant-reponse {
        margin: 0; white-space: pre-wrap; overflow-wrap: anywhere;
        font-size: 1rem; line-height: 1.55;
    }
    .assistant-etat {
        margin: 0; font-size: 0.95rem; color: var(--muted, #8892a6);
        display: flex; align-items: center; gap: 0.55rem;
    }
    .assistant-etat.assistant-erreur { color: var(--danger, #f87171); }
    .assistant-point {
        width: 0.55rem; height: 0.55rem; border-radius: 50%;
        background: var(--primary, #48bdd3);
        animation: assistant-pulse 1.1s ease-in-out infinite;
        flex: 0 0 auto;
    }
    @keyframes assistant-pulse { 0%, 100% { opacity: 0.25; } 50% { opacity: 1; } }

    .assistant-sources { margin: 0.8rem 0 0; padding: 0; list-style: none; }
    .assistant-sources li {
        font-size: 0.8rem; color: var(--muted, #8892a6);
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 8px; padding: 0.35rem 0.6rem;
        overflow-wrap: anywhere;
    }
    .assistant-sources .assistant-sources-titre { border: none; background: none; padding: 0 0 0.25rem; font-weight: 700; }
    .assistant-meta { margin: 0.7rem 0 0; font-size: 0.78rem; color: var(--muted, #8892a6); }
    .assistant-avertissement {
        margin: 0.7rem 0 0; font-size: 0.85rem; font-weight: 700;
        color: #fbbf24;
    }
</style>

<script>
(function () {
    'use strict';

    var carton = document.getElementById('assistant-carton');
    var forme = document.getElementById('assistant-forme');
    var champ = document.getElementById('assistant-question');
    var bouton = document.getElementById('assistant-poser');
    var boutonReset = document.getElementById('assistant-reset');
    var zone = document.getElementById('assistant-resultat');
    var conversation = document.getElementById('assistant-conversation');
    var zoneBulles = document.getElementById('assistant-bulles');
    if (!carton || !forme || !champ || !bouton || !zone) return;

    var urlAsk = carton.getAttribute('data-ask-url') || '';
    var champCsrf = forme.querySelector('input[name="_csrf"]');
    var jetonCsrf = champCsrf ? champCsrf.value : '';
    var minuteur = null;

    // Tout est inséré par textContent : aucune donnée serveur ne passe
    // par innerHTML (échappement systématique, aucune injection HTML).
    function texte(parent, balise, contenu) {
        var el = document.createElement(balise);
        el.textContent = contenu;
        parent.appendChild(el);
        return el;
    }

    function viderZone() {
        while (zone.firstChild) zone.removeChild(zone.firstChild);
    }

    // Mémoire de conversation (T4-P1-B) : reconstruction des bulles par
    // textContent uniquement — jamais de innerHTML sur une donnée serveur.
    function afficherConversation(historique) {
        if (!conversation || !zoneBulles) return;
        while (zoneBulles.firstChild) zoneBulles.removeChild(zoneBulles.firstChild);
        for (var j = 0; j < historique.length; j++) {
            var entree = historique[j];
            var bulle = document.createElement('div');
            bulle.className = 'assistant-bulle ' + (entree.role === 'user'
                ? 'assistant-bulle-user' : 'assistant-bulle-assistant');
            texte(bulle, 'p', entree.role === 'user' ? 'Toi' : 'Assistant').className = 'assistant-bulle-role';
            texte(bulle, 'p', String(entree.texte || '')).className = 'assistant-bulle-texte';
            zoneBulles.appendChild(bulle);
        }
        conversation.hidden = historique.length === 0;
    }

    function afficherAttente(question) {
        viderZone();
        zone.hidden = false;
        texte(zone, 'p', 'Q : ' + question).className = 'assistant-question-posee';
        var etat = texte(zone, 'p', '');
        etat.className = 'assistant-etat';
        var point = document.createElement('span');
        point.className = 'assistant-point';
        etat.appendChild(point);
        var libelle = document.createElement('span');
        libelle.textContent = 'Réflexion en cours… 0 s';
        etat.appendChild(libelle);

        var debut = Date.now();
        var rafraichir = function () {
            libelle.textContent = 'Réflexion en cours… ' + Math.floor((Date.now() - debut) / 1000) + ' s';
        };
        minuteur = window.setInterval(rafraichir, 1000);
    }

    function afficherErreur(message) {
        viderZone();
        zone.hidden = false;
        var p = texte(zone, 'p', message);
        p.className = 'assistant-etat assistant-erreur';
    }

    function afficherReponse(json, question) {
        viderZone();
        zone.hidden = false;

        // La conversation complète (bulles, dernière question comprise)
        // est renvoyée par le serveur : la zone de résultat ne répète pas
        // la réponse, elle montre sources, durée et avertissement. Garde :
        // sans historique (vieille page en cache), réponse isolée.
        if (Array.isArray(json.historique) && json.historique.length > 0) {
            afficherConversation(json.historique);
        } else {
            texte(zone, 'p', 'Q : ' + question).className = 'assistant-question-posee';
            texte(zone, 'p', String(json.reponse || '')).className = 'assistant-reponse';
        }

        var sources = json.sources || [];
        if (sources.length > 0) {
            var liste = document.createElement('ul');
            liste.className = 'assistant-sources';
            texte(liste, 'li', 'Sources :').className = 'assistant-sources-titre';
            for (var i = 0; i < sources.length; i++) {
                texte(liste, 'li', String(sources[i]));
            }
            zone.appendChild(liste);
        }

        var dureeS = Math.round(Number(json.duree_ms || 0)) / 1000;
        var appels = Number(json.appels || 0);
        var meta = 'Réponse en ' + dureeS.toFixed(1).replace('.', ',') + ' s · '
            + appels + ' appel' + (appels > 1 ? 's' : '') + ' d\'outil';
        texte(zone, 'p', meta).className = 'assistant-meta';

        if (json.avertissement) {
            texte(zone, 'p', String(json.avertissement)).className = 'assistant-avertissement';
        }
    }

    function relacher() {
        if (minuteur !== null) { window.clearInterval(minuteur); minuteur = null; }
        bouton.disabled = false;
        champ.disabled = false;
    }

    forme.addEventListener('submit', function (evenement) {
        evenement.preventDefault();

        var question = champ.value.trim();
        if (question === '') {
            champ.focus();
            return;
        }

        // Une seule question à la fois : le modèle local est lent.
        bouton.disabled = true;
        champ.disabled = true;
        afficherAttente(question);

        var abandon = ('AbortController' in window) ? new AbortController() : null;
        var minuteurAbandon = window.setTimeout(function () {
            if (abandon) abandon.abort();
        }, 135000); // le serveur coupe à 120 s + marge réseau

        fetch(urlAsk, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': jetonCsrf },
            body: new URLSearchParams({ question: question }),
            signal: abandon ? abandon.signal : undefined
        }).then(function (reponse) {
            return reponse.json().catch(function () { return null; }).then(function (json) {
                return { httpOk: reponse.ok, json: json };
            });
        }).then(function (resultat) {
            relacher();
            var json = resultat.json;
            if (resultat.httpOk && json && json.ok === true) {
                afficherReponse(json, question);
            } else {
                // Échec honnête : message serveur s'il y en a un, générique sinon.
                afficherErreur(json && json.error
                    ? String(json.error)
                    : 'L\'assistant n\'a pas pu répondre. Reformule la question.');
            }
        }).catch(function () {
            relacher();
            afficherErreur('Connexion interrompue — la question a été annulée. Repose-la.');
        }).finally(function () {
            window.clearTimeout(minuteurAbandon);
            champ.focus();
        });
    });

    // « Nouvelle conversation » (T4-P1-B) : purge de la mémoire serveur
    // (POST reset=1), puis affichage vidé — aucune question requise.
    if (boutonReset) {
        boutonReset.addEventListener('click', function () {
            boutonReset.disabled = true;
            fetch(urlAsk, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': jetonCsrf },
                body: new URLSearchParams({ reset: '1' })
            }).then(function (reponse) {
                return reponse.json().catch(function () { return null; });
            }).then(function (json) {
                boutonReset.disabled = false;
                if (json && json.ok === true) {
                    afficherConversation([]);
                    viderZone();
                    zone.hidden = true;
                } else {
                    afficherErreur('Impossible de réinitialiser la conversation — recharge la page.');
                }
            }).catch(function () {
                boutonReset.disabled = false;
                afficherErreur('Connexion interrompue — la conversation n\'a pas été réinitialisée.');
            });
        });
    }
})();
</script>
