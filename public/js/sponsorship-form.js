(function (document) {
    "use strict";

    // Ausgehandelt wird je Projekt, deshalb deckt sich der Zeitraum einer
    // Vereinbarung fast immer mit dem des Projekts. Das Formular schlaegt ihn
    // deshalb vor, sobald ein Projekt gewaehlt wird und das Feld noch leer ist
    // oder unveraendert dem zuletzt vorgeschlagenen Wert entspricht. Eine
    // eigene Eingabe wird nie ueberschrieben; ohne JavaScript ergaenzt der
    // SponsorshipController denselben Wert beim Speichern.
    function suggestedValue(field) {
        return field.dataset.suggestedValue || "";
    }

    function applySuggestion(field, value) {
        if (!field) {
            return;
        }

        const current = field.value;
        const isUntouched = current === "" || current === suggestedValue(field);

        if (!isUntouched) {
            return;
        }

        field.value = value;
        field.dataset.suggestedValue = value;
    }

    function bindProjectSelect(select) {
        const form = select.closest("form");
        if (!form) {
            return;
        }

        select.addEventListener("change", function () {
            const option = select.options[select.selectedIndex];
            if (!option) {
                return;
            }

            applySuggestion(form.querySelector("[data-sponsorship-start]"), option.dataset.startDate || "");
            applySuggestion(form.querySelector("[data-sponsorship-end]"), option.dataset.endDate || "");
        });
    }

    // Der "Kontakt"-Knopf unter einer Vereinbarung oeffnet dasselbe Modal wie
    // der allgemeine Knopf, waehlt die Vereinbarung darin aber schon aus.
    function bindContactShortcuts() {
        const modal = document.getElementById("newContactModal");
        if (!modal) {
            return;
        }

        const select = modal.querySelector("[data-contact-sponsorship-select]");
        if (!select) {
            return;
        }

        document.querySelectorAll("[data-contact-sponsorship]").forEach(function (trigger) {
            trigger.addEventListener("click", function () {
                select.value = trigger.dataset.contactSponsorship || "";
            });
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll("[data-sponsorship-project]").forEach(bindProjectSelect);
        bindContactShortcuts();
    });
})(document);
