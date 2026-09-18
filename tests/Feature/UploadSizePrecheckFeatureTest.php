<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\UploadValidator;
use PHPUnit\Framework\TestCase;

/**
 * Zu große Anhänge fallen schon beim Auswählen auf, nicht erst nach dem Hochladen.
 * Die Grenzen kommen aus UploadValidator, damit Browser und Server nie auseinanderlaufen.
 */
class UploadSizePrecheckFeatureTest extends TestCase
{
    public function testClientLimitsMirrorServerLimits(): void
    {
        $limits = UploadValidator::clientLimits();

        $this->assertSame(UploadValidator::MAX_IMAGE_SIZE, $limits['image']);
        $this->assertSame(UploadValidator::MAX_AUDIO_SIZE, $limits['audio']);
        $this->assertSame(UploadValidator::MAX_NON_IMAGE_SIZE, $limits['default']);
        $this->assertSame(UploadValidator::getImageMimeTypes(), $limits['imageTypes']);
        $this->assertSame(UploadValidator::getAudioMimeTypes(), $limits['audioTypes']);
    }

    public function testLayoutPublishesLimitsFromTwigGlobal(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/templates/layout.twig');
        $dependencies = (string) file_get_contents($root . '/src/Dependencies.php');

        $this->assertStringContainsString('name="upload-limits"', $layout);
        $this->assertStringContainsString('upload_limits|json_encode', $layout);
        $this->assertStringContainsString(
            "addGlobal('upload_limits', UploadValidator::clientLimits())",
            $dependencies
        );
    }

    public function testSongUploadFormsCompressAndCheckLikeTheOthers(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['/templates/songs/create.twig', '/templates/songs/detail.twig'] as $template) {
            $content = (string) file_get_contents($root . $template);
            $this->assertStringContainsString('data-upload-compress="true"', $content, $template);
        }
    }

    public function testBankStatementInputCarriesItsOwnLimit(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/finances/index.twig');

        $this->assertStringContainsString(
            'data-upload-max-bytes="{{ constant("App\\\\Services\\\\BankStatementImportService::MAX_FILE_SIZE") }}"',
            $content
        );
    }

    public function testFindOversizedFilesAppliesLimitPerFileType(): void
    {
        $output = $this->runNode(<<<'JS'
const limits = JSON.parse(process.env.LIMITS);
const helper = window.uploadHelper;
const f = (name, type, size) => ({ name, type, size });

const expectNames = (label, limitsToUse, files, options, expectedNames) => {
    const names = helper.findOversizedFiles(files, limitsToUse, options).map(entry => entry.file.name);
    if (JSON.stringify(names) !== JSON.stringify(expectedNames)) {
        throw new Error(label + ': erwartet ' + JSON.stringify(expectedNames) + ', erhalten ' + JSON.stringify(names));
    }
};
const check = (label, files, options, expected) => expectNames(label, limits, files, options, expected);
const checkWithout = (label, files, options, expected) => expectNames(label, null, files, options, expected);

const MB = 1024 * 1024;
check('PDF an der Grenze', [f('a.pdf', 'application/pdf', 10 * MB)], {}, []);
check('PDF über der Grenze', [f('a.pdf', 'application/pdf', 10 * MB + 1)], {}, ['a.pdf']);
check('MP3 unter 30 MB', [f('probe.mp3', 'audio/mpeg', 25 * MB)], {}, []);
check('MP3 über 30 MB', [f('probe.mp3', 'audio/mpeg', 30 * MB + 1)], {}, ['probe.mp3']);
check('Bild wird komprimiert', [f('foto.jpg', 'image/jpeg', 8 * MB)], { compressImages: true }, []);
check('Bild ohne Komprimierung', [f('foto.jpg', 'image/jpeg', 2 * MB + 1)], {}, ['foto.jpg']);
check('Eigene Grenze am Feld', [f('auszug.csv', 'text/csv', 3 * MB)], { maxBytes: 2 * MB }, ['auszug.csv']);
check('Nur die zu große', [f('ok.pdf', 'application/pdf', MB), f('gross.docx', '', 11 * MB)], {}, ['gross.docx']);
checkWithout('Ohne Grenzen gilt Gesamtlimit', [f('a.pdf', 'application/pdf', 50 * MB)], { hardLimit: 100 * MB }, []);

const entry = helper.findOversizedFiles([f('a.pdf', 'application/pdf', 10 * MB + 1)], limits, {})[0];
if (entry.limit !== 10 * MB) {
    throw new Error('Grenze fehlt im Ergebnis: ' + entry.limit);
}
checkWithout('Ohne Grenzen und Gesamtlimit', [f('a.pdf', 'application/pdf', 150 * MB)], { hardLimit: 100 * MB }, ['a.pdf']);
console.log('ok');
JS);

        $this->assertContains('ok', $output);
    }

    public function testSelectingAnOversizedFileDropsItAndShowsMessageAtTheField(): void
    {
        $output = $this->runNode(<<<'JS'
const MB = 1024 * 1024;
const small = { name: 'noten.pdf', type: 'application/pdf', size: MB };
const big = { name: 'aufnahme.mp3', type: 'audio/mpeg', size: 31 * MB };

const inserted = [];
const classes = new Set();
const input = {
    tagName: 'INPUT',
    type: 'file',
    dataset: {},
    files: [small, big],
    form: { dataset: { uploadCompress: 'true' } },
    classList: { add: c => classes.add(c), remove: c => classes.delete(c) },
    closest: () => null,
    insertAdjacentElement: (pos, el) => { inserted.push(el); el.parentNode = { removeChild: () => {} }; },
    nextElementSibling: null,
};

listeners.change({ target: input });

if (input.files.length !== 1 || input.files[0] !== small) {
    throw new Error('Zu große Datei nicht entfernt: ' + JSON.stringify(input.files.map(x => x.name)));
}
if (!classes.has('is-invalid')) {
    throw new Error('Feld nicht als ungültig markiert');
}
if (inserted.length !== 1 || !inserted[0].textContent.includes('aufnahme.mp3') || !inserted[0].textContent.includes('30 MB')) {
    throw new Error('Meldung fehlt oder unvollständig: ' + (inserted[0] && inserted[0].textContent));
}

input.nextElementSibling = inserted[0];
input.files = [small];
listeners.change({ target: input });
if (classes.has('is-invalid')) {
    throw new Error('Markierung bleibt nach gültiger Auswahl stehen');
}
console.log('ok');
JS);

        $this->assertContains('ok', $output);
    }

    /**
     * Lädt upload-helper.js mit schlanken DOM-Attrappen in Node und führt das Szenario aus.
     *
     * @return list<string>
     */
    private function runNode(string $scenario): array
    {
        $helperPath = dirname(__DIR__, 2) . '/public/js/upload-helper.js';
        $prelude = <<<'JS'
const fs = require('fs');
const listeners = {};
const limitsJson = process.env.LIMITS;
global.window = {};
global.alert = () => {};
global.document = {
    addEventListener: (type, fn) => { listeners[type] = fn; },
    querySelector: sel => sel === 'meta[name="upload-limits"]' ? { getAttribute: () => limitsJson } : null,
    createElement: tag => ({ tagName: tag.toUpperCase(), className: '', textContent: '', dataset: {} }),
};
global.DataTransfer = class {
    constructor() { this.list = []; this.items = { add: f => this.list.push(f) }; }
    get files() { return this.list; }
};
eval(fs.readFileSync(process.argv[2], 'utf8'));

JS;
        $script = tempnam(sys_get_temp_dir(), 'upload-precheck-') . '.js';
        file_put_contents($script, $prelude . $scenario);

        $output = [];
        $exitCode = 1;
        $env = 'LIMITS=' . escapeshellarg((string) json_encode(UploadValidator::clientLimits()));
        exec($env . ' node ' . escapeshellarg($script) . ' ' . escapeshellarg($helperPath) . ' 2>&1', $output, $exitCode);
        @unlink($script);

        $this->assertSame(0, $exitCode, implode("\n", $output));

        return $output;
    }
}
