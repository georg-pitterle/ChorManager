import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const sourcePath = path.resolve(__dirname, '..', '..', 'public', 'js', 'attachment-preview.js');
const source = fs.readFileSync(sourcePath, 'utf8');

/**
 * Minimales Element-Double. Es trägt bewusst `firstChild` und `removeChild`,
 * weil genau daran das Leeren des Modal-Körpers hängt: bliebe es aus, liefe ein
 * Audio-Anhang nach dem Schließen weiter und das nächste Öffnen zeigte kurz die
 * vorige Datei.
 */
function createElement(tagName = 'div') {
    const listeners = {};

    return {
        tagName,
        attributes: {},
        className: '',
        textContent: '',
        controls: false,
        preload: '',
        src: '',
        alt: '',
        title: '',
        children: [],
        get firstChild() {
            return this.children.length > 0 ? this.children[0] : null;
        },
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
        removeChild(child) {
            const index = this.children.indexOf(child);
            if (index >= 0) {
                this.children.splice(index, 1);
            }
            return child;
        },
        addEventListener(type, handler) {
            listeners[type] = listeners[type] || [];
            listeners[type].push(handler);
        },
        dispatch(type, event) {
            (listeners[type] || []).forEach(handler => handler(event));
        },
    };
}

/**
 * Baut die Umgebung nach, die public/js/attachment-preview.js im Betrieb
 * vorfindet: das eine global eingebundene Modal mit seinen fünf festen
 * Kennungen, ein Bootstrap-Double und ein fetch-Double für die Textvorschau.
 */
function createHarness(textResponse = { ok: true, body: 'Beleg-Inhalt' }) {
    const modalElement = createElement('div');
    const bodyElement = createElement('div');
    const titleElement = createElement('h5');
    const metaElement = createElement('div');
    const downloadElement = createElement('a');

    const elements = {
        attachmentPreviewModal: modalElement,
        attachmentPreviewBody: bodyElement,
        attachmentPreviewTitle: titleElement,
        attachmentPreviewMeta: metaElement,
        attachmentPreviewDownload: downloadElement,
    };

    const domContentLoadedCallbacks = [];
    const clickCallbacks = [];

    const document = {
        readyState: 'complete',
        getElementById(id) {
            return elements[id] || null;
        },
        createElement(tagName) {
            return createElement(tagName);
        },
        addEventListener(type, handler) {
            if (type === 'DOMContentLoaded') {
                domContentLoadedCallbacks.push(handler);
            }
            if (type === 'click') {
                clickCallbacks.push(handler);
            }
        },
    };

    const shownModals = [];

    class FakeModal {
        constructor(element) {
            this.element = element;
        }

        static getOrCreateInstance(element) {
            const instance = new FakeModal(element);
            return {
                show() {
                    shownModals.push(element);
                },
            };
        }
    }

    const fetchCalls = [];

    async function fetch(url, options) {
        fetchCalls.push({ url, options });

        return {
            ok: textResponse.ok,
            status: textResponse.ok ? 200 : 404,
            async text() {
                return textResponse.body;
            },
        };
    }

    const window = { bootstrap: { Modal: FakeModal } };

    const context = vm.createContext({
        document,
        window,
        fetch,
        Number,
        Math,
        String,
        Boolean,
        Object,
        Array,
        Promise,
        JSON,
        encodeURIComponent,
        console,
    });

    new vm.Script(source, { filename: 'attachment-preview.js' }).runInContext(context);
    domContentLoadedCallbacks.forEach(handler => handler());

    /**
     * Löst einen Klick aus. `triggerAttributes` ist das, was der Baustein
     * `templates/partials/attachment_actions.twig` an den Knopf schreibt.
     */
    function click(triggerAttributes) {
        const trigger = createElement('button');
        trigger.attributes = triggerAttributes;

        const event = {
            target: {
                closest(selector) {
                    if (selector === '[data-attachment-preview]') {
                        return Object.prototype.hasOwnProperty.call(triggerAttributes, 'data-attachment-preview')
                            ? trigger
                            : null;
                    }
                    return null;
                },
            },
            preventDefault() { },
        };

        clickCallbacks.forEach(handler => handler(event));
    }

    return {
        modalElement,
        bodyElement,
        titleElement,
        metaElement,
        downloadElement,
        shownModals,
        fetchCalls,
        click,
        childTags() {
            return this.bodyElement.children.map(child => child.tagName);
        },
    };
}

function previewTrigger(overrides = {}) {
    return Object.assign(
        {
            'data-attachment-preview': '',
            'data-attachment-id': '7',
            'data-attachment-name': 'Programmheft.pdf',
            'data-attachment-mime': 'application/pdf',
            'data-attachment-size': '98765',
        },
        overrides
    );
}

test('PDF landet in einem Rahmen', () => {
    const harness = createHarness();

    harness.click(previewTrigger());

    assert.ok(harness.childTags().includes('iframe'));
    assert.equal(harness.bodyElement.children[0].src, '/attachments/7/preview');
    assert.equal(harness.downloadElement.getAttribute('href'), '/attachments/7/download');
    assert.equal(harness.titleElement.textContent, 'Programmheft.pdf');
    assert.equal(harness.shownModals.length, 1);
});

test('Bild landet in einem img-Element', () => {
    const harness = createHarness();

    harness.click(previewTrigger({ 'data-attachment-mime': 'image/png', 'data-attachment-name': 'logo.png' }));

    assert.deepEqual(harness.childTags(), ['img']);
    assert.equal(harness.bodyElement.children[0].alt, 'logo.png');
});

test('MP3 landet in einem audio-Element', () => {
    const harness = createHarness();

    harness.click(previewTrigger({ 'data-attachment-mime': 'audio/mpeg' }));

    assert.deepEqual(harness.childTags(), ['audio']);
    assert.equal(harness.bodyElement.children[0].controls, true);
});

test('Textdatei wird nachgeladen und als pre dargestellt', async () => {
    const harness = createHarness({ ok: true, body: 'Zeile eins' });

    harness.click(previewTrigger({ 'data-attachment-mime': 'text/plain' }));

    assert.equal(harness.fetchCalls.length, 1);
    assert.equal(harness.fetchCalls[0].url, '/attachments/7/preview');

    await new Promise(resolve => setImmediate(resolve));

    assert.deepEqual(harness.childTags(), ['pre']);
    assert.equal(harness.bodyElement.children[0].textContent, 'Zeile eins');
});

test('Fehlgeschlagene Textvorschau zeigt einen Hinweis statt eines leeren Körpers', async () => {
    const harness = createHarness({ ok: false, body: '' });

    harness.click(previewTrigger({ 'data-attachment-mime': 'text/plain' }));

    await new Promise(resolve => setImmediate(resolve));

    assert.deepEqual(harness.childTags(), ['p']);
    assert.match(harness.bodyElement.children[0].textContent, /nicht geladen/);
});

test('Der MIME-Parameter wird abgeschnitten, bevor verzweigt wird', () => {
    const harness = createHarness();

    harness.click(previewTrigger({ 'data-attachment-mime': 'text/plain; charset=utf-8' }));

    assert.equal(harness.fetchCalls.length, 1);
});

test('Unbekannter Typ zeigt einen Hinweis, keinen leeren Körper', () => {
    const harness = createHarness();

    harness.click(previewTrigger({ 'data-attachment-mime': 'application/zip' }));

    assert.deepEqual(harness.childTags(), ['p']);
    assert.match(harness.bodyElement.children[0].textContent, /keine Vorschau/);
});

test('Schließen leert den Körper', () => {
    const harness = createHarness();

    harness.click(previewTrigger({ 'data-attachment-mime': 'audio/mpeg' }));
    assert.equal(harness.bodyElement.children.length, 1);

    harness.modalElement.dispatch('hidden.bs.modal', {});

    assert.equal(
        harness.bodyElement.children.length,
        0,
        'Ohne Leeren liefe der Player weiter und das nächste Öffnen zeigte kurz die vorige Datei'
    );
});

test('Ein Element mit Kennung, aber ohne eigenen Haken löst nichts aus', () => {
    const harness = createHarness();

    harness.click({
        'data-attachment-id': '7',
        'data-attachment-mime': 'application/pdf',
    });

    assert.equal(harness.bodyElement.children.length, 0);
    assert.equal(harness.shownModals.length, 0);
});
