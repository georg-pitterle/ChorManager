(function (root) {
    "use strict";

    const document = root.document;

    // Ausgehandelt wird je Projekt, deshalb deckt sich der Zeitraum einer
    // Vereinbarung fast immer mit dem des Projekts. Das Formular schlägt ihn
    // deshalb vor, sobald ein Projekt gewählt wird und das Feld noch leer ist
    // oder unverändert dem zuletzt vorgeschlagenen Wert entspricht. Eine
    // eigene Eingabe wird nie überschrieben; ohne JavaScript ergänzt der
    // SponsorshipController denselben Wert beim Anlegen.
    function suggestedValue(field) {
        return field.dataset.suggestedValue || "";
    }

    // Unberührt ist ein leeres Feld oder eines mit dem letzten Vorschlag. Beim
    // Betrag zählt auch 0 als leer: Eine gespeicherte Anfrage steht im
    // Bearbeiten-Dialog mit "0.00" da, und seit das Paket erst bei der Zusage
    // wählbar ist, ist das der Regelfall - der Mindestbetrag kam sonst nie an.
    function isUntouched(current, suggested, isAmount) {
        if (current === "" || current === suggested) {
            return true;
        }

        return isAmount && Number.parseFloat(current) === 0;
    }

    function applySuggestion(field, value) {
        if (!field) {
            return;
        }

        const isAmount = field.matches("[data-sponsorship-amount]");
        if (!isUntouched(field.value, suggestedValue(field), isAmount)) {
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

        // Ohne Anfrage warnt der Dialog; mit einer Anfrage, die man ändern
        // darf, bietet er deren Status gleich mit an.
        const hint = modal.querySelector("[data-contact-no-request-hint]");
        const statusField = modal.querySelector("[data-contact-status-field]");
        const sync = function () {
            const option = select.options[select.selectedIndex];
            const hasRequest = select.value !== "";
            const maySetStatus = hasRequest && option && option.dataset.maySetStatus === "1";

            if (hint) {
                hint.classList.toggle("d-none", hasRequest);
            }
            if (statusField) {
                statusField.classList.toggle("d-none", !maySetStatus);
                if (!maySetStatus) {
                    statusField.querySelector("select").value = "";
                }
            }
        };

        select.addEventListener("change", sync);
        document.querySelectorAll("[data-contact-sponsorship]").forEach(function (trigger) {
            trigger.addEventListener("click", function () {
                select.value = trigger.dataset.contactSponsorship || "";
                sync();
            });
        });
        sync();
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
        const logo = form.querySelector("[data-sponsorship-logo]");
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
            if (option && logo && option.dataset.needsLogo !== undefined) {
                logo.checked = option.dataset.needsLogo === "1";
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

    // Ein Paket steht erst mit der Zusage fest. Bis dahin ist die Auswahl
    // gesperrt; ein gesperrtes Feld schickt der Browser nicht mit, und der
    // SponsorshipController verwirft ein Paket zu einem anderen Status ohnehin.
    function bindStatusSelect(select) {
        const form = select.closest("form");
        const packageSelect = form ? form.querySelector("[data-sponsorship-package]") : null;
        if (!packageSelect) {
            return;
        }

        const hint = form.querySelector("[data-sponsorship-package-hint]");
        const allowed = (select.dataset.packageStatuses || "").split(",");
        const sync = function () {
            const open = allowed.indexOf(select.value) !== -1;
            packageSelect.disabled = !open;
            if (hint) {
                hint.classList.toggle("d-none", open);
            }
        };

        select.addEventListener("change", sync);
        sync();
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

    if (typeof module !== "undefined" && module.exports) {
        module.exports = { isUntouched: isUntouched };
    }

    if (!document) {
        return;
    }

    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll("[data-sponsorship-project]").forEach(bindProjectSelect);
        document.querySelectorAll("[data-sponsorship-package]").forEach(bindPackageSelect);
        document.querySelectorAll("[data-sponsorship-status]").forEach(bindStatusSelect);
        document.querySelectorAll("[data-reveals]").forEach(bindReveal);
        bindContactShortcuts();
    });
})(typeof window !== "undefined" ? window : globalThis);
