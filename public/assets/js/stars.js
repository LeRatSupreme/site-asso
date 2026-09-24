/* Étoiles flottantes décoratives (générées côté client, aucune requête). */
(function () {
    var field = document.getElementById('starfield');
    if (!field) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var count = Math.max(15, Math.min(70, Math.round(window.innerWidth / 22)));
    var frag = document.createDocumentFragment();

    for (var i = 0; i < count; i++) {
        var spark = i % 6 === 0;
        var s = document.createElement('span');
        s.className = spark ? 'star star-spark' : 'star';
        s.style.left = (Math.random() * 100).toFixed(2) + '%';
        s.style.top = (Math.random() * 100).toFixed(2) + '%';
        s.style.setProperty('--size', (spark ? 8 + Math.random() * 6 : 2 + Math.random() * 2.5).toFixed(1) + 'px');
        s.style.setProperty('--dur', (6 + Math.random() * 9).toFixed(2) + 's');
        s.style.setProperty('--delay', (-Math.random() * 15).toFixed(2) + 's');
        s.style.setProperty('--drift', (Math.random() * 44 - 22).toFixed(0) + 'px');
        s.style.setProperty('--opacity', (0.25 + Math.random() * 0.5).toFixed(2));
        frag.appendChild(s);
    }
    field.appendChild(frag);
})();
