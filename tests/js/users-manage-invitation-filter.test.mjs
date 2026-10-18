import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

/**
 * Lädt das Plugin gegen eine Attrappe der Tabellen-Engine und gibt die
 * registrierte Fabrik zurück. Das Plugin ist ein IIFE ohne Exporte; es meldet
 * sich ausschließlich über window.ChorTableEngine.registerFilterPlugin an.
 */
function loadPluginFactory() {
    const registered = {};
    globalThis.window = {
        ChorTableEngine: {
            registerFilterPlugin(name, factory) {
                registered[name] = factory;
            },
        },
    };
    globalThis.document = {};

    const path = require.resolve('../../public/js/table-plugins/users-manage-plugin.js');
    delete require.cache[path];
    require(path);

    return registered.usersManage;
}

function row(dataset) {
    return { dataset: Object.assign({ role: '|1|', voice: '|2|', project: '|3|' }, dataset) };
}

test('Einladungsfilter: "gesendet" trifft nur Mitglieder mit versandter Einladung', () => {
    const plugin = loadPluginFactory()({ onPluginStateChange() {} });
    plugin.setState({ invitation: 'sent' });
    const matches = plugin.getPredicate();

    assert.equal(matches(row({ invitation: '|sent|' })), true);
    assert.equal(matches(row({ invitation: '|not_sent|' })), false);
});

test('Einladungsfilter: "nicht gesendet" trifft nur Mitglieder ohne versandte Einladung', () => {
    const plugin = loadPluginFactory()({ onPluginStateChange() {} });
    plugin.setState({ invitation: 'not_sent' });
    const matches = plugin.getPredicate();

    assert.equal(matches(row({ invitation: '|not_sent|' })), true);
    assert.equal(matches(row({ invitation: '|sent|' })), false);
});

test('Einladungsfilter: "offen" trifft nur Zeilen mit offener Einladung', () => {
    const plugin = loadPluginFactory()({ onPluginStateChange() {} });
    plugin.setState({ invitation: 'pending' });
    const matches = plugin.getPredicate();

    assert.equal(matches(row({ invitation: '|sent|pending|' })), true);
    assert.equal(matches(row({ invitation: '|sent|' })), false);
});

test('Filteroptionen bleiben in der Reihenfolge des Templates (Sopran, Alt, Tenor, Bass)', () => {
    const slot = { appendChild() {}, closest() { return container; } };
    const container = {
        dataset: {
            voiceOptions: '1::Sopran||2::Alt||3::Tenor||4::Bass||',
            roleOptions: '',
            projectOptions: '',
            invitationFilter: '0',
        },
    };
    const created = [];
    globalThis.document = {
        createElement(tag) {
            const el = {
                tag, children: [], className: '', textContent: '', value: '',
                setAttribute() {}, addEventListener() {},
                appendChild(child) { this.children.push(child); return child; },
            };
            created.push(el);
            return el;
        },
    };
    const factory = (() => {
        const registered = {};
        globalThis.window = { ChorTableEngine: { registerFilterPlugin(name, f) { registered[name] = f; } } };
        const path = require.resolve('../../public/js/table-plugins/users-manage-plugin.js');
        delete require.cache[path];
        require(path);
        return registered.usersManage;
    })();

    factory({ pluginSlot: slot, onPluginStateChange() {} }).mount();

    const voiceSelect = created.find((el) => el.tag === 'select' && el.children.some((o) => o.textContent === 'Sopran'));
    assert.deepEqual(voiceSelect.children.map((o) => o.textContent), ['Alle', 'Sopran', 'Alt', 'Tenor', 'Bass']);
});

test('Archiv: ohne Projekt-Filter, weil Archivierte in keinem Projekt mehr sind', () => {
    const labels = [];
    const container = {
        dataset: {
            voiceOptions: '1::Sopran||',
            roleOptions: '1::Mitglied||',
            projectOptions: '7::HS 2026||',
            invitationFilter: '0',
            showArchived: '1',
        },
    };
    globalThis.document = {
        createElement(tag) {
            return {
                tag, children: [], className: '', value: '',
                set textContent(v) { if (tag === 'label') { labels.push(v); } this._t = v; },
                get textContent() { return this._t; },
                setAttribute() {}, addEventListener() {},
                appendChild(child) { this.children.push(child); return child; },
            };
        },
    };
    const registered = {};
    globalThis.window = { ChorTableEngine: { registerFilterPlugin(name, f) { registered[name] = f; } } };
    const path = require.resolve('../../public/js/table-plugins/users-manage-plugin.js');
    delete require.cache[path];
    require(path);

    registered.usersManage({ pluginSlot: { appendChild() {}, closest() { return container; } }, onPluginStateChange() {} }).mount();

    assert.deepEqual(labels, ['Rolle', 'Stimme']);
});

test('Einladungsfilter: ohne Auswahl bleiben alle Zeilen sichtbar, auch ohne Attribut', () => {
    const plugin = loadPluginFactory()({ onPluginStateChange() {} });
    const matches = plugin.getPredicate();

    assert.equal(matches(row({ invitation: '|sent|' })), true);
    assert.equal(matches(row({})), true);
});

test('Einladungsfilter: wirkt zusammen mit den anderen Filtern und wird beim Zurücksetzen geleert', () => {
    const plugin = loadPluginFactory()({ onPluginStateChange() {} });
    plugin.setState({ invitation: 'sent', role: '9' });

    assert.equal(plugin.getPredicate()(row({ invitation: '|sent|' })), false, 'Rolle 9 passt nicht');

    plugin.reset();
    assert.deepEqual(plugin.getState(), { role: '', voice: '', project: '', invitation: '' });
});
