<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Util\UploadValidator;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Anhänge einer beliebigen Entität (`attachments.entity_type`).
 *
 * Hochladen, Ausliefern und Löschen lagen vorher in jedem Controller als
 * eigene Kopie: derselbe UploadValidator-Ablauf, dasselbe Namensschema, derselbe
 * von Hand gebaute Content-Disposition-Header. Die Kopien liefen bereits
 * auseinander, und ein Fix an der Upload-Prüfung hätte an jeder einzelnen
 * nachgezogen werden müssen.
 *
 * Zwei Eigenschaften sind hier bewusst festgelegt, weil sie in den Kopien
 * teilweise fehlten:
 *
 *  - Jede Abfrage bindet `entity_type` **und** `entity_id` ein. Ein Anhang, der
 *    nicht zur angefragten Entität gehört, wird damit gar nicht erst geladen -
 *    vorher las der Server erst den kompletten Datei-Inhalt und verwarf ihn dann
 *    mit einem 403.
 *  - Für alles außer dem Download werden nur die Metadaten-Spalten gelesen.
 *    `file_content` ist ein BLOB; wer ihn für eine Namensliste mitlädt, zieht
 *    bei ein paar Vertrags-PDFs zweistellige Megabytes durch den Speicher.
 */
class EntityAttachmentService
{
    /**
     * `attachments.filename` und `attachments.original_name` sind je
     * `varchar(255)`; MySQL zählt dort Zeichen, nicht Bytes.
     */
    private const NAME_MAX_LENGTH = 255;

    /** 32 Hex-Zeichen Zufall plus der trennende Unterstrich. */
    private const STORED_NAME_PREFIX_LENGTH = 33;

    /**
     * Alles darüber ist kein Dateisuffix mehr, sondern Teil des Namens - und
     * würde beim Kürzen den ganzen Platz belegen.
     */
    private const MAX_EXTENSION_LENGTH = 20;

    /**
     * Alles außer `file_content`. Als Eager-Load- und Abfragefilter zu nutzen,
     * damit Übersichten den Datei-Inhalt nicht anfassen.
     *
     * @var list<string>
     */
    public const METADATA_COLUMNS = [
        'id',
        'entity_type',
        'entity_id',
        'filename',
        'original_name',
        'mime_type',
        'file_size',
        'created_at',
    ];

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Speichert alle hochgeladenen Dateien und meldet die erste Beanstandung
     * zurück. Eine abgelehnte Datei bricht den Lauf nicht ab - die übrigen
     * werden weiterhin gespeichert, wie es die Vorgängerfassung tat.
     *
     * @param UploadedFileInterface|list<UploadedFileInterface>|null $files
     * @return array{stored: int, error: ?string}
     */
    public function storeUploads(mixed $files, string $entityType, int $entityId): array
    {
        if ($files === null) {
            return ['stored' => 0, 'error' => null];
        }

        if (!is_array($files)) {
            $files = [$files];
        }

        $stored = 0;
        $error = null;

        foreach ($files as $file) {
            if (!$file instanceof UploadedFileInterface) {
                continue;
            }

            $uploadError = UploadValidator::getUploadErrorMessage($file->getError(), 'Anhang');
            if ($uploadError !== null) {
                $error = $uploadError;
                continue;
            }

            if ($file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $mimeType = UploadValidator::detectMimeType($file);
            $contents = $file->getStream()->getContents();
            $size = strlen($contents);

            $validation = UploadValidator::validateFileSize($size, $mimeType);
            if (!$validation['valid']) {
                // Ohne Dateiname: der Name stammt vom Hochladenden und hat in
                // einer Protokollzeile nichts verloren.
                $this->logger->warning('File upload rejected.', [
                    'event' => 'security.upload.rejected',
                    'reason' => $validation['reason'],
                ]);
                $error = $validation['error'];
                continue;
            }

            $clientFilename = (string) $file->getClientFilename();

            Attachment::create([
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'filename'      => bin2hex(random_bytes(16)) . '_' . self::shortenName(
                    $clientFilename,
                    self::NAME_MAX_LENGTH - self::STORED_NAME_PREFIX_LENGTH
                ),
                'original_name' => self::shortenName($clientFilename, self::NAME_MAX_LENGTH),
                'mime_type'     => UploadValidator::normalizeMimeType($mimeType),
                'file_size'     => $size,
                'file_content'  => $contents,
            ]);

            $stored++;
        }

        return ['stored' => $stored, 'error' => $error];
    }

    /**
     * Kürzt einen Dateinamen auf die Spaltenbreite und behält dabei das Suffix.
     *
     * Der Name kommt vom Hochladenden und ist beliebig lang; ungekürzt lehnt
     * MySQL den Datensatz ab und der Upload endet in einer Fehlerseite statt in
     * einem gespeicherten Anhang. Das Suffix bleibt stehen, weil sich sonst
     * weder der Dateityp ablesen noch die Datei nach dem Herunterladen öffnen
     * lässt.
     */
    private static function shortenName(string $name, int $maxLength): string
    {
        if (mb_strlen($name) <= $maxLength) {
            return $name;
        }

        $suffix = '';
        $dotPosition = mb_strrpos($name, '.');
        if ($dotPosition !== false && $dotPosition > 0) {
            $candidate = mb_substr($name, $dotPosition);
            // Ein "Suffix" von halber Namenslänge ist keines, sondern ein Punkt
            // mitten im Namen. Es abzuschneiden wäre schlimmer als es zu verlieren.
            if (mb_strlen($candidate) <= self::MAX_EXTENSION_LENGTH + 1) {
                $suffix = $candidate;
            }
        }

        $baseLength = max(1, $maxLength - mb_strlen($suffix));

        return mb_substr($name, 0, $baseLength) . $suffix;
    }

    /**
     * Anhang samt Inhalt, aber nur wenn er zur angefragten Entität gehört.
     * Der Zugehörigkeitstest steckt in der Abfrage, nicht in einem Vergleich
     * danach - ein fremder Anhang wird deshalb nie gelesen.
     */
    public function findWithContent(string $entityType, int $entityId, int $attachmentId): ?Attachment
    {
        return Attachment::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->find($attachmentId);
    }

    /**
     * Löscht einen einzelnen Anhang der Entität. Gibt false zurück, wenn es ihn
     * dort nicht gibt (falsche Entität oder bereits gelöscht).
     */
    public function deleteForEntity(string $entityType, int $entityId, int $attachmentId): bool
    {
        return Attachment::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereKey($attachmentId)
            ->delete() > 0;
    }

    /**
     * Räumt alle Anhänge mehrerer Entitäten ab. Nötig, weil `attachments`
     * polymorph ist und keinen Fremdschlüssel hat: Löscht die Datenbank eine
     * Zeile per Kaskade weg, bleiben ihre Anhänge sonst unerreichbar liegen.
     *
     * @param list<int> $entityIds
     */
    public function deleteAllForEntities(string $entityType, array $entityIds): int
    {
        if ($entityIds === []) {
            return 0;
        }

        return Attachment::where('entity_type', $entityType)
            ->whereIn('entity_id', $entityIds)
            ->delete();
    }
}
