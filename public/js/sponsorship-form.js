(function (document) {
    "use strict";

    // Ausgehandelt wird je Projekt, deshalb deckt sich der Zeitraum einer
    // Vereinbarung fast immer mit dem des Projekts. Das Formular schlägt ihn
    // deshalb vor, sobald ein Projekt gewählt wird und das Feld noch leer ist
    // oder unverändert dem zuletzt vorgeschlagenen Wert entspricht. Eine
    // eigene Eingabe wird nie überschrieben; ohne JavaScript ergänzt der
    // SponsorshipController denselben Wert beim Anlegen.
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

    // Der "Kontakt"-Knopf unter einer Vereinbarung öffnet dasselbe Modal wie
    // der allgemeine Knopf, wählt die Vereinbarung darin aber schon aus. Der
    // allgemeine Knopf trägt dasselbe Attribut mit leerem Wert und setzt die
    // Auswahl damit zurück - sonst klebte die zuletzt gewählte Vereinbarung am
    // geteilten Modal, und ein sponsorweiter Kontakt landete an ihr.
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

    const euro = new Intl.NumberFormat("de-AT", { style: "currency", currency: "EUR" });

    // Das Paket gibt Gegenleistungen und Mindestbetrag vor. Beides wird nur
    // vorgeschlagen - wie beim Zeitraum überschreibt die Vorgabe keine eigene
    // Eingabe. Liegt der Betrag darunter, erscheint ein Hinweis statt einer
    // Sperre: Sachleistungen oder Kulanz liegen bewusst einmal darunter.
    function bindPackageSelect(select) {
        const form = select.closest("form");
        if (!form) {
            return;
        }

        const benefits = form.querySelector("[data-sponsorship-benefits]");
        const deliverables = form.querySelector("[data-sponsorship-deliverables]");
        const logoPrint = form.querySelector("[data-sponsorship-logo-print]");
        const logoOnline = form.querySelector("[data-sponsorship-logo-online]");
        const amount = form.querySelector("[data-sponsorship-amount]");
        const hint = form.querySelector("[data-sponsorship-min-hint]");

        function selectedOption() {
            return select.options[select.selectedIndex] || null;
        }

        function updateMinHint() {
            if (!hint || !amount) {
                return;
            }

            const option = selectedOption();
            const minimum = option ? Number.parseFloat(option.dataset.minAmount || "0") : 0;
            const value = Number.parseFloat(amount.value || "");
            const below = minimum > 0 && !Number.isNaN(value) && value < minimum;

            hint.textContent = below ? "Unter dem Mindestbetrag des Pakets (" + euro.format(minimum) + ")." : "";
            hint.classList.toggle("d-none", !below);
        }

        select.addEventListener("change", function () {
            const option = selectedOption();
            if (option && benefits) {
                applySuggestion(benefits, option.dataset.benefits || "");
            }
            if (option && deliverables) {
                applySuggestion(deliverables, option.dataset.deliverables || "");
            }
            // Wofür das Logo gebraucht wird, gibt das Paket vor - wie bei den
            // Leistungen ein Vorschlag, den man danach ändern kann.
            if (option && logoPrint && option.dataset.needsLogoPrint !== undefined) {
                logoPrint.checked = option.dataset.needsLogoPrint === "1";
            }
            if (option && logoOnline && option.dataset.needsLogoOnline !== undefined) {
                logoOnline.checked = option.dataset.needsLogoOnline === "1";
            }
            if (option && amount && option.dataset.minAmount) {
                applySuggestion(amount, Number.parseFloat(option.dataset.minAmount).toFixed(2));
            }
            updateMinHint();
        });

        if (amount) {
            amount.addEventListener("input", updateMinHint);
        }
        updateMinHint();
    }

    // Ein Schalter blendet das Feld ein, das nur mit ihm Sinn ergibt - etwa die
    // Begründung zu "Keine Anfragen erwünscht". Vorher stand das Feld immer da
    // und erklärte sich per Platzhalter selbst für meist überflüssig.
    function bindReveal(toggle) {
        const target = document.querySelector(toggle.dataset.reveals || "");
        if (!target) {
            return;
        }

        const sync = function () {
            target.classList.toggle("d-none", !toggle.checked);
        };
        toggle.addEventListener("change", sync);
        sync();
    }

    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll("[data-sponsorship-project]").forEach(bindProjectSelect);
        document.querySelectorAll("[data-sponsorship-package]").forEach(bindPackageSelect);
        document.querySelectorAll("[data-reveals]").forEach(bindReveal);
        bindContactShortcuts();
    });
})(document);
