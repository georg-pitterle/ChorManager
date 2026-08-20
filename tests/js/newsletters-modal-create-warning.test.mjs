import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const sourcePath = path.resolve(__dirname, '..', '..', 'public', 'js', 'newsletters.js');
const source = fs.readFileSync(sourcePath, 'utf8');

/**
 * Minimales Element-Double: reicht für die Attribut-, Kind- und Ereignis-Zugriffe, die
 * newsletters.js beim Laden des Modal-Dialogs und beim Absenden des Anlegen-Formulars braucht.
 * addEventListener zeichnet die Handler auf, statt sie zu ignorieren, damit der Test das
 * submit-Ereignis des Anlegen-Formulars gezielt auslösen kann.
 */
function createElement() {
    const listeners = {};

    return {
        attributes: {},
        className: '',
        innerHTML: '',
        textContent: '',
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
        addEventListener(type, handler) {
            listeners[type] = listeners[type] || [];
            listeners[type].push(handler);
        },
        dispatch(type, event) {
            (listeners[type] || []).forEach(handler => handler(event));
        },
        querySelector() {
            return null;
        },
        querySelectorAll() {
            return [];
        },
        closest() {
            return null;
        },
        classList: {
            add() { },
            remove() { },
            contains() {
                return false;
            },
        },
    };
}

/**
 * Baut den Modal-Dialog so nach, wie ihn public/js/newsletters.js im echten Betrieb vorfindet:
 * ein Bootstrap-Modal mit Titel- und Inhalts-Element. Der erste fetch-Aufruf liefert die Antwort
 * des Anlegen-Endpunkts (JSON mit redirect + warnings), der zweite die HTML-Antwort des
 * nachgeladenen Editors - genau die zwei Anfragen, die newsletters.js beim Absenden des
 * Anlegen-Formulars im Modal auslöst.
 */
function createHarness(createResponseWarnings) {
    const modalElement = createElement();
    const contentElement = createElement();
    const titleElement = createElement();

    const elements = {
        newsletterActionModal: modalElement,
        newsletterActionContent: contentElement,
        newsletterActionModalLabel: titleElement,
    };

    const domContentLoadedCallbacks = [];

    const document = {
        readyState: 'complete',
        getElementById(id) {
            return elements[id] || null;
        },
        querySelector(selector) {
            if (selector === 'meta[name="csrf-token"]') {
                return { getAttribute: () => 'csrf-token-value' };
            }
            return null;
        },
        querySelectorAll() {
            return [];
        },
        createElement() {
            return createElement();
        },
        addEventListener(type, handler) {
            if (type === 'DOMContentLoaded') {
                domContentLoadedCallbacks.push(handler);
            }
        },
    };

    class FakeModal {
        show() { }

        hide() { }
    }

    class FakeDOMParser {
        parseFromString(html) {
            return {
                querySelector(selector) {
                    return selector === 'body' ? { innerHTML: html } : null;
                },
                querySelectorAll() {
                    return [];
                },
            };
        }
    }

    class FakeFormData {
        constructor() {
            this.entries = [];
        }

        set(key, value) {
            this.entries.push([key, value]);
        }

        append(key, value) {
            this.entries.push([key, value]);
        }

        has(key) {
            return this.entries.some(([existingKey]) => existingKey === key);
        }
    }

    class HTMLFormElement { }

    const fetchCalls = [];
    const editedPageMarker = '<div id="edit-newsletter-form" data-loaded="1"></div>';

    async function fetch(url, options) {
        fetchCalls.push({ url, options });

        if (fetchCalls.length === 1) {
            // Antwort des Anlegen-Endpunkts: enthält immer "warnings", nie einen Session-Eintrag.
            return {
                ok: true,
                headers: { get: () => 'application/json' },
                async json() {
                    return {
                        id: 42,
                        redirect: '/newsletters/42/edit?modal=1',
                        warnings: createResponseWarnings,
                    };
                },
            };
        }

        // Antwort des nachgeladenen Editors im selben Dialog.
        return {
            ok: true,
            headers: { get: () => 'text/html' },
            async text() {
                return `<html><body>${editedPageMarker}</body></html>`;
            },
        };
    }

    const showAlertCalls = [];

    const window = {
        location: { origin: 'https://chormanager.test' },
        newsletterEditShowAlert(type, message) {
            showAlertCalls.push({ type, message, contentAtCall: contentElement.innerHTML });
        },
    };

    const context = vm.createContext({
        document,
        window,
        bootstrap: { Modal: FakeModal },
        DOMParser: FakeDOMParser,
        FormData: FakeFormData,
        HTMLFormElement,
        fetch,
        URL,
        Set,
        Promise,
        Array,
        Object,
        String,
        Number,
        Boolean,
        Function,
        JSON,
        console,
    });

    new vm.Script(source, { filename: 'newsletters.js' }).runInContext(context);
    domContentLoadedCallbacks.forEach(handler => handler());

    const createForm = Object.create(HTMLFormElement.prototype);
    createForm.getAttribute = function getAttribute(name) {
        if (name === 'action') {
            return '/newsletters';
        }
        return null;
    };

    return {
        editedPageMarker,
        showAlertCalls,
        fetchCalls,
        async submitCreateForm() {
            const event = {
                target: createForm,
                preventDefault() { },
            };
            contentElement.dispatch('submit', event);
            // Der submit-Handler ist async (zwei fetch-Aufrufe hintereinander); ein paar
            // Mikrotask-Runden reichen, um die Kette bis zur Warnungs-Anzeige durchlaufen zu lassen.
            await Promise.resolve();
            await Promise.resolve();
            await Promise.resolve();
            await new Promise(resolve => setImmediate(resolve));
            await new Promise(resolve => setImmediate(resolve));
        },
    };
}

test('nach dem Anlegen mit unbekanntem Platzhalter wird die Warnung im nachgeladenen Editor-Dialog angezeigt', async () => {
    const warningText = 'Unbekannte Platzhalter bleiben unverändert stehen: {{tippfehler}}';
    const harness = createHarness([warningText]);

    await harness.submitCreateForm();

    assert.equal(harness.fetchCalls.length, 2);
    assert.equal(harness.showAlertCalls.length, 1);
    assert.equal(harness.showAlertCalls[0].type, 'warning');
    assert.equal(harness.showAlertCalls[0].message, warningText);

    // Reihenfolge: Der Editor-Inhalt muss beim Setzen der Warnung bereits nachgeladen sein, sonst
    // würde das Nachladen die Meldung sofort wieder überschreiben.
    assert.ok(harness.showAlertCalls[0].contentAtCall.includes(harness.editedPageMarker));
});

test('ohne unbekannten Platzhalter bleibt der Editor-Dialog ohne Warnung', async () => {
    const harness = createHarness([]);

    await harness.submitCreateForm();

    assert.equal(harness.fetchCalls.length, 2);
    assert.equal(harness.showAlertCalls.length, 0);
});
