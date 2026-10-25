<?php

declare(strict_types=1);

/**
 * Page « Kiosques » (Système) : tous les liens kiosque centralisés, en
 * deux catégories — Membres (à partager) et Admins (données financières) —
 * plus les kiosques personnalisés (un par type de personne, tuiles au
 * choix dans le catalogue).
 *
 * Les jetons ne figurent JAMAIS dans le HTML initial (mission §10 : aucune
 * capture d'écran ne doit suffire à obtenir un accès) : chaque lien est
 * masqué (« •••••••• ») et révélé à la demande par un POST CSRF vers
 * /admin/kiosques/reveal, tracé dans le journal d'audit.
 *
 * @var array<string,mixed> $user
 * @var list<array{emoji:string,label:string,desc:string,path:string,main?:bool,scope:string}> $memberPages
 * @var list<array{emoji:string,label:string,desc:string,path?:string,scope?:string,url?:string,kiosk?:bool,main?:bool}> $adminPages
 * @var list<array{id:string,name:string,token:string,pages:list<string>,options:?list<string>,created_by:string,created_at:string,updated_at:?string}> $customKiosques
 * @var list<array{id:string,emoji:string,label:string,desc:string,path:string}> $tileCatalog
 * @var list<array{id:string,emoji:string,label:string,desc:string}> $optionCatalog
 */
?>
<style>
    .kq-section { margin-bottom: 1.6rem; }
    .kq-section-title {
        display: flex; align-items: center; gap: 0.5rem;
        margin: 0 0 0.65rem; font-size: 1rem; font-weight: 900;
    }
    .kq-section-sub { margin: -0.3rem 0 0.8rem; font-size: 0.82rem; color: var(--muted, #8892a6); }
    /* Grille 2 colonnes : plus dense, tout se voit d'un coup d'œil. */
    .kq-grid {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem; align-items: stretch;
    }
    .kq-card {
        display: flex; flex-direction: column; gap: 0.45rem;
        padding: 1rem 1.1rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 14px;
        min-width: 0;
    }
    .kq-card.is-main { grid-column: 1 / -1; border-color: rgba(72, 189, 211, 0.45); background: rgba(72, 189, 211, 0.06); }
    .kq-card.is-admin { border-left: 4px solid rgba(97, 80, 170, 0.65); }
    .kq-head { display: flex; align-items: baseline; gap: 0.5rem; flex-wrap: wrap; }
    .kq-emoji { font-size: 1.25rem; }
    .kq-label { font-size: 1.02rem; font-weight: 900; }
    .kq-badge {
        font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;
        color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.14);
        border-radius: 999px; padding: 0.1rem 0.55rem;
    }
    .kq-desc { margin: 0; font-size: 0.85rem; color: var(--muted, #8892a6); }
    .kq-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-top: auto; }
    .kq-row input {
        flex: 1 1 200px; min-width: 0; padding: 0.5rem 0.7rem;
        border: 1px solid var(--border); border-radius: 8px;
        background: rgba(255, 255, 255, 0.04); color: var(--foreground);
        font-size: 0.8rem; font-family: monospace;
    }
    @media (max-width: 860px) {
        .kq-grid { grid-template-columns: 1fr; }
    }
    .kq-note { font-size: 0.85rem; color: var(--muted, #8892a6); margin: 0 0 1rem; max-width: 720px; }
    .kq-note strong { color: var(--foreground); }
    /* Kiosques personnalisés : chips de pages, cases à cocher, édition. */
    .kq-chips { display: flex; flex-wrap: wrap; gap: 0.3rem; }
    .kq-chip {
        font-size: 0.72rem; font-weight: 700; white-space: nowrap;
        padding: 0.12rem 0.55rem; border-radius: 999px;
        background: rgba(255, 255, 255, 0.07);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    .kq-field { display: flex; flex-direction: column; gap: 0.25rem; margin: 0.55rem 0; }
    .kq-field > label { font-size: 0.78rem; font-weight: 800; }
    .kq-field input[type="text"] {
        padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 8px;
        background: rgba(255, 255, 255, 0.04); color: var(--foreground);
    }
    .kq-checks {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(215px, 1fr));
        gap: 0.35rem; margin: 0.55rem 0;
    }
    .kq-check {
        display: flex; align-items: center; gap: 0.45rem; cursor: pointer;
        font-size: 0.8rem; padding: 0.28rem 0.5rem; border-radius: 8px;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.06);
    }
    .kq-check:hover { background: rgba(255, 255, 255, 0.06); }
    .kq-opts-title {
        margin: 0.75rem 0 0.1rem; font-size: 0.78rem; font-weight: 900;
        text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted, #8892a6);
    }
    .kq-opts-note { margin: 0.15rem 0 0; font-size: 0.72rem; color: var(--muted, #8892a6); }
    .kq-chip.kq-chip-on { color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.12); border-color: rgba(72, 189, 211, 0.35); }
    .kq-details {
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 10px; padding: 0.45rem 0.7rem;
    }
    .kq-details summary {
        cursor: pointer; font-weight: 800; font-size: 0.85rem;
        user-select: none;
    }
    .kq-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.55rem; align-items: center; }
    .kq-actions form { margin: 0; }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Système</p>
        <h1 class="page-title">Kiosques</h1>
        <p class="muted">Tous les liens d'accès téléphone, sans connexion, en deux catégories.</p>
    </div>
</div>

<div class="card surface glass" style="padding: 0.95rem 1.1rem; margin-bottom: 1.4rem;">
    <p class="kq-note">
        L'authentification du kiosque <strong>est le lien lui-même</strong> : ne partage les liens
        « Membres » qu'aux membres du bureau. Chaque action (comptage, pause, case cochée) exige
        l'identité du membre (prénom, nom, rôle) et est tracée en son nom. Les liens « Admins »
        donnent accès aux <strong>données financières</strong> — récap complet, analytics, bilan.
        La régénération du lien <strong>révoque tous les liens d'un coup</strong>.
        Les liens sont <strong>masqués à l'écran</strong> : affiche seulement celui que tu utilises —
        chaque affichage est tracé dans le journal d'audit.
    </p>
    <form method="post" action="<?= e(url('/admin/kiosques/regenerate')) ?>"
          data-confirm="Régénérer le lien MEMBRES ? Tous les liens membres déjà partagés (hub, comptages, liste, ventes du jour) cesseront de fonctionner."
          data-confirm-button="Régénérer membres">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="membres">
        <button type="submit" class="btn btn-outline btn-sm">Régénérer le lien membres</button>
    </form>
    <form method="post" action="<?= e(url('/admin/kiosques/regenerate')) ?>" style="margin-top: 0.5rem;"
          data-confirm="Régénérer le lien ADMIN ? Tous les liens financiers déjà copiés (hub admin, récaps) cesseront de fonctionner."
          data-confirm-button="Régénérer admins">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="admins">
        <button type="submit" class="btn btn-outline btn-sm">Régénérer le lien admins</button>
    </form>
</div>

<section class="kq-section">
    <h2 class="kq-section-title">Membres — à partager</h2>
    <p class="kq-section-sub">Outils de saisie et suivi léger : aucun bénéfice ni donnée financière sensible.</p>

    <div class="kq-grid">
    <?php foreach ($memberPages as $p): ?>
    <div class="kq-card<?= !empty($p['main']) ? ' is-main' : '' ?>">
        <div class="kq-head">
            <span class="kq-emoji"><?= $p['emoji'] ?></span>
            <span class="kq-label"><?= e($p['label']) ?></span>
            <?php if (!empty($p['main'])): ?><span class="kq-badge">à partager</span><?php endif; ?>
        </div>
        <p class="kq-desc"><?= e($p['desc']) ?></p>
        <div class="kq-row" data-kq-scope="<?= e($p['scope']) ?>" data-kq-path="<?= e($p['path']) ?>">
            <input type="text" readonly value="••••••••" onclick="this.select()"
                   aria-label="Lien <?= e($p['label']) ?> — masqué, utilise le bouton « Afficher le lien »">
            <button type="button" class="btn btn-outline btn-sm kq-reveal" aria-expanded="false">Afficher le lien</button>
            <button type="button" class="btn btn-outline btn-sm kq-copy">Copier</button>
            <a class="btn btn-ghost btn-sm kq-open" hidden>Ouvrir ↗</a>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</section>

<section class="kq-section">
    <h2 class="kq-section-title">Admins — pour ton téléphone</h2>
    <p class="kq-section-sub">Données financières complètes. Les pages /admin demandent la connexion admin (mémorisée sur ton téléphone) ; les liens kiosque marchent sans connexion.</p>

    <div class="kq-grid">
    <?php foreach ($adminPages as $p): ?>
    <div class="kq-card is-admin<?= !empty($p['main']) ? ' is-main' : '' ?>">
        <div class="kq-head">
            <span class="kq-emoji"><?= $p['emoji'] ?></span>
            <span class="kq-label"><?= e($p['label']) ?></span>
            <?php if (!empty($p['main'])): ?><span class="kq-badge">à garder sous la main</span><?php endif; ?>
            <?php if (empty($p['kiosk'])): ?><span class="kq-badge" style="color:#a78bfa;background:rgba(97,80,170,0.16)">connexion requise</span><?php endif; ?>
        </div>
        <p class="kq-desc"><?= e($p['desc']) ?></p>
        <?php if (!empty($p['kiosk'])): ?>
        <div class="kq-row" data-kq-scope="<?= e((string) $p['scope']) ?>" data-kq-path="<?= e((string) $p['path']) ?>">
            <input type="text" readonly value="••••••••" onclick="this.select()"
                   aria-label="Lien <?= e($p['label']) ?> — masqué, utilise le bouton « Afficher le lien »">
            <button type="button" class="btn btn-outline btn-sm kq-reveal" aria-expanded="false">Afficher le lien</button>
            <button type="button" class="btn btn-outline btn-sm kq-copy">Copier</button>
            <a class="btn btn-ghost btn-sm kq-open" hidden>Ouvrir ↗</a>
        </div>
        <?php else: ?>
        <div class="kq-row">
            <input type="text" readonly value="<?= e(url((string) $p['url'])) ?>" onclick="this.select()"
                   aria-label="Lien <?= e($p['label']) ?>">
            <button type="button" class="btn btn-outline btn-sm kq-copy" data-url="<?= e(url((string) $p['url'])) ?>">Copier</button>
            <a class="btn btn-ghost btn-sm" href="<?= e(url((string) $p['url'])) ?>" target="_blank">Ouvrir ↗</a>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</section>

<section class="kq-section">
    <h2 class="kq-section-title">Kiosques personnalisés</h2>
    <p class="kq-section-sub">Un kiosque par type de personne : tu choises les tuiles, le lien d'authentification est dédié (la régénération d'un kiosque n'affecte ni les autres ni les liens membres/admins).</p>

    <?php
    // Map id → tuile du catalogue : libellés des chips, jamais l'inverse.
    // AUCUN jeton ne transite ici — les lignes $customKiosques ne servent
    // que pour name/pages/id (le token reste en base).
    $tileById = [];
    foreach ($tileCatalog as $t) {
        $tileById[$t['id']] = $t;
    }
    ?>
    <div class="kq-grid">
        <div class="kq-card is-main">
            <div class="kq-head">
                <span class="kq-emoji">➕</span>
                <span class="kq-label">Créer un kiosque</span>
            </div>
            <p class="kq-desc">Crée autant de kiosques que besoin — un par type de personne (trésorier, bar, membres…). Coche les pages à mettre dessus, puis partage le lien révélé.</p>
            <form method="post" action="<?= e(url('/admin/kiosques/create')) ?>">
                <?= csrf_field() ?>
                <div class="kq-field">
                    <label for="kq-new-name">Nom du kiosque</label>
                    <input type="text" id="kq-new-name" name="name" required maxlength="120" placeholder="ex. Trésorier">
                </div>
                <div class="kq-checks">
                    <?php foreach ($tileCatalog as $tile): ?>
                    <label class="kq-check" title="<?= e($tile['desc']) ?>">
                        <input type="checkbox" name="pages[]" value="<?= e($tile['id']) ?>">
                        <span><?= $tile['emoji'] ?> <?= e($tile['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="kq-opts-title">Données sensibles visibles</p>
                <p class="kq-opts-note">Personnalise ce que cette personne voit sur ses pages — décoché = masqué.</p>
                <div class="kq-checks">
                    <?php foreach ($optionCatalog as $opt): ?>
                    <label class="kq-check" title="<?= e($opt['desc']) ?>">
                        <input type="checkbox" name="options[]" value="<?= e($opt['id']) ?>">
                        <span><?= $opt['emoji'] ?> <?= e($opt['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Créer le kiosque</button>
            </form>
        </div>

    <?php foreach ($customKiosques as $kq): ?>
    <?php
        $chipLabels = [];
        foreach ($kq['pages'] as $pid) {
            $tile = $tileById[$pid] ?? null;
            if ($tile !== null) {
                $chipLabels[] = $tile['emoji'] . ' ' . $tile['label'];
            }
        }
        // Options effectives du kiosque : NULL (kiosque antérieur) =
        // comportement historique — bénéfice/paiements visibles, coûts
        // si une tuile pleine « avec coûts » est cochée.
        $optById = [];
        foreach ($optionCatalog as $opt) {
            $optById[$opt['id']] = $opt;
        }
        if ($kq['options'] !== null) {
            $effectiveOpts = $kq['options'];
        } else {
            $effectiveOpts = ['benefice', 'paiements'];
            if (in_array('reappro', $kq['pages'], true) || in_array('liste', $kq['pages'], true)) {
                $effectiveOpts[] = 'couts';
            }
        }
        $optChips = [];
        foreach ($optionCatalog as $opt) {
            $optChips[$opt['id']] = in_array($opt['id'], $effectiveOpts, true);
        }
    ?>
    <div class="kq-card is-admin">
        <div class="kq-head">
            <span class="kq-label"><?= e($kq['name']) ?></span>
            <span class="kq-badge">kiosque</span>
        </div>
        <?php if ($chipLabels !== []): ?>
        <div class="kq-chips">
            <?php foreach ($chipLabels as $chip): ?><span class="kq-chip"><?= e($chip) ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="kq-chips" title="Données sensibles visibles sur ce kiosque">
            <?php foreach ($optChips as $optId => $on): ?>
            <span class="kq-chip<?= $on ? ' kq-chip-on' : '' ?>"><?= $on ? '👁' : '🚫' ?> <?= e($optById[$optId]['label']) ?></span>
            <?php endforeach; ?>
        </div>
        <div class="kq-row" data-kq-scope="custom" data-kq-id="<?= e($kq['id']) ?>">
            <input type="text" readonly value="••••••••" onclick="this.select()"
                   aria-label="Lien <?= e($kq['name']) ?> — masqué, utilise le bouton « Afficher le lien »">
            <button type="button" class="btn btn-outline btn-sm kq-reveal" aria-expanded="false">Afficher le lien</button>
            <button type="button" class="btn btn-outline btn-sm kq-copy">Copier</button>
            <a class="btn btn-ghost btn-sm kq-open" hidden>Ouvrir ↗</a>
        </div>
        <details class="kq-details">
            <summary>Modifier</summary>
            <form method="post" action="<?= e(url('/admin/kiosques/' . rawurlencode($kq['id']) . '/save')) ?>">
                <?= csrf_field() ?>
                <div class="kq-field">
                    <label for="kq-name-<?= e($kq['id']) ?>">Nom du kiosque</label>
                    <input type="text" id="kq-name-<?= e($kq['id']) ?>" name="name" required maxlength="120" value="<?= e($kq['name']) ?>">
                </div>
                <div class="kq-checks">
                    <?php foreach ($tileCatalog as $tile): ?>
                    <label class="kq-check" title="<?= e($tile['desc']) ?>">
                        <input type="checkbox" name="pages[]" value="<?= e($tile['id']) ?>"<?= in_array($tile['id'], $kq['pages'], true) ? ' checked' : '' ?>>
                        <span><?= $tile['emoji'] ?> <?= e($tile['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="kq-opts-title">Données sensibles visibles</p>
                <div class="kq-checks">
                    <?php foreach ($optionCatalog as $opt): ?>
                    <label class="kq-check" title="<?= e($opt['desc']) ?>">
                        <input type="checkbox" name="options[]" value="<?= e($opt['id']) ?>"<?= in_array($opt['id'], $effectiveOpts, true) ? ' checked' : '' ?>>
                        <span><?= $opt['emoji'] ?> <?= e($opt['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-outline btn-sm">Enregistrer</button>
            </form>
        </details>
        <div class="kq-actions">
            <form method="post" action="<?= e(url('/admin/kiosques/regenerate')) ?>"
                  data-confirm="Régénérer le lien du kiosque « <?= e($kq['name']) ?> » ? L'ancien lien ne fonctionnera plus.">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($kq['id']) ?>">
                <button type="submit" class="btn btn-outline btn-sm">Régénérer le lien</button>
            </form>
            <form method="post" action="<?= e(url('/admin/kiosques/' . rawurlencode($kq['id']) . '/delete')) ?>"
                  data-confirm="Supprimer définitivement le kiosque « <?= e($kq['name']) ?> » ? Son lien cessera de fonctionner.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline btn-sm">Supprimer</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</section>

<script>
(function () {
    'use strict';
    var REVEAL_URL = <?= json_encode(url('/admin/kiosques/reveal'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var MASK = '••••••••';
    var csrfInput = document.querySelector('input[name="_csrf"]');

    function rowsOf(card) { return card.querySelectorAll('.kq-row[data-kq-path], .kq-row[data-kq-id]'); }

    function applyUrl(row, url) {
        row.querySelector('input').value = url;
        row.querySelector('.kq-copy').setAttribute('data-url', url);
        var open = row.querySelector('.kq-open');
        open.setAttribute('href', url);
        open.removeAttribute('hidden');
    }

    function clearUrl(row, btn) {
        row.querySelector('input').value = MASK;
        row.querySelector('.kq-copy').removeAttribute('data-url');
        var open = row.querySelector('.kq-open');
        open.removeAttribute('href');
        open.setAttribute('hidden', '');
        btn.setAttribute('aria-expanded', 'false');
        btn.textContent = 'Afficher le lien';
    }

    function fetchUrl(row) {
        var fd = new FormData();
        fd.append('_csrf', csrfInput ? csrfInput.value : '');
        var scope = row.getAttribute('data-kq-scope') || '';
        fd.append('scope', scope);
        if (scope === 'custom') {
            /* Kiosque personnalisé : on vise par id — jamais de path,
               jamais de jeton côté client. */
            fd.append('id', row.getAttribute('data-kq-id') || '');
        } else {
            /* Legacy membres/admins : couple scope + path en liste blanche. */
            fd.append('path', row.getAttribute('data-kq-path') || '');
        }
        return fetch(REVEAL_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                return (d && d.ok && d.url) ? String(d.url) : null;
            })
            .catch(function () { return null; });
    }

    function flash(btn, label) {
        var old = btn.textContent;
        btn.textContent = label;
        setTimeout(function () { btn.textContent = old; }, 1500);
    }

    /* Révélation : POST CSRF, l'URL complète n'arrive qu'en réponse. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.kq-reveal');
        if (!btn) return;
        var row = btn.closest('.kq-row');
        if (!row) return;

        if (btn.getAttribute('aria-expanded') === 'true') {
            clearUrl(row, btn);
            return;
        }

        fetchUrl(row).then(function (url) {
            if (!url) {
                flash(btn, 'Indisponible');
                return;
            }
            applyUrl(row, url);
            btn.setAttribute('aria-expanded', 'true');
            btn.textContent = 'Masquer le lien';
        });
    });

    /* Copie : après révélation ; sinon révèle d'abord, copie ensuite. */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.kq-copy');
        if (!b) return;
        var url = b.getAttribute('data-url');

        var copy = function (u) {
            var done = function () { flash(b, 'Copié ✓'); };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(u).then(done, done);
            } else {
                var input = b.closest('.kq-row').querySelector('input');
                input.select();
                try { document.execCommand('copy'); } catch (err) {}
                done();
            }
        };

        if (url) {
            copy(url);
            return;
        }

        var row = b.closest('.kq-row');
        if (!row) return;
        var isCustom = row.getAttribute('data-kq-scope') === 'custom';
        if (!isCustom && !row.hasAttribute('data-kq-path')) return;
        flash(b, 'Révélation…');
        fetchUrl(row).then(function (u) {
            if (!u) {
                flash(b, 'Indisponible');
                return;
            }
            var reveal = row.querySelector('.kq-reveal');
            if (reveal) {
                applyUrl(row, u);
                reveal.setAttribute('aria-expanded', 'true');
                reveal.textContent = 'Masquer le lien';
            }
            copy(u);
        });
    });
})();
</script>
