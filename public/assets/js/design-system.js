/* AEIC — Design system : interactions (vanilla, defer, progressif). */
(function () {
    "use strict";

    var tous = Array.prototype.forEach.call;
    var $ = document.querySelector.bind(document);

    /* Montant français (« 1 234,56 € ») -> nombre. */
    function nombre(v) {
        var n = parseFloat(String(v || "").replace(/[\s\u00a0\u202f\u20ac%]/g, "").replace(",", "."));
        return isNaN(n) ? null : n;
    }

    /* Tri : clic sur th[aria-sort] (numérique via td.num ou contenu). */
    document.addEventListener("click", function (e) {
        var th = e.target.closest("th[aria-sort]");
        if (!th) return;
        var table = th.closest("table"), tbody = table.tBodies[0];
        if (!tbody || !tbody.rows.length) return;

        var i = Array.prototype.indexOf.call(th.parentElement.children, th);
        var croissant = th.getAttribute("aria-sort") !== "ascending";
        tous(table.querySelectorAll("thead th[aria-sort]"), function (c) {
            if (c !== th) c.setAttribute("aria-sort", "none");
        });
        th.setAttribute("aria-sort", croissant ? "ascending" : "descending");

        var cell = tbody.rows[0].cells[i];
        var numerique = !!(cell && (cell.classList.contains("num") || nombre(cell.textContent) !== null));

        var parents = [];
        tous(tbody.rows, function (tr) {
            if (!tr.classList.contains("ds-row-detail")) parents.push(tr);
        });
        parents.sort(function (a, b) {
            var r;
            if (numerique) {
                var na = nombre(a.cells[i].textContent), nb = nombre(b.cells[i].textContent);
                if (na === null) na = -Infinity;
                if (nb === null) nb = -Infinity;
                r = na === nb ? 0 : (na < nb ? -1 : 1);
            } else {
                r = String(a.cells[i].textContent).trim().localeCompare(String(b.cells[i].textContent).trim(), "fr");
            }
            return croissant ? r : -r;
        });
        tous(parents, function (tr) {
            tbody.appendChild(tr);
            var d = tr.nextElementSibling;
            if (d && d.classList.contains("ds-row-detail")) tbody.appendChild(d);
        });
    });

    /* Filtre rapide + recherche : input[data-table-filter|data-table-search],
       table ciblée par data-target ; les zones d'une même table se combinent. */
    var filtres = {};
    function filtre(table, cle) {
        var tbody = table.tBodies[0];
        if (!tbody) return;
        var requetes = [];
        tous(filtres[cle] || [], function (input) {
            var q = input.value.trim().toLowerCase();
            if (q !== "") requetes.push(q);
        });
        var visible = true, ouvert = false;
        tous(tbody.rows, function (tr) {
            if (tr.classList.contains("ds-row-detail")) {
                tr.hidden = !visible || !ouvert;
                return;
            }
            var texte = tr.textContent.toLowerCase();
            visible = requetes.every(function (q) { return texte.indexOf(q) !== -1; });
            tr.hidden = !visible;
            var b = tr.querySelector(".ds-expand");
            ouvert = !!(b && b.getAttribute("aria-expanded") === "true");
        });
    }
    tous(document.querySelectorAll("input[data-table-filter], input[data-table-search]"), function (input) {
        var cle = input.getAttribute("data-table-filter") || input.getAttribute("data-table-search");
        var table = cle ? $(cle) : null;
        if (!table) return;
        (filtres[cle] = filtres[cle] || []).push(input);
        input.addEventListener("input", function () { filtre(table, cle); });
    });

    /* Ligne extensible : button.ds-expand -> tr.ds-row-detail (repliée
       au chargement par le JS ; visible sans JS). */
    tous(document.querySelectorAll("tr.ds-row-detail"), function (tr) { tr.hidden = true; });
    tous(document.querySelectorAll("button.ds-expand"), function (b) { b.setAttribute("aria-expanded", "false"); });
    document.addEventListener("click", function (e) {
        var bouton = e.target.closest("button.ds-expand");
        if (!bouton) return;
        var detail = bouton.closest("tr");
        detail = detail ? detail.nextElementSibling : null;
        if (!detail || !detail.classList.contains("ds-row-detail")) return;
        var ouvert = bouton.getAttribute("aria-expanded") === "true";
        bouton.setAttribute("aria-expanded", ouvert ? "false" : "true");
        detail.hidden = ouvert;
    });

    /* Onglets : [data-tabs], role=tab + role=tabpanel, flèches clavier. */
    tous(document.querySelectorAll("[data-tabs]"), function (bloc) {
        var onglets = bloc.querySelectorAll('[role="tab"]');
        if (!onglets.length) return;
        function active(onglet, focus) {
            tous(onglets, function (o) {
                var choisi = o === onglet;
                o.setAttribute("aria-selected", choisi ? "true" : "false");
                o.tabIndex = choisi ? 0 : -1;
                var p = o.getAttribute("aria-controls") ? document.getElementById(o.getAttribute("aria-controls")) : null;
                if (p) p.hidden = !choisi;
            });
            if (focus) onglet.focus();
        }
        tous(onglets, function (o, i) {
            o.addEventListener("click", function () { active(o, false); });
            o.addEventListener("keydown", function (e) {
                var s = null;
                if (e.key === "ArrowRight" || e.key === "ArrowDown") s = onglets[(i + 1) % onglets.length];
                else if (e.key === "ArrowLeft" || e.key === "ArrowUp") s = onglets[(i - 1 + onglets.length) % onglets.length];
                else if (e.key === "Home") s = onglets[0];
                else if (e.key === "End") s = onglets[onglets.length - 1];
                if (s) { e.preventDefault(); active(s, true); }
            });
        });
        active(bloc.querySelector('[role="tab"][aria-selected="true"]') || onglets[0], false);
    });

    /* Menu déroulant : fermeture au clic extérieur et Échap. */
    function fermeMenus(sauf) {
        tous(document.querySelectorAll(".ds-menu-trigger[aria-expanded='true']"), function (t) {
            if (t === sauf) return;
            t.setAttribute("aria-expanded", "false");
            var l = t.parentElement.querySelector(".ds-menu-list");
            if (l) l.hidden = true;
        });
    }
    tous(document.querySelectorAll(".ds-menu-list"), function (l) { l.hidden = true; });
    document.addEventListener("click", function (e) {
        var t = e.target.closest(".ds-menu-trigger");
        if (t) {
            var ouvert = t.getAttribute("aria-expanded") === "true";
            fermeMenus(t);
            t.setAttribute("aria-expanded", ouvert ? "false" : "true");
            var l = t.parentElement.querySelector(".ds-menu-list");
            if (l) l.hidden = ouvert;
            return;
        }
        if (!e.target.closest(".ds-menu")) fermeMenus(null);
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") fermeMenus(null);
    });
})();
