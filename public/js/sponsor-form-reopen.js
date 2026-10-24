(function (window, document) {
    "use strict";

    // Nach einer Dublettenwarnung kommt die Seite mit dem gemerkten Formular
    // zurück. Der Dialog trägt dann data-open-on-load="1" und öffnet sich
    // wieder, damit "Trotzdem anlegen" oder eine Korrektur gleich möglich ist.
    document.addEventListener("DOMContentLoaded", function () {
        if (!window.bootstrap || !window.bootstrap.Modal) {
            return;
        }

        document.querySelectorAll('.modal[data-open-on-load="1"]').forEach(function (modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    });
})(window, document);
