<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class TableEngineViewportAutoFeatureTest extends TestCase
{
    public function testEngineCombinesSearchPluginFiltersAndPaginationDeterministically(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-composition-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');

const enginePath = process.argv[2];
const engineCode = fs.readFileSync(enginePath, 'utf8');

const rows = [];
for (let i = 1; i <= 150; i += 1) {
    const isAlpha = i <= 120;
    const isTenor = i % 2 === 0;
    rows.push({
        hidden: false,
        dataset: {
            role: isTenor ? 'tenor' : 'bass'
        },
        textContent: (isAlpha ? 'alpha ' : 'beta ') + i,
        querySelectorAll: function () {
            return [];
        }
    });
}

const storage = {
    'chor.table.users.manage': JSON.stringify({
        state: {
            pluginFilters: {
                usersManage: {
                    role: 'tenor'
                }
            }
        }
    })
};

const table = {
    id: 'usersTable',
    scrollWidth: 700,
    clientWidth: 700,
    parentElement: { clientWidth: 900 },
    closest: function () { return { clientWidth: 900 }; },
    querySelectorAll: function (selector) {
        if (selector === 'tbody tr') {
            return rows;
        }
        return [];
    }
};

const searchInput = {
    value: '',
    disabled: true,
    _onInput: null,
    addEventListener: function (_eventName, cb) {
        this._onInput = cb;
    }
};

const pluginSlot = {
    appendChild: function () {}
};

const resetButton = {
    disabled: true,
    addEventListener: function () {}
};

const pageSizeSelect = {
    value: '25',
    disabled: true,
    options: [
        { value: '25' },
        { value: '50' },
        { value: '100' }
    ],
    addEventListener: function () {}
};

const pagePrevButton = {
    disabled: true,
    addEventListener: function () {}
};

const pageNextButton = {
    disabled: true,
    addEventListener: function () {}
};

const pageLabel = {
    textContent: ''
};

const container = {
    dataset: {
        tableId: 'users.manage',
        tableEngine: 'true',
        tablePlugins: 'usersManage',
        defaultPageSize: '25'
    },
    querySelector: function (selector) {
        if (selector === 'table') return table;
        if (selector === '[data-table-search]') return searchInput;
        if (selector === '[data-table-plugin-slot]') return pluginSlot;
        if (selector === '[data-table-reset]') return resetButton;
        if (selector === '[data-table-page-size]') return pageSizeSelect;
        if (selector === '[data-table-page-prev]') return pagePrevButton;
        if (selector === '[data-table-page-next]') return pageNextButton;
        if (selector === '[data-table-page-label]') return pageLabel;
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-mode], [data-table-view]') return [];
        if (selector === 'th[data-sort-key]') return [];
        return [];
    }
};

const documentMock = {
    _onReady: null,
    addEventListener: function (eventName, cb) {
        if (eventName === 'DOMContentLoaded') {
            this._onReady = cb;
        }
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-engine="true"]') {
            return [container];
        }
        return [];
    },
    createElement: function () {
        return {
            className: '',
            textContent: '',
            value: '',
            dataset: {},
            children: [],
            appendChild: function (child) { this.children.push(child); },
            setAttribute: function () {},
            addEventListener: function () {}
        };
    }
};

const windowMock = {
    addEventListener: function () {},
    localStorage: {
        getItem: function (key) {
            return Object.prototype.hasOwnProperty.call(storage, key) ? storage[key] : null;
        },
        setItem: function (key, value) {
            storage[key] = value;
        },
        removeItem: function (key) {
            delete storage[key];
        }
    },
    ChorTablePrefs: {
        read: function (tableId) {
            const raw = storage['chor.table.' + tableId];
            return raw ? JSON.parse(raw) : {};
        },
        write: function (tableId, value) {
            storage['chor.table.' + tableId] = JSON.stringify(value);
        },
        clear: function (tableId) {
            delete storage['chor.table.' + tableId];
        }
    }
};

global.window = windowMock;
global.document = documentMock;

eval(engineCode);

windowMock.ChorTableEngine.registerFilterPlugin('usersManage', function (context) {
    let state = { role: '' };

    return {
        mount: function () {
            context.pluginSlot.appendChild({ mounted: true });
        },
        getPredicate: function () {
            return function (row) {
                return context.matchCell(row, 'role', state.role);
            };
        },
        getState: function () {
            return state;
        },
        setState: function (nextState) {
            state = Object.assign({ role: '' }, nextState || {});
        },
        reset: function () {
            state = { role: '' };
        }
    };
});

documentMock._onReady();

if (rows[0].hidden !== true || rows[1].hidden !== false) {
    throw new Error('Expected plugin baseline to hide bass rows and keep tenor rows visible');
}

const initiallyVisible = rows.filter(function (row) { return !row.hidden; }).length;
if (initiallyVisible !== 25) {
    throw new Error('Expected page size 25 on baseline');
}

searchInput.value = 'beta';
searchInput._onInput();

if (pageLabel.textContent !== 'Seite 1 / 1') {
    throw new Error('Expected narrowed search+plugin result to be single page');
}

const visibleAfterSearch = rows.filter(function (row) { return !row.hidden; }).length;
if (visibleAfterSearch !== 15) {
    throw new Error('Expected 15 visible rows after search+plugin composition');
}

if (pageNextButton.disabled !== true) {
    throw new Error('Expected next button disabled on single page result');
}

console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);

        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testEngineUsesOverrideOnlyPreferenceModelWithLegacyFallback(): void
    {
        $engine = file_get_contents(dirname(__DIR__) . '/../public/js/table-engine.js');

        $this->assertIsString($engine);
        $this->assertStringContainsString('viewOverride', $engine);
        $this->assertStringContainsString('prefs.view', $engine);
        $this->assertStringContainsString('container.dataset.defaultView', $engine);
        $this->assertStringContainsString('data-table-mode', $engine);
        $this->assertStringContainsString('registerFilterPlugin', $engine);
        $this->assertStringContainsString('tablePlugins', $engine);
    }

    public function testAutoModeUpdatesOnResizeAndDoesNotPersistDuringInitialization(): void
    {
        $engine = file_get_contents(dirname(__DIR__) . '/../public/js/table-engine.js');

        $this->assertIsString($engine);
        $this->assertStringContainsString('table.scrollWidth', $engine);
        $this->assertStringContainsString('viewportElement.scrollWidth', $engine);
        $this->assertStringContainsString('clientWidth', $engine);
        $this->assertStringContainsString('AUTO_VIEW_HYSTERESIS_PX', $engine);
        $this->assertStringContainsString("window.addEventListener('resize'", $engine);
        $this->assertStringContainsString("if (mode === 'auto')", $engine);
        $this->assertStringContainsString('applyMode(mode, false);', $engine);
        $this->assertStringContainsString('if (shouldPersist) {', $engine);
        $this->assertStringContainsString('persistMode(mode);', $engine);
        $this->assertStringContainsString('applyMode(clickedMode, true);', $engine);
        $this->assertStringContainsString('applyEffectiveView(mode);', $engine);
        $this->assertStringNotContainsString('setView(initialView)', $engine);
    }

    public function testPreferencesHelperRetainsSafeReadWriteContract(): void
    {
        $prefs = file_get_contents(dirname(__DIR__) . '/../public/js/table-preferences.js');

        $this->assertIsString($prefs);
        $this->assertStringContainsString("const PREFIX = 'chor.table.';", $prefs);
        $this->assertStringContainsString('function read(tableId)', $prefs);
        $this->assertStringContainsString('function write(tableId, value)', $prefs);
        $this->assertStringContainsString('window.ChorTablePrefs', $prefs);
    }

    public function testEngineRuntimeBehaviorForAutoAndOverrideModes(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');

const enginePath = process.argv[2];
const engineCode = fs.readFileSync(enginePath, 'utf8');

let viewportWidth = 1200;
const resizeHandlers = [];
const storage = {};
const writeCalls = [];

const responsiveWrapper = {
    clientWidth: 900
};

function makeButton(label, dataset) {
    return {
        label,
        dataset,
        _handlers: {},
        classList: {
            toggle: function () {}
        },
        setAttribute: function () {},
        addEventListener: function (eventName, cb) {
            this._handlers[eventName] = cb;
        },
        click: function () {
            if (this._handlers.click) {
                this._handlers.click();
            }
        }
    };
}

const autoButton = makeButton('Auto', { tableMode: 'auto' });
const cardsButton = makeButton('Karten', { tableView: 'cards' });
const tableButton = makeButton('Tabelle', { tableView: 'table' });
const modeButtons = [autoButton, cardsButton, tableButton];

const tableElement = {
    id: 'table-id',
    scrollWidth: 700,
    clientWidth: 700,
    parentElement: responsiveWrapper,
    closest: function (selector) {
        if (selector === '.table-responsive') {
            return responsiveWrapper;
        }
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === 'tbody tr') {
            return [];
        }
        return [];
    }
};

const container = {
    dataset: {
        tableId: 'engine.runtime',
        tableEngine: 'true',
        defaultView: 'table'
    },
    querySelector: function (selector) {
        if (selector === 'table') {
            return tableElement;
        }
        if (selector === '[data-table-search]') {
            return null;
        }
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-mode], [data-table-view]') {
            return modeButtons;
        }
        return [];
    }
};

const documentMock = {
    _domReady: null,
    addEventListener: function (eventName, cb) {
        if (eventName === 'DOMContentLoaded') {
            this._domReady = cb;
        }
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-engine="true"]') {
            return [container];
        }
        return [];
    }
};

const windowMock = {
    matchMedia: function () {
        return {
            matches: viewportWidth <= 767.98
        };
    },
    addEventListener: function (eventName, cb) {
        if (eventName === 'resize') {
            resizeHandlers.push(cb);
        }
    },
    localStorage: {
        getItem: function (key) {
            return Object.prototype.hasOwnProperty.call(storage, key) ? storage[key] : null;
        },
        setItem: function (key, value) {
            storage[key] = value;
        }
    },
    ChorTablePrefs: {
        read: function () {
            return {};
        },
        write: function (_tableId, value) {
            writeCalls.push(value);
        }
    }
};

global.window = windowMock;
global.document = documentMock;

eval(engineCode);

if (typeof documentMock._domReady !== 'function') {
    throw new Error('DOMContentLoaded handler was not registered');
}

documentMock._domReady();

if (container.dataset.activeView !== 'table') {
    throw new Error('Expected desktop auto initialization to resolve to table view');
}

if (writeCalls.length !== 0) {
    throw new Error('Initialization must not persist preferences');
}

cardsButton.click();
if (container.dataset.activeView !== 'cards') {
    throw new Error('Cards override click should activate cards view');
}

if (writeCalls.length !== 1 || writeCalls[0].viewOverride !== 'cards') {
    throw new Error('Cards override click must persist viewOverride=cards');
}

viewportWidth = 1200;
resizeHandlers.forEach((cb) => cb());
if (container.dataset.activeView !== 'cards') {
    throw new Error('Override mode must ignore viewport resize changes');
}

autoButton.click();
if (container.dataset.activeView !== 'table') {
    throw new Error('Auto click on desktop should resolve to table view');
}

if (writeCalls.length !== 2 || Object.prototype.hasOwnProperty.call(writeCalls[1], 'viewOverride')) {
    throw new Error('Auto click must clear viewOverride in persisted value');
}

viewportWidth = 500;
responsiveWrapper.clientWidth = 500;
tableElement.scrollWidth = 700;
resizeHandlers.forEach((cb) => cb());
if (container.dataset.activeView !== 'cards') {
    throw new Error('Auto mode should switch to cards when table overflows');
}

// In cards mode the transformed layout may distort scrollWidth. The engine should still be able
// to switch back using the cached width measured while in table mode.
tableElement.scrollWidth = 2000;

// Hysteresis: with only a small free space buffer we should stay in cards mode.
responsiveWrapper.clientWidth = 710;
resizeHandlers.forEach((cb) => cb());
if (container.dataset.activeView !== 'cards') {
    throw new Error('Auto mode should not immediately switch back to table near threshold');
}

// Once there is enough free space, auto mode should return to table mode.
responsiveWrapper.clientWidth = 740;
resizeHandlers.forEach((cb) => cb());
if (container.dataset.activeView !== 'table') {
    throw new Error('Auto mode should switch back to table when overflow pressure is gone');
}

console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);

        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testAutoModeCanReturnToTableFromPersistedCardsOverrideAtWideViewport(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-persisted-cards-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');

const enginePath = process.argv[2];
const engineCode = fs.readFileSync(enginePath, 'utf8');

const resizeHandlers = [];
const responsiveWrapper = {
    clientWidth: 1700
};

function makeButton(dataset) {
    return {
        dataset,
        _handlers: {},
        classList: {
            toggle: function () {}
        },
        setAttribute: function () {},
        addEventListener: function (eventName, cb) {
            this._handlers[eventName] = cb;
        },
        click: function () {
            if (this._handlers.click) {
                this._handlers.click();
            }
        }
    };
}

const autoButton = makeButton({ tableMode: 'auto' });
const cardsButton = makeButton({ tableView: 'cards' });
const tableButton = makeButton({ tableView: 'table' });
const modeButtons = [autoButton, cardsButton, tableButton];

const rows = [
    { hidden: false, textContent: 'Visible row', dataset: {}, querySelectorAll: function () { return []; } },
    { hidden: false, textContent: 'Another visible row', dataset: {}, querySelectorAll: function () { return []; } },
    { hidden: false, textContent: 'Off-page row with long content '.repeat(80), dataset: {}, querySelectorAll: function () { return []; } }
];

const tableElement = {
    id: 'table-id',
    scrollWidth: 700,
    clientWidth: 700,
    style: {},
    parentElement: responsiveWrapper,
    closest: function (selector) {
        if (selector === '.table-responsive') {
            return responsiveWrapper;
        }
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === 'tbody tr') {
            return rows;
        }
        return [];
    }
};

const pageSizeSelect = {
    value: '2',
    disabled: true,
    options: [{ value: '2' }],
    setAttribute: function () {},
    addEventListener: function () {}
};

const container = {
    dataset: {
        tableId: 'engine.persisted-cards',
        tableEngine: 'true',
        defaultView: 'table',
        defaultPageSize: '2'
    },
    querySelector: function (selector) {
        if (selector === 'table') {
            return tableElement;
        }
        if (selector === '[data-table-page-size]') {
            return pageSizeSelect;
        }
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-mode], [data-table-view]') {
            return modeButtons;
        }
        if (selector === 'th[data-sort-key]') {
            return [];
        }
        return [];
    }
};

const documentMock = {
    _domReady: null,
    addEventListener: function (eventName, cb) {
        if (eventName === 'DOMContentLoaded') {
            this._domReady = cb;
        }
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-engine="true"]') {
            return [container];
        }
        return [];
    }
};

const windowMock = {
    addEventListener: function (eventName, cb) {
        if (eventName === 'resize') {
            resizeHandlers.push(cb);
        }
    },
    ChorTablePrefs: {
        read: function () {
            return { viewOverride: 'cards' };
        },
        write: function () {},
        clear: function () {}
    }
};

global.window = windowMock;
global.document = documentMock;

eval(engineCode);

if (typeof documentMock._domReady !== 'function') {
    throw new Error('DOMContentLoaded handler was not registered');
}

documentMock._domReady();

if (container.dataset.activeView !== 'cards') {
    throw new Error('Expected persisted cards override to initialize cards view');
}

tableElement.scrollWidth = 2000;
autoButton.click();

if (container.dataset.activeView !== 'table') {
    throw new Error('Expected auto mode to switch back to table at wide viewport');
}

const visibleRows = rows.filter(function (row) {
    return !row.hidden;
});

if (visibleRows.length !== 2) {
    throw new Error('Expected initial pagination to hide off-page rows before measuring width');
}

console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);

        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testDefaultViewIsUsedWhenNoPreferenceExists(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-default-view-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');

const enginePath = process.argv[2];
const engineCode = fs.readFileSync(enginePath, 'utf8');

const responsiveWrapper = {
    clientWidth: 900,
    scrollWidth: 900
};

const tableElement = {
    id: 'table-id',
    clientWidth: 900,
    scrollWidth: 900,
    parentElement: responsiveWrapper,
    closest: function (selector) {
        if (selector === '.table-responsive') {
            return responsiveWrapper;
        }
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === 'tbody tr') {
            return [];
        }
        return [];
    }
};

const container = {
    dataset: {
        tableId: 'engine.default-view',
        tableEngine: 'true',
        defaultView: 'cards'
    },
    querySelector: function (selector) {
        if (selector === 'table') {
            return tableElement;
        }
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-mode], [data-table-view]') {
            return [];
        }
        if (selector === 'th[data-sort-key]') {
            return [];
        }
        return [];
    }
};

const documentMock = {
    _domReady: null,
    addEventListener: function (eventName, cb) {
        if (eventName === 'DOMContentLoaded') {
            this._domReady = cb;
        }
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-engine="true"]') {
            return [container];
        }
        return [];
    }
};

const windowMock = {
    addEventListener: function () {},
    ChorTablePrefs: {
        read: function () {
            return {};
        },
        write: function () {}
    }
};

global.window = windowMock;
global.document = documentMock;

eval(engineCode);
documentMock._domReady();

if (container.dataset.activeView !== 'cards') {
    throw new Error('Expected data-default-view=cards to initialize cards view');
}

console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);

        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testAutoModeUsesRealTableLayoutMeasurementForWrappedDesktopTable(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-real-layout-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');

const enginePath = process.argv[2];
const engineCode = fs.readFileSync(enginePath, 'utf8');

const responsiveWrapper = {
    clientWidth: 1296,
    scrollWidth: 1296
};

const tableStyle = {};
const containerDataset = {
    tableId: 'projects.index',
    tableEngine: 'true',
    defaultView: 'auto',
    defaultPageSize: '100'
};

const rows = [];
for (let i = 0; i < 6; i += 1) {
    rows.push({
        hidden: false,
        dataset: {},
        textContent: 'Projekt ' + i,
        querySelectorAll: function () { return []; }
    });
}

const tableElement = {
    id: 'projectsTable',
    style: tableStyle,
    get clientWidth() {
        return 1296;
    },
    get scrollWidth() {
        if (tableStyle.width === 'max-content') {
            return 2200;
        }
        if (containerDataset.activeView === 'cards') {
            return 2200;
        }
        return 1296;
    },
    parentElement: responsiveWrapper,
    closest: function (selector) {
        if (selector === '.table-responsive') {
            return responsiveWrapper;
        }
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === 'tbody tr') {
            return rows;
        }
        return [];
    }
};

function makeButton(label, dataset) {
    return {
        label,
        dataset,
        _handlers: {},
        classList: {
            toggle: function () {}
        },
        setAttribute: function () {},
        addEventListener: function (eventName, cb) {
            this._handlers[eventName] = cb;
        },
        click: function () {
            if (this._handlers.click) {
                this._handlers.click();
            }
        }
    };
}

const modeButtons = [
    makeButton('Auto', { tableMode: 'auto' }),
    makeButton('Karten', { tableView: 'cards' }),
    makeButton('Tabelle', { tableView: 'table' })
];

const pageSizeSelect = {
    value: '100',
    disabled: true,
    options: [{ value: '100' }],
    setAttribute: function () {},
    addEventListener: function () {}
};

const container = {
    dataset: containerDataset,
    querySelector: function (selector) {
        if (selector === 'table') {
            return tableElement;
        }
        if (selector === '[data-table-page-size]') {
            return pageSizeSelect;
        }
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-mode], [data-table-view]') {
            return modeButtons;
        }
        if (selector === 'th[data-sort-key]') {
            return [];
        }
        return [];
    }
};

const documentMock = {
    _domReady: null,
    addEventListener: function (eventName, cb) {
        if (eventName === 'DOMContentLoaded') {
            this._domReady = cb;
        }
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-engine="true"]') {
            return [container];
        }
        return [];
    }
};

const windowMock = {
    addEventListener: function () {},
    ChorTablePrefs: {
        read: function () {
            return {};
        },
        write: function () {}
    }
};

global.window = windowMock;
global.document = documentMock;

eval(engineCode);
documentMock._domReady();

if (container.dataset.activeView !== 'table') {
    throw new Error('Expected wrapped desktop table to stay in table view in auto mode');
}

console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);

        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testEngineRuntimeBehaviorForSortPaginationAndReset(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $prefsPath = dirname(__DIR__) . '/../public/js/table-preferences.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-pagination-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');
const prefsCode = fs.readFileSync(process.argv[2], 'utf8');
const engineCode = fs.readFileSync(process.argv[3], 'utf8');

const storage = {};
const rows = [];
for (let i = 1; i <= 205; i++) {
    rows.push({
        hidden: false,
        dataset: {
            sortName: String(206 - i)
        },
        textContent: 'Name ' + i,
        querySelectorAll: () => []
    });
}

const sortByNameHeader = {
    dataset: { sortKey: 'name' },
    _onClick: null,
    addEventListener: function (_eventName, cb) {
        this._onClick = cb;
    },
    click: function () {
        if (typeof this._onClick === 'function') {
            this._onClick();
        }
    }
};

const table = {
    id: 'usersTable',
    scrollWidth: 700,
    clientWidth: 700,
    parentElement: { clientWidth: 900 },
    closest: () => ({ clientWidth: 900 }),
    querySelectorAll: (selector) => selector === 'tbody tr' ? rows : []
};

storage['chor.table.users.manage'] = JSON.stringify({
    state: {
        pageSize: 999,
        page: 1
    }
});

const searchInput = {
    value: '',
    disabled: true,
    addEventListener: function (_e, cb) { this._onInput = cb; }
};
const resetBtn = {
    disabled: true,
    addEventListener: function (_e, cb) { this._onClick = cb; },
    click: function () { this._onClick(); }
};
const pageSize = {
    value: '100',
    disabled: true,
    options: [{ value: '50' }, { value: '100' }, { value: '200' }],
    attrs: {},
    setAttribute: function (name, value) { this.attrs[name] = value; },
    addEventListener: function (_e, cb) { this._onChange = cb; }
};
const prevBtn = {
    disabled: true,
    attrs: {},
    setAttribute: function (name, value) { this.attrs[name] = value; },
    addEventListener: function (_e, cb) { this._onClick = cb; }
};
const nextBtn = {
    disabled: true,
    attrs: {},
    setAttribute: function (name, value) { this.attrs[name] = value; },
    addEventListener: function (_e, cb) { this._onClick = cb; }
};
const pageLabel = { textContent: '' };

const container = {
    dataset: { tableId: 'users.manage', tableEngine: 'true', defaultPageSize: '100' },
    querySelector: function (selector) {
        if (selector === 'table') return table;
        if (selector === '[data-table-search]') return searchInput;
        if (selector === '[data-table-reset]') return resetBtn;
        if (selector === '[data-table-page-size]') return pageSize;
        if (selector === '[data-table-page-prev]') return prevBtn;
        if (selector === '[data-table-page-next]') return nextBtn;
        if (selector === '[data-table-page-label]') return pageLabel;
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-mode], [data-table-view]') return [];
        if (selector === 'th[data-sort-key]') return [sortByNameHeader];
        return [];
    }
};

const documentMock = {
    _onReady: null,
    addEventListener: function (eventName, cb) {
        if (eventName === 'DOMContentLoaded') this._onReady = cb;
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-engine="true"]') return [container];
        return [];
    }
};

const windowMock = {
    addEventListener: function () {},
    localStorage: {
        getItem: (k) => Object.prototype.hasOwnProperty.call(storage, k) ? storage[k] : null,
        setItem: (k, v) => { storage[k] = v; },
        removeItem: (k) => { delete storage[k]; }
    }
};

global.window = windowMock;
global.document = documentMock;
eval(prefsCode);
eval(engineCode);
documentMock._onReady();

function parsePageLabel(label) {
    const match = String(label || '').match(/(\d+)\s*[^\d]+\s*(\d+)/);
    if (!match) {
        throw new Error('Could not parse pagination label: ' + label);
    }
    return { page: Number(match[1]), total: Number(match[2]) };
}

function visibleRows() {
    return rows.filter((row) => !row.hidden);
}

function firstVisibleRowText() {
    const first = visibleRows()[0];
    return first ? first.textContent : '';
}

let parsed = parsePageLabel(pageLabel.textContent);
if (parsed.page !== 1 || parsed.total !== 3) throw new Error('Expected first page with three total pages after initialization');
if (visibleRows().length !== 100) throw new Error('Expected first page to render a 100-row visibility window by default');
if (pageSize.value !== '100') throw new Error('Persisted invalid page size should normalize to default page size 100');
if (pageSize.attrs['aria-controls'] !== 'usersTable') throw new Error('Page-size control should link to table via aria-controls');
if (prevBtn.attrs['aria-controls'] !== 'usersTable') throw new Error('Prev control should link to table via aria-controls');
if (nextBtn.attrs['aria-controls'] !== 'usersTable') throw new Error('Next control should link to table via aria-controls');

if (nextBtn.disabled !== false) throw new Error('Next should be enabled on page 1 of 3');
if (prevBtn.disabled !== true) throw new Error('Prev should be disabled on page 1 of 3');
if (searchInput.disabled !== false) throw new Error('Search should be enabled when engine initializes');
if (pageSize.disabled !== false) throw new Error('Page size should be enabled when engine initializes');
if (resetBtn.disabled !== false) throw new Error('Reset should be enabled when engine initializes');

sortByNameHeader.click();
if (visibleRows().length !== 100) throw new Error('Sorting should keep the current page-size visibility window');
if (firstVisibleRowText() !== 'Name 106') throw new Error('First sort click should apply ascending sort by data-sort-name visibility window');
if (rows[0].hidden !== true) throw new Error('Ascending sort should move Name 1 out of the first-page visibility window');
if (rows[105].hidden !== false) throw new Error('Ascending sort should bring Name 106 into the first-page visibility window');
if (rows[204].hidden !== false) throw new Error('Ascending sort should include Name 205 in the first-page visibility window');

sortByNameHeader.click();
if (visibleRows().length !== 100) throw new Error('Sort direction toggle should keep the same page-size visibility window');
if (firstVisibleRowText() !== 'Name 1') throw new Error('Second sort click should toggle to descending sort by data-sort-name');
if (rows[0].hidden !== false) throw new Error('Descending sort should bring Name 1 back into the first-page visibility window');
if (rows[105].hidden !== true) throw new Error('Descending sort should move Name 106 out of the first-page visibility window');

pageSize.value = '200';
pageSize._onChange();
parsed = parsePageLabel(pageLabel.textContent);
if (parsed.page !== 1 || parsed.total !== 2) throw new Error('Expected first page with two total pages after page-size change');
if (visibleRows().length !== 200) throw new Error('Expected page-size change to expand the visibility window to 200 rows');

nextBtn._onClick();
parsed = parsePageLabel(pageLabel.textContent);
if (parsed.page !== 2 || parsed.total !== 2) throw new Error('Expected second page with two total pages after next click');
if (visibleRows().length !== 5) throw new Error('Expected second page visibility window to contain remaining 5 rows');
if (nextBtn.disabled !== true) throw new Error('Next should be disabled on last page');

resetBtn.click();
if (pageSize.value !== '100') throw new Error('Reset should restore default page size 100');
parsed = parsePageLabel(pageLabel.textContent);
if (parsed.page !== 1 || parsed.total !== 3) throw new Error('Reset should restore first page with three total pages');
if (visibleRows().length !== 100) throw new Error('Reset should restore the default 100-row visibility window');
if (Object.prototype.hasOwnProperty.call(storage, 'chor.table.users.manage')) throw new Error('Reset should clear persisted preferences');

console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);
        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($prefsPath) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testEngineRuntimeBehaviorForRegisteredFilterPlugin(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-plugin-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');
const engineCode = fs.readFileSync(process.argv[2], 'utf8');

const storage = {
    'chor.table.users.manage': JSON.stringify({
        state: {
            pluginFilters: {
                usersManage: { role: 'admin' }
            }
        }
    })
};

const rows = [
    {
        hidden: false,
        dataset: { role: 'admin' },
        textContent: 'Alice',
        querySelectorAll: function () { return []; }
    },
    {
        hidden: false,
        dataset: { role: 'member' },
        textContent: 'Bob',
        querySelectorAll: function () { return []; }
    }
];

const table = {
    id: 'usersTable',
    scrollWidth: 700,
    clientWidth: 700,
    parentElement: { clientWidth: 900 },
    closest: function () { return { clientWidth: 900 }; },
    querySelectorAll: function (selector) {
        if (selector === 'tbody tr') {
            return rows;
        }
        return [];
    }
};

const pluginSlot = {
    children: [],
    appendChild: function (child) {
        this.children.push(child);
    }
};

const resetButton = {
    _onClick: null,
    disabled: true,
    addEventListener: function (_eventName, cb) { this._onClick = cb; },
    click: function () { if (this._onClick) { this._onClick(); } }
};

const container = {
    dataset: {
        tableId: 'users.manage',
        tableEngine: 'true',
        tablePlugins: 'usersManage'
    },
    querySelector: function (selector) {
        if (selector === 'table') return table;
        if (selector === '[data-table-plugin-slot]') return pluginSlot;
        if (selector === '[data-table-reset]') return resetButton;
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-mode], [data-table-view]') return [];
        if (selector === 'th[data-sort-key]') return [];
        return [];
    }
};

function createElement(tagName) {
    return {
        tagName,
        className: '',
        textContent: '',
        value: '',
        dataset: {},
        children: [],
        appendChild: function (child) { this.children.push(child); },
        setAttribute: function () {},
        addEventListener: function () {}
    };
}

const documentMock = {
    _onReady: null,
    addEventListener: function (eventName, cb) {
        if (eventName === 'DOMContentLoaded') {
            this._onReady = cb;
        }
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-engine="true"]') {
            return [container];
        }
        return [];
    },
    createElement
};

const windowMock = {
    addEventListener: function () {},
    localStorage: {
        getItem: function (key) {
            return Object.prototype.hasOwnProperty.call(storage, key) ? storage[key] : null;
        },
        setItem: function (key, value) {
            storage[key] = value;
        },
        removeItem: function (key) {
            delete storage[key];
        }
    },
    ChorTablePrefs: {
        read: function (tableId) {
            const raw = storage['chor.table.' + tableId];
            return raw ? JSON.parse(raw) : {};
        },
        write: function (tableId, value) {
            storage['chor.table.' + tableId] = JSON.stringify(value);
        },
        clear: function (tableId) {
            delete storage['chor.table.' + tableId];
        }
    }
};

global.window = windowMock;
global.document = documentMock;
eval(engineCode);

if (!windowMock.ChorTableEngine || typeof windowMock.ChorTableEngine.registerFilterPlugin !== 'function') {
    throw new Error('Expected registerFilterPlugin API to exist');
}

let resetCalls = 0;
windowMock.ChorTableEngine.registerFilterPlugin('usersManage', function (context) {
    let state = { role: '' };
    return {
        mount: function () {
            context.pluginSlot.appendChild({ name: 'mounted' });
        },
        getPredicate: function () {
            return function (row) {
                return context.matchCell(row, 'role', state.role);
            };
        },
        getState: function () {
            return state;
        },
        setState: function (nextState) {
            state = Object.assign({ role: '' }, nextState || {});
        },
        reset: function () {
            resetCalls += 1;
            state = { role: '' };
        }
    };
});

documentMock._onReady();

if (rows[0].hidden !== false || rows[1].hidden !== true) {
    throw new Error('Persisted plugin state should filter to matching rows');
}

if (pluginSlot.children.length !== 1) {
    throw new Error('Registered plugin should mount into plugin slot');
}

resetButton.click();

if (resetCalls !== 1) {
    throw new Error('Reset should call plugin.reset exactly once');
}

if (rows[0].hidden !== false || rows[1].hidden !== false) {
    throw new Error('Reset should clear plugin filter and restore all rows');
}

console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);
        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testDefaultSortKeyAndDirAreAppliedOnFirstLoad(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-default-sort-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');
const enginePath = process.argv[2];
const engineCode = fs.readFileSync(enginePath, 'utf8');

const rows = [
    { hidden: false, dataset: { sortName: 'charlie' }, textContent: 'Charlie', querySelectorAll: function () { return []; } },
    { hidden: false, dataset: { sortName: 'alice' }, textContent: 'Alice', querySelectorAll: function () { return []; } },
    { hidden: false, dataset: { sortName: 'bob' }, textContent: 'Bob', querySelectorAll: function () { return []; } }
];
const table = {
    id: '', scrollWidth: 0, clientWidth: 0,
    closest: function () { return null; },
    querySelectorAll: function (sel) { return sel === 'tbody tr' ? rows : []; }
};
const container = {
    dataset: { tableEngine: 'true', tableId: 'test.default-sort', defaultView: 'table', defaultPageSize: '2', defaultSortKey: 'name', defaultSortDir: 'asc' },
    querySelector: function (sel) {
        if (sel === 'table') { return table; }
        if (sel === '[data-table-search]') { return { value: '', disabled: true, addEventListener: function () {} }; }
        if (sel === '[data-table-reset]') { return { disabled: true, addEventListener: function () {} }; }
        if (sel === '[data-table-page-size]') { return { value: '2', disabled: true, options: [{ value: '2' }], addEventListener: function () {} }; }
        if (sel === '[data-table-page-prev]') { return { disabled: true, setAttribute: function () {}, removeAttribute: function () {}, addEventListener: function () {} }; }
        if (sel === '[data-table-page-next]') { return { disabled: true, setAttribute: function () {}, removeAttribute: function () {}, addEventListener: function () {} }; }
        if (sel === '[data-table-page-label]') { return { textContent: '' }; }
        if (sel === '[data-table-pagination]') { return { textContent: '', setAttribute: function () {}, removeAttribute: function () {} }; }
        if (sel === '[data-table-plugin-slot]') { return { appendChild: function () {}, querySelectorAll: function () { return []; } }; }
        return null;
    },
    querySelectorAll: function (sel) {
        if (sel === '[data-table-mode], [data-table-view]') { return []; }
        if (sel === 'th[data-sort-key]') { return [{ dataset: { sortKey: 'name' }, addEventListener: function () {} }]; }
        return [];
    },
    addEventListener: function () {}
};
const documentMock = {
    _onReady: null,
    addEventListener: function (eventName, cb) { if (eventName === 'DOMContentLoaded') { this._onReady = cb; } },
    querySelectorAll: function (sel) { return sel === '[data-table-engine="true"]' ? [container] : []; },
    createElement: function () { return { className: '', textContent: '', value: '', dataset: {}, children: [], appendChild: function (c) { this.children.push(c); }, setAttribute: function () {}, addEventListener: function () {} }; }
};
global.window = { addEventListener: function () {}, ChorTablePrefs: { read: function () { return {}; }, write: function () {}, clear: function () {} } };
global.document = documentMock;
eval(engineCode);
documentMock._onReady();

// asc: alice, bob, charlie; page 1 (size 2) => alice(rows[1]) + bob(rows[2]) visible, charlie(rows[0]) hidden
if (rows[1].hidden !== false) { throw new Error('rows[1] (alice) should be visible on page 1 of asc sort, hidden=' + rows[1].hidden); }
if (rows[2].hidden !== false) { throw new Error('rows[2] (bob) should be visible on page 1 of asc sort, hidden=' + rows[2].hidden); }
if (rows[0].hidden !== true) { throw new Error('rows[0] (charlie) should be hidden on page 1 of asc sort, hidden=' + rows[0].hidden); }
console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);
        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testDefaultSortDescIsAppliedOnFirstLoad(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-default-sort-desc-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');
const enginePath = process.argv[2];
const engineCode = fs.readFileSync(enginePath, 'utf8');

const rows = [
    { hidden: false, dataset: { sortName: 'charlie' }, textContent: 'Charlie', querySelectorAll: function () { return []; } },
    { hidden: false, dataset: { sortName: 'alice' }, textContent: 'Alice', querySelectorAll: function () { return []; } },
    { hidden: false, dataset: { sortName: 'bob' }, textContent: 'Bob', querySelectorAll: function () { return []; } }
];
const table = {
    id: '', scrollWidth: 0, clientWidth: 0,
    closest: function () { return null; },
    querySelectorAll: function (sel) { return sel === 'tbody tr' ? rows : []; }
};
const container = {
    dataset: { tableEngine: 'true', tableId: 'test.default-sort-desc', defaultView: 'table', defaultPageSize: '2', defaultSortKey: 'name', defaultSortDir: 'desc' },
    querySelector: function (sel) {
        if (sel === 'table') { return table; }
        if (sel === '[data-table-search]') { return { value: '', disabled: true, addEventListener: function () {} }; }
        if (sel === '[data-table-reset]') { return { disabled: true, addEventListener: function () {} }; }
        if (sel === '[data-table-page-size]') { return { value: '2', disabled: true, options: [{ value: '2' }], addEventListener: function () {} }; }
        if (sel === '[data-table-page-prev]') { return { disabled: true, setAttribute: function () {}, removeAttribute: function () {}, addEventListener: function () {} }; }
        if (sel === '[data-table-page-next]') { return { disabled: true, setAttribute: function () {}, removeAttribute: function () {}, addEventListener: function () {} }; }
        if (sel === '[data-table-page-label]') { return { textContent: '' }; }
        if (sel === '[data-table-pagination]') { return { textContent: '', setAttribute: function () {}, removeAttribute: function () {} }; }
        if (sel === '[data-table-plugin-slot]') { return { appendChild: function () {}, querySelectorAll: function () { return []; } }; }
        return null;
    },
    querySelectorAll: function (sel) {
        if (sel === '[data-table-mode], [data-table-view]') { return []; }
        if (sel === 'th[data-sort-key]') { return [{ dataset: { sortKey: 'name' }, addEventListener: function () {} }]; }
        return [];
    },
    addEventListener: function () {}
};
const documentMock = {
    _onReady: null,
    addEventListener: function (eventName, cb) { if (eventName === 'DOMContentLoaded') { this._onReady = cb; } },
    querySelectorAll: function (sel) { return sel === '[data-table-engine="true"]' ? [container] : []; },
    createElement: function () { return { className: '', textContent: '', value: '', dataset: {}, children: [], appendChild: function (c) { this.children.push(c); }, setAttribute: function () {}, addEventListener: function () {} }; }
};
global.window = { addEventListener: function () {}, ChorTablePrefs: { read: function () { return {}; }, write: function () {}, clear: function () {} } };
global.document = documentMock;
eval(engineCode);
documentMock._onReady();

// desc: charlie, bob, alice; page 1 (size 2) => charlie(rows[0]) + bob(rows[2]) visible, alice(rows[1]) hidden
if (rows[0].hidden !== false) { throw new Error('rows[0] (charlie) should be visible on page 1 of desc sort, hidden=' + rows[0].hidden); }
if (rows[2].hidden !== false) { throw new Error('rows[2] (bob) should be visible on page 1 of desc sort, hidden=' + rows[2].hidden); }
if (rows[1].hidden !== true) { throw new Error('rows[1] (alice) should be hidden on page 1 of desc sort, hidden=' + rows[1].hidden); }
console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);
        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testPersistedStateOverridesDefaultSortKey(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-persisted-sort-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');
const enginePath = process.argv[2];
const engineCode = fs.readFileSync(enginePath, 'utf8');

// rows: charlie(score=10), alice(score=30), bob(score=20)
// Default sort: name asc => alice, bob, charlie
// Persisted: score asc => charlie(10), bob(20), alice(30)
// Page 1 (size 2): charlie(rows[0]) + bob(rows[2]) visible; alice(rows[1]) hidden
const rows = [
    { hidden: false, dataset: { sortName: 'charlie', sortScore: '10' }, textContent: 'Charlie 10', querySelectorAll: function () { return []; } },
    { hidden: false, dataset: { sortName: 'alice', sortScore: '30' }, textContent: 'Alice 30', querySelectorAll: function () { return []; } },
    { hidden: false, dataset: { sortName: 'bob', sortScore: '20' }, textContent: 'Bob 20', querySelectorAll: function () { return []; } }
];
const storage = {
    'chor.table.test.persisted-sort': JSON.stringify({ state: { sortKey: 'score', sortDir: 'asc' } })
};
const table = {
    id: '', scrollWidth: 0, clientWidth: 0,
    closest: function () { return null; },
    querySelectorAll: function (sel) { return sel === 'tbody tr' ? rows : []; }
};
const container = {
    dataset: { tableEngine: 'true', tableId: 'test.persisted-sort', defaultView: 'table', defaultPageSize: '2', defaultSortKey: 'name', defaultSortDir: 'asc' },
    querySelector: function (sel) {
        if (sel === 'table') { return table; }
        if (sel === '[data-table-search]') { return { value: '', disabled: true, addEventListener: function () {} }; }
        if (sel === '[data-table-reset]') { return { disabled: true, addEventListener: function () {} }; }
        if (sel === '[data-table-page-size]') { return { value: '2', disabled: true, options: [{ value: '2' }], addEventListener: function () {} }; }
        if (sel === '[data-table-page-prev]') { return { disabled: true, setAttribute: function () {}, removeAttribute: function () {}, addEventListener: function () {} }; }
        if (sel === '[data-table-page-next]') { return { disabled: true, setAttribute: function () {}, removeAttribute: function () {}, addEventListener: function () {} }; }
        if (sel === '[data-table-page-label]') { return { textContent: '' }; }
        if (sel === '[data-table-pagination]') { return { textContent: '', setAttribute: function () {}, removeAttribute: function () {} }; }
        if (sel === '[data-table-plugin-slot]') { return { appendChild: function () {}, querySelectorAll: function () { return []; } }; }
        return null;
    },
    querySelectorAll: function (sel) {
        if (sel === '[data-table-mode], [data-table-view]') { return []; }
        if (sel === 'th[data-sort-key]') { return [{ dataset: { sortKey: 'name' }, addEventListener: function () {} }, { dataset: { sortKey: 'score' }, addEventListener: function () {} }]; }
        return [];
    },
    addEventListener: function () {}
};
const documentMock = {
    _onReady: null,
    addEventListener: function (eventName, cb) { if (eventName === 'DOMContentLoaded') { this._onReady = cb; } },
    querySelectorAll: function (sel) { return sel === '[data-table-engine="true"]' ? [container] : []; },
    createElement: function () { return { className: '', textContent: '', value: '', dataset: {}, children: [], appendChild: function (c) { this.children.push(c); }, setAttribute: function () {}, addEventListener: function () {} }; }
};
global.window = {
    addEventListener: function () {},
    ChorTablePrefs: {
        read: function (tableId) { const raw = storage['chor.table.' + tableId]; return raw ? JSON.parse(raw) : {}; },
        write: function () {},
        clear: function () {}
    }
};
global.document = documentMock;
eval(engineCode);
documentMock._onReady();

if (rows[0].hidden !== false) { throw new Error('rows[0] (charlie, score=10) should be visible as first in score-asc, hidden=' + rows[0].hidden); }
if (rows[2].hidden !== false) { throw new Error('rows[2] (bob, score=20) should be visible as second in score-asc, hidden=' + rows[2].hidden); }
if (rows[1].hidden !== true) { throw new Error('rows[1] (alice, score=30) should be hidden on page 1 of score-asc, hidden=' + rows[1].hidden); }
console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);
        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testShiftClickAddsSecondarySortForVisibleWindow(): void
    {
        $prefsPath = dirname(__DIR__) . '/../public/js/table-preferences.js';
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-multi-sort-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');
const prefsCode = fs.readFileSync(process.argv[2], 'utf8');
const engineCode = fs.readFileSync(process.argv[3], 'utf8');

const storage = {};
const rows = [
    { hidden: false, dataset: { sortGroup: 'a', sortName: 'zeta' }, textContent: 'A Zeta', querySelectorAll: () => [] },
    { hidden: false, dataset: { sortGroup: 'a', sortName: 'beta' }, textContent: 'A Beta', querySelectorAll: () => [] },
    { hidden: false, dataset: { sortGroup: 'a', sortName: 'alpha' }, textContent: 'A Alpha', querySelectorAll: () => [] },
    { hidden: false, dataset: { sortGroup: 'b', sortName: 'omega' }, textContent: 'B Omega', querySelectorAll: () => [] }
];

function makeHeader(sortKey, cellIndex) {
    return {
        dataset: { sortKey: sortKey },
        cellIndex: cellIndex,
        _onClick: null,
        addEventListener: function (_eventName, cb) {
            this._onClick = cb;
        },
        click: function (shiftKey) {
            if (this._onClick) {
                this._onClick({ shiftKey: !!shiftKey });
            }
        }
    };
}

const groupHeader = makeHeader('group', 0);
const nameHeader = makeHeader('name', 1);
const table = {
    id: 'multiSortTable',
    scrollWidth: 700,
    clientWidth: 700,
    parentElement: { clientWidth: 900 },
    closest: function () { return { clientWidth: 900 }; },
    querySelectorAll: function (selector) {
        if (selector === 'tbody tr') {
            return rows;
        }
        return [];
    }
};

const pageSize = {
    value: '2',
    disabled: true,
    options: [{ value: '2' }],
    setAttribute: function () {},
    addEventListener: function (_eventName, cb) { this._onChange = cb; }
};

const container = {
    dataset: { tableId: 'test.multi-sort', tableEngine: 'true', defaultPageSize: '2' },
    querySelector: function (selector) {
        if (selector === 'table') return table;
        if (selector === '[data-table-search]') return { value: '', disabled: true, addEventListener: function () {} };
        if (selector === '[data-table-reset]') return { disabled: true, addEventListener: function () {} };
        if (selector === '[data-table-page-size]') return pageSize;
        if (selector === '[data-table-page-prev]') return { disabled: true, setAttribute: function () {}, addEventListener: function () {} };
        if (selector === '[data-table-page-next]') return { disabled: true, setAttribute: function () {}, addEventListener: function () {} };
        if (selector === '[data-table-page-label]') return { textContent: '' };
        if (selector === '[data-table-plugin-slot]') return { appendChild: function () {}, querySelectorAll: function () { return []; } };
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-mode], [data-table-view]') return [];
        if (selector === 'th[data-sort-key]') return [groupHeader, nameHeader];
        return [];
    }
};

const documentMock = {
    _onReady: null,
    addEventListener: function (eventName, cb) {
        if (eventName === 'DOMContentLoaded') {
            this._onReady = cb;
        }
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-engine="true"]') {
            return [container];
        }
        return [];
    },
    createElement: function () {
        return {
            className: '',
            textContent: '',
            innerHTML: '',
            dataset: {},
            appendChild: function () {},
            setAttribute: function () {},
            addEventListener: function () {}
        };
    }
};

global.window = {
    addEventListener: function () {},
    localStorage: {
        getItem: function (key) { return Object.prototype.hasOwnProperty.call(storage, key) ? storage[key] : null; },
        setItem: function (key, value) { storage[key] = value; },
        removeItem: function (key) { delete storage[key]; }
    }
};
global.document = documentMock;

eval(prefsCode);
eval(engineCode);
documentMock._onReady();

groupHeader.click(false);
if (rows[0].hidden !== false || rows[1].hidden !== false || rows[2].hidden !== true) {
    throw new Error('Primary group sort should keep original order within the same group');
}

nameHeader.click(true);
if (rows[2].hidden !== false || rows[1].hidden !== false || rows[0].hidden !== true) {
    throw new Error('Shift+click secondary sort should bring alpha and beta into the first page window');
}

console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);
        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($prefsPath) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testSortStatePersistsAsSortColumnsArray(): void
    {
        $prefsPath = dirname(__DIR__) . '/../public/js/table-preferences.js';
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-sort-columns-persist-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');
const prefsCode = fs.readFileSync(process.argv[2], 'utf8');
const engineCode = fs.readFileSync(process.argv[3], 'utf8');

const storage = {};
const rows = [
    { hidden: false, dataset: { sortName: 'alice' }, textContent: 'Alice', querySelectorAll: () => [] },
    { hidden: false, dataset: { sortName: 'bob' }, textContent: 'Bob', querySelectorAll: () => [] }
];

const header = {
    dataset: { sortKey: 'name' },
    cellIndex: 0,
    _onClick: null,
    addEventListener: function (_eventName, cb) { this._onClick = cb; },
    click: function () { if (this._onClick) { this._onClick({ shiftKey: false }); } }
};

const table = {
    id: 'persistSortTable',
    scrollWidth: 700,
    clientWidth: 700,
    parentElement: { clientWidth: 900 },
    closest: function () { return { clientWidth: 900 }; },
    querySelectorAll: function (selector) {
        if (selector === 'tbody tr') {
            return rows;
        }
        return [];
    }
};

const container = {
    dataset: { tableId: 'test.persist-sort-columns', tableEngine: 'true', defaultPageSize: '25' },
    querySelector: function (selector) {
        if (selector === 'table') return table;
        if (selector === '[data-table-search]') return { value: '', disabled: true, addEventListener: function () {} };
        if (selector === '[data-table-reset]') return { disabled: true, addEventListener: function () {} };
        if (selector === '[data-table-page-size]') return { value: '25', disabled: true, options: [{ value: '25' }], setAttribute: function () {}, addEventListener: function () {} };
        if (selector === '[data-table-page-prev]') return { disabled: true, setAttribute: function () {}, addEventListener: function () {} };
        if (selector === '[data-table-page-next]') return { disabled: true, setAttribute: function () {}, addEventListener: function () {} };
        if (selector === '[data-table-page-label]') return { textContent: '' };
        if (selector === '[data-table-plugin-slot]') return { appendChild: function () {}, querySelectorAll: function () { return []; } };
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-mode], [data-table-view]') return [];
        if (selector === 'th[data-sort-key]') return [header];
        return [];
    }
};

const documentMock = {
    _onReady: null,
    addEventListener: function (eventName, cb) { if (eventName === 'DOMContentLoaded') { this._onReady = cb; } },
    querySelectorAll: function (selector) { return selector === '[data-table-engine="true"]' ? [container] : []; },
    createElement: function () {
        return {
            className: '',
            textContent: '',
            innerHTML: '',
            dataset: {},
            appendChild: function () {},
            setAttribute: function () {},
            addEventListener: function () {}
        };
    }
};

global.window = {
    addEventListener: function () {},
    localStorage: {
        getItem: function (key) { return Object.prototype.hasOwnProperty.call(storage, key) ? storage[key] : null; },
        setItem: function (key, value) { storage[key] = value; },
        removeItem: function (key) { delete storage[key]; }
    }
};
global.document = documentMock;

eval(prefsCode);
eval(engineCode);
documentMock._onReady();
header.click();

const persisted = JSON.parse(storage['chor.table.test.persist-sort-columns']);
if (!persisted.state || !Array.isArray(persisted.state.sortColumns)) {
    throw new Error('Expected persisted state to store sortColumns array');
}
if (Object.prototype.hasOwnProperty.call(persisted.state, 'sortKey')) {
    throw new Error('Persisted state should not keep legacy sortKey');
}
if (persisted.state.sortColumns.length !== 1 || persisted.state.sortColumns[0].key !== 'name') {
    throw new Error('Expected name sort to be persisted as first sort column');
}

console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);
        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($prefsPath) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testPersistedSortColumnsOverrideDefaultsOnFirstLoad(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-persisted-sort-columns-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');
const enginePath = process.argv[2];
const engineCode = fs.readFileSync(enginePath, 'utf8');

const rows = [
    { hidden: false, dataset: { sortName: 'charlie', sortScore: '10' }, textContent: 'Charlie 10', querySelectorAll: function () { return []; } },
    { hidden: false, dataset: { sortName: 'alice', sortScore: '30' }, textContent: 'Alice 30', querySelectorAll: function () { return []; } },
    { hidden: false, dataset: { sortName: 'bob', sortScore: '20' }, textContent: 'Bob 20', querySelectorAll: function () { return []; } }
];
const storage = {
    'chor.table.test.persisted-sort-columns': JSON.stringify({
        state: {
            sortColumns: [{ key: 'score', dir: 'asc' }]
        }
    })
};
const table = {
    id: '', scrollWidth: 0, clientWidth: 0,
    closest: function () { return null; },
    querySelectorAll: function (sel) { return sel === 'tbody tr' ? rows : []; }
};
const container = {
    dataset: { tableEngine: 'true', tableId: 'test.persisted-sort-columns', defaultView: 'table', defaultPageSize: '2', defaultSortKey: 'name', defaultSortDir: 'asc' },
    querySelector: function (sel) {
        if (sel === 'table') { return table; }
        if (sel === '[data-table-search]') { return { value: '', disabled: true, addEventListener: function () {} }; }
        if (sel === '[data-table-reset]') { return { disabled: true, addEventListener: function () {} }; }
        if (sel === '[data-table-page-size]') { return { value: '2', disabled: true, options: [{ value: '2' }], addEventListener: function () {}, setAttribute: function () {} }; }
        if (sel === '[data-table-page-prev]') { return { disabled: true, setAttribute: function () {}, removeAttribute: function () {}, addEventListener: function () {} }; }
        if (sel === '[data-table-page-next]') { return { disabled: true, setAttribute: function () {}, removeAttribute: function () {}, addEventListener: function () {} }; }
        if (sel === '[data-table-page-label]') { return { textContent: '' }; }
        if (sel === '[data-table-plugin-slot]') { return { appendChild: function () {}, querySelectorAll: function () { return []; } }; }
        return null;
    },
    querySelectorAll: function (sel) {
        if (sel === '[data-table-mode], [data-table-view]') { return []; }
        if (sel === 'th[data-sort-key]') {
            return [
                { dataset: { sortKey: 'name' }, cellIndex: 0, addEventListener: function () {} },
                { dataset: { sortKey: 'score' }, cellIndex: 1, addEventListener: function () {} }
            ];
        }
        return [];
    }
};
const documentMock = {
    _onReady: null,
    addEventListener: function (eventName, cb) { if (eventName === 'DOMContentLoaded') { this._onReady = cb; } },
    querySelectorAll: function (sel) { return sel === '[data-table-engine="true"]' ? [container] : []; },
    createElement: function () {
        return {
            className: '', textContent: '', innerHTML: '', value: '', dataset: {}, children: [],
            appendChild: function (c) { this.children.push(c); }, setAttribute: function () {}, addEventListener: function () {}
        };
    }
};
global.window = {
    addEventListener: function () {},
    ChorTablePrefs: {
        read: function (tableId) { const raw = storage['chor.table.' + tableId]; return raw ? JSON.parse(raw) : {}; },
        write: function () {},
        clear: function () {}
    }
};
global.document = documentMock;
eval(engineCode);
documentMock._onReady();

if (rows[0].hidden !== false) { throw new Error('rows[0] (charlie, score=10) should be visible as first in score-asc, hidden=' + rows[0].hidden); }
if (rows[2].hidden !== false) { throw new Error('rows[2] (bob, score=20) should be visible as second in score-asc, hidden=' + rows[2].hidden); }
if (rows[1].hidden !== true) { throw new Error('rows[1] (alice, score=30) should be hidden on page 1 of score-asc, hidden=' + rows[1].hidden); }
console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);
        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }

    public function testSortingReordersVisibleDomRowsWhenAllRowsFitOnPage(): void
    {
        $enginePath = dirname(__DIR__) . '/../public/js/table-engine.js';
        $tempScript = tempnam(sys_get_temp_dir(), 'table-engine-dom-order-test-');
        $this->assertNotFalse($tempScript);

        $nodeScript = <<<'JS'
const fs = require('fs');
const enginePath = process.argv[2];
const engineCode = fs.readFileSync(enginePath, 'utf8');

const rows = [
    { hidden: false, dataset: { sortName: 'charlie' }, textContent: 'Charlie', querySelectorAll: function () { return []; } },
    { hidden: false, dataset: { sortName: 'alice' }, textContent: 'Alice', querySelectorAll: function () { return []; } },
    { hidden: false, dataset: { sortName: 'bob' }, textContent: 'Bob', querySelectorAll: function () { return []; } }
];

const tbody = {
    orderedRows: rows.slice(),
    appendChild: function (row) {
        this.orderedRows = this.orderedRows.filter(function (entry) {
            return entry !== row;
        });
        this.orderedRows.push(row);
    }
};

const header = {
    dataset: { sortKey: 'name' },
    cellIndex: 0,
    _onClick: null,
    addEventListener: function (_eventName, cb) { this._onClick = cb; },
    click: function () { if (this._onClick) { this._onClick({ shiftKey: false }); } }
};

const table = {
    id: 'domOrderTable',
    scrollWidth: 700,
    clientWidth: 700,
    parentElement: { clientWidth: 900 },
    closest: function () { return { clientWidth: 900 }; },
    querySelector: function (selector) {
        if (selector === 'tbody') {
            return tbody;
        }
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === 'tbody tr') {
            return rows;
        }
        return [];
    }
};

const container = {
    dataset: { tableEngine: 'true', tableId: 'test.dom-order', defaultView: 'table', defaultPageSize: '25' },
    querySelector: function (selector) {
        if (selector === 'table') { return table; }
        if (selector === '[data-table-search]') { return { value: '', disabled: true, addEventListener: function () {} }; }
        if (selector === '[data-table-reset]') { return { disabled: true, addEventListener: function () {} }; }
        if (selector === '[data-table-page-size]') { return { value: '25', disabled: true, options: [{ value: '25' }], addEventListener: function () {}, setAttribute: function () {} }; }
        if (selector === '[data-table-page-prev]') { return { disabled: true, setAttribute: function () {}, addEventListener: function () {} }; }
        if (selector === '[data-table-page-next]') { return { disabled: true, setAttribute: function () {}, addEventListener: function () {} }; }
        if (selector === '[data-table-page-label]') { return { textContent: '' }; }
        if (selector === '[data-table-plugin-slot]') { return { appendChild: function () {}, querySelectorAll: function () { return []; } }; }
        return null;
    },
    querySelectorAll: function (selector) {
        if (selector === '[data-table-mode], [data-table-view]') { return []; }
        if (selector === 'th[data-sort-key]') { return [header]; }
        return [];
    }
};

const documentMock = {
    _onReady: null,
    addEventListener: function (eventName, cb) { if (eventName === 'DOMContentLoaded') { this._onReady = cb; } },
    querySelectorAll: function (selector) { return selector === '[data-table-engine="true"]' ? [container] : []; },
    createElement: function () {
        return {
            className: '', textContent: '', innerHTML: '', value: '', dataset: {}, children: [],
            appendChild: function (c) { this.children.push(c); }, setAttribute: function () {}, addEventListener: function () {}
        };
    }
};

global.window = { addEventListener: function () {}, ChorTablePrefs: { read: function () { return {}; }, write: function () {}, clear: function () {} } };
global.document = documentMock;
eval(engineCode);
documentMock._onReady();
header.click();

if (tbody.orderedRows[0].textContent !== 'Alice') {
    throw new Error('Expected DOM row order to start with Alice after ascending sort');
}
if (tbody.orderedRows[1].textContent !== 'Bob') {
    throw new Error('Expected DOM row order to continue with Bob after ascending sort');
}
if (tbody.orderedRows[2].textContent !== 'Charlie') {
    throw new Error('Expected DOM row order to end with Charlie after ascending sort');
}

console.log('ok');
JS;

        file_put_contents($tempScript, $nodeScript);
        $output = [];
        $exitCode = 1;
        exec('node ' . escapeshellarg($tempScript) . ' ' . escapeshellarg($enginePath) . ' 2>&1', $output, $exitCode);
        @unlink($tempScript);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }
}
