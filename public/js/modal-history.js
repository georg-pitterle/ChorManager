/**
 * Gibt einem Bootstrap-Modal einen Eintrag im Browser-Verlauf.
 *
 * Auf dem Telefon füllt ein großes Modal - etwa die Anhang-Vorschau mit einem PDF -
 * fast den ganzen Bildschirm und wirkt wie eine eigene Seite. Wer es mit "Zurück"
 * (Wisch-Geste in Safari, Zurück-Taste unter Android) schließen wollte, verließ die
 * ganze Seite: Das Modal stand nicht im Verlauf.
 *
 * Jetzt legt das Öffnen einen Eintrag an. "Zurück" verbraucht ihn und schließt das
 * Modal; wird es per Knopf geschlossen, nimmt es seinen Eintrag selbst zurück, damit
 * kein toter Schritt im Verlauf bleibt.
 */
(function (root) {
    'use strict';

    /**
     * Der reine Zustandsteil, ohne DOM - so lässt er sich ohne Browser prüfen.
     *
     * @param {{ history: { pushState: Function, back: Function }, hide: Function }} deps
     */
    function createModalHistory(deps) {
        let pushed = false;

        return {
            opened: function () {
                if (pushed) {
                    return;
                }
                deps.history.pushState({ modalHistory: true }, '');
                pushed = true;
            },
            // Per Knopf, X, Escape oder Klick daneben geschlossen.
            closed: function () {
                if (!pushed) {
                    return;
                }
                pushed = false;
                deps.history.back();
            },
            // "Zurück" hat den Eintrag schon verbraucht; nur noch das Modal schließen.
            popped: function () {
                if (!pushed) {
                    return;
                }
                pushed = false;
                deps.hide();
            },
        };
    }

    function bindModalHistory(modalElement) {
        if (!modalElement || !root.history || !root.bootstrap || !root.bootstrap.Modal) {
            return;
        }

        const state = createModalHistory({
            history: root.history,
            hide: function () {
                root.bootstrap.Modal.getOrCreateInstance(modalElement).hide();
            },
        });

        modalElement.addEventListener('show.bs.modal', state.opened);
        modalElement.addEventListener('hidden.bs.modal', state.closed);
        root.addEventListener('popstate', state.popped);
    }

    root.ChorModalHistory = { bind: bindModalHistory };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { createModalHistory: createModalHistory };
    }
})(typeof window !== 'undefined' ? window : globalThis);
