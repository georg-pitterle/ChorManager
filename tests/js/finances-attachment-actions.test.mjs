import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const sourcePath = path.resolve(__dirname, '..', '..', 'public', 'js', 'finances.js');
const source = fs.readFileSync(sourcePath, 'utf8');

/**
 * Die Anhangsliste im Finanz-Bearbeiten-Dialog entsteht erst im Browser und war
 * deshalb von keinem Test erfasst. Genau dort zeigte der Namenslink noch auf die
 * alte Leseroute /finances/attachments/{id} - nach dem Entfernen dieser Route
 * wäre daraus still ein 404 geworden.
 *
 * Anders als das übrige Element-Double dieses Projekts merkt sich `appendChild`
 * hier seine Kinder: ohne das ist das erzeugte Markup nicht prüfbar.
 */
function createElement() {
    const classes = new Set();

    return {
        value: '',
        required: false,
        innerHTML: '',
        innerText: '',
        className: '',
        options: [],
        children: [],
        attributes: {},
        classList: {
            add(name) {
                classes.add(name);
            },
            remove(name) {
                classes.delete(name);
            },
            contains(name) {
                return classes.has(name);
            },
        },
        focus() { },
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
        addEventListener() { },
    };
}

function createHarness() {
    const listeners = {};
    const elements = {
        financeModal: createElement(),
        finance_id: createElement(),
        invoice_date: createElement(),
        payment_date: createElement(),
        description: createElement(),
        group_select: createElement(),
        group_name: createElement(),
        type: createElement(),
        payment_method: createElement(),
        finance_account_id: createElement(),
        amount: createElement(),
        existing_attachments_section: createElement(),
        existing_attachments_list: createElement(),
        attachments: createElement(),
        financeModalLabel: createElement(),
    };

    elements.group_select.options = [{ value: '' }, { value: '__new__' }, { value: 'Allgemein' }];

    const document = {
        addEventListener(type, handler) {
            listeners[type] = listeners[type] || [];
            listeners[type].push(handler);
        },
        querySelectorAll() {
            return [];
        },
        getElementById(id) {
            return elements[id] || null;
        },
        createElement() {
            return createElement();
        },
    };

    const bootstrap = { Modal: function Modal() { } };
    bootstrap.Modal.getOrCreateInstance = function () {
        return { show() { } };
    };

    const window = { document, bootstrap };

    const context = vm.createContext({
        window,
        document,
        bootstrap,
        console,
        Date,
        Array,
        Object,
        Number,
        String,
        Boolean,
        Math,
        JSON,
        parseFloat,
        Set,
    });

    new vm.Script(source, { filename: 'finances.js' }).runInContext(context);

    (listeners.DOMContentLoaded || []).forEach(handler => handler());

    /**
     * Öffnet den Dialog auf demselben Weg wie im Betrieb: über den delegierten
     * Klick auf einen Knopf mit der Nutzlast im Datenattribut.
     */
    function openEditDialog(attachments) {
        const button = createElement();
        button.attributes['data-action'] = 'edit-finance';
        button.attributes['data-finance-item'] = JSON.stringify({
            id: 12,
            invoice_date: '2026-08-01',
            payment_date: null,
            description: 'Notenkauf',
            group_name: 'Allgemein',
            type: 'expense',
            payment_method: 'transfer',
            finance_account_id: 3,
            amount: '42.00',
            running_number: 'A-0001',
            attachments,
        });

        const event = {
            target: {
                closest(selector) {
                    return selector === '[data-action="edit-finance"]' ? button : null;
                },
            },
        };

        (listeners.click || []).forEach(handler => handler(event));

        return elements.existing_attachments_list.children.map(child => child.innerHTML).join('\n');
    }

    return { elements, openEditDialog };
}

const previewableAttachment = {
    id: 77,
    name: 'beleg.pdf',
    mime: 'application/pdf',
    size: 4096,
    previewable: true,
};

const plainAttachment = {
    id: 78,
    name: 'konzept.docx',
    mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    size: 8192,
    previewable: false,
};

test('Die Liste zeigt auf die zentrale Download-Route, nicht mehr auf die alte Finanz-Route', () => {
    const harness = createHarness();

    const html = harness.openEditDialog([previewableAttachment]);

    assert.match(html, /href="\/attachments\/77\/download"/);
    assert.doesNotMatch(html, /\/finances\/attachments\/77"/);
    assert.doesNotMatch(html, /href="\/finances\/attachments\//);
});

test('Das Löschformular bleibt auf seiner eigenen Route', () => {
    const harness = createHarness();

    const html = harness.openEditDialog([previewableAttachment]);

    assert.match(html, /action="\/finances\/attachments\/77\/delete"/);
});

test('Ein darstellbarer Anhang bekommt den Vorschau-Knopf samt Datenattributen', () => {
    const harness = createHarness();

    const html = harness.openEditDialog([previewableAttachment]);

    assert.match(html, /data-attachment-preview/);
    assert.match(html, /data-attachment-id="77"/);
    assert.match(html, /data-attachment-name="beleg\.pdf"/);
    assert.match(html, /data-attachment-mime="application\/pdf"/);
    assert.match(html, /data-attachment-size="4096"/);
});

test('Ein nicht darstellbarer Anhang bekommt nur den Download-Knopf', () => {
    const harness = createHarness();

    const html = harness.openEditDialog([plainAttachment]);

    assert.doesNotMatch(html, /data-attachment-preview/);
    assert.match(html, /href="\/attachments\/78\/download"/);
});

test('Die Entscheidung über die Vorschau kommt vom Server, nicht aus dem Skript', () => {
    const harness = createHarness();

    // Derselbe darstellbare MIME-Typ, aber der Server hat previewable auf false
    // gesetzt. Das Skript darf das nicht überstimmen.
    const html = harness.openEditDialog([Object.assign({}, previewableAttachment, { previewable: false })]);

    assert.doesNotMatch(html, /data-attachment-preview/);
});

test('Ein Dateiname mit HTML-Metazeichen wird maskiert', () => {
    const harness = createHarness();

    const html = harness.openEditDialog([
        Object.assign({}, previewableAttachment, { name: '"><img src=x onerror=alert(1)>.pdf' }),
    ]);

    assert.doesNotMatch(html, /<img src=x/);
    assert.match(html, /&quot;&gt;&lt;img/);
});

test('Ohne Anhänge bleibt der Abschnitt verborgen', () => {
    const harness = createHarness();

    harness.openEditDialog([]);

    assert.ok(harness.elements.existing_attachments_section.classList.contains('d-none'));
});
