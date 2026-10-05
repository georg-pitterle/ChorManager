<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Models\FileFolder;
use App\Models\StoredFile;
use App\Services\Files\FileActor;
use App\Services\Files\FileManagementException;
use App\Services\Files\FileNameCleaner;
use App\Services\Files\FileService;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * Legt neue, leere Office-Dokumente in einem Ordner an.
 *
 * Ein neues Dokument ist für die Ablage ein Upload: Es braucht dieselbe Stufe
 * ("Hochladen") und läuft durch dieselben Prüfungen. Anders als ein Upload
 * überschreibt es aber nie eine vorhandene Datei gleichen Namens - wer "Neu"
 * wählt, erwartet kein Versionieren eines fremden Dokuments.
 *
 * Die Vorlagen liegen unter assets/office-templates/ und stammen aus Collabora
 * selbst (convert-to), damit sie sich überall sauber öffnen.
 */
final class OfficeDocumentCreator
{
    /** Typ => Endung, Vorlagendatei und Bezeichnung in der Oberfläche. */
    private const TYPES = [
        'text' => ['extension' => 'docx', 'template' => 'text.docx', 'label' => 'Textdokument'],
        'spreadsheet' => ['extension' => 'xlsx', 'template' => 'spreadsheet.xlsx', 'label' => 'Tabelle'],
        'presentation' => ['extension' => 'pptx', 'template' => 'presentation.pptx', 'label' => 'Präsentation'],
    ];

    public function __construct(
        private readonly FileService $files,
        private readonly OfficeDiscovery $discovery,
        private readonly string $templateDir,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Die Typen, die der Office-Server bearbeiten kann. Leer, wenn er nicht
     * erreichbar ist - dann gibt es auch keinen Knopf.
     *
     * @return array<string, array{extension: string, label: string}>
     */
    public function availableTypes(): array
    {
        $available = [];
        foreach (self::TYPES as $type => $definition) {
            if ($this->discovery->actionFor('x.' . $definition['extension'])?->canEdit) {
                $available[$type] = ['extension' => $definition['extension'], 'label' => $definition['label']];
            }
        }

        return $available;
    }

    public function create(FileActor $actor, FileFolder $folder, string $type, string $name): StoredFile
    {
        $definition = $this->availableTypes()[$type] ?? null;
        if ($definition === null) {
            throw new FileManagementException('Diesen Dokumenttyp gibt es nicht.', 422);
        }

        $fileName = self::fileName($name, $definition['extension']);
        if ($fileName === null) {
            throw new FileManagementException('Bitte einen gültigen Namen angeben.', 422);
        }
        if (StoredFile::query()->where('folder_id', $folder->id)->where('name', $fileName)->exists()) {
            throw new FileManagementException('Eine Datei mit diesem Namen gibt es dort schon.', 409);
        }

        $templatePath = $this->templateDir . '/' . self::TYPES[$type]['template'];
        $content = (string) file_get_contents($templatePath);
        $upload = new UploadedFile(
            (new StreamFactory())->createStream($content),
            $fileName,
            'application/octet-stream',
            strlen($content)
        );
        $file = $this->files->upload($actor, $folder, $upload)->file;

        $this->logger->info('Office document created.', [
            'event' => 'office.document_created',
            'file_id' => (int) $file->id,
            'folder_id' => (int) $folder->id,
            'type' => $type,
            'user_id' => $actor->userId,
        ]);

        return $file;
    }

    /** Name plus Endung; eine schon getippte gleiche Endung wird nicht verdoppelt. */
    private static function fileName(string $name, string $extension): ?string
    {
        $clean = FileNameCleaner::clean($name);
        if ($clean === null) {
            return null;
        }

        $suffix = '.' . $extension;
        $stem = str_ends_with(strtolower($clean), $suffix) ? substr($clean, 0, -strlen($suffix)) : $clean;
        if (trim($stem) === '') {
            return null;
        }

        return FileNameCleaner::clean(trim($stem) . $suffix);
    }
}
