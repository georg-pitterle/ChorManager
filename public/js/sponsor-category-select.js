(function (window, document) {
    "use strict";

    // Kategorie am Sponsor: Auswahl aus den vorhandenen Werten, direkt
    // erweiterbar. Ein eingetippter neuer Name wird als Wert übernommen; der
    // Server legt die Kategorie beim Speichern an (SponsorCategoryResolver).
    function init(root) {
        if (typeof window.TomSelect === "undefined") {
            return;
        }

        (root || document).querySelectorAll("select[data-sponsor-category]").forEach(function (select) {
            if (select.tomselect) {
                return;
            }

            new window.TomSelect(select, {
                create: true,
                createOnBlur: true,
                persist: false,
                maxItems: 1,
                // Die leere Option ist nur der Platzhalter; geleert wird über
                // den Knopf im Feld ("ohne Kategorie").
                allowEmptyOption: false,
                plugins: {
                    clear_button: { title: "Kategorie entfernen" }
                },
                maxOptions: null,
                placeholder: select.getAttribute("data-placeholder") || "",
                render: {
                    option_create: function (data, escape) {
                        return '<div class="create">„' + escape(data.input) + '“ neu anlegen</div>';
                    },
                    // Neben "neu anlegen" wäre "nichts gefunden" nur Lärm.
                    no_results: function () {
                        return null;
                    }
                }
            });
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        init(document);
    });

    // In Dialogen erst beim Öffnen: Tom Select misst die Breite des Felds,
    // und im versteckten Dialog ist sie null.
    document.addEventListener("shown.bs.modal", function (event) {
        init(event.target || document);
    });
})(window, document);
