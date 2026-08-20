import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const sourcePath = path.resolve(__dirname, '..', '..', 'public', 'js', 'newsletters-edit.js');
const source = fs.readFileSync(sourcePath, 'utf8');

/**
 * Minimales Element-Double: reicht für die Attribut-, Kind- und Klassen-Zugriffe,
 * die newsletters-edit.js beim Speichern und Anzeigen von Meldungen tatsächlich braucht.
 */
function createElement(registry) {
    const el = {
        attributes: {},
        className: '',
        innerHTML: '',
        textContent: '',
        value: '',
        children: [],
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
        },
        setAttribute(name, value) {
            this.attributes[name] = String(value);
        },
        appendChild(child) {
            this.children.push(child);
            return child;
        },
        prepend(child) {
            this.children.unshift(child);
            return child;
        },
        remove() { },
        addEventListener() { },
        querySelector() {
            return null;
        },
        querySelectorAll() {
            return [];
        },
        classList: {
            add() { },
            remove() { },
            contains() {
                return false;
            },
        },
    };

    Object.defineProperty(el, 'id', {
        get() {
            return el._id || '';
        },
        set(value) {
            el._id = value;
            registry[value] = el;
        },
    });

    return el;
}

/**
 * Baut die Editor-Seite so nach, dass der 30-Sekunden-Hintergrund-Speicherlauf direkt
 * auslösbar ist: setInterval wird abgefangen, statt wirklich zu warten, und der
 * Speicherstand liegt in editForm.children (für die angezeigten Warnungen).
 */
function createHarness() {
    const registry = {};

    const editForm = createElement(registry);
    editForm.setAttribute('action', '/newsletters/42');
    const sendForm = createElement(registry);

    registry['edit-newsletter-form'] = editForm;
    registry['send-form'] = sendForm;

    const titleInput = createElement(registry);
    titleInput.value = 'Ausgangstitel';
    registry.title = titleInput;

    const document = {
        readyState: 'complete',
        body: {
            contains() {
                return true;
            },
        },
        getElementById(id) {
            return registry[id] || null;
        },
        querySelector() {
            return null;
        },
        createElement() {
            return createElement(registry);
        },
        addEventListener() { },
    };

    const intervalCallbacks = [];
    let warningsForNextSave = [];
    const fetchCalls = [];

    class FakeFormData {
        constructor() {
            this.entries = {};
        }

        set(key, value) {
            this.entries[key] = value;
        }
    }

    const context = vm.createContext({
        document,
        window: {
            document,
            setTimeout(fn) {
                fn();
                return 1;
            },
            clearTimeout() { },
        },
        setInterval(fn) {
            intervalCallbacks.push(fn);
            return intervalCallbacks.length;
        },
        clearInterval() { },
        FormData: FakeFormData,
        tinymce: {
            get() {
                return null;
            },
        },
        fetch: async function fetch(url, options) {
            fetchCalls.push({ url, options });
            return {
                ok: true,
                async json() {
                    return { success: true, warnings: warningsForNextSave };
                },
            };
        },
        console,
        Array,
        Object,
        String,
        Number,
        Boolean,
        JSON,
    });

    new vm.Script(source, { filename: 'newsletters-edit.js' }).runInContext(context);

    // Nur der periodische Speicherlauf ist registriert, da data-newsletter-id fehlt und
    // damit der Sperr-Check-Intervall gar nicht erst aufgesetzt wird.
    assert.equal(intervalCallbacks.length, 1);

    let titleCounter = 0;

    return {
        editForm,
        setWarnings(warnings) {
            warningsForNextSave = warnings;
        },
        /**
         * Löst einen Hintergrund-Speicherlauf aus. Der Titel wird dabei jedes Mal
         * verändert, damit der Inhalts-Abgleich die Anfrage nicht wegen
         * Unverändertheit überspringt.
         */
        async triggerBackgroundSave() {
            titleCounter += 1;
            titleInput.value = `Titel ${titleCounter}`;
            intervalCallbacks[0]();
            // saveNewsletter() ist async (fetch + json()); ein paar Mikrotask-Runden
            // reichen, um die Kette bis zur Anzeige-Entscheidung durchlaufen zu lassen.
            await Promise.resolve();
            await Promise.resolve();
            await Promise.resolve();
            await new Promise(resolve => setImmediate(resolve));
        },
        alertCount() {
            return editForm.children.filter(child => (child.className || '').includes('newsletter-edit-alert')).length;
        },
        fetchCallCount() {
            return fetchCalls.length;
        },
    };
}

test('dieselbe Warnung wird beim zweiten Hintergrund-Speicherlauf nicht erneut angezeigt', async () => {
    const harness = createHarness();

    harness.setWarnings(['Unbekannte Platzhalter bleiben unverändert stehen: {{tippfehler}}']);
    await harness.triggerBackgroundSave();
    assert.equal(harness.alertCount(), 1);

    harness.setWarnings(['Unbekannte Platzhalter bleiben unverändert stehen: {{tippfehler}}']);
    await harness.triggerBackgroundSave();
    assert.equal(harness.alertCount(), 1);
});

test('eine geänderte Warnung wird trotz Unterdrückung wieder angezeigt', async () => {
    const harness = createHarness();

    harness.setWarnings(['Unbekannte Platzhalter bleiben unverändert stehen: {{tippfehler}}']);
    await harness.triggerBackgroundSave();
    assert.equal(harness.alertCount(), 1);

    harness.setWarnings(['Unbekannte Platzhalter bleiben unverändert stehen: {{andererfehler}}']);
    await harness.triggerBackgroundSave();
    assert.equal(harness.alertCount(), 2);
});

test('eine zusätzliche Warnung neben der bekannten wird wieder angezeigt', async () => {
    const harness = createHarness();

    harness.setWarnings(['Unbekannte Platzhalter bleiben unverändert stehen: {{tippfehler}}']);
    await harness.triggerBackgroundSave();
    assert.equal(harness.alertCount(), 1);

    harness.setWarnings([
        'Unbekannte Platzhalter bleiben unverändert stehen: {{tippfehler}}, {{zweiterfehler}}',
    ]);
    await harness.triggerBackgroundSave();
    assert.equal(harness.alertCount(), 2);
});

test('nach einem Speicherlauf ohne Warnungen taucht dieselbe Warnung erneut auf', async () => {
    const harness = createHarness();

    harness.setWarnings(['Unbekannte Platzhalter bleiben unverändert stehen: {{tippfehler}}']);
    await harness.triggerBackgroundSave();
    assert.equal(harness.alertCount(), 1);

    harness.setWarnings([]);
    await harness.triggerBackgroundSave();
    assert.equal(harness.alertCount(), 1);

    harness.setWarnings(['Unbekannte Platzhalter bleiben unverändert stehen: {{tippfehler}}']);
    await harness.triggerBackgroundSave();
    assert.equal(harness.alertCount(), 2);
});

test('ohne Warnungen im Speicherlauf erscheint keine Meldung', async () => {
    const harness = createHarness();

    harness.setWarnings([]);
    await harness.triggerBackgroundSave();

    assert.equal(harness.alertCount(), 0);
    assert.equal(harness.fetchCallCount(), 1);
});
