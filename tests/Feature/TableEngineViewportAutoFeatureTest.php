<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class TableEngineViewportAutoFeatureTest extends TestCase
{
    public function testEngineUsesOverrideOnlyPreferenceModelWithLegacyFallback(): void
    {
        $engine = file_get_contents(dirname(__DIR__) . '/../public/js/table-engine.js');

        $this->assertIsString($engine);
        $this->assertStringContainsString('viewOverride', $engine);
        $this->assertStringContainsString('prefs.view', $engine);
        $this->assertStringContainsString('data-table-mode', $engine);
    }

    public function testAutoModeUpdatesOnResizeAndDoesNotPersistDuringInitialization(): void
    {
        $engine = file_get_contents(dirname(__DIR__) . '/../public/js/table-engine.js');

        $this->assertIsString($engine);
        $this->assertStringContainsString('table.scrollWidth', $engine);
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
}
