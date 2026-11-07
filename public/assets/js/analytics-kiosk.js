/* =========================================================
   AEIC — Analytique kiosque (vue téléphone /kiosque/admin/analytics)
   Extrait de la vue inline (lot 4, mission §9) : le JS ne contient
   AUCUN calcul métier — toutes les valeurs viennent de l'îlot
   <script type="application/json" id="analytics-kiosk-data"> rendu
   par le contrôleur (buildPage). Formatage d'affichage seul.
   PITFALL conservé : les graphiques des onglets sont initialisés
   EN RETARDÉ (canvas hidden = taille nulle pour Chart.js).
   ========================================================= */
(function () {
    var island = document.getElementById('analytics-kiosk-data');
    var data = island ? JSON.parse(island.textContent) : {};

    // ---------- Défauts Chart.js + palette (identiques à la vue desktop) ----------
    Chart.defaults.color = '#9fb3c8';
    Chart.defaults.borderColor = 'rgba(255,255,255,.08)';
    Chart.defaults.font.family = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";

    var teal    = '#48bdd3';
    var violet  = '#6150aa';
    var amber   = '#f59e0b';
    var green   = '#22c55e';
    var red     = '#ef4444';
    var palette = [teal, violet, amber, green, red, '#6db4ff', '#d9c24a', '#c46ad9', '#4ad9b0', '#d96a6a'];

    function money(v) { return Number(v).toFixed(2).replace('.', ',') + ' €'; }
    function pct(v)   { return Number(v).toFixed(1).replace('.', ',') + ' %'; }
    function esc(s)   { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    // ============================================================
    // Filtres compacts : pills à application immédiate, custom,
    // selects catégorie/paiement auto.
    // ============================================================
    var form = document.getElementById('ka-filters');
    var customBox = document.getElementById('ka-custom');
    var periodHidden = document.getElementById('ka-period-hidden');

    function syncCustom() { customBox.hidden = periodHidden.value !== 'custom'; }

    document.querySelectorAll('.ka-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('.ka-pill').forEach(function (p) { p.classList.remove('is-active'); });
            pill.classList.add('is-active');
            periodHidden.value = pill.getAttribute('data-period');
            syncCustom();
            // Application immédiate, SAUF « Personnalisé » (dates d'abord).
            if (periodHidden.value !== 'custom') {
                form.submit();
            }
        });
    });

    // Dates custom : bascule immédiate sur « Personnalisé », application
    // dès que les deux bornes sont remplies (sinon bouton Appliquer).
    var fromInput = document.getElementById('ka-from');
    var toInput = document.getElementById('ka-to');
    Array.prototype.forEach.call([fromInput, toInput], function (input) {
        if (!input) return;
        input.addEventListener('change', function () {
            var customPill = document.querySelector('.ka-pill[data-period="custom"]');
            document.querySelectorAll('.ka-pill').forEach(function (p) { p.classList.remove('is-active'); });
            if (customPill) customPill.classList.add('is-active');
            periodHidden.value = 'custom';
            syncCustom();
            if (fromInput.value && toInput.value) {
                form.submit();
            }
        });
    });
    syncCustom();

    // Selects catégorie / paiement : soumission auto au changement.
    Array.prototype.forEach.call(['ka-cat', 'ka-pay'], function (id) {
        var sel = document.getElementById(id);
        if (sel) {
            sel.addEventListener('change', function () { form.submit(); });
        }
    });

    // ============================================================
    // Onglets (pattern Livre comptable) + initialisation EN RETARDÉ
    // des graphiques (un canvas dans un onglet hidden est à taille
    // nulle pour Chart.js).
    // ============================================================
    var bar = document.querySelector('[data-ka-tabs]');
    var tabs = bar ? bar.querySelectorAll('.compta-tab') : [];
    var panes = document.querySelectorAll('.ka-pane');
    var inited = {};

    function initPane(name) {
        if (inited[name]) return;
        inited[name] = true;
        if (name === 'vue') { initVueCharts(); }
        if (name === 'repart') { initRepartCharts(); }
        if (name === 'heures') { initHeuresCharts(); }
    }

    function activate(name, push) {
        var found = false;
        Array.prototype.forEach.call(tabs, function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', on);
            if (on) found = true;
        });
        if (!found) name = tabs.length ? tabs[0].getAttribute('data-tab') : name;
        Array.prototype.forEach.call(panes, function (p) {
            var on = p.getAttribute('data-pane') === name;
            p.classList.toggle('is-active', on);
            p.hidden = !on;
        });
        if (push && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + name);
        }
        initPane(name);
    }

    Array.prototype.forEach.call(tabs, function (t) {
        t.addEventListener('click', function () { activate(t.getAttribute('data-tab'), true); });
    });

    // ---------- Onglet « Vue » : trend CA/bénéfice + top produits ----------
    function initVueCharts() {
        var tr = data.trend || { labels: [], ca: [], profit: [] };
        // Option du kiosque : sans bénéfice, la courbe « Bénéfice » et sa
        // légende disparaissent (les valeurs sont absentes du payload).
        var showBenefice = data.show_benefice !== false;
        var datasets = [
            {
                type: 'bar',
                label: "Chiffre d'affaires (€)",
                data: tr.ca,
                backgroundColor: 'rgba(72,189,211,.55)',
                borderColor: teal,
                borderWidth: 1,
                borderRadius: 4,
                order: 2,
            },
        ];
        if (showBenefice && tr.profit) {
            datasets.push({
                type: 'line',
                label: 'Bénéfice (€)',
                data: tr.profit,
                borderColor: violet,
                backgroundColor: 'rgba(97,80,170,.15)',
                tension: 0.35,
                pointBackgroundColor: violet,
                pointRadius: 3,
                fill: false,
                order: 1,
            });
        }
        var sub = document.getElementById('ka-trend-sub');
        if (sub) {
            var g = (data.filters || {}).granularity || 'auto';
            sub.textContent = g.charAt(0).toUpperCase() + g.slice(1);
        }
        var elTrend = document.getElementById('ka-chart-trend');
        if (elTrend) {
            new Chart(elTrend, {
                type: 'bar',
                data: {
                    labels: tr.labels,
                    datasets: datasets,
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } },
                        tooltip: { callbacks: { label: function (c) { return c.dataset.label + ' : ' + money(c.raw); } } },
                    },
                    scales: { y: { beginAtZero: true, ticks: { callback: money } } },
                },
            });
        }

        var tp = data.topProducts || { labels: [], ca: [] };
        var elTop = document.getElementById('ka-chart-top');
        if (elTop) {
            var grad = elTop.getContext('2d').createLinearGradient(0, 0, 600, 0);
            grad.addColorStop(0, teal);
            grad.addColorStop(1, violet);
            new Chart(elTop, {
                type: 'bar',
                data: {
                    labels: tp.labels,
                    datasets: [{
                        label: 'CA (€)',
                        data: tp.ca,
                        backgroundColor: grad,
                        borderRadius: 6,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: function (c) { return money(c.raw); } } },
                    },
                    scales: { x: { beginAtZero: true, ticks: { callback: money } } },
                },
            });
        }
    }

    // ---------- Onglet « Répartition » : donuts catégorie + paiements ----------
    // Légendes HTML sous les donuts (la légende Canvas de Chart.js se
    // superposait au graphique sur téléphone). Totaux et parts pré-
    // calculés côté contrôleur (payload byCategory.total / pcts et
    // payments.total / pct / labels, lot 4) — plus aucun calcul local.
    function renderCatLegend(bc, catTotal) {
        var el = document.getElementById('ka-leg-category');
        if (!el) return;
        if (!(catTotal > 0)) {
            el.innerHTML = '<p class="ka-leg-empty">Aucune vente sur la période</p>';
            return;
        }
        var pcts = bc.pcts || [];
        var html = '';
        (bc.labels || []).forEach(function (label, i) {
            var v = Number((bc.ca || [])[i] || 0);
            html += '<div class="ka-leg-row">'
                + '<span class="ka-leg-dot" style="background:' + palette[i % palette.length] + '"></span>'
                + '<span class="ka-leg-name">' + esc(label) + '</span>'
                + '<span class="ka-leg-val">' + money(v) + '</span>'
                + '<span class="ka-leg-pct">' + (pcts[i] != null ? pcts[i] : '—') + ' %</span>'
                + '</div>';
        });
        el.innerHTML = html;
    }

    function renderPmLegend(pm, pmCa, pmTotal) {
        var el = document.getElementById('ka-leg-payment');
        if (!el) return;
        var pmLabels = ['Carte', 'Liquide'];
        var pmColors = [teal, amber];
        if (!(pmTotal > 0)) {
            el.innerHTML = '<p class="ka-leg-empty">Aucune vente sur la période</p>';
            return;
        }
        var pmPctTxt = pm.pct || {};
        var html = '';
        pmLabels.forEach(function (label, i) {
            var nb = Number((pm.by_count || {})[(i === 0 ? 'CARTE' : 'LIQUIDE')] || 0);
            html += '<div class="ka-leg-row">'
                + '<span class="ka-leg-dot" style="background:' + pmColors[i] + '"></span>'
                + '<span class="ka-leg-name">' + label + '</span>'
                + '<span class="ka-leg-val">' + money(pmCa[i]) + '</span>'
                + '<span class="ka-leg-pct">' + nb + ' tr. · ' + (pmPctTxt[i === 0 ? 'CARTE' : 'LIQUIDE'] || '0') + ' %</span>'
                + '</div>';
        });
        el.innerHTML = html;
    }

    function initRepartCharts() {
        var bc = data.byCategory || { labels: [], ca: [], total: 0, pcts: [] };
        var catTotal = Number(bc.total == null ? 0 : bc.total);
        var elCat = document.getElementById('ka-chart-category');
        if (elCat) {
            new Chart(elCat, {
                type: 'doughnut',
                data: {
                    labels: bc.labels,
                    datasets: [{ data: bc.ca, backgroundColor: palette, borderColor: 'transparent' }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '64%',
                    plugins: {
                        // Légende désactivée : remplacée par la légende HTML
                        // .ka-leg sous le donut (lisible sur téléphone).
                        legend: { display: false },
                        tooltip: { callbacks: { label: function (c) { return c.label + ' : ' + money(c.raw); } } },
                    },
                },
                plugins: [{
                    id: 'kaCenterText',
                    beforeDraw: function (chart) {
                        var w = chart.width, h = chart.height, ctx = chart.ctx;
                        ctx.save();
                        ctx.font = '600 12px system-ui';
                        ctx.fillStyle = '#9fb3c8';
                        ctx.textAlign = 'center';
                        ctx.fillText('Total', w / 2, h / 2 - 8);
                        ctx.font = '700 15px system-ui';
                        ctx.fillStyle = '#eaf2fb';
                        ctx.fillText(money(catTotal), w / 2, h / 2 + 10);
                        ctx.restore();
                    },
                }],
            });
        }

        // Total, parts et libellés pré-composés par le contrôleur (lot 4).
        var pm = data.payments || { by_ca: {}, by_count: {} };
        var pmLabels = pm.labels || ['Carte', 'Liquide'];
        var pmKeys   = ['CARTE', 'LIQUIDE'];
        var pmCa     = pmKeys.map(function (k) { return Number((pm.by_ca || {})[k] || 0); });
        var pmTotal  = Number(pm.total == null ? 0 : pm.total);
        var elPm = document.getElementById('ka-chart-payment');
        if (elPm) {
            new Chart(elPm, {
                type: 'doughnut',
                data: {
                    labels: pmLabels,
                    datasets: [{
                        data: pmCa,
                        backgroundColor: [teal, amber],
                        borderColor: 'transparent',
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    plugins: {
                        // Légende désactivée : remplacée par la légende HTML
                        // .ka-leg sous le donut (lisible sur téléphone).
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (c) {
                                    var key = pmKeys[c.dataIndex];
                                    var nb = Number((pm.by_count || {})[key] || 0);
                                    return c.label + ' : ' + money(c.raw) + ' (' + ((pm.pct || {})[key] || '0') + '% · ' + nb + ' transactions)';
                                },
                            },
                        },
                    },
                },
                plugins: [{
                    id: 'kaPmCenter',
                    beforeDraw: function (chart) {
                        var w = chart.width, h = chart.height, ctx = chart.ctx;
                        var cartPct = pm.pct_carte != null ? Number(pm.pct_carte) : 0;
                        ctx.save();
                        ctx.font = '600 11px system-ui';
                        ctx.fillStyle = '#9fb3c8';
                        ctx.textAlign = 'center';
                        ctx.fillText('Carte', w / 2, h / 2 - 9);
                        ctx.font = '800 18px system-ui';
                        ctx.fillStyle = '#48bdd3';
                        ctx.fillText(cartPct + '%', w / 2, h / 2 + 11);
                        ctx.restore();
                    },
                }],
            });
        }

        // Légendes HTML sous les donuts (les légendes Chart.js sont désactivées).
        renderCatLegend(bc, catTotal);
        renderPmLegend(pm, pmCa, pmTotal);
    }

    // ============================================================
    // Heures : graphiques CA par jour de semaine et par heure
    // (initialisés EN RETARDÉ à la première activation de l'onglet),
    // puis heatmap INVERSÉE (DOM pur, construite immédiatement).
    // ============================================================
    function initHeuresCharts() {
        // Barres PLEINES = OBSERVÉ (obs : null = pas de barre), points/
        // ligne = MODÈLE (prochaine occurrence), bande discrète =
        // fourchette ~80 % (payload wd.lo/wd.hi, calculée côté
        // contrôleur, lot 4) ; tooltips des séries principales.
        var wd = data.weekday || { labels: [], ca: [], obs: [] };
        var elWd = document.getElementById('ka-chart-weekday');
        if (elWd) {
            var kWdObs = (wd.obs || []).map(function (v) { return v == null ? null : Number(v); });
            var kWdModel = (wd.ca || []).map(function (v) { return v == null ? null : Number(v); });
            var kWdLo = (wd.lo || []).map(function (v) { return v == null ? null : Number(v); });
            var kWdHi = (wd.hi || []).map(function (v) { return v == null ? null : Number(v); });
            var kWdCount = (wd.days_count || []).map(function (v) { return v == null ? 0 : Number(v); });
            // Observé « dont période creuse » (moyenne + nb des jours du dow
            // en plage creuse, null si aucun) — ajouté au tooltip de la barre.
            var kWdObsCreuse = (wd.obs_creuse || []).map(function (v) { return v == null ? null : Number(v); });
            var kWdObsNCreuse = (wd.obs_n_creuse || []).map(function (v) { return v == null ? 0 : Number(v); });
            var kWdTi = Number(wd.today_index);
            new Chart(elWd, {
                type: 'bar',
                data: {
                    labels: wd.labels || [],
                    datasets: [
                        {
                            // Bande de fourchette ~80 % (fill entre hi et lo),
                            // masquée légende/tooltips — même motif que la vue
                            // desktop.
                            label: 'Fourchette ~80 %',
                            data: kWdHi,
                            borderColor: 'transparent',
                            backgroundColor: 'rgba(97,80,170,.14)',
                            fill: '+1',
                            pointRadius: 0,
                            pointHitRadius: 0,
                            order: 0,
                        },
                        {
                            label: 'Borne basse',
                            data: kWdLo,
                            borderColor: 'transparent',
                            pointRadius: 0,
                            pointHitRadius: 0,
                            fill: false,
                            order: 0,
                        },
                        {
                            type: 'bar',
                            label: 'Observé',
                            data: kWdObs,
                            backgroundColor: kWdObs.map(function (v, i) {
                                return i === kWdTi ? 'rgba(245,158,11,.75)' : 'rgba(97,80,170,.55)';
                            }),
                            borderColor: violet,
                            borderWidth: 1,
                            borderRadius: 4,
                            order: 2,
                        },
                        {
                            type: 'line',
                            label: 'Modèle (prochaine occurrence)',
                            data: kWdModel,
                            borderColor: violet,
                            borderDash: [4, 4],
                            backgroundColor: violet,
                            pointRadius: 3,
                            pointHitRadius: 10,
                            tension: 0,
                            fill: false,
                            order: 1,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            filter: function (t) { return t.dataset.label !== 'Fourchette ~80 %' && t.dataset.label !== 'Borne basse'; },
                            callbacks: {
                                label: function (c) {
                                    if (c.dataset.type === 'bar') {
                                        var t = 'Observé : ' + (c.raw == null ? '—' : money(c.raw))
                                            + ' (n=' + (kWdCount[c.dataIndex] || 0) + ')';
                                        if (kWdObsNCreuse[c.dataIndex] > 0) {
                                            t += ' · dont période creuse : '
                                                + (kWdObsCreuse[c.dataIndex] == null ? '—' : money(kWdObsCreuse[c.dataIndex]))
                                                + ' (n=' + kWdObsNCreuse[c.dataIndex] + ')';
                                        }
                                        return t;
                                    }
                                    return 'Modèle : ' + (c.raw == null ? '—' : money(c.raw));
                                },
                            },
                        },
                    },
                    scales: { y: { beginAtZero: true, ticks: { callback: money } } },
                },
            });
        }

        // Barres ambre = CA moyen OBSERVÉ par heure ; tirets violets =
        // attendu du jour ; pointillés verts = projeté fin de journée ;
        // ligne sarcelle = réel du jour tronqué à l'heure courante.
        var hr = data.hourly || { labels: [], ca_avg: [] };
        var elHr = document.getElementById('ka-chart-hourly');
        if (elHr) {
            var kHrHour = Number(hr.now_hour == null ? 0 : hr.now_hour);
            var kHrAvg = (hr.ca_avg || []).map(function (v) { return v == null ? null : Number(v); });
            var kHrToday = (hr.today_ca || []).map(function (v, i) {
                return i <= kHrHour && v != null ? Number(v) : null;
            });
            var kHrExpected = (hr.today_expected || []).map(function (v) { return v == null ? null : Number(v); });
            var kHrProjete = (hr.today_projete || []).map(function (v) { return v == null ? null : Number(v); });
            new Chart(elHr, {
                type: 'line',
                data: {
                    labels: hr.labels || [],
                    datasets: [
                        {
                            type: 'bar',
                            label: 'CA moyen observé (€)',
                            data: kHrAvg,
                            backgroundColor: 'rgba(245,158,11,.55)',
                            borderColor: amber,
                            borderWidth: 1,
                            borderRadius: 3,
                            order: 4,
                        },
                        {
                            type: 'line',
                            label: "Attendu aujourd'hui (€)",
                            data: kHrExpected,
                            borderColor: violet,
                            borderDash: [6, 6],
                            fill: false,
                            tension: 0.3,
                            pointRadius: 0,
                            pointHitRadius: 8,
                            order: 3,
                        },
                        {
                            type: 'line',
                            label: 'Projeté (€)',
                            data: kHrProjete,
                            borderColor: green,
                            borderDash: [2, 4],
                            fill: false,
                            tension: 0.3,
                            pointRadius: 0,
                            pointHitRadius: 8,
                            order: 2,
                        },
                        {
                            type: 'line',
                            label: "Aujourd'hui (€)",
                            data: kHrToday,
                            borderColor: teal,
                            backgroundColor: 'rgba(72,189,211,.15)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 2,
                            pointBackgroundColor: teal,
                            spanGaps: false,
                            order: 1,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 8, font: { size: 10 } } },
                        tooltip: { callbacks: { label: function (c) { return c.dataset.label + ' : ' + (c.raw == null ? '—' : money(c.raw)); } } },
                    },
                    scales: {
                        x: { ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 4, font: { size: 9 } } },
                        y: { beginAtZero: true, ticks: { callback: money } },
                    },
                },
            });
        }
    }

    // ============================================================
    // Heures : heatmap INVERSÉE (DOM pur, construite immédiatement).
    // HEURES en lignes (00h→23h), 7 JOURS en colonnes. Colonnes
    // minmax(0,1fr) → la grille tient à 320px sans scroll horizontal.
    // Le détail d'une case s'affiche au TAP dans la ligne d'info
    // (les tooltips flottants sont inutilisables au doigt).
    // L'échelle (max global) est calculée côté contrôleur
    // (payload heatmap_meta, lot 4) ; l'alpha = simple passage
    // valeur / max pour l'intensité de couleur.
    // ============================================================
    function initHeatmap() {
        var heat = data.heatmap || [];
        var heatMeta = data.heatmap_meta || { max: 0, day_totals: [] };
        var days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        var heatEl = document.getElementById('ka-heatmap');
        var infoEl = document.getElementById('ka-ht-info');
        if (!heatEl) { return; }

        // Max GLOBAL (toutes les cases partagent la même échelle de couleur).
        var max = Number(heatMeta.max || 0);

        // Cas trivial : aucune vente → message, pas de grille.
        if (!(max > 0)) {
            heatEl.innerHTML = '<p class="ka-ht-empty">Aucune vente sur la période</p>';
            if (infoEl) { infoEl.hidden = true; }
            var legendEl = document.querySelector('.ka-ht-legend');
            if (legendEl) { legendEl.hidden = true; }
            return;
        }

        // Ligne d'entête : coin vide (38px) + 7 jours abrégés.
        var html = '<div class="ka-ht-row"><span class="ka-ht-hour"></span>';
        for (var dd = 0; dd < 7; dd++) { html += '<span class="ka-ht-day">' + days[dd] + '</span>'; }
        html += '</div>';

        // 24 lignes horaires : libellé heure + 7 cellules colorées.
        // alpha = valeur / max global (0.03 plancher pour garder la nuance
        // des petites valeurs ; 0 = case vide, transparente).
        for (var hh = 0; hh < 24; hh++) {
            html += '<div class="ka-ht-row"><span class="ka-ht-hour">' + (hh < 10 ? '0' + hh : hh) + 'h</span>';
            for (var d2 = 0; d2 < 7; d2++) {
                var val = Number(((heat[d2] || [])[hh]) || 0);
                var alpha = val > 0 ? Math.max(val / max, 0.03) : 0;
                html += '<button type="button" class="ka-ht-cell" data-jour="' + d2 + '" data-heure="' + hh + '" data-val="' + val + '"'
                    + ' aria-label="' + days[d2] + ' ' + hh + 'h : ' + money(val) + '"'
                    + ' style="background:rgba(72,189,211,' + alpha.toFixed(3) + ')"></button>';
            }
            html += '</div>';
        }
        heatEl.innerHTML = html;

        // Détail au TAP : un seul listener délégué sur la grille.
        // Re-tap sur la même case → retour au texte par défaut.
        var DEFAULT_INFO = 'Touche une case pour le détail';
        var selected = null;
        heatEl.addEventListener('click', function (e) {
            var cell = e.target && e.target.closest ? e.target.closest('.ka-ht-cell') : null;
            if (!cell) { return; }
            var same = (selected === cell);
            if (selected) { selected.classList.remove('is-sel'); selected = null; }
            if (same) {
                if (infoEl) { infoEl.textContent = DEFAULT_INFO; }
                return;
            }
            cell.classList.add('is-sel');
            selected = cell;
            var jour = Number(cell.getAttribute('data-jour'));
            var heure = Number(cell.getAttribute('data-heure'));
            var montant = Number(cell.getAttribute('data-val'));
            if (infoEl) { infoEl.textContent = days[jour] + ' ' + heure + 'h — ' + money(montant); }
        });
    }

    // ============================================================
    // Produits : rangées-cartes pleine largeur (zéro scroll-x),
    // tri par select — DÉFAUT « Catégorie » (groupement : catégories
    // ordonnées par CA total décroissant, produits par CA décroissant
    // dans chaque catégorie), autres tris = liste plate avec chip.
    // + totaux + export CSV. (DOM pur : construit immédiatement.)
    // Totaux et sous-totaux par catégorie pré-calculés côté contrôleur
    // (payload table_totals / table_categories, lot 4).
    // ============================================================
    var rows = data.table || [];
    var totals = data.table_totals || null;
    var catCa = data.table_categories || {};
    var sortKey = 'category';

    // Bénéfice signé : texte « +12,30 € » / « −4,00 € » + classe couleur.
    function profitParts(v) {
        v = Number(v);
        return {
            cls: v >= 0 ? 'is-pos' : 'is-neg',
            text: (v >= 0 ? '+' : '−') + money(Math.abs(v)),
        };
    }

    // Une carte produit. withCat = chip catégorie (masquée en tri
    // « Catégorie », redondante avec l'entête de section). La marge est
    // OBSERVÉE (coût à la date de vente), distincte de la marge
    // prévisionnelle de la carte « Bénéfice prévu ». Options du kiosque :
    // bénéfice/marge et coût moyen masqués si décochés (clés absentes
    // du payload).
    var showBenefice = data.show_benefice !== false;
    var showCosts = data.show_costs !== false;

    function cardHtml(r, withCat) {
        var stats = '<span class="ka-pcard-ca">CA : ' + money(r.ca) + '</span>';
        if (showBenefice && r.profit !== undefined) {
            var pp = profitParts(r.profit);
            stats += '<span class="ka-pcard-profit ' + pp.cls + '">Bénéfice : ' + pp.text + '</span>'
                + '<span class="ka-pcard-margin">Marge observée (coût à la date de vente) ' + Math.round(Number(r.margin)) + ' %</span>';
        }
        var meta = '<span>×' + Number(r.qty) + '</span>';
        if (showCosts && r.cost !== undefined) {
            meta += '<span>Coût moy. ' + money(r.cost) + '</span>';
        }
        return '<div class="ka-pcard">'
            + '<div class="ka-pcard-top">'
            + '<span class="ka-pcard-name">' + esc(r.product) + '</span>'
            + (withCat ? '<span class="ka-pcard-cat">' + esc(r.category) + '</span>' : '')
            + '</div>'
            + '<div class="ka-pcard-meta">' + meta + '</div>'
            + '<div class="ka-pcard-stats">' + stats + '</div>'
            + '</div>';
    }

    function renderCards() {
        var wrap = document.getElementById('ka-product-cards');
        var totalsEl = document.getElementById('ka-ptotals');
        if (!wrap) { return; }

        // Liste vide → message simple.
        if (!rows.length) {
            wrap.innerHTML = '<p class="ka-pempty">Aucune vente sur la période</p>';
            if (totalsEl) { totalsEl.textContent = ''; }
            return;
        }

        var sorted = rows.slice().sort(function (a, b) {
            return Number(b[sortKey]) - Number(a[sortKey]);
        });

        if (totalsEl) {
            var html = '<span>Total ' + Number(totals.qty || 0) + ' u.</span>'
                + '<span>CA <strong>' + money(totals.ca || 0) + '</strong></span>';
            if (showBenefice && totals.profit !== undefined) {
                var tp = profitParts(Number(totals.profit || 0));
                html += '<span class="ka-ptot-profit ' + tp.cls + '">Bénéfice ' + tp.text + '</span>'
                    + '<span>Marge ' + (totals.margin_pct != null ? pct(totals.margin_pct) : '—') + '</span>';
            }
            totalsEl.innerHTML = html;
        }

        // Tri « Catégorie » = GROUPAGE : sections par catégorie (CA total
        // décroissant — valeurs du payload), produits par CA décroissant
        // dans chaque catégorie, sans chip redondante. Autres tris = liste
        // plate avec chip.
        if (sortKey === 'category') {
            var byCat = {};
            sorted.forEach(function (r) {
                var key = String(r.category == null ? '—' : r.category);
                (byCat[key] = byCat[key] || []).push(r);
            });
            var cats = Object.keys(byCat).map(function (name) {
                // Produits de la catégorie triés par CA décroissant.
                var products = byCat[name].slice().sort(function (a, b) {
                    return Number(b.ca) - Number(a.ca);
                });
                return { name: name, caTotal: Number(catCa[name] || 0), products: products };
            }).sort(function (a, b) { return b.caTotal - a.caTotal; });

            wrap.innerHTML = cats.map(function (c) {
                return '<div class="ka-psec">'
                    + '<div class="ka-psec-head">'
                    + '<span class="ka-psec-name">' + esc(c.name) + '</span>'
                    + '<span class="ka-psec-meta">' + c.products.length + ' produits · ' + money(c.caTotal) + '</span>'
                    + '</div>'
                    + c.products.map(function (r) { return cardHtml(r, false); }).join('')
                    + '</div>';
            }).join('');
            return;
        }

        wrap.innerHTML = sorted.map(function (r) { return cardHtml(r, true); }).join('');
    }

    function initProducts() {
        // Tri : re-sert la liste au changement du select (toujours décroissant).
        var sel = document.getElementById('ka-psort');
        if (sel) {
            sel.addEventListener('change', function () {
                sortKey = sel.value;
                renderCards();
            });
        }
        renderCards();

        // Export CSV : mêmes colonnes que l'ancien tableau.
        var btnCsv = document.getElementById('ka-export-csv');
        if (btnCsv) {
            btnCsv.addEventListener('click', function () {
                var header = ['Produit', 'Categorie', 'Qte', 'CA', 'Cout moyen', 'Benefice', 'Marge %'];
                if (!showCosts) { header.splice(4, 1); }
                if (!showBenefice) { header.splice(-2, 2); }
                var lines = [header.join(';')];
                rows.forEach(function (r) {
                    var cols = [
                        csvEsc(r.product), csvEsc(r.category), r.qty,
                        Number(r.ca).toFixed(2).replace('.', ','),
                        Number(r.cost).toFixed(2).replace('.', ','),
                        Number(r.profit).toFixed(2).replace('.', ','), Number(r.margin).toFixed(1).replace('.', ',')
                    ];
                    if (!showCosts) { cols.splice(4, 1); }
                    if (!showBenefice) { cols.splice(-2, 2); }
                    lines.push(cols.join(';'));
                });
                var blob = new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'analytics_produits.csv';
                a.click();
            });
        }
    }

    function csvEsc(s) {
        s = s == null ? '' : String(s);
        return /[;"\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }

    // Heatmap + cartes produits : construits tout de suite (pas de canvas).
    initHeatmap();
    initProducts();

    // Onglet initial : hash de l'URL (#repart, #heures, #produits),
    // sinon « Vue » (initialisé immédiatement — pane visible).
    var initial = (window.location.hash || '').replace('#', '');
    activate(initial || 'vue', false);
})();
