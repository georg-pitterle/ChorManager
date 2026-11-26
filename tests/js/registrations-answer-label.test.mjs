import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { answerLabel } = require('../../public/js/registrations.js');

// Die Beschriftung der drei Antworten stand viermal da: in drei Vorlagen und hier im
// JavaScript, das die Zeile "Deine Antwort: ..." nach dem Speichern neu schreibt. Sie steht
// jetzt als EventRegistration::STATUS_LABELS am Modell; die Vorlage schreibt sie an den Knopf,
// und das JavaScript liest sie dort ab.

function formWithButtons(labelsByStatus) {
    return {
        querySelector(selector) {
            const match = /button\[data-status="([^"]+)"\]/.exec(selector);
            const status = match ? match[1] : null;
            if (status === null || !(status in labelsByStatus)) {
                return null;
            }

            return {
                getAttribute(name) {
                    return name === 'data-answer-label' ? labelsByStatus[status] : null;
                },
            };
        },
    };
}

test('Anmeldung: die Beschriftung kommt vom Knopf, nicht aus einer zweiten Zuordnung', () => {
    const form = formWithButtons({ yes: 'Zusage', maybe: 'Vielleicht', no: 'Absage' });

    assert.equal(answerLabel(form, 'yes'), 'Zusage');
    assert.equal(answerLabel(form, 'maybe'), 'Vielleicht');
    assert.equal(answerLabel(form, 'no'), 'Absage');
});

test('Anmeldung: ohne Knopf bleibt der Schlüssel stehen, statt "undefined" anzuzeigen', () => {
    const form = formWithButtons({ yes: 'Zusage' });

    assert.equal(answerLabel(form, 'maybe'), 'maybe');
});

test('Anmeldung: ein Knopf ohne Beschriftung fällt ebenfalls auf den Schlüssel zurück', () => {
    const form = formWithButtons({ yes: '' });

    assert.equal(answerLabel(form, 'yes'), 'yes');
});
