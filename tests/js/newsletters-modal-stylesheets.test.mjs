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

const TOM_SELECT_CSS = '/vendor/tom-select/css/tom-select.bootstrap5.min.css';
const BOOTSTRAP_CSS = '/vendor/bootstrap/dist/css/bootstrap.min.css';

function createElement(tagName) {
    return {
        tagName: String(tagName).toUpperCase(),
        attributes: {},
        children: [],
        rel: '',
        href: '',
        className: '',
        innerHTML: '',
        textContent: '',
        getAttribute(name) {
            if (name === 'rel') {
                return this.rel || this.attributes.rel || null;
            }
            if (name === 'href') {
                return this.href || this.attributes.href || null;
            }

            return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
        },
        setAttribute(name, value) {
            this.attributes[name] = String(value);
        },
        hasAttribute(name) {
            return Object.prototype.hasOwnProperty.call(this.attributes, name);
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
        querySelectorAll() {
            return [];
        },
        querySelector() {
            return null;
        },
        contains() {
            return false;
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
        style: {
            removeProperty() { },
        },
    };
}

function createLink(href) {
    const link = createElement('link');
    link.rel = 'stylesheet';
    link.href = href;

    return link;
}

function createHarness(modalHtml) {
    const contentElement = createElement('div');
    const modalElement = createElement('div');
    const titleElement = createElement('h5');
    const headElement = createElement('head');
    const bodyElement = createElement('body');
    const existingLinks = [createLink(BOOTSTRAP_CSS)];
    const documentListeners = {};

    const elements = {
        newsletterActionModal: modalElement,
        newsletterActionContent: contentElement,
        newsletterActionModalLabel: titleElement,
    };

    const document = {
        readyState: 'loading',
        head: headElement,
        body: bodyElement,
        scripts: [],
        getElementById(id) {
            return elements[id] || null;
        },
        querySelector() {
            return null;
        },
        querySelectorAll(selector) {
            if (selector === 'link[rel="stylesheet"][href]') {
                return existingLinks.concat(headElement.children);
            }

            return [];
        },
        createElement,
        addEventListener(type, handler) {
            documentListeners[type] = documentListeners[type] || [];
            documentListeners[type].push(handler);
        },
    };

    const parsedDocument = {
        querySelector(selector) {
            if (selector === 'body') {
                return { innerHTML: '<p>modal body</p>' };
            }

            return null;
        },
        querySelectorAll(selector) {
            if (selector.includes('link')) {
                return modalHtml.stylesheets.map(createLink);
            }

            return [];
        },
    };

    class FakeModal {
        show() { }
        hide() { }
    }

    const context = vm.createContext({
        document,
        window: {
            document,
            location: { origin: 'https://chormanager.test' },
        },
        bootstrap: {
            Modal: FakeModal,
            Dropdown: {
                getInstance() {
                    return null;
                },
            },
        },
        DOMParser: class {
            parseFromString() {
                return parsedDocument;
            }
        },
        fetch: async function fetch() {
            return {
                ok: true,
                headers: {
                    get() {
                        return 'text/html';
                    },
                },
                async text() {
                    return '<html><head></head><body><p>modal body</p></body></html>';
                },
            };
        },
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
    (documentListeners.DOMContentLoaded || []).forEach(handler => handler());

    return {
        context,
        headElement,
    };
}

test('modal load injects stylesheets from the fetched page head', async () => {
    const harness = createHarness({ stylesheets: [BOOTSTRAP_CSS, TOM_SELECT_CSS] });

    harness.context.window.newsletterModalNavigate('/newsletters/create?project_id=1', 'Newsletter');
    await new Promise(resolve => setImmediate(resolve));

    const injectedHrefs = harness.headElement.children.map(child => child.getAttribute('href'));

    assert.deepEqual(injectedHrefs, [TOM_SELECT_CSS]);
    assert.equal(harness.headElement.children[0].getAttribute('rel'), 'stylesheet');
});

test('modal load does not inject the same stylesheet twice', async () => {
    const harness = createHarness({ stylesheets: [TOM_SELECT_CSS] });

    harness.context.window.newsletterModalNavigate('/newsletters/create?project_id=1', 'Newsletter');
    await new Promise(resolve => setImmediate(resolve));
    harness.context.window.newsletterModalNavigate('/newsletters/2/edit', 'Newsletter');
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(harness.headElement.children.length, 1);
});
