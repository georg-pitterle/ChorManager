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

function createCheckbox(value, checked = false, dispatchToForm = null) {
    const listeners = {};

    return {
        value: String(value),
        checked,
        classList: {
            contains(name) {
                return name === 'newsletter-source-option';
            },
        },
        addEventListener(type, handler) {
            listeners[type] = listeners[type] || [];
            listeners[type].push(handler);
        },
        dispatch(type) {
            const event = { target: this };
            (listeners[type] || []).forEach(handler => handler(event));
            if (typeof dispatchToForm === 'function') {
                dispatchToForm(type, event);
            }
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

    const projectMembers = [createCheckbox(1, true, dispatchToForm), createCheckbox(2, false, dispatchToForm)];
    const eventAttendees = [createCheckbox(11, false, dispatchToForm)];
    const roles = [createCheckbox(21, false, dispatchToForm)];
    const users = [createCheckbox(101, false, dispatchToForm), createCheckbox(102, false, dispatchToForm)];

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
            querySelectorAll(selector) {
                if (selector.includes('project_members')) {
                    return projectMembers;
                }
                if (selector.includes('event_attendees')) {
                    return eventAttendees;
                }
                if (selector.includes('data-source-type="role"')) {
                    return roles;
                }
                if (selector.includes('data-source-type="user"')) {
                    return users;
                }

                return [];
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
        users,
        elements,
        fetchCalls,
    };
}

test('recipient source badges and preview update live when selecting checkbox options', async () => {
    const harness = createHarness();

    assert.equal(harness.elements['source-project-members-count'].textContent, '1');
    assert.equal(harness.elements['source-users-count'].textContent, '0');
    assert.ok(harness.fetchCalls.length >= 1);

    harness.users[0].checked = true;
    harness.users[0].dispatch('change');

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
