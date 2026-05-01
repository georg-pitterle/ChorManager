import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const sourcePath = path.resolve(__dirname, '..', '..', 'public', 'js', 'table-engine.js');
const source = fs.readFileSync(sourcePath, 'utf8');

function loadResolveAutoView() {
    const window = {};
    const document = {
        addEventListener() { },
        querySelectorAll() {
            return [];
        }
    };

    window.document = document;

    const context = vm.createContext({
        window,
        document,
        console,
        Set,
        Array,
        Object,
        Number,
        String,
        Boolean,
        Math,
        JSON
    });

    new vm.Script(source, { filename: 'table-engine.js' }).runInContext(context);

    return window.ChorTableEngine.resolveAutoView;
}

function loadResolveAvailableWidth() {
    const window = {
        innerWidth: 320
    };
    const document = {
        documentElement: {
            clientWidth: 305
        },
        addEventListener() { },
        querySelectorAll() {
            return [];
        }
    };

    window.document = document;

    const context = vm.createContext({
        window,
        document,
        console,
        Set,
        Array,
        Object,
        Number,
        String,
        Boolean,
        Math,
        JSON
    });

    new vm.Script(source, { filename: 'table-engine.js' }).runInContext(context);

    return window.ChorTableEngine.resolveAvailableWidth;
}

test('auto view decision is symmetric and based on overflow only', () => {
    const resolveAutoView = loadResolveAutoView();

    assert.equal(resolveAutoView(-1, 'cards', true), 'table');
    assert.equal(resolveAutoView(-1, 'cards', false), 'table');
});

test('auto mode still switches to cards when the table overflows', () => {
    const resolveAutoView = loadResolveAutoView();

    assert.equal(resolveAutoView(12, 'cards', false), 'cards');
    assert.equal(resolveAutoView(12, 'table', true), 'cards');
});

test('available width is capped by the visible viewport width', () => {
    const resolveAvailableWidth = loadResolveAvailableWidth();

    assert.equal(resolveAvailableWidth(928), 305);
    assert.equal(resolveAvailableWidth(280), 280);
});
