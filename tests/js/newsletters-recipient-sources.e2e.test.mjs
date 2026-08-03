import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const sourcePath = path.resolve(__dirname, '..', '..', 'public', 'js', 'newsletters-create.js');
const source = fs.readFileSync(sourcePath, 'utf8');

// Mirrors a Tom Select enhanced multi-select: the original <select> stays in the DOM and
// Tom Select re-dispatches a bubbling "change" event on it whenever the selection changes.
function createSourceSelect(sourceType, options, dispatchToForm) {
    return {
        sourceType,
        options: options.map(option => ({ value: String(option.value), selected: !!option.selected })),
        classList: {
            contains(name) {
                return name === 'newsletter-source-select';
            },
        },
        get selectedOptions() {
            return this.options.filter(option => option.selected);
        },
        select(value) {
            const option = this.options.find(candidate => candidate.value === String(value));
            if (option) {
                option.selected = true;
            }
        },
        dispatch(type) {
            dispatchToForm(type, { target: this });
        },
    };
}

function createBadge(initial) {
    return {
        textContent: String(initial),
    };
}

function createHarness() {
    const formListeners = {};
    const dispatchToForm = (type, event) => {
        (formListeners[type] || []).forEach(handler => handler(event));
    };

    const selects = {
        project_members: createSourceSelect(
            'project_members',
            [{ value: 1, selected: true }, { value: 2 }],
            dispatchToForm
        ),
        event_attendees: createSourceSelect('event_attendees', [{ value: 11 }], dispatchToForm),
        role: createSourceSelect('role', [{ value: 21 }], dispatchToForm),
        user: createSourceSelect('user', [{ value: 101 }, { value: 102 }], dispatchToForm),
    };

    const elements = {
        'create-newsletter-form': {
            attributes: {},
            appendChild() { },
            addEventListener(type, handler) {
                formListeners[type] = formListeners[type] || [];
                formListeners[type].push(handler);
            },
            getAttribute(name) {
                return this.attributes[name] || '';
            },
            setAttribute(name, value) {
                this.attributes[name] = String(value);
            },
            querySelector(selector) {
                const match = selector.match(/data-source-type="([^"]+)"/);

                return match ? selects[match[1]] || null : null;
            },
        },
        project_id: {
            value: '1',
            addEventListener() { },
        },
        template: {
            value: '',
            addEventListener() { },
        },
        title: { value: '' },
        'recipient-count-badge': createBadge(0),
        'recipient-count-status': { textContent: '' },
        'source-project-members-count': createBadge(0),
        'source-event-attendees-count': createBadge(0),
        'source-roles-count': createBadge(0),
        'source-users-count': createBadge(0),
    };

    const document = {
        readyState: 'complete',
        getElementById(id) {
            return elements[id] || null;
        },
        querySelector(selector) {
            if (selector === 'meta[name="csrf-token"]') {
                return {
                    getAttribute() {
                        return 'csrf-token';
                    },
                };
            }

            return null;
        },
        createElement() {
            return {
                type: '',
                name: '',
                value: '',
                id: '',
                className: '',
                innerHTML: '',
                children: [],
                appendChild(child) {
                    this.children.push(child);
                },
            };
        },
        addEventListener() { },
    };

    const fetchCalls = [];

    class FakeFormData {
        constructor() {
            this.entries = [];
        }

        append(key, value) {
            this.entries.push([key, value]);
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
        FormData: FakeFormData,
        fetch: async function fetch(_url, options) {
            fetchCalls.push(options?.body?.entries || []);
            return {
                ok: true,
                async json() {
                    const selectedCount = (options?.body?.entries || []).filter(entry => entry[0].includes('[type]')).length;
                    return { count: selectedCount * 10 };
                },
            };
        },
        console,
        Number,
        String,
        Array,
        Object,
        Boolean,
        Math,
        JSON,
    });

    new vm.Script(source, { filename: 'newsletters-create.js' }).runInContext(context);

    return {
        selects,
        elements,
        fetchCalls,
    };
}

test('recipient source badges and preview update live when the Tom Select dropdowns change', async () => {
    const harness = createHarness();

    assert.equal(harness.elements['source-project-members-count'].textContent, '1');
    assert.equal(harness.elements['source-users-count'].textContent, '0');
    assert.ok(harness.fetchCalls.length >= 1);

    harness.selects.user.select(101);
    harness.selects.user.dispatch('change');

    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(harness.elements['source-users-count'].textContent, '1');
    assert.ok(harness.fetchCalls.length >= 2);
    const latestRequest = harness.fetchCalls[harness.fetchCalls.length - 1];
    const sourceTypeEntries = latestRequest.filter(entry => entry[0].includes('[type]'));
    const sourceReferenceEntries = latestRequest.filter(entry => entry[0].includes('[reference_id]'));

    assert.ok(sourceTypeEntries.some(entry => entry[1] === 'project_members'));
    assert.ok(sourceTypeEntries.some(entry => entry[1] === 'user'));
    assert.ok(sourceReferenceEntries.some(entry => entry[1] === '101'));
});

test('selecting several roles and events aggregates all sources in the preview request', async () => {
    const harness = createHarness();

    harness.selects.event_attendees.select(11);
    harness.selects.event_attendees.dispatch('change');
    harness.selects.role.select(21);
    harness.selects.role.dispatch('change');

    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(harness.elements['source-event-attendees-count'].textContent, '1');
    assert.equal(harness.elements['source-roles-count'].textContent, '1');

    const latestRequest = harness.fetchCalls[harness.fetchCalls.length - 1];
    const sourceTypeEntries = latestRequest.filter(entry => entry[0].includes('[type]')).map(entry => entry[1]);

    assert.deepEqual(sourceTypeEntries.sort(), ['event_attendees', 'project_members', 'role']);
});
