<?php

declare(strict_types=1);

/**
 * Page « Agent » (V1+ rapidité) — préparation des questions types de
 * l'assistant admin. Deux volets : questions POSÉES (agrégat du
 * journal, bouton « Préparer » conditionnel) et questions PRÉPARÉES
 * (badges de dates, TTL, gabarit éditable + aperçu, activer,
 * réordonner, supprimer). Fonctionnelle et sobre.
 *
 * TOUT ce qui vient du journal/de la base passe par e() — le journal
 * contient de VRAIS payloads d'injection (rm -rf, jailbreaks), ils
 * doivent rester inertes à l'écran.
 *
 * @var list<array<string,mixed>> $groupes      Agrégat du journal (fréquence décroissante).
 * @var int                       $nbGroupes    Questions distinctes totales.
 * @var int                       $totalLignes  Lignes du journal agrégées.
 * @var bool                      $journalPresent
 * @var list<array<string,mixed>> $preparees    Questions préparées.
 * @var array<string,string>      $categories
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Agent</h1>
        <p class="muted">Prépare les questions types de l'assistant : une question préparée répond en quelques secondes (routage rejoué sans modèle, gabarit rempli sur les données live), en citant ses sources.</p>
    </div>
</div>

<section class="card surface glass" id="questions-posees">
    <h2 class="card-title">Questions posées (journal)</h2>
    <?php if (!($journalPresent ?? false)): ?>
        <p class="muted">Journal introuvable : aucune question enregistrée pour l'instant.</p>
    <?php else: ?>
        <p class="card-meta">
            <?= (int) ($totalLignes ?? 0) ?> question(s) posée(s) · <?= (int) ($nbGroupes ?? 0) ?> question(s) distincte(s)
            — le badge ⚠ signale une injection suspectée (affichage seul : le pont y résiste, jamais exécutée).
        </p>
        <div style="overflow-x:auto;">
        <table class="agent-table">
            <thead>
                <tr>
                    <th>Question</th><th>Fréq.</th><th>Réussite</th><th>Latence moy.</th><th>Routage</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (($groupes ?? []) as $g): ?>
                <tr>
                    <td>
                        <?= e((string) ($g['affichee'] ?? '')) ?>
                        <?php if (!empty($g['injection'])): ?>
                            <span class="agent-badge agent-badge-danger" title="Injection suspectée (payload observé dans le journal — neutralisé par le pont, affiché échappé)">⚠ injection</span>
                        <?php endif; ?>
                        <?php foreach ((array) ($g['exemples'] ?? []) as $exemple): ?>
                            <?php if ($exemple !== ($g['affichee'] ?? '')): ?>
                                <div class="muted agent-variante"><?= e((string) $exemple) ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                    <td class="agent-num"><?= (int) ($g['frequence'] ?? 0) ?>×</td>
                    <td class="agent-num"><?php
                        $freq = (int) ($g['frequence'] ?? 0);
                        $taux = $freq > 0 ? (int) round(100 * (int) ($g['nb_ok'] ?? 0) / $freq) : 0;
                        echo $taux; ?> %</td>
                    <td class="agent-num"><?= e(number_format(((int) ($g['latence_ms'] ?? 0)) / 1000, 1, ',', ' ')) ?> s</td>
                    <td class="agent-routage">
                        <?php if (($g['routage'] ?? null) !== null): ?>
                            <?php foreach ((array) $g['routage'] as $appel): ?>
                                <div><?= e(json_encode($appel, JSON_UNESCAPED_UNICODE)) ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="muted">aucun succès routé</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $verdict = $g['preparable'] ?? ['ok' => false, 'motif' => '']; ?>
                        <?php if ($verdict['ok']): ?>
                            <form method="post" action="<?= e(url('/admin/agent/prepare')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="question" value="<?= e((string) ($g['affichee'] ?? '')) ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Préparer</button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="btn btn-primary btn-sm" disabled
                                    title="<?= e($verdict['motif']) ?>">Préparer</button>
                            <div class="muted agent-variante">non préparable : <?= e($verdict['motif']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (($nbGroupes ?? 0) > count(($groupes ?? []))): ?>
            <p class="card-meta">+ <?= (int) $nbGroupes - count($groupes) ?> question(s) moins fréquentes non affichées.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card surface glass" id="questions-preparees" style="margin-top:1.2rem;">
    <h2 class="card-title">Questions préparées</h2>
    <p class="card-meta">Une question préparée active s'affiche en chip sur la page Assistant et répond par le fast-path (routage rejoué sans LLM — valable 30 jours après sa capture, 7 jours si les dates sont figées par la question).</p>
    <?php if (($preparees ?? []) === []): ?>
        <p class="muted">Aucune question préparée — utilise « Préparer » dans le volet ci-dessus.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="agent-table">
            <thead>
                <tr><th>Ordre</th><th>Question</th><th>Catégorie</th><th>État</th><th>Routage</th><th>Dates</th><th>Gabarit</th><th>Source</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($preparees as $p): ?>
                <?php $id = (int) ($p['id'] ?? 0); $active = (bool) ($p['active'] ?? false); ?>
                <tr>
                    <td class="agent-num">
                        <form method="post" action="<?= e(url('/admin/agent/deplacer')) ?>" class="agent-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="sens" value="-1">
                            <button type="submit" class="btn btn-ghost btn-sm" title="Monter">▲</button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/agent/deplacer')) ?>" class="agent-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="sens" value="1">
                            <button type="submit" class="btn btn-ghost btn-sm" title="Descendre">▼</button>
                        </form>
                    </td>
                    <td><?= e((string) ($p['question_affichee'] ?? '')) ?></td>
                    <td><?= e($categories[(string) ($p['categorie'] ?? 'autre')] ?? 'Autre') ?></td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/agent/toggle')) ?>" class="agent-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="actif" value="<?= $active ? '0' : '1' ?>">
                            <button type="submit" class="btn <?= $active ? 'btn-ghost' : 'btn-primary' ?> btn-sm">
                                <?= $active ? 'Désactiver' : 'Activer' ?>
                            </button>
                        </form>
                        <span class="agent-badge <?= $active ? 'agent-badge-ok' : 'agent-badge-muted' ?>"><?= $active ? 'active' : 'inactive' ?></span>
                    </td>
                    <td class="agent-routage"><?= e((string) ($p['routage_json'] ?? '')) ?></td>
                    <td>
                        <?php if (!empty($p['dates_relatives'])): ?>
                            <span class="agent-badge agent-badge-ok" title="Fenêtres recalculées à chaque exécution (jamais périmées)">⟳ dates recalculées</span>
                        <?php elseif (!empty($p['dates_figees'])): ?>
                            <span class="agent-badge agent-badge-muted" title="Période figée par la question elle-même">📅 dates figées</span>
                        <?php endif; ?>
                        <div class="muted agent-variante">TTL <?= (int) ($p['ttl_jours'] ?? 0) ?> j</div>
                    </td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/agent/gabarit')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <textarea name="gabarit" rows="3" class="agent-routage" style="width:100%;"
                                      placeholder="Vide = formateur générique"><?= e((string) ($p['gabarit_reponse'] ?? '')) ?></textarea>
                            <div style="display:flex; gap:0.4rem; margin-top:0.3rem;">
                                <button type="submit" class="btn btn-ghost btn-sm">Enregistrer le gabarit</button>
                            </div>
                        </form>
                        <form method="post" action="<?= e(url('/admin/agent/apercu')) ?>" style="margin-top:0.3rem;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-ghost btn-sm">Aperçu (données live)</button>
                        </form>
                        <?php if ((string) ($p['mapping_json'] ?? '') !== ''): ?>
                            <div class="muted agent-variante agent-routage">mapping : <?= e((string) $p['mapping_json']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= (bool) ($p['source_journal'] ?? false) ? 'journal' : 'manuel' ?></td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/agent/supprimer')) ?>" class="agent-inline"
                              onsubmit="return confirm('Supprimer cette question préparée ?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-ghost btn-sm">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<style>
    .agent-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .agent-table th {
        text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em;
        color: var(--muted, #8892a6); padding: 0.45rem 0.6rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
    }
    .agent-table td { padding: 0.5rem 0.6rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06); vertical-align: top; }
    .agent-num { white-space: nowrap; }
    .agent-variante { font-size: 0.78rem; }
    .agent-routage {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 0.76rem; max-width: 22rem;
        overflow-wrap: anywhere; white-space: pre-wrap;
    }
    .agent-inline { display: inline-block; }
    .agent-badge {
        display: inline-block; margin-left: 0.4rem; padding: 0.05rem 0.45rem;
        border-radius: 999px; font-size: 0.7rem; font-weight: 700;
        border: 1px solid rgba(255, 255, 255, 0.15);
    }
    .agent-badge-danger { color: #fbbf24; border-color: rgba(251, 191, 36, 0.4); }
    .agent-badge-ok { color: #4ade80; border-color: rgba(74, 222, 128, 0.4); }
    .agent-badge-muted { color: var(--muted, #8892a6); }
</style>
