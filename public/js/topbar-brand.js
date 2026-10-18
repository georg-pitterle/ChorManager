// Chorname in der Kopfleiste: ganz oder gar nicht.
//
// Am Telefon blieb neben Logo und Symbolen oft nur "Chor-Manag…" - ein
// angeschnittener Name sagt weniger als keiner. Passt er nicht vollständig,
// steht nur das Logo da. Per Media-Query ließe sich das nicht entscheiden: Der
// Name ist pro Installation ein anderer, also wird im Browser gemessen.
(function (root) {
    function nameOverflows(el) {
        // Ein Pixel Spielraum für Rundungen beim Messen.
        return el.scrollWidth > el.clientWidth + 1;
    }

    function updateBrandName(el) {
        // Ausgeblendet hat der Name die Breite 0; gemessen wird deshalb immer
        // sichtbar, damit er zurückkommt, sobald wieder Platz ist. Das Ein- und
        // Ausblenden geschieht im selben Durchlauf, bevor der Browser zeichnet.
        el.classList.remove('is-overflowing');
        el.classList.toggle('is-overflowing', nameOverflows(el));
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { nameOverflows: nameOverflows, updateBrandName: updateBrandName };
    }

    if (typeof document === 'undefined') {
        return;
    }

    function init() {
        const el = document.querySelector('.app-topbar__brand-name');
        if (!el) {
            return;
        }

        const update = function () { updateBrandName(el); };
        update();

        if (typeof root.ResizeObserver === 'function') {
            const topbar = el.closest('.app-topbar__inner') || el.parentElement;
            new root.ResizeObserver(update).observe(topbar);
        } else {
            root.addEventListener('resize', update);
        }

        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(update);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(typeof window !== 'undefined' ? window : globalThis);
