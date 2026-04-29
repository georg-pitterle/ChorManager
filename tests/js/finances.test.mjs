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

function createElement() {
    const classes = new Set();
    const listeners = {};

    return {
        value: '',
        required: false,
        innerHTML: '',
        innerText: '',
        options: [],
        classList: {
            add(name) {
                classes.add(name);
            },
            remove(name) {
                classes.delete(name);
            },
            contains(name) {
                return classes.has(name);
            }
        },
        focus() { },
        appendChild() { },
        addEventListener(type, handler) {
            listeners[type] = listeners[type] || [];
            listeners[type].push(handler);
        },
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
        amount: createElement(),
        existing_attachments_section: createElement(),
        existing_attachments_list: createElement(),
        attachments: createElement(),
        financeModalLabel: createElement(),
    };

    elements.group_select.options = [
        { value: '' },
        { value: '__new__' },
        { value: 'Allgemein' }
    ];

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
        }
    };

    let showCalls = 0;
    const bootstrap = {
        Modal: function Modal() {
            this.show = function () {
                showCalls += 1;
            };
        }
    };
    bootstrap.Modal.getOrCreateInstance = function () {
        return {
            show() {
                showCalls += 1;
            }
        };
    };

    const window = {
        document,
        bootstrap,
    };

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

    return {
        listeners,
        elements,
        getShowCalls() {
            return showCalls;
        }
    };
}

test('edit-finance click works with delegated handler and object date payload', () => {
    const harness = createHarness();
    const domReadyHandlers = harness.listeners.DOMContentLoaded || [];
    domReadyHandlers.forEach(handler => handler());

    const clickHandlers = harness.listeners.click || [];
    assert.ok(clickHandlers.length > 0, 'expected delegated click handler to be registered');

    const payload = JSON.stringify({
        id: 42,
        invoice_date: { date: '2026-04-17 12:00:00' },
        payment_date: { date: '2026-04-20 08:00:00' },
        description: 'Rechnung',
        group_name: 'Allgemein',
        type: 'expense',
        payment_method: 'bank_transfer',
        amount: '12.34',
        running_number: 99,
        attachments: []
    });

    const button = {
        getAttribute(name) {
            if (name === 'data-finance-item') {
                return payload;
            }
            return '';
        }
    };

    const event = {
        target: {
            closest(selector) {
                if (selector === '[data-action="edit-finance"]') {
                    return button;
                }
                return null;
            }
        }
    };

    clickHandlers.forEach(handler => handler(event));

    assert.equal(harness.elements.finance_id.value, 42);
    assert.equal(harness.elements.invoice_date.value, '2026-04-17');
    assert.equal(harness.elements.payment_date.value, '2026-04-20');
    assert.equal(harness.getShowCalls(), 1);
});

test('script evaluation does not throw when bootstrap is not available', () => {
    const listeners = {};
    const elements = {
        financeModal: createElement(),
    };

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
        }
    };

    const window = { document };
    const context = vm.createContext({
        window,
        document,
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

    assert.doesNotThrow(() => {
        new vm.Script(source, { filename: 'finances.js' }).runInContext(context);
    });
    assert.equal(typeof window.editFinance, 'function');
    assert.equal(typeof window.resetFinanceModal, 'function');
});
