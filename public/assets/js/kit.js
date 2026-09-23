/* =====================================================================
   AEIC — kit.js
   Animations & interactions communes du kit de design :
   - révélation au scroll (.ae-reveal)
   - compteurs animés (.ae-stat-num[data-target])
   - tilt 3D au survol ([data-tilt], optionnels data-tilt-max / data-tilt-base)
   - parallaxe des icônes flottantes (.ae-chips[data-parallax] .ae-chip)
   Inoffensif si aucun élément correspondant n'est présent sur la page,
   et désactivé en motion réduit / écrans tactiles.
   ===================================================================== */
(function () {
    'use strict';

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var finePointer = window.matchMedia && window.matchMedia('(pointer: fine)').matches;

    /* ---------- Révélation au scroll ---------- */
    if (!reduced && 'IntersectionObserver' in window) {
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
    }

    /* ---------- Tilt 3D au survol ---------- */
    function initTilt() {
        var els = document.querySelectorAll('[data-tilt]');
        if (!els.length) {
            return;
        }

        Array.prototype.forEach.call(els, function (el) {
            var max = parseFloat(el.getAttribute('data-tilt-max')) || 8;
            var base = el.getAttribute('data-tilt-base') || '';
            var frame = null;
            var rx = 0;
            var ry = 0;

            function apply() {
                frame = null;
                el.style.transition = 'transform 0.08s linear';
                el.style.transform = 'perspective(900px) ' + base +
                    ' rotateX(' + rx.toFixed(2) + 'deg) rotateY(' + ry.toFixed(2) + 'deg) scale(1.02)';
            }

            el.addEventListener('mousemove', function (e) {
                var r = el.getBoundingClientRect();
                var px = (e.clientX - r.left) / r.width - 0.5;
                var py = (e.clientY - r.top) / r.height - 0.5;
                rx = -py * max;
                ry = px * max;
                if (frame === null) {
                    frame = window.requestAnimationFrame(apply);
                }
            });

            el.addEventListener('mouseleave', function () {
                if (frame !== null) {
                    window.cancelAnimationFrame(frame);
                    frame = null;
                }
                el.style.transition = 'transform 0.45s cubic-bezier(0.22, 1, 0.36, 1)';
                el.style.transform = '';
            });
        });
    }

    /* ---------- Parallaxe des icônes flottantes ---------- */
    function initChips() {
        var boxes = document.querySelectorAll('.ae-chips');
        if (!boxes.length) {
            return;
        }

        Array.prototype.forEach.call(boxes, function (box) {
            var chips = box.querySelectorAll('.ae-chip');
            if (!chips.length) {
                return;
            }
            var depth = parseFloat(box.getAttribute('data-parallax')) || 14;
            var frame = null;
            var mx = 0;
            var my = 0;

            function apply() {
                frame = null;
                Array.prototype.forEach.call(chips, function (c, i) {
                    var d = depth * (1 + (i % 3) * 0.4);
                    var dir = (i % 2 === 0) ? 1 : -1;
                    c.style.translate = (mx * d * dir).toFixed(1) + 'px ' + (my * d * 0.8).toFixed(1) + 'px';
                });
            }

            window.addEventListener('mousemove', function (e) {
                mx = e.clientX / window.innerWidth - 0.5;
                my = e.clientY / window.innerHeight - 0.5;
                if (frame === null) {
                    frame = window.requestAnimationFrame(apply);
                }
            });
        });
    }

    if (!reduced && finePointer) {
        initTilt();
        initChips();
    }
})();
