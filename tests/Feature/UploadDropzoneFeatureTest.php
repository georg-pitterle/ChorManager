<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Eine Ablegefläche für alle Anhang-Uploads an bestehenden Datensätzen.
 *
 * Vorher schickten Lied, Aufgabe und Sponsor alle Dateien in einer einzigen
 * Anfrage. Ab 20 Dateien verwarf PHP den Rest, und bei großen Mengen gab es bis
 * zum Ende keinerlei Rückmeldung. Die Fläche aus der Dateiablage schickt jede
 * Datei einzeln, zeigt Fortschritt und Fehler je Datei und kennt das Limit nicht.
 */
class UploadDropzoneFeatureTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testMacroRendersAFormThatWorksWithoutJavaScript(): void
    {
        $html = $this->renderMacro('{{ upload.dropzone("/song-library/songs/7/attachments", {id: "song-files"}) }}');

        $this->assertMatchesRegularExpression('#<form[^>]+action="/song-library/songs/7/attachments"#', $html);
        $this->assertMatchesRegularExpression('#<form[^>]+method="post"#', $html);
        $this->assertMatchesRegularExpression('#<form[^>]+enctype="multipart/form-data"#', $html);
        $this->assertStringContainsString('data-upload-dropzone', $html);
        $this->assertStringContainsString('data-upload-url="/song-library/songs/7/attachments"', $html);
        $this->assertMatchesRegularExpression(
            '#<input[^>]+type="file"[^>]+id="song-files"[^>]+name="attachments\[\]"[^>]+multiple#',
            $html
        );
        $this->assertStringContainsString('<label for="song-files"', $html);
        $this->assertMatchesRegularExpression('#<button[^>]+type="submit"[^>]+data-upload-fallback#', $html);
        $this->assertStringContainsString('data-upload-list', $html);
        $this->assertStringContainsString('data-upload-shrink-images="1"', $html);

        // common.js bindet die alte Formular-Komprimierung an dieses Attribut und
        // finge das Absenden ab, bevor die Fläche es tut.
        $this->assertStringNotContainsString('data-upload-compress', $html);
    }

    public function testMacroOptionsForTheFileStore(): void
    {
        $html = $this->renderMacro(
            '{{ upload.dropzone("/files/folders/3/upload", {field: "file", max_bytes: 1024, shrink_images: false,'
            . ' hint: "Höchstens 1 KB je Datei."}) }}'
        );

        $this->assertStringContainsString('name="file"', $html);
        $this->assertStringContainsString('data-upload-field="file"', $html);
        $this->assertStringContainsString('data-upload-max-bytes="1024"', $html);
        $this->assertStringNotContainsString('data-upload-shrink-images', $html);
        $this->assertStringContainsString('Höchstens 1 KB je Datei.', $html);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function uploadPages(): array
    {
        return [
            'Liedanhänge' => ['templates/songs/detail.twig', '"/song-library/songs/" ~ song.id ~ "/attachments"'],
            'Anhänge-Partial' => ['templates/partials/attachments.twig', 'upload_url'],
            'Sponsor' => ['templates/sponsoring/sponsors/detail.twig', '"/sponsoring/sponsors/" ~ sponsor.id ~ "/attachments"'],
            'Dateiablage' => ['templates/files/folder.twig', '"/files/folders/" ~ folder.id ~ "/upload"'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uploadPages')]
    public function testUploadPagesUseTheDropzone(string $template, string $url): void
    {
        $content = (string) file_get_contents(self::ROOT . '/' . $template);

        $this->assertStringContainsString('{% import "macros/upload.twig" as upload %}', $content);
        $this->assertStringContainsString('upload.dropzone(' . $url, $content);
    }

    public function testOldSingleRequestUploadFormsAreGone(): void
    {
        $song = (string) file_get_contents(self::ROOT . '/templates/songs/detail.twig');
        $partial = (string) file_get_contents(self::ROOT . '/templates/partials/attachments.twig');
        $sponsor = (string) file_get_contents(self::ROOT . '/templates/sponsoring/sponsors/detail.twig');

        $this->assertStringNotContainsString('id="attachments"', $song);
        $this->assertStringNotContainsString('id="attachments-form"', $partial);
        $this->assertStringNotContainsString('id="sponsor_attachments"', $sponsor);
    }

    public function testLayoutLoadsTheDropzoneAfterTheUploadHelper(): void
    {
        $layout = (string) file_get_contents(self::ROOT . '/templates/layout.twig');

        $helper = strpos($layout, "/js/upload-helper.js");
        $dropzone = strpos($layout, "/js/upload-dropzone.js");
        $this->assertNotFalse($helper);
        $this->assertNotFalse($dropzone);
        $this->assertGreaterThan($helper, $dropzone, 'Die Fläche nutzt Grenzen und Verkleinerung aus upload-helper.js.');
    }

    public function testFileManagerNoLongerCarriesItsOwnUploader(): void
    {
        $fileManager = (string) file_get_contents(self::ROOT . '/public/js/file-manager.js');

        $this->assertStringNotContainsString('XMLHttpRequest', $fileManager);
        $this->assertStringNotContainsString('data-files-dropzone', $fileManager);
    }

    public function testQueueRunsAtMostThreeUploadsAtOnceAndFinishesAll(): void
    {
        $output = $this->runNode(<<<'JS'
const queue = window.uploadDropzone.createQueue(3, item => new Promise(resolve => {
    running += 1;
    peak = Math.max(peak, running);
    setTimeout(() => { running -= 1; done.push(item); resolve(); }, 5);
}));
let running = 0;
let peak = 0;
const done = [];
for (let i = 1; i <= 24; i++) {
    queue.add(i);
}
setTimeout(() => {
    if (done.length !== 24) {
        throw new Error('Nicht alle fertig: ' + done.length);
    }
    if (peak !== 3) {
        throw new Error('Gleichzeitig liefen ' + peak + ' statt 3');
    }
    console.log('ok');
}, 200);
JS);

        $this->assertContains('ok', $output);
    }

    public function testPageReloadsOnlyWhenEveryFileArrived(): void
    {
        $output = $this->runNode(<<<'JS'
const summary = window.uploadDropzone.summarize;
const cases = [
    [{ succeeded: 24, failed: 0 }, 'reload'],
    [{ succeeded: 20, failed: 4 }, 'offer'],
    [{ succeeded: 0, failed: 3 }, 'stay'],
];
for (const [counts, expected] of cases) {
    const result = summary(counts).action;
    if (result !== expected) {
        throw new Error(JSON.stringify(counts) + ': erwartet ' + expected + ', erhalten ' + result);
    }
}
if (!summary({ succeeded: 20, failed: 4 }).text.includes('4 von 24')) {
    throw new Error('Zusammenfassung nennt die Zahl nicht: ' + summary({ succeeded: 20, failed: 4 }).text);
}
console.log('ok');
JS);

        $this->assertContains('ok', $output);
    }

    /**
     * Nach einer Remember-Me-Erneuerung antwortet der Server einmal mit 403 und
     * X-CSRF-Retry. Die Fläche übernimmt den neuen Token und wiederholt genau
     * einmal - csrf-token-sync.js erreicht XMLHttpRequest nicht.
     */
    public function testRenewedSessionIsRetriedOnceWithTheNewToken(): void
    {
        $output = $this->runNode(<<<'JS'
const env = setupZone([
    { status: 403, headers: { 'X-CSRF-Retry': '1', 'X-CSRF-Token': 'neu' }, body: { error: 'Die Sitzung wurde erneuert.' } },
    { status: 200, headers: { 'X-CSRF-Token': 'neu' }, body: { ok: true } },
]);
env.select([{ name: 'noten.pdf', type: 'application/pdf', size: 100 }]);
env.afterQueue(() => {
    const sent = env.requests.map(r => r.headers['X-CSRF-Token']);
    if (JSON.stringify(sent) !== JSON.stringify(['alt', 'neu'])) {
        throw new Error('Gesendete Tokens: ' + JSON.stringify(sent));
    }
    if (env.applied.join() !== 'neu,neu') {
        throw new Error('Token nicht übernommen: ' + env.applied.join());
    }
    if (env.statuses().join() !== 'Hochgeladen.') {
        throw new Error('Status: ' + env.statuses().join());
    }
    if (env.reloads() !== 1) {
        throw new Error('Kein Neuladen nach Erfolg');
    }
    console.log('ok');
});
JS);

        $this->assertContains('ok', $output);
    }

    public function testSecondRenewalIsNotRetriedAgain(): void
    {
        $output = $this->runNode(<<<'JS'
const renewed = { status: 403, headers: { 'X-CSRF-Retry': '1' }, body: { error: 'Die Sitzung wurde erneuert.' } };
const env = setupZone([renewed, renewed, renewed]);
env.select([{ name: 'noten.pdf', type: 'application/pdf', size: 100 }]);
env.afterQueue(() => {
    if (env.requests.length !== 2) {
        throw new Error('Anfragen: ' + env.requests.length);
    }
    if (env.statuses().join() !== 'Die Sitzung wurde erneuert.' || env.reloads() !== 0) {
        throw new Error('Status: ' + env.statuses().join() + ', Neuladen: ' + env.reloads());
    }
    console.log('ok');
});
JS);

        $this->assertContains('ok', $output);
    }

    public function testAFailingRequestStillFinishesTheRowAndTheBatch(): void
    {
        $output = $this->runNode(<<<'JS'
const env = setupZone([
    { throws: true },
    { status: 200, headers: {}, body: { ok: true } },
]);
env.select([
    { name: 'kaputt.pdf', type: 'application/pdf', size: 100 },
    { name: 'gut.pdf', type: 'application/pdf', size: 100 },
]);
env.afterQueue(() => {
    const statuses = env.statuses().sort().join('|');
    if (statuses !== 'Hochgeladen.|Hochladen fehlgeschlagen.') {
        throw new Error('Status: ' + statuses);
    }
    if (!env.summary().includes('1 von 2')) {
        throw new Error('Zusammenfassung: ' + env.summary());
    }
    if (env.reloads() !== 0) {
        throw new Error('Mit Fehler darf nicht neu geladen werden');
    }
    console.log('ok');
});
JS);

        $this->assertContains('ok', $output);
    }

    private function renderMacro(string $call): string
    {
        $twig = new Environment(new FilesystemLoader(self::ROOT . '/templates'));

        return $twig->createTemplate('{% import "macros/upload.twig" as upload %}' . $call)->render();
    }

    /**
     * @return list<string>
     */
    private function runNode(string $scenario): array
    {
        $prelude = <<<'JS'
const fs = require('fs');
global.window = {};
global.document = {
    readyState: 'complete',
    addEventListener: () => {},
    querySelector: () => null,
    querySelectorAll: () => [],
};
eval(fs.readFileSync(process.argv[2], 'utf8'));

// Schlanke DOM-Attrappen für eine Ablegefläche und ein XMLHttpRequest, das
// vorbereitete Antworten der Reihe nach ausliefert.
function element() {
    const classes = new Set();
    const node = {
        children: [],
        textContent: '',
        hidden: false,
        style: { setProperty: () => {} },
        classList: {
            add: c => classes.add(c),
            remove: c => classes.delete(c),
            toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)),
            contains: c => classes.has(c),
        },
        appendChild(child) { this.children.push(child); return child; },
        insertBefore(child) {
            this.children = [child].concat(this.children.filter(c => c !== child));
            return child;
        },
        get firstChild() { return this.children[0] || null; },
        addEventListener: () => {},
    };
    return node;
}

function setupZone(responses) {
    const requests = [];
    const applied = [];
    let reloads = 0;
    const timers = [];
    const meta = { content: 'alt' };
    const list = element();
    const summaryBox = element();
    const totalBox = element();
    const listeners = {};
    const input = Object.assign(element(), {
        files: [],
        value: '',
        addEventListener: (type, fn) => { listeners['input:' + type] = fn; },
    });
    const zone = Object.assign(element(), {
        dataset: {},
        getAttribute: name => ({ 'data-upload-url': '/song-library/songs/1/attachments' })[name] || null,
        hasAttribute: () => false,
        querySelector: sel => ({
            '[data-upload-input]': input,
            '[data-upload-list]': list,
            '[data-upload-summary]': summaryBox,
            '[data-upload-total]': totalBox,
        })[sel] || null,
        querySelectorAll: () => [],
        addEventListener: () => {},
    });

    global.document.querySelector = sel => (sel === 'meta[name="csrf-token"]' ? meta : null);
    global.document.createElement = () => element();
    global.window.ChorCsrf = { applyToken: (doc, token) => { applied.push(token); meta.content = token; } };
    global.window.location = { reload: () => { reloads += 1; } };
    global.window.setTimeout = fn => timers.push(fn);
    global.FormData = class { append() {} };
    global.XMLHttpRequest = class {
        constructor() { this.headers = {}; this.listeners = {}; this.upload = { addEventListener: () => {} }; }
        open() {}
        setRequestHeader(name, value) { this.headers[name] = value; }
        addEventListener(type, fn) { this.listeners[type] = fn; }
        getResponseHeader(name) { return (this.response.headers || {})[name] || null; }
        send() {
            this.response = responses.shift();
            requests.push(this);
            if (this.response.throws) {
                throw new Error('Netzwerk kaputt');
            }
            this.status = this.response.status;
            this.responseText = JSON.stringify(this.response.body);
            setImmediate(() => this.listeners.load());
        }
    };

    window.uploadDropzone.setup(zone);

    return {
        requests,
        applied,
        select(files) { input.files = files; listeners['input:change'](); },
        afterQueue(check) {
            setTimeout(() => { timers.splice(0).forEach(fn => fn()); check(); }, 50);
        },
        statuses: () => list.children.map(item => item.children[0].children[1].textContent),
        summary: () => summaryBox.textContent,
        reloads: () => reloads,
    };
}

JS;
        $script = tempnam(sys_get_temp_dir(), 'upload-dropzone-') . '.js';
        file_put_contents($script, $prelude . $scenario);

        $output = [];
        $exitCode = 1;
        $path = self::ROOT . '/public/js/upload-dropzone.js';
        exec('node ' . escapeshellarg($script) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        @unlink($script);

        $this->assertSame(0, $exitCode, implode("\n", $output));

        return $output;
    }
}
