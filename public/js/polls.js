// Stimmzettel: Sobald eine Antwort gewählt ist, hat sich "Bitte eine Antwort wählen."
// erledigt. Bliebe die Meldung stehen, wirkte es, als sei die Auswahl nicht angekommen.
document.addEventListener('DOMContentLoaded', function () {
    const ballot = document.querySelector('[data-poll-ballot]');
    const error = ballot ? ballot.querySelector('[data-poll-ballot-error]') : null;
    if (!ballot || !error) {
        return;
    }

    // "input" für das Textfeld einer offenen Frage, "change" für die Auswahl.
    ['change', 'input'].forEach(function (type) {
        ballot.addEventListener(type, function () {
            error.hidden = true;
        });
    });
});

// Umfrage anlegen: Bei "Freie Antwort" gibt es keine Antworten zur Auswahl. Der Teil
// wird ausgeblendet; was darin steht, ignoriert der Server bei einer offenen Frage.
document.addEventListener('DOMContentLoaded', function () {
    const kinds = document.querySelectorAll('[data-poll-kind]');
    const choiceOnly = document.querySelector('[data-poll-choice-only]');
    if (kinds.length === 0 || !choiceOnly) {
        return;
    }

    function refresh() {
        const text = document.querySelector('[data-poll-kind][value="text"]');
        choiceOnly.hidden = Boolean(text && text.checked);
    }

    kinds.forEach(function (input) {
        input.addEventListener('change', refresh);
    });
    refresh();
});

// Antwortzeilen im Umfrage-Formular: hinzufügen und entfernen.
// Es bleiben immer mindestens zwei Zeilen; mehr als data-max-options gibt es nicht.
document.addEventListener('DOMContentLoaded', function () {
    const list = document.querySelector('[data-poll-options]');
    const addButton = document.querySelector('[data-poll-option-add]');
    const template = document.querySelector('[data-poll-option-template]');
    if (!list || !addButton || !template) {
        return;
    }

    const minRows = 2;
    const maxRows = parseInt(list.getAttribute('data-max-options') || '20', 10);

    function rows() {
        return list.querySelectorAll('[data-poll-option]');
    }

    function refresh() {
        const count = rows().length;
        addButton.disabled = count >= maxRows;
        rows().forEach(function (row, index) {
            const input = row.querySelector('input');
            if (input) {
                input.setAttribute('aria-label', 'Antwort ' + (index + 1));
            }
            const remove = row.querySelector('[data-poll-option-remove]');
            if (remove) {
                remove.disabled = count <= minRows;
            }
        });
    }

    addButton.addEventListener('click', function () {
        if (rows().length >= maxRows) {
            return;
        }
        const row = template.content.firstElementChild.cloneNode(true);
        list.appendChild(row);
        refresh();
        const input = row.querySelector('input');
        if (input) {
            input.focus();
        }
    });

    list.addEventListener('click', function (event) {
        const remove = event.target.closest('[data-poll-option-remove]');
        if (!remove || rows().length <= minRows) {
            return;
        }
        remove.closest('[data-poll-option]').remove();
        refresh();
    });

    refresh();
});
