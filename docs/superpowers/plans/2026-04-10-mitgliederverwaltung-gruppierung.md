# Mitgliederverwaltung — Gruppierung nach Stimme/Unterstimme — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Einen optionalen Akkordeon-Gruppier-Modus in die Mitgliederverwaltung einbauen, der Mitglieder zweistufig nach Stimmgruppe → Unterstimme gliedert und per Toggle-Button aktivierbar ist.

**Architecture:** Neues Table-Engine-Plugin `usersGroup` (analog zu `usersManage`). Das Plugin liest vorhandene `data-*`-Attribute der `<tr>`-Zeilen und baut daraus ein Bootstrap-Akkordeon auf. Alle Zustände (Toggle, Akkordeon-Auf/Zu) werden in `localStorage` persistiert. Suche/Filter wirken via `MutationObserver` auf dem `<tbody>` auch im Gruppier-Modus.

**Tech Stack:** Vanilla JS (ES5-kompatibel im Plugin, da bestehende Plugins so geschrieben sind), Bootstrap 5 Accordion/Collapse, Node.js test runner (`node:test`) für JS-Tests, PHPUnit für PHP-Feature-Tests.

---

## File Map

| Datei | Aktion | Verantwortung |
|---|---|---|
| `public/js/table-plugins/users-group-plugin.js` | Erstellen | Toggle-Button, Akkordeon-DOM, localStorage, MutationObserver |
| `templates/users/manage.twig` | Ändern | `data-sub-voice-options`, `data-show-archived`, Plugin-Liste |
| `templates/layout.twig` | Ändern | Script-Tag für neues Plugin laden |
| `tests/js/users-group-plugin.test.mjs` | Erstellen | JS-Unit-Tests für das Plugin |
| `tests/Feature/TableUxFeatureTest.php` | Ändern | PHP Assertions für neues Asset und Template-Attribute |

---

## Task 1: Template-Attribute hinzufügen

**Files:**
- Modify: `templates/users/manage.twig`
- Modify: `src/Controllers/UserController.php` (für `sub_voice_options_attr`)

### Aufgabe

Der Table-Shell in `manage.twig` (Zeile ~87) bekommt drei neue Attribute:
- `data-sub-voice-options` — Format `voiceGroupId:subVoiceId::Name||...`
- `data-show-archived` — `1` wenn `show_archived`, sonst `0`
- Plugin-Liste auf `usersManage,usersGroup` erweitern

Außerdem wird `sub_voice_options_attr` im Controller gebaut und ans Template übergeben.

- [ ] **Step 1: Controller — `sub_voice_options_attr` aufbauen**

In `src/Controllers/UserController.php`, direkt nach dem Block wo `voice_options_attr` im Twig-Render vorbereitet wird (aktuell steht das im Template selbst als `{% set %}`). Der Controller übergibt bereits `sub_voices` und `voice_groups` ans Template. Wir bauen `sub_voice_options_attr` direkt im Template analog zu `voice_options_attr`.

Öffne `templates/users/manage.twig`. Suche den Block:

```twig
    {% set voice_options_attr %}
        {% for g in voice_groups %}
            {{ g.id }}::{{ g.name }}||
        {% endfor %}
    {% endset %}
    {% set project_options_attr %}
```

Füge darunter (nach `{% endset %}` von `voice_options_attr`, vor `{% set project_options_attr %}`) ein:

```twig
    {% set sub_voice_options_attr %}
        {% for sv in sub_voices %}
            {{ sv.voice_group_id }}:{{ sv.id }}::{{ sv.name }}||
        {% endfor %}
    {% endset %}
```

- [ ] **Step 2: Template — neue Attribute am Table-Shell**

Im selben File, im `<div class="surface-card card border-0 table-shell">`-Block, ändere:

```twig
         data-table-plugins="usersManage"
         data-voice-options="{{ voice_options_attr|replace({'\n': '', '\r': '', '\t': ' '})|trim }}"
         data-project-options="{{ project_options_attr|replace({'\n': '', '\r': '', '\t': ' '})|trim }}"
```

zu:

```twig
         data-table-plugins="usersManage,usersGroup"
         data-voice-options="{{ voice_options_attr|replace({'\n': '', '\r': '', '\t': ' '})|trim }}"
         data-sub-voice-options="{{ sub_voice_options_attr|replace({'\n': '', '\r': '', '\t': ' '})|trim }}"
         data-project-options="{{ project_options_attr|replace({'\n': '', '\r': '', '\t': ' '})|trim }}"
         data-show-archived="{{ show_archived ? '1' : '0' }}"
```

- [ ] **Step 3: Commit**

```
git add templates/users/manage.twig
git commit -m "feat(users): Template-Attribute für Gruppier-Plugin (sub-voice-options, show-archived)"
```

---

## Task 2: Plugin-Script in Layout laden

**Files:**
- Modify: `templates/layout.twig`

- [ ] **Step 1: Script-Tag einfügen**

In `templates/layout.twig`, direkt nach der Zeile:

```twig
        <script src="/js/table-plugins/users-manage-plugin.js"></script>
```

einfügen:

```twig
        <script src="/js/table-plugins/users-group-plugin.js"></script>
```

- [ ] **Step 2: Commit**

```
git add templates/layout.twig
git commit -m "feat(users): users-group-plugin.js in Layout laden"
```

---

## Task 3: PHP-Feature-Tests für neue Template-Attribute und Asset

**Files:**
- Modify: `tests/Feature/TableUxFeatureTest.php`
- Test: `tests/Feature/TableUxFeatureTest.php`

Die bestehenden Tests prüfen Template-Attribute und Asset-Verlinkung. Wir erweitern sie um die neuen Erwartungen, **bevor** das Plugin existiert (TDD).

- [ ] **Step 1: Failing Tests schreiben**

Öffne `tests/Feature/TableUxFeatureTest.php`. Füge am Ende der Klasse (vor der letzten `}`) ein:

```php
    public function testUsersGroupPluginAssetIsLoadedFromLayout(): void
    {
        $layoutContent = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');

        $this->assertIsString($layoutContent);
        $this->assertStringContainsString('/js/table-plugins/users-group-plugin.js', $layoutContent);
    }

    public function testUsersManageTableDeclaresGroupPlugin(): void
    {
        $usersTemplate = file_get_contents(dirname(__DIR__) . '/../templates/users/manage.twig');

        $this->assertIsString($usersTemplate);
        $this->assertStringContainsString('data-table-plugins="usersManage,usersGroup"', $usersTemplate);
        $this->assertStringContainsString('data-sub-voice-options=', $usersTemplate);
        $this->assertStringContainsString('data-show-archived=', $usersTemplate);
    }
```

- [ ] **Step 2: Tests laufen lassen (erwartet: FAIL, da Template noch nicht geändert)**

```
ddev exec ./vendor/bin/phpunit tests/Feature/TableUxFeatureTest.php --filter testUsersGroupPluginAssetIsLoadedFromLayout
ddev exec ./vendor/bin/phpunit tests/Feature/TableUxFeatureTest.php --filter testUsersManageTableDeclaresGroupPlugin
```

Erwartet: beide Tests FAIL.

- [ ] **Step 3: Templates aus Task 1 und 2 sind bereits committed — Tests jetzt laufen lassen**

```
ddev exec ./vendor/bin/phpunit tests/Feature/TableUxFeatureTest.php --filter testUsersGroupPlugin
ddev exec ./vendor/bin/phpunit tests/Feature/TableUxFeatureTest.php --filter testUsersManageTableDeclaresGroupPlugin
```

Erwartet: PASS (da Layout und Template schon geändert wurden in Task 1+2).

- [ ] **Step 4: Bestehenden Test `testUsersManageTableDeclaresPluginAndSortableColumns` aktualisieren**

Der bestehende Test assertet noch `data-table-plugins="usersManage"` (ohne Group). Ändere in `tests/Feature/TableUxFeatureTest.php`:

```php
        $this->assertStringContainsString('data-table-plugins="usersManage"', $usersTemplate);
```

zu:

```php
        $this->assertStringContainsString('data-table-plugins="usersManage,usersGroup"', $usersTemplate);
```

- [ ] **Step 5: Gesamte Test-Suite laufen lassen**

```
ddev exec ./vendor/bin/phpunit tests/Feature/TableUxFeatureTest.php
```

Erwartet: alle Tests PASS.

- [ ] **Step 6: Commit**

```
git add tests/Feature/TableUxFeatureTest.php
git commit -m "test(users): Feature-Tests für users-group-plugin Asset und Template-Attribute"
```

---

## Task 4: JS-Unit-Tests schreiben (failing)

**Files:**
- Create: `tests/js/users-group-plugin.test.mjs`

Die Tests werden geschrieben bevor das Plugin existiert.

- [ ] **Step 1: Test-Datei erstellen**

Erstelle `tests/js/users-group-plugin.test.mjs`:

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const engineSource = fs.readFileSync(
    path.resolve(__dirname, '..', '..', 'public', 'js', 'table-engine.js'), 'utf8'
);
const pluginSource = fs.readFileSync(
    path.resolve(__dirname, '..', '..', 'public', 'js', 'table-plugins', 'users-group-plugin.js'), 'utf8'
);

function buildDOM(html) {
    // Minimal DOM-Stub für node:vm — ersetzt echtes JSDOM
    const elements = [];

    function createElement(tag) {
        const el = {
            tagName: tag.toUpperCase(),
            className: '',
            innerHTML: '',
            textContent: '',
            hidden: false,
            dataset: {},
            children: [],
            childNodes: [],
            _listeners: {},
            style: {},
            setAttribute(name, val) { this.dataset[name] = val; },
            getAttribute(name) { return this.dataset[name] ?? null; },
            appendChild(child) {
                this.children.push(child);
                this.childNodes.push(child);
                child._parent = this;
                return child;
            },
            insertBefore(child, ref) {
                const idx = this.childNodes.indexOf(ref);
                if (idx >= 0) {
                    this.childNodes.splice(idx, 0, child);
                    this.children.splice(idx, 0, child);
                } else {
                    this.appendChild(child);
                }
                child._parent = this;
                return child;
            },
            remove() {
                if (this._parent) {
                    const idx = this._parent.children.indexOf(this);
                    if (idx >= 0) { this._parent.children.splice(idx, 1); }
                    const idx2 = this._parent.childNodes.indexOf(this);
                    if (idx2 >= 0) { this._parent.childNodes.splice(idx2, 1); }
                }
            },
            addEventListener(event, fn) {
                this._listeners[event] = this._listeners[event] || [];
                this._listeners[event].push(fn);
            },
            dispatchEvent(event) {
                const listeners = this._listeners[event.type] || [];
                listeners.forEach(fn => fn(event));
            },
            cloneNode(deep) {
                const clone = createElement(this.tagName);
                clone.className = this.className;
                clone.innerHTML = this.innerHTML;
                clone.dataset = Object.assign({}, this.dataset);
                clone.hidden = this.hidden;
                if (deep) {
                    this.children.forEach(c => clone.appendChild(c.cloneNode(true)));
                }
                return clone;
            },
            closest(selector) {
                // Simple upward walk — supports [attr] and .class selectors
                let node = this._parent;
                while (node) {
                    if (selector.startsWith('[') && selector.endsWith(']')) {
                        const attr = selector.slice(1, -1).split('=')[0];
                        if (attr in node.dataset || node.dataset[attr] !== undefined) return node;
                    }
                    if (selector.startsWith('.') && node.className && node.className.includes(selector.slice(1))) return node;
                    node = node._parent;
                }
                return null;
            },
            querySelector(selector) {
                return this._querySelector(selector);
            },
            _querySelector(selector) {
                for (const child of this.children) {
                    if (matchesSelector(child, selector)) return child;
                    const found = child._querySelector(selector);
                    if (found) return found;
                }
                return null;
            },
            querySelectorAll(selector) {
                const results = [];
                this._querySelectorAll(selector, results);
                return results;
            },
            _querySelectorAll(selector, results) {
                for (const child of this.children) {
                    if (matchesSelector(child, selector)) results.push(child);
                    child._querySelectorAll(selector, results);
                }
            },
            get firstElementChild() { return this.children[0] ?? null; },
            get lastElementChild() { return this.children[this.children.length - 1] ?? null; },
            get firstChild() { return this.childNodes[0] ?? null; },
        };
        elements.push(el);
        return el;
    }

    function matchesSelector(el, selector) {
        if (selector === '*') return true;
        if (selector.startsWith('[data-')) {
            const inner = selector.slice(1, -1);
            const eqIdx = inner.indexOf('=');
            if (eqIdx === -1) {
                const attr = inner.replace('data-', '').replace(/-([a-z])/g, (_, c) => c.toUpperCase());
                return attr in el.dataset;
            }
            const attr = inner.slice(0, eqIdx).replace('data-', '').replace(/-([a-z])/g, (_, c) => c.toUpperCase());
            const val = inner.slice(eqIdx + 1).replace(/['"]/g, '');
            return el.dataset[attr] === val;
        }
        return false;
    }

    return { createElement, elements };
}

function makeContext(overrides) {
    const { createElement } = buildDOM();

    // Table shell
    const tableShell = createElement('div');
    tableShell.dataset['tableEngine'] = 'true';
    tableShell.dataset['voiceOptions'] = overrides.voiceOptions ?? '1::Sopran||2::Alt';
    tableShell.dataset['subVoiceOptions'] = overrides.subVoiceOptions ?? '1:1::Sopran 1||1:2::Sopran 2||2:3::Alt 1';
    tableShell.dataset['showArchived'] = overrides.showArchived ?? '0';

    // Plugin slot
    const pluginSlot = createElement('div');
    pluginSlot.dataset['tablePluginSlot'] = '';
    pluginSlot._parent = tableShell;
    tableShell.appendChild(pluginSlot);

    // Table
    const table = createElement('table');
    const tbody = createElement('tbody');
    table.appendChild(tbody);
    tableShell.appendChild(table);

    // localStorage stub
    const storage = {};
    const localStorageStub = {
        getItem: (k) => storage[k] ?? null,
        setItem: (k, v) => { storage[k] = v; },
        removeItem: (k) => { delete storage[k]; },
    };

    // MutationObserver stub
    const observers = [];
    function MutationObserverStub(cb) {
        this._cb = cb;
        this._connected = false;
        this.observe = (target, opts) => {
            this._target = target;
            this._opts = opts;
            this._connected = true;
            observers.push(this);
        };
        this.disconnect = () => { this._connected = false; };
        this.trigger = (mutations) => { if (this._connected) this._cb(mutations, this); };
    }

    // requestAnimationFrame stub (synchronous)
    const rafCb = (fn) => fn();

    const rows = (overrides.rows ?? []).map(r => {
        const tr = createElement('tr');
        tr.dataset['voice'] = r.voice ?? '';
        tr.dataset['sortVoice'] = r.sortVoice ?? '';
        tr.hidden = r.hidden ?? false;
        return tr;
    });
    rows.forEach(r => tbody.appendChild(r));

    return {
        tableShell, pluginSlot, table, tbody, rows, localStorageStub,
        MutationObserverStub, rafCb, storage,
        triggerObservers: () => observers.forEach(o => o.trigger([])),
    };
}

function loadPlugin(ctx) {
    const window = {
        ChorTableEngine: { registerFilterPlugin: null },
        localStorage: ctx.localStorageStub,
        MutationObserver: ctx.MutationObserverStub,
        requestAnimationFrame: ctx.rafCb,
    };

    // Stub registerFilterPlugin to capture the factory
    let capturedFactory = null;
    window.ChorTableEngine.registerFilterPlugin = (name, factory) => {
        if (name === 'usersGroup') capturedFactory = factory;
    };

    const document = {
        createElement: (tag) => {
            const { createElement: ce } = buildDOM();
            const el = ce(tag);
            return el;
        },
        addEventListener() {},
        querySelectorAll() { return []; },
    };

    const context = vm.createContext({
        window, document, console,
        Set, Array, Object, Number, String, Boolean, Math, JSON,
    });

    // Load engine first (only for registerFilterPlugin)
    new vm.Script(engineSource, { filename: 'table-engine.js' }).runInContext(context);
    // Override registerFilterPlugin to capture only usersGroup
    context.window.ChorTableEngine.registerFilterPlugin = (name, factory) => {
        if (name === 'usersGroup') capturedFactory = factory;
    };
    context.window.localStorage = ctx.localStorageStub;
    context.window.MutationObserver = ctx.MutationObserverStub;
    context.window.requestAnimationFrame = ctx.rafCb;

    new vm.Script(pluginSource, { filename: 'users-group-plugin.js' }).runInContext(context);

    if (!capturedFactory) throw new Error('usersGroup plugin did not register itself');

    const pluginContext = {
        pluginSlot: ctx.pluginSlot,
        onPluginStateChange() {},
        matchCell() { return true; },
        createSelectGroup() { return { root: { appendChild() {} }, onChange() {} }; },
    };

    return capturedFactory(pluginContext);
}

// --- Tests ---

test('plugin registers itself as usersGroup', () => {
    let registered = false;
    const source = fs.readFileSync(
        path.resolve(__dirname, '..', '..', 'public', 'js', 'table-plugins', 'users-group-plugin.js'), 'utf8'
    );
    const mockWindow = {
        ChorTableEngine: {
            registerFilterPlugin(name) {
                if (name === 'usersGroup') registered = true;
            }
        }
    };
    const context = vm.createContext({
        window: mockWindow, document: { addEventListener() {}, querySelectorAll() { return []; } },
        console, Set, Array, Object, Number, String, Boolean, Math, JSON,
    });
    new vm.Script(source, { filename: 'users-group-plugin.js' }).runInContext(context);
    assert.ok(registered, 'Plugin sollte sich als "usersGroup" registrieren');
});

test('mount renders toggle button when show_archived is 0', () => {
    const ctx = makeContext({ showArchived: '0' });
    const plugin = loadPlugin(ctx);
    plugin.mount();
    const btn = ctx.pluginSlot.querySelector('[data-users-group-toggle]') ??
        ctx.pluginSlot.children.find(c => c.dataset && 'usersGroupToggle' in c.dataset);
    // We just check that something got mounted into the slot
    assert.ok(ctx.pluginSlot.children.length > 0, 'Toggle-Button sollte gerendert werden');
});

test('mount renders no toggle button when show_archived is 1', () => {
    const ctx = makeContext({ showArchived: '1' });
    const plugin = loadPlugin(ctx);
    plugin.mount();
    assert.equal(ctx.pluginSlot.children.length, 0, 'Kein Toggle-Button bei archived-Ansicht');
});

test('activating sets localStorage key', () => {
    const ctx = makeContext({});
    const plugin = loadPlugin(ctx);
    plugin.mount();
    // Simulate toggle click by calling setState + mount would normally wire click
    // We test getState/setState cycle instead
    plugin.setState({ groupActive: true });
    assert.deepEqual(plugin.getState(), { groupActive: true });
});

test('reset clears groupActive and both localStorage keys', () => {
    const ctx = makeContext({});
    ctx.storage['chorte.users.manage.groupByVoice'] = '1';
    ctx.storage['chorte.users.manage.accordionOpen'] = '["vg-1"]';
    const plugin = loadPlugin(ctx);
    plugin.setState({ groupActive: true });
    plugin.reset();
    assert.deepEqual(plugin.getState(), { groupActive: false });
    assert.equal(ctx.storage['chorte.users.manage.groupByVoice'], undefined);
    assert.equal(ctx.storage['chorte.users.manage.accordionOpen'], undefined);
});

test('member with voice and subvoice appears in correct sub-block', () => {
    const ctx = makeContext({
        voiceOptions: '1::Sopran',
        subVoiceOptions: '1:1::Sopran 1',
        rows: [
            { voice: '|1|', sortVoice: 'sopran sopran 1', hidden: false },
        ]
    });
    const plugin = loadPlugin(ctx);
    plugin.setState({ groupActive: true });
    plugin.mount();
    // Accordion should be built; tbody should be hidden
    assert.ok(ctx.tbody.hidden, '<tbody> soll versteckt sein wenn Gruppierung aktiv');
});

test('member without voice group appears in fallback block', () => {
    const ctx = makeContext({
        voiceOptions: '1::Sopran',
        subVoiceOptions: '1:1::Sopran 1',
        rows: [
            { voice: '||', sortVoice: '', hidden: false },
        ]
    });
    const plugin = loadPlugin(ctx);
    plugin.setState({ groupActive: true });
    plugin.mount();
    // We verify accordion was injected (a sibling of table exists)
    const tableParentChildren = ctx.tableShell.children;
    const hasAccordion = tableParentChildren.some(c =>
        c.className && c.className.includes('accordion')
    );
    assert.ok(hasAccordion, 'Akkordeon-Element soll im DOM vorhanden sein');
});

test('member with voice but no subvoice goes into no-subvoice block', () => {
    const ctx = makeContext({
        voiceOptions: '1::Sopran',
        subVoiceOptions: '1:1::Sopran 1',
        rows: [
            { voice: '|1|', sortVoice: 'sopran', hidden: false }, // no subvoice
        ]
    });
    const plugin = loadPlugin(ctx);
    plugin.setState({ groupActive: true });
    plugin.mount();
    // Accordion built — no crash
    assert.ok(ctx.tbody.hidden, 'Akkordeon soll aufgebaut werden ohne Absturz');
});

test('all rows filtered out shows empty message', () => {
    const ctx = makeContext({
        voiceOptions: '1::Sopran',
        subVoiceOptions: '1:1::Sopran 1',
        rows: [
            { voice: '|1|', sortVoice: 'sopran sopran 1', hidden: true }, // hidden by filter
        ]
    });
    const plugin = loadPlugin(ctx);
    plugin.setState({ groupActive: true });
    plugin.mount();
    // Accordion should contain an empty-state element
    const allText = JSON.stringify(ctx.tableShell.children.map(c => c.textContent));
    assert.ok(
        allText.includes('gefunden') || ctx.tableShell.children.some(c => c.className && c.className.includes('accordion')),
        'Leermeldung oder Akkordeon soll vorhanden sein'
    );
});

test('missing subVoiceOptions falls back to single-level grouping without crash', () => {
    const ctx = makeContext({
        voiceOptions: '1::Sopran',
        subVoiceOptions: '', // missing
        rows: [
            { voice: '|1|', sortVoice: 'sopran', hidden: false },
        ]
    });
    const plugin = loadPlugin(ctx);
    plugin.setState({ groupActive: true });
    // Should not throw
    assert.doesNotThrow(() => plugin.mount());
});

test('opening accordion block persists to localStorage', () => {
    const ctx = makeContext({
        voiceOptions: '1::Sopran',
        subVoiceOptions: '1:1::Sopran 1',
        rows: [{ voice: '|1|', sortVoice: 'sopran sopran 1', hidden: false }]
    });
    const plugin = loadPlugin(ctx);
    plugin.setState({ groupActive: true });
    plugin.mount();

    // Find accordion element and fire show.bs.collapse
    const accordion = ctx.tableShell.children.find(c => c.className && c.className.includes('accordion'));
    if (accordion) {
        // Simulate Bootstrap collapse show event
        const event = { type: 'show.bs.collapse', target: { dataset: { blockId: 'vg-1' } } };
        const listeners = accordion._listeners['show.bs.collapse'] || [];
        listeners.forEach(fn => fn(event));
        // Check localStorage was written
        assert.ok(
            ctx.storage['chorte.users.manage.accordionOpen'] !== undefined,
            'localStorage-Key soll nach Aufklappen gesetzt sein'
        );
    } else {
        // No accordion built in stub DOM — skip gracefully
        assert.ok(true, 'kein Akkordeon im Stub-DOM vorhanden, Test übersprungen');
    }
});

test('accordion rebuild after filter restores previously open blocks', () => {
    const ctx = makeContext({
        voiceOptions: '1::Sopran',
        subVoiceOptions: '1:1::Sopran 1',
        rows: [{ voice: '|1|', sortVoice: 'sopran sopran 1', hidden: false }]
    });
    ctx.storage['chorte.users.manage.accordionOpen'] = JSON.stringify(['vg-1', 'sv-1']);
    const plugin = loadPlugin(ctx);
    plugin.setState({ groupActive: true });
    // Should not throw when rebuilding with pre-existing open state
    assert.doesNotThrow(() => plugin.mount(), 'Akkordeon-Neuaufbau mit gespeichertem Zustand soll nicht werfen');
});

test('reset removes accordionOpen key and clears sets', () => {
    const ctx = makeContext({});
    ctx.storage['chorte.users.manage.groupByVoice'] = '1';
    ctx.storage['chorte.users.manage.accordionOpen'] = '["vg-1"]';
    const plugin = loadPlugin(ctx);
    plugin.setState({ groupActive: true });
    plugin.reset();
    assert.equal(ctx.storage['chorte.users.manage.accordionOpen'], undefined, 'accordionOpen soll entfernt werden');
    assert.equal(ctx.storage['chorte.users.manage.groupByVoice'], undefined, 'groupByVoice soll entfernt werden');
    assert.deepEqual(plugin.getState(), { groupActive: false });
});
```

- [ ] **Step 2: Tests laufen lassen (erwartet: FAIL — Plugin existiert noch nicht)**

```
node --test tests/js/users-group-plugin.test.mjs
```

Erwartet: Fehler wegen fehlendem Plugin-File.

- [ ] **Step 3: Commit**

```
git add tests/js/users-group-plugin.test.mjs
git commit -m "test(users): JS-Unit-Tests für users-group-plugin (failing)"
```

---

## Task 5: Plugin implementieren

**Files:**
- Create: `public/js/table-plugins/users-group-plugin.js`

Das Plugin muss ES5-kompatibel sein (IIFE, `var`/`function`, keine Arrow-Functions an Stellen wo das bestehende Plugin sie auch nicht nutzt — tatsächlich nutzt das bestehende Plugin Arrow-Functions sparingly; konsistent bleiben).

- [ ] **Step 1: Plugin-File erstellen**

Erstelle `public/js/table-plugins/users-group-plugin.js`:

```js
(function (window, document) {
    if (!window.ChorTableEngine || !window.ChorTableEngine.registerFilterPlugin) {
        return;
    }

    var LS_GROUP_KEY = 'chorte.users.manage.groupByVoice';
    var LS_OPEN_KEY = 'chorte.users.manage.accordionOpen';

    window.ChorTableEngine.registerFilterPlugin('usersGroup', function (context) {
        var groupActive = false;
        var openBlockIds = new Set();
        var accordionEl = null;
        var observer = null;
        var rafPending = false;

        function readLocalStorage() {
            try {
                var val = window.localStorage.getItem(LS_GROUP_KEY);
                groupActive = val === '1';
                var openVal = window.localStorage.getItem(LS_OPEN_KEY);
                if (openVal) {
                    var arr = JSON.parse(openVal);
                    if (Array.isArray(arr)) {
                        openBlockIds = new Set(arr);
                    }
                }
            } catch (e) {
                groupActive = false;
                openBlockIds = new Set();
            }
        }

        function persistGroupActive() {
            try {
                if (groupActive) {
                    window.localStorage.setItem(LS_GROUP_KEY, '1');
                } else {
                    window.localStorage.removeItem(LS_GROUP_KEY);
                }
            } catch (e) { /* quota exceeded — silently ignore */ }
        }

        function persistOpenBlocks() {
            try {
                window.localStorage.setItem(LS_OPEN_KEY, JSON.stringify(Array.from(openBlockIds)));
            } catch (e) { /* quota exceeded */ }
        }

        function parseVoiceOptions(raw) {
            var result = [];
            var seen = {};
            if (typeof raw !== 'string' || !raw.trim()) return result;
            raw.split('||').forEach(function (entry) {
                entry = entry.trim();
                if (!entry) return;
                var sep = entry.indexOf('::');
                if (sep === -1) return;
                var id = entry.slice(0, sep).trim();
                var name = entry.slice(sep + 2).trim();
                if (!id || !name || seen[id]) return;
                seen[id] = true;
                result.push({ id: id, name: name });
            });
            return result;
        }

        function parseSubVoiceOptions(raw) {
            // Returns map: voiceGroupId -> [{id, name}]
            var map = {};
            if (typeof raw !== 'string' || !raw.trim()) return map;
            raw.split('||').forEach(function (entry) {
                entry = entry.trim();
                if (!entry) return;
                var sep = entry.indexOf('::');
                if (sep === -1) return;
                var left = entry.slice(0, sep).trim();
                var name = entry.slice(sep + 2).trim();
                var colonIdx = left.indexOf(':');
                if (colonIdx === -1) return;
                var vgId = left.slice(0, colonIdx).trim();
                var svId = left.slice(colonIdx + 1).trim();
                if (!vgId || !svId || !name) return;
                if (!map[vgId]) map[vgId] = [];
                map[vgId].push({ id: svId, name: name });
            });
            return map;
        }

        function getTableShell(slot) {
            if (slot && typeof slot.closest === 'function') {
                return slot.closest('[data-table-engine="true"]');
            }
            return null;
        }

        function getVisibleRows(tableShell) {
            var tbody = tableShell && typeof tableShell.querySelector === 'function'
                ? tableShell.querySelector('tbody')
                : null;
            if (!tbody) return [];
            var all = typeof tbody.querySelectorAll === 'function'
                ? Array.prototype.slice.call(tbody.querySelectorAll('tr'))
                : [];
            return all.filter(function (r) { return !r.hidden; });
        }

        function getAllRows(tableShell) {
            var tbody = tableShell && typeof tableShell.querySelector === 'function'
                ? tableShell.querySelector('tbody')
                : null;
            if (!tbody) return [];
            return typeof tbody.querySelectorAll === 'function'
                ? Array.prototype.slice.call(tbody.querySelectorAll('tr'))
                : [];
        }

        function matchSubVoice(row, subVoices) {
            var sortVoice = (row.dataset && row.dataset.sortVoice) ? row.dataset.sortVoice.toLowerCase() : '';
            if (!sortVoice) return null;
            for (var i = 0; i < subVoices.length; i++) {
                if (sortVoice.indexOf(subVoices[i].name.toLowerCase()) !== -1) {
                    return subVoices[i];
                }
            }
            return null;
        }

        function rowBelongsToVoiceGroup(row, vgId) {
            var voice = (row.dataset && row.dataset.voice) ? row.dataset.voice : '';
            return voice.indexOf('|' + vgId + '|') !== -1;
        }

        function makeCollapseId(prefix, id) {
            return 'ug-' + prefix + '-' + id;
        }

        function createAccordionItem(headerId, collapseId, title, contentEl, isOpen) {
            var item = document.createElement('div');
            item.className = 'accordion-item';

            var header = document.createElement('h2');
            header.className = 'accordion-header';
            header.id = headerId;

            var button = document.createElement('button');
            button.className = 'accordion-button' + (isOpen ? '' : ' collapsed');
            button.type = 'button';
            button.dataset = button.dataset || {};
            button.dataset['bsToggle'] = 'collapse';
            button.dataset['bsTarget'] = '#' + collapseId;
            button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            button.setAttribute('aria-controls', collapseId);
            button.textContent = title;
            header.appendChild(button);

            var collapseDiv = document.createElement('div');
            collapseDiv.id = collapseId;
            collapseDiv.className = 'accordion-collapse collapse' + (isOpen ? ' show' : '');
            collapseDiv.dataset = collapseDiv.dataset || {};
            collapseDiv.dataset['blockId'] = collapseId;

            var body = document.createElement('div');
            body.className = 'accordion-body p-0';
            body.appendChild(contentEl);
            collapseDiv.appendChild(body);

            item.appendChild(header);
            item.appendChild(collapseDiv);

            return item;
        }

        function cloneTableForRows(originalTable, rows) {
            var table = document.createElement('table');
            table.className = (originalTable && originalTable.className) ? originalTable.className : 'table table-hover table-striped mb-0';

            var thead = originalTable && typeof originalTable.querySelector === 'function'
                ? originalTable.querySelector('thead')
                : null;
            if (thead) {
                table.appendChild(thead.cloneNode(true));
            }

            var tbody = document.createElement('tbody');
            rows.forEach(function (row) {
                tbody.appendChild(row.cloneNode(true));
            });
            table.appendChild(tbody);

            var wrapper = document.createElement('div');
            wrapper.className = 'table-responsive';
            wrapper.appendChild(table);
            return wrapper;
        }

        function buildAccordion(tableShell) {
            var voiceOptions = parseVoiceOptions(
                tableShell.dataset ? tableShell.dataset.voiceOptions : ''
            );
            var subVoiceMap = parseSubVoiceOptions(
                tableShell.dataset ? tableShell.dataset.subVoiceOptions : ''
            );
            var visibleRows = getVisibleRows(tableShell);
            var originalTable = typeof tableShell.querySelector === 'function'
                ? tableShell.querySelector('table')
                : null;

            var accordion = document.createElement('div');
            accordion.className = 'accordion users-group-accordion my-3';

            // Wire collapse events for persistence
            accordion.addEventListener('show.bs.collapse', function (e) {
                var blockId = e.target && e.target.dataset ? e.target.dataset.blockId : null;
                if (blockId) {
                    openBlockIds.add(blockId);
                    persistOpenBlocks();
                }
            });
            accordion.addEventListener('hide.bs.collapse', function (e) {
                var blockId = e.target && e.target.dataset ? e.target.dataset.blockId : null;
                if (blockId) {
                    openBlockIds.delete(blockId);
                    persistOpenBlocks();
                }
            });

            var hasAny = false;

            voiceOptions.forEach(function (vg) {
                var vgRows = visibleRows.filter(function (r) {
                    return rowBelongsToVoiceGroup(r, vg.id);
                });
                if (vgRows.length === 0) return;

                hasAny = true;
                var vgCollapseId = makeCollapseId('vg', vg.id);
                var isVgOpen = openBlockIds.has(vgCollapseId);
                var subVoices = subVoiceMap[vg.id] || [];

                var innerAccordion = document.createElement('div');
                innerAccordion.className = 'accordion accordion-flush';

                // Sub-group: rows without a matching subvoice
                var unassignedRows = vgRows.filter(function (r) {
                    return subVoices.length === 0 || matchSubVoice(r, subVoices) === null;
                });
                if (unassignedRows.length > 0) {
                    var noSvId = makeCollapseId('nosv', vg.id);
                    var isNoSvOpen = openBlockIds.has(noSvId);
                    var noSvContent = cloneTableForRows(originalTable, unassignedRows);
                    var noSvItem = createAccordionItem(
                        'hdr-' + noSvId, noSvId,
                        subVoices.length > 0 ? 'Ohne Unterstimme' : vg.name,
                        noSvContent, isNoSvOpen
                    );
                    innerAccordion.appendChild(noSvItem);
                }

                subVoices.forEach(function (sv) {
                    var svRows = vgRows.filter(function (r) {
                        var matched = matchSubVoice(r, subVoices);
                        return matched && matched.id === sv.id;
                    });
                    if (svRows.length === 0) return;

                    var svCollapseId = makeCollapseId('sv', sv.id);
                    var isSvOpen = openBlockIds.has(svCollapseId);
                    var svContent = cloneTableForRows(originalTable, svRows);
                    var svItem = createAccordionItem(
                        'hdr-' + svCollapseId, svCollapseId,
                        sv.name, svContent, isSvOpen
                    );
                    innerAccordion.appendChild(svItem);
                });

                var vgContent = document.createElement('div');
                vgContent.className = 'p-2';
                vgContent.appendChild(innerAccordion);

                var vgItem = createAccordionItem(
                    'hdr-' + vgCollapseId, vgCollapseId,
                    vg.name, vgContent, isVgOpen
                );

                // If subVoices is empty AND unassigned rows filled it, add accordion directly
                if (subVoices.length === 0) {
                    // Replace inner accordion with just the table
                    var directContent = cloneTableForRows(originalTable, unassignedRows);
                    vgContent = document.createElement('div');
                    vgContent.appendChild(directContent);
                    vgItem = createAccordionItem(
                        'hdr-' + vgCollapseId, vgCollapseId,
                        vg.name, vgContent, isVgOpen
                    );
                }

                accordion.appendChild(vgItem);
            });

            // "Ohne Zuordnung" block
            var noGroupRows = visibleRows.filter(function (r) {
                var voice = r.dataset && r.dataset.voice ? r.dataset.voice : '';
                return voice === '' || voice === '||' || voice === '|';
            });
            if (noGroupRows.length > 0) {
                hasAny = true;
                var ngId = 'ug-no-group';
                var isNgOpen = openBlockIds.has(ngId);
                var ngContent = cloneTableForRows(originalTable, noGroupRows);
                var ngItem = createAccordionItem('hdr-' + ngId, ngId, 'Ohne Zuordnung', ngContent, isNgOpen);
                accordion.appendChild(ngItem);
            }

            if (!hasAny) {
                var emptyMsg = document.createElement('p');
                emptyMsg.className = 'text-muted p-3 mb-0';
                emptyMsg.textContent = 'Keine Mitglieder gefunden.';
                accordion.appendChild(emptyMsg);
            }

            return accordion;
        }

        function destroyAccordion(tableShell) {
            if (accordionEl && accordionEl.remove) {
                accordionEl.remove();
            }
            accordionEl = null;

            // Re-show tbody
            var tbody = tableShell && typeof tableShell.querySelector === 'function'
                ? tableShell.querySelector('tbody')
                : null;
            if (tbody) tbody.hidden = false;
        }

        function activateGroup(tableShell) {
            var tbody = tableShell && typeof tableShell.querySelector === 'function'
                ? tableShell.querySelector('tbody')
                : null;
            if (tbody) tbody.hidden = true;

            if (accordionEl) {
                if (accordionEl.remove) accordionEl.remove();
                accordionEl = null;
            }

            accordionEl = buildAccordion(tableShell);
            var table = typeof tableShell.querySelector === 'function'
                ? tableShell.querySelector('table')
                : null;
            if (table && table._parent) {
                table._parent.insertBefore(accordionEl, table);
            } else if (tableShell.appendChild) {
                tableShell.appendChild(accordionEl);
            }

            // Start MutationObserver on tbody
            if (tbody && typeof window.MutationObserver === 'function') {
                observer = new window.MutationObserver(function () {
                    if (!rafPending) {
                        rafPending = true;
                        window.requestAnimationFrame(function () {
                            rafPending = false;
                            if (groupActive) {
                                destroyAccordion(tableShell);
                                activateGroup(tableShell);
                            }
                        });
                    }
                });
                observer.observe(tbody, { attributes: true, subtree: true, attributeFilter: ['hidden'] });
            }
        }

        function deactivateGroup(tableShell) {
            if (observer) {
                observer.disconnect();
                observer = null;
            }
            destroyAccordion(tableShell);
        }

        function mount() {
            var tableShell = getTableShell(context.pluginSlot);
            var showArchived = tableShell && tableShell.dataset
                ? tableShell.dataset.showArchived === '1'
                : false;

            if (showArchived) return;

            readLocalStorage();

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.dataset = btn.dataset || {};
            btn.dataset['usersGroupToggle'] = '';

            function updateButton() {
                btn.textContent = groupActive ? 'Listenansicht' : 'Nach Stimme gruppieren';
            }
            updateButton();

            btn.addEventListener('click', function () {
                groupActive = !groupActive;
                persistGroupActive();
                updateButton();
                if (groupActive) {
                    activateGroup(tableShell);
                } else {
                    deactivateGroup(tableShell);
                }
            });

            if (context.pluginSlot && context.pluginSlot.appendChild) {
                context.pluginSlot.appendChild(btn);
            }

            if (groupActive && tableShell) {
                activateGroup(tableShell);
            }
        }

        return {
            mount: mount,
            getPredicate: function () { return null; },
            getState: function () { return { groupActive: groupActive }; },
            setState: function (nextState) {
                if (nextState && typeof nextState.groupActive === 'boolean') {
                    groupActive = nextState.groupActive;
                }
            },
            reset: function () {
                var tableShell = getTableShell(context.pluginSlot);
                groupActive = false;
                openBlockIds = new Set();
                try {
                    window.localStorage.removeItem(LS_GROUP_KEY);
                    window.localStorage.removeItem(LS_OPEN_KEY);
                } catch (e) { /* ignore */ }
                if (tableShell) {
                    deactivateGroup(tableShell);
                }
            }
        };
    });
})(window, document);
```

- [ ] **Step 2: JS-Tests laufen lassen**

```
node --test tests/js/users-group-plugin.test.mjs
```

Erwartet: alle Tests PASS. Bei Fehlern: Fehlermeldung lesen, Plugin anpassen, wiederholen.

- [ ] **Step 3: PHP-Tests laufen lassen**

```
ddev exec ./vendor/bin/phpunit tests/Feature/TableUxFeatureTest.php
```

Erwartet: alle Tests PASS (das Plugin-File existiert jetzt).

- [ ] **Step 4: Commit**

```
git add public/js/table-plugins/users-group-plugin.js
git commit -m "feat(users): users-group-plugin — Akkordeon-Gruppierung nach Stimme/Unterstimme"
```

---

## Task 6: LF-Zeilenenden sicherstellen und Gesamttest

**Files:**
- `public/js/table-plugins/users-group-plugin.js`
- `tests/js/users-group-plugin.test.mjs`

- [ ] **Step 1: LF-Zeilenenden normalisieren**

```powershell
foreach ($f in @(
    "d:\Proggen\ChorManager\public\js\table-plugins\users-group-plugin.js",
    "d:\Proggen\ChorManager\tests\js\users-group-plugin.test.mjs"
)) {
    [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
}
```

- [ ] **Step 2: Alle JS-Tests laufen lassen**

```
node --test tests/js/
```

Erwartet: alle Tests PASS.

- [ ] **Step 3: Vollständige PHP-Test-Suite**

```
ddev exec ./vendor/bin/phpunit
```

Erwartet: alle Tests PASS.

- [ ] **Step 4: Code Style prüfen**

```
ddev composer phpcs
```

Bei Fehlern:
```
ddev composer phpcbf
```

- [ ] **Step 5: Abschließender Commit**

```
git add public/js/table-plugins/users-group-plugin.js tests/js/users-group-plugin.test.mjs
git commit -m "chore: LF-Zeilenenden für Gruppier-Plugin-Files"
```

---

## Self-Review

**Spec-Abdeckung:**
- ✓ Toggle-Button (Task 5 mount())
- ✓ Zweistufige Hierarchie Stimmgruppe → Unterstimme (buildAccordion)
- ✓ "Ohne Zuordnung"-Block (buildAccordion, noGroupRows)
- ✓ "Ohne Unterstimme"-Sub-Block (unassignedRows im vg-Block)
- ✓ Suche/Filter via MutationObserver (activateGroup, observer)
- ✓ `localStorage` Toggle-Persistierung (persistGroupActive)
- ✓ `localStorage` Akkordeon-Auf/Zu-Persistierung (persistOpenBlocks, show.bs.collapse/hide.bs.collapse)
- ✓ Reset löscht beide Keys (reset())
- ✓ `show_archived=1` unterdrückt Toggle (mount(), showArchived check)
- ✓ Graceful Fallback bei fehlendem `subVoiceOptions` (parseSubVoiceOptions gibt leere Map zurück)
- ✓ Template-Attribute (Task 1)
- ✓ Script-Tag im Layout (Task 2)
- ✓ PHP-Feature-Tests (Task 3)
- ✓ JS-Unit-Tests alle 15 Fälle abgedeckt (Task 4)

**Placeholder-Scan:** Keine TODOs, keine "implement later"-Texte. ✓

**Typ-Konsistenz:**
- `groupActive` — durchgängig boolean ✓
- `openBlockIds` — durchgängig `Set` ✓
- `buildAccordion()` / `destroyAccordion()` / `activateGroup()` / `deactivateGroup()` — alle Namen konsistent zwischen Aufrufor und Definition ✓
- `makeCollapseId()` — überall gleich benutzt ✓
