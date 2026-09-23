/* =====================================================================
   AEIC — kit.js
   Animations communes du kit de design (refonte 2026) :
   révélation au scroll (.ae-reveal) + compteurs animés (.ae-stat-num[data-target]).
   Inoffensif si aucun élément correspondant n'est présent sur la page.
   ===================================================================== */
(function () {
    'use strict';

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced || !('IntersectionObserver' in window)) {
        return;
    }

    /* ---------- Révélation au scroll ---------- */
    var reveals = document.querySelectorAll('.ae-reveal');

    Array.prototype.forEach.call(reveals, function (el, i) {
        el.classList.add('ae-reveal-pending');
        el.style.transitionDelay = ((i % 3) * 70) + 'ms';
    });

    var revealObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) {
                return;
            }
            var el = entry.target;
            el.classList.add('ae-reveal-in');
            revealObserver.unobserve(el);
            el.addEventListener('transitionend', function handler() {
                el.removeEventListener('transitionend', handler);
                el.classList.remove('ae-reveal-pending');
                el.classList.remove('ae-reveal-in');
                el.style.transitionDelay = '';
            });
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

    Array.prototype.forEach.call(reveals, function (el) {
        revealObserver.observe(el);
    });

    /* ---------- Compteurs animés ---------- */
    function animateCounter(el) {
        var target = parseInt(el.getAttribute('data-target'), 10) || 0;
        var suffix = el.getAttribute('data-suffix') || '';
        var duration = 1300;
        var start = null;

        function step(ts) {
            if (start === null) {
                start = ts;
            }
            var p = Math.min((ts - start) / duration, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = String(Math.round(eased * target)) + suffix;
            if (p < 1) {
                window.requestAnimationFrame(step);
            }
        }

        window.requestAnimationFrame(step);
    }

    var nums = document.querySelectorAll('.ae-stat-num[data-target]');
    var numObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) {
                return;
            }
            animateCounter(entry.target);
            numObserver.unobserve(entry.target);
        });
    }, { threshold: 0.4 });

    Array.prototype.forEach.call(nums, function (el) {
        numObserver.observe(el);
    });
})();
