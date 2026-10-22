/* =========================================================
   AEIC — Dashboard Analytics (vue desktop /admin/analytics)
   Extrait de la vue inline (lot 4, mission §9) : le JS ne
   contient AUCUN calcul métier — toutes les valeurs viennent
   de l'îlot <script type="application/json" id="analytics-data">
   rendu par le contrôleur (buildPage). Les valeurs PHP ponctuelles
   (mode kiosque, URL produits, catégorie courante) transitent par
   la constante window.ANALYTICS_BOOT posée par la vue.
   Formatage d'affichage seul : money()/pct() ci-dessous.
   ========================================================= */
(function () {
    var island = document.getElementById('analytics-data');
    var data = island ? JSON.parse(island.textContent) : {};
    var BOOT = window.ANALYTICS_BOOT || {};

    // Mode kiosque : les liens vers l'espace admin sont désactivés.
    var kiosk = !!BOOT.kiosk;

    // ---------- Heatmap filter sync ----------
    var hmCat = document.getElementById('hm-cat');
    if (hmCat) {
        // Sync valeur actuelle.
        var curCat = BOOT.categorie || 'all';
        if (curCat !== 'all') { hmCat.value = curCat; }
        hmCat.addEventListener('change', function () {
            // Met à jour le select catégorie global et soumet le form.
            var globalCat = document.querySelector('select[name="category"]');
            if (globalCat) {
                globalCat.value = hmCat.value || 'all';
            }
            document.getElementById('analytics-filters').submit();
        });
    }

    // ---------- Affichage du sélecteur de dates custom + pills ----------
    var customBox = document.getElementById('filter-custom');
    var periodHidden = document.getElementById('period-hidden');
    function syncCustom() {
        var val = periodHidden.value;
        customBox.hidden = val !== 'custom';
    }
    document.querySelectorAll('.af-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('.af-pill').forEach(function (p) { p.classList.remove('is-active'); });
            pill.classList.add('is-active');
            periodHidden.value = pill.getAttribute('data-period');
            syncCustom();
            // Application immédiate : la pastille soumet le formulaire,
            // sauf « Personnalisé » qui affiche d'abord les champs de dates.
            if (periodHidden.value !== 'custom') {
                document.getElementById('analytics-filters').submit();
            }
        });
    });

    // Saisie manuelle d'une date → bascule immédiate sur « Personnalisé »,
    // avec application automatique dès que les deux bornes sont remplies.
    var customInputs = [
        customBox.querySelector('input[name="from"]'),
        customBox.querySelector('input[name="to"]')
    ];
    Array.prototype.forEach.call(customInputs, function (input) {
        if (!input) return;
        input.addEventListener('change', function () {
            var customPill = document.querySelector('.af-pill[data-period="custom"]');
            document.querySelectorAll('.af-pill').forEach(function (p) { p.classList.remove('is-active'); });
            if (customPill) customPill.classList.add('is-active');
            periodHidden.value = 'custom';
            syncCustom();
            var from = customInputs[0], to = customInputs[1];
            if (from && to && from.value && to.value) {
                document.getElementById('analytics-filters').submit();
            }
        });
    });
    syncCustom();

    // ---------- Chart.js defaults ----------
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

    // ---------- 1. Évolution CA + bénéfice (bar CA + line profit, double axe) ----------
    var tr = data.trend || { labels: [], ca: [], profit: [] };
    var sub = document.getElementById('trend-sub');
    if (sub) {
        var g = (data.filters || {}).granularity || 'auto';
        sub.textContent = g.charAt(0).toUpperCase() + g.slice(1);
    }
    var ctxTrend = document.getElementById('chart-trend');
    if (ctxTrend) {
        new Chart(ctxTrend, {
            type: 'bar',
            data: {
                labels: tr.labels,
                datasets: [
                    {
                        type: 'bar',
                        label: "Chiffre d'affaires (€)",
                        data: tr.ca,
                        backgroundColor: 'rgba(72,189,211,.55)',
                        borderColor: teal,
                        borderWidth: 1,
                        borderRadius: 4,
                        yAxisID: 'y',
                        order: 2,
                    },
                    {
                        type: 'line',
                        label: 'Bénéfice (€)',
                        data: tr.profit,
                        borderColor: violet,
                        backgroundColor: 'rgba(97,80,170,.15)',
                        tension: 0.35,
                        pointBackgroundColor: violet,
                        pointRadius: 3,
                        fill: false,
                        yAxisID: 'y',
                        order: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ' : ' + money(c.raw); } } },
                },
                scales: { y: { beginAtZero: true, ticks: { callback: money } } },
            },
        });
    }

    // ---------- 1bis. Prévisions : réalisé observé + courbe du modèle + bande 80 % ----------
    // Barres = CA passé OBSERVÉ (payload historique ; barre du jour en
    // ambre via today_index), ligne violette en tirets = prévision (point
    // du jour + 7 jours J+1..J+7), bande violette = fourchette 80 %
    // (lo–hi). Toutes les valeurs viennent du payload — aucun recalcul.
    var fc = data.forecast || null;
    var hist = data.historique || { labels: [], values: [], today_index: -1 };
    var ctxFc = document.getElementById('chart-forecast');
    if (ctxFc && fc) {
        var fLabels = (hist.labels || []).map(String);
        var fBars   = (hist.values || []).map(function (v) { return v == null ? null : Number(v); });
        var fTi     = Number(hist.today_index);
        var fPrev   = fc.prevision_jour;
        var fIv     = fc.intervalle_80 || null;
        var fLine = fBars.map(function () { return null; });
        var fLo   = fBars.map(function () { return null; });
        var fHi   = fBars.map(function () { return null; });
        if (fPrev != null) {
            fLabels.push('Auj.');
            fBars.push(null);
            fLine.push(Number(fPrev));
            // Bande plancher 0 : un CA négatif n'existe pas (le lo PHP est
            // déjà clampé ; ce clamp JS garantit l'axe côté client).
            fLo.push(fIv && fIv.lo != null ? Math.max(0, Number(fIv.lo)) : null);
            fHi.push(fIv && fIv.hi != null ? Number(fIv.hi) : null);
        }
        (fc.jours || []).forEach(function (d) {
            fLabels.push(String(d.label));
            fBars.push(null);
            fLine.push(d.is_open && d.ca != null ? Number(d.ca) : null);
            fLo.push(d.is_open && d.lo != null ? Math.max(0, Number(d.lo)) : null);
            fHi.push(d.is_open && d.hi != null ? Number(d.hi) : null);
        });
        var fBg = fBars.map(function (v, i) {
            return i === fTi ? 'rgba(245,158,11,.75)' : 'rgba(72,189,211,.55)';
        });
        new Chart(ctxFc, {
            type: 'line',
            data: {
                labels: fLabels,
                datasets: [
                    {
                        // Bande 80 % : remplissage entre hi (ce dataset,
                        // fill vers le suivant) et lo. Masquée légende/tooltips.
                        label: 'Fourchette 80 %',
                        data: fHi,
                        borderColor: 'transparent',
                        backgroundColor: 'rgba(97,80,170,.14)',
                        fill: '+1',
                        pointRadius: 0,
                        pointHitRadius: 0,
                        order: 0,
                    },
                    {
                        label: 'Borne basse',
                        data: fLo,
                        borderColor: 'transparent',
                        pointRadius: 0,
                        pointHitRadius: 0,
                        fill: false,
                        order: 0,
                    },
                    {
                        type: 'bar',
                        label: 'CA réalisé (€)',
                        data: fBars,
                        backgroundColor: fBg,
                        borderColor: teal,
                        borderWidth: 1,
                        borderRadius: 4,
                        order: 2,
                    },
                    {
                        label: 'Prévision (€)',
                        data: fLine,
                        borderColor: violet,
                        borderDash: [6, 6],
                        pointRadius: 3,
                        pointBackgroundColor: violet,
                        fill: false,
                        spanGaps: false,
                        tension: 0.25,
                        order: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { filter: function (item) { return item.text !== 'Fourchette 80 %' && item.text !== 'Borne basse'; } },
                    },
                    tooltip: {
                        filter: function (t) { return t.dataset.label !== 'Fourchette 80 %' && t.dataset.label !== 'Borne basse'; },
                        callbacks: {
                            label: function (c) {
                                if (c.dataset.label === 'Prévision (€)' && c.raw != null
                                    && fLo[c.dataIndex] != null && fHi[c.dataIndex] != null) {
                                    return 'Prévision : ' + money(c.raw)
                                        + ' (fourchette ' + money(fLo[c.dataIndex]) + ' – ' + money(fHi[c.dataIndex]) + ')';
                                }
                                return c.dataset.label + ' : ' + (c.raw == null ? '—' : money(c.raw));
                            },
                        },
                    },
                },
                scales: { y: { beginAtZero: true, ticks: { callback: money } } },
            },
        });
    }

    // ---------- 1ter. Estimation du jour : cumul réel vs attendu ----------
    // Le cumul réel s'arrête à l'heure courante (null au-delà), la
    // courbe attendue court sur 0h→23h (tirets violets). Valeurs du
    // payload day_track (format plat : cum_actual / cum_expected).
    var dt = data.day_track || null;
    var ctxDt = document.getElementById('chart-daytrack');
    if (ctxDt && dt && dt.estimate != null && Number(dt.estimate) > 0) {
        var dtHour = Number(dt.hour == null ? 0 : dt.hour);
        var dtLabels = [];
        for (var di = 0; di < 24; di++) { dtLabels.push(di + 'h'); }
        var dtToday = (dt.cum_actual || []).map(function (v, i) {
            return i <= dtHour && v != null ? Number(v) : null;
        });
        var dtExpected = (dt.cum_expected || []).map(function (v) {
            return v == null ? null : Number(v);
        });
        new Chart(ctxDt, {
            type: 'line',
            data: {
                labels: dtLabels,
                datasets: [
                    {
                        label: "Aujourd'hui (cumulé €)",
                        data: dtToday,
                        borderColor: teal,
                        backgroundColor: 'rgba(72,189,211,.15)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointBackgroundColor: teal,
                        spanGaps: false,
                    },
                    {
                        label: 'Attendu (cumulé €)',
                        data: dtExpected,
                        borderColor: violet,
                        borderDash: [6, 6],
                        fill: false,
                        tension: 0.3,
                        pointRadius: 0,
                        pointHitRadius: 8,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ' : ' + (c.raw == null ? '—' : money(c.raw)); } } },
                },
                scales: {
                    x: { ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 4 } },
                    y: { beginAtZero: true, ticks: { callback: money } },
                },
            },
        });
    }

    // ---------- 2. Top 10 produits (bar horizontal, cliquable) ----------
    var tp = data.topProducts || { labels: [], ca: [] };
    var ctxTop = document.getElementById('chart-top');
    if (ctxTop) {
        var grad = ctxTop.getContext('2d').createLinearGradient(0, 0, 600, 0);
        grad.addColorStop(0, teal);
        grad.addColorStop(1, violet);
        new Chart(ctxTop, {
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
                onClick: function () { if (!kiosk && BOOT.produitsUrl) window.location.href = BOOT.produitsUrl; },
            },
        });
    }

    // ---------- 3. Répartition par catégorie (donut, centre = total) ----------
    // Total calculé côté contrôleur (payload byCategory.total, lot 4).
    var bc = data.byCategory || { labels: [], ca: [], total: 0 };
    var catTotal = Number(bc.total == null ? 0 : bc.total);
    var ctxCat = document.getElementById('chart-category');
    if (ctxCat) {
        new Chart(ctxCat, {
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
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
                    tooltip: { callbacks: { label: function (c) { return c.label + ' : ' + money(c.raw); } } },
                },
            },
            plugins: [{
                id: 'centerText',
                beforeDraw: function (chart) {
                    var w = chart.width, h = chart.height, ctx = chart.ctx;
                    ctx.save();
                    ctx.font = '600 14px system-ui';
                    ctx.fillStyle = '#9fb3c8';
                    ctx.textAlign = 'center';
                    ctx.fillText('Total', w / 2, h / 2 - 10);
                    ctx.font = '700 18px system-ui';
                    ctx.fillStyle = '#eaf2fb';
                    ctx.fillText(money(catTotal), w / 2, h / 2 + 14);
                    ctx.restore();
                },
            }],
        });
    }

    // ---------- 4. Répartition des paiements (donut CA + % au centre) ----------
    // Total, parts et libellés pré-composés par le contrôleur
    // (payload payments, lot 4) — plus aucun calcul local.
    var pm = data.payments || { by_ca: {}, by_count: {} };
    var pmLabels = pm.labels || ['Carte', 'Liquide'];
    var pmKeys   = ['CARTE', 'LIQUIDE'];
    var pmCa     = pmKeys.map(function (k) { return Number((pm.by_ca || {})[k] || 0); });
    var pmTotal  = Number(pm.total == null ? 0 : pm.total);
    var pmPctTxt = pm.pct || {};
    var ctxPm = document.getElementById('chart-payment');
    if (ctxPm) {
        new Chart(ctxPm, {
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
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function (c) {
                                var key = pmKeys[c.dataIndex];
                                var nb = Number((pm.by_count || {})[key] || 0);
                                return c.label + ' : ' + money(c.raw) + ' (' + (pmPctTxt[key] || '0') + '% · ' + nb + ' transactions)';
                            },
                        },
                    },
                },
            },
            plugins: [{
                id: 'pmCenter',
                beforeDraw: function (chart) {
                    var w = chart.width, h = chart.height, ctx = chart.ctx;
                    var cartPct = pm.pct_carte != null ? Number(pm.pct_carte) : 0;
                    ctx.save();
                    ctx.font = '600 12px system-ui';
                    ctx.fillStyle = '#9fb3c8';
                    ctx.textAlign = 'center';
                    ctx.fillText('Carte', w / 2, h / 2 - 12);
                    ctx.font = '800 22px system-ui';
                    ctx.fillStyle = '#48bdd3';
                    ctx.fillText(cartPct + '%', w / 2, h / 2 + 14);
                    ctx.restore();
                },
            }],
        });
    }

    // ---------- 6. Activité de l'association (multi-line) ----------
    var ac = data.activity || { labels: [], registrations: [], members: [], votes: [] };
    var ctxAc = document.getElementById('chart-activity');
    if (ctxAc) {
        new Chart(ctxAc, {
            type: 'line',
            data: {
                labels: ac.labels,
                datasets: [
                    { label: 'Inscriptions événements', data: ac.registrations, borderColor: violet, backgroundColor: 'rgba(97,80,170,.12)', tension: 0.35, pointBackgroundColor: violet },
                    { label: 'Nouveaux membres',        data: ac.members,        borderColor: teal,    backgroundColor: 'rgba(72,189,211,.12)', tension: 0.35, pointBackgroundColor: teal },
                    { label: 'Votes sondages',          data: ac.votes,          borderColor: amber,   backgroundColor: 'rgba(245,158,11,.12)', tension: 0.35, pointBackgroundColor: amber },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' } },
                scales: { y: { beginAtZero: true } },
            },
        });
    }

    // ---------- 5bis. CA par jour de semaine : OBSERVÉ (barres) + MODÈLE (points/ligne) + fourchette en bande ----------
    // Barres PLEINES = moyenne OBSERVÉE par dow (obs : null = pas de
    // barre), points/ligne tiretée = MODÈLE (attendu de la prochaine
    // occurrence), bande discrète = fourchette ~80 % (payload wd.lo/wd.hi,
    // calculée côté contrôleur). « Meilleur jour » (seulement s'il est
    // significatif) et la mention période creuse viennent du payload —
    // AUCUN calcul local.
    var wd = data.weekday || { labels: [], ca: [], obs: [] };
    var ctxWd = document.getElementById('chart-weekday');
    if (ctxWd) {
        var wdObs = (wd.obs || []).map(function (v) { return v == null ? null : Number(v); });
        var wdModel = (wd.ca || []).map(function (v) { return v == null ? null : Number(v); });
        var wdLo = (wd.lo || []).map(function (v) { return v == null ? null : Number(v); });
        var wdHi = (wd.hi || []).map(function (v) { return v == null ? null : Number(v); });
        var wdDaysCount = (wd.days_count || []).map(function (v) { return v == null ? 0 : Number(v); });
        // Observé « dont période creuse » (moyenne + nb des jours du dow en
        // plage creuse, null si aucun) — ajouté au tooltip de la barre.
        var wdObsCreuse = (wd.obs_creuse || []).map(function (v) { return v == null ? null : Number(v); });
        var wdObsNCreuse = (wd.obs_n_creuse || []).map(function (v) { return v == null ? 0 : Number(v); });
        var wdTi  = Number(wd.today_index);
        var wdBest = wd.best || null;
        var wdCreuse = wd.creuse_note || null;

        var wdSub = document.getElementById('weekday-sub');
        if (wdSub) {
            // F4 : un SEUL chiffre dans le sous-titre (déjà pré-rendu côté
            // serveur) — reconstruit depuis le payload, jamais un meilleur
            // jour non significatif.
            if (wdBest && wdBest.label && wdBest.ca != null) {
                wdSub.textContent = 'Meilleur jour : ' + wdBest.label + ' — ' + money(wdBest.ca)
                    + '/jour attendu (modèle, n=' + Number(wdBest.n || 0) + ')';
            } else {
                wdSub.textContent = 'Pas de différence significative entre les jours (historique court)';
            }
            if (wdCreuse) {
                wdSub.textContent += ' · ' + wdCreuse;
            }
        }

        var wdBg = wdObs.map(function (v, i) {
            return i === wdTi ? 'rgba(245,158,11,.75)' : 'rgba(97,80,170,.55)';
        });
        new Chart(ctxWd, {
            type: 'bar',
            data: {
                labels: wd.labels || [],
                datasets: [
                    {
                        // Bande de fourchette ~80 % (fill entre hi et lo),
                        // masquée légende/tooltips — même motif que le
                        // graphique de prévisions.
                        label: 'Fourchette ~80 %',
                        data: wdHi,
                        borderColor: 'transparent',
                        backgroundColor: 'rgba(97,80,170,.14)',
                        fill: '+1',
                        pointRadius: 0,
                        pointHitRadius: 0,
                        order: 0,
                    },
                    {
                        label: 'Borne basse',
                        data: wdLo,
                        borderColor: 'transparent',
                        pointRadius: 0,
                        pointHitRadius: 0,
                        fill: false,
                        order: 0,
                    },
                    {
                        type: 'bar',
                        label: 'Observé',
                        data: wdObs,
                        backgroundColor: wdBg,
                        borderColor: violet,
                        borderWidth: 1,
                        borderRadius: 4,
                        order: 2,
                    },
                    {
                        type: 'line',
                        label: 'Modèle (prochaine occurrence)',
                        data: wdModel,
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
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { filter: function (item) { return item.text !== 'Fourchette ~80 %' && item.text !== 'Borne basse'; } },
                    },
                    tooltip: {
                        filter: function (t) { return t.dataset.label !== 'Fourchette ~80 %' && t.dataset.label !== 'Borne basse'; },
                        callbacks: {
                            label: function (c) {
                                if (c.dataset.type === 'bar') {
                                    var t = 'Observé : ' + (c.raw == null ? '—' : money(c.raw))
                                        + ' (n=' + (wdDaysCount[c.dataIndex] || 0) + ')';
                                    if (wdObsNCreuse[c.dataIndex] > 0) {
                                        t += ' · dont période creuse : '
                                            + (wdObsCreuse[c.dataIndex] == null ? '—' : money(wdObsCreuse[c.dataIndex]))
                                            + ' (n=' + wdObsNCreuse[c.dataIndex] + ')';
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

    // ---------- 5ter. CA par heure (observé + attendu/projeté du jour) ----------
    // Barres ambre = CA moyen OBSERVÉ par heure (payload hourly.ca_avg).
    // Tirets violets = attendu du jour ; pointillés verts = projeté fin
    // de journée ; ligne sarcelle = réel du jour tronqué à l'heure
    // courante. Le SOUS-TITRE (heure de pointe, attendu JOURNÉE, attendu
    // à Nh / trop tôt) est rendu côté serveur (#hourly-sub) — aucune
    // reconstruction JS, une seule source de vérité.
    var hr = data.hourly || { labels: [], ca_avg: [] };
    var ctxHr = document.getElementById('chart-hourly');
    if (ctxHr) {
        var hrAvg  = (hr.ca_avg || []).map(function (v) { return v == null ? null : Number(v); });
        var hrHour = Number(hr.now_hour == null ? 0 : hr.now_hour);
        var hrToday = (hr.today_ca || []).map(function (v, i) {
            return i <= hrHour && v != null ? Number(v) : null;
        });
        var hrExpected = (hr.today_expected || []).map(function (v) { return v == null ? null : Number(v); });
        var hrProjete  = (hr.today_projete || []).map(function (v) { return v == null ? null : Number(v); });

        new Chart(ctxHr, {
            type: 'bar',
            data: {
                labels: hr.labels || [],
                datasets: [
                    {
                        type: 'bar',
                        label: 'CA moyen observé (€)',
                        data: hrAvg,
                        backgroundColor: 'rgba(245,158,11,.55)',
                        borderColor: amber,
                        borderWidth: 1,
                        borderRadius: 3,
                        order: 4,
                    },
                    {
                        type: 'line',
                        label: "Attendu aujourd'hui (€)",
                        data: hrExpected,
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
                        label: 'Projeté fin de journée (€)',
                        data: hrProjete,
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
                        data: hrToday,
                        borderColor: teal,
                        backgroundColor: 'rgba(72,189,211,.15)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
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
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ' : ' + (c.raw == null ? '—' : money(c.raw)); } } },
                },
                scales: {
                    x: { ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 4 } },
                    y: { beginAtZero: true, ticks: { callback: money } },
                },
            },
        });
    }

    // ---------- 5quater. Tendance produit (semaines) + sélecteur auto ----------
    var pw = data.product_weekly || { labels: [], ca: [] };
    var pwSelect = document.getElementById('product-select');
    if (pwSelect) {
        pwSelect.addEventListener('change', function () { pwSelect.form.submit(); });
    }
    var ctxPw = document.getElementById('chart-product-weekly');
    if (ctxPw) {
        new Chart(ctxPw, {
            type: 'line',
            data: {
                labels: pw.labels,
                datasets: [{
                    label: 'CA (€)',
                    data: pw.ca,
                    borderColor: teal,
                    backgroundColor: 'rgba(72,189,211,.12)',
                    tension: 0.3,
                    pointBackgroundColor: teal,
                    pointRadius: 3,
                    fill: true,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (c) { return money(c.raw); } } },
                },
                scales: { y: { beginAtZero: true, ticks: { callback: money } } },
            },
        });
    }

    // ---------- 5. Heatmap jour × heure ----------
    // Échelle (max) et totaux par jour calculés côté contrôleur
    // (payload heatmap_meta, lot 4) ; l'alpha reste un simple
    // passage valeur / max pour l'intensité de couleur.
    var heat = data.heatmap || [];
    var heatMeta = data.heatmap_meta || { max: 0, day_totals: [] };
    var days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    var heatEl = document.getElementById('heatmap');
    if (heatEl) {
        var max = Number(heatMeta.max || 0);

        var html = '<div class="hm-row hm-head"><span class="hm-label"></span>';
        for (var hh = 0; hh < 24; hh++) { html += '<span class="hm-hh">' + hh + 'h</span>'; }
        html += '</div>';

        for (var dd = 0; dd < 7; dd++) {
            var dayTotal = Number(heatMeta.day_totals[dd] || 0);

            html += '<div class="hm-row">';
            html += '<span class="hm-label">' + days[dd] + '</span>';
            for (var hhh = 0; hhh < 24; hhh++) {
                var val = Number(((heat[dd] || [])[hhh]) || 0);
                var alpha = max > 0 ? val / max : 0;
                var tip = days[dd] + ' à ' + hhh + 'h\nCA : ' + money(val) + '\nTotal ' + days[dd] + ' : ' + money(dayTotal);
                html += '<span class="hm-cell" style="background:rgba(72,189,211,' + alpha.toFixed(3) + ')" data-tip="' + tip.replace(/"/g, '&quot;').replace(/\n/g, '&#10;') + '"></span>';
            }
            html += '</div>';
        }
        heatEl.innerHTML = html;

        // Tooltip flottant.
        var hTip = document.createElement('div');
        hTip.className = 'hm-tooltip';
        hTip.style.cssText = 'position:fixed;pointer-events:none;z-index:9999;background:#0c1d36;border:1px solid rgba(72,189,211,0.4);border-radius:8px;padding:0.6rem 0.85rem;font-size:0.78rem;color:#eaf2fb;box-shadow:0 8px 24px rgba(0,0,0,0.5);display:none;white-space:pre-line;line-height:1.5;';
        document.body.appendChild(hTip);

        heatEl.querySelectorAll('.hm-cell').forEach(function (cell) {
            cell.addEventListener('mouseenter', function () {
                hTip.innerHTML = cell.getAttribute('data-tip').replace(/&#10;/g, '<br>');
                hTip.style.display = 'block';
            });
            cell.addEventListener('mousemove', function (e) {
                hTip.style.left = (e.clientX + 14) + 'px';
                hTip.style.top = (e.clientY - 10) + 'px';
            });
            cell.addEventListener('mouseleave', function () {
                hTip.style.display = 'none';
            });
        });
    }

    // ---------- Tableau récapitulatif (tri + totaux + CSV) ----------
    // Totaux calculés côté contrôleur (payload table_totals, lot 4).
    var rows = data.table || [];
    var totals = data.table_totals || null;
    var tbody = document.getElementById('product-table-body');
    var sortKey = 'ca', sortDir = -1, sortType = 'num';

    function renderTable() {
        if (!tbody) { return; }
        var sorted = rows.slice().sort(function (a, b) {
            var va = a[sortKey], vb = b[sortKey];
            if (sortType === 'num') { return (Number(va) - Number(vb)) * sortDir; }
            return String(va).localeCompare(String(vb)) * sortDir;
        });

        tbody.innerHTML = sorted.map(function (r) {
            return '<tr>'
                + '<td>' + esc(r.product) + '</td>'
                + '<td>' + esc(r.category) + '</td>'
                + '<td class="num">' + Number(r.qty) + '</td>'
                + '<td class="num">' + money(r.ca) + '</td>'
                + '<td class="num">' + money(r.cost) + '</td>'
                + '<td class="num">' + money(r.profit) + '</td>'
                + '<td class="num">' + pct(r.margin) + '</td>'
                + '</tr>';
        }).join('');

        setText('tot-qty', totals ? Number(totals.qty) : 0);
        setText('tot-ca', totals ? money(totals.ca) : '—');
        setText('tot-profit', totals ? money(totals.profit) : '—');
        setText('tot-margin', totals && totals.margin_pct != null ? pct(totals.margin_pct) : '—');
    }
    function setText(id, v) { var el = document.getElementById(id); if (el) { el.textContent = v; } }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    document.querySelectorAll('#product-table thead th').forEach(function (th) {
        th.style.cursor = 'pointer';
        th.addEventListener('click', function () {
            var key = th.getAttribute('data-key');
            var type = th.getAttribute('data-type') || 'str';
            if (sortKey === key) { sortDir *= -1; } else { sortKey = key; sortType = type; sortDir = type === 'num' ? -1 : 1; }
            renderTable();
        });
    });
    renderTable();

    // ---------- Export CSV ----------
    var btnCsv = document.getElementById('export-csv');
    if (btnCsv) {
        btnCsv.addEventListener('click', function () {
            var header = ['Produit', 'Categorie', 'Qte', 'CA', 'Cout moyen', 'Benefice', 'Marge %'];
            var lines = [header.join(';')];
            rows.forEach(function (r) {
                lines.push([
                    csvEsc(r.product), csvEsc(r.category), r.qty,
                    Number(r.ca).toFixed(2).replace('.', ','), Number(r.cost).toFixed(2).replace('.', ','),
                    Number(r.profit).toFixed(2).replace('.', ','), Number(r.margin).toFixed(1).replace('.', ',')
                ].join(';'));
            });
            var blob = new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'analytics_produits.csv';
            a.click();
        });
    }
    function csvEsc(s) {
        s = s == null ? '' : String(s);
        return /[;"\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }
})();
