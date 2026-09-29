<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Support\Collection;

/**
 * Alles, was der Newsletter über seine Dateien wissen muss.
 *
 * Schwelle, Gesamtlimit und die Bedeutung von `attach`/`link` stehen hier und
 * nur hier. Controller, Versand, Mail-Renderer und Queue-Worker fragen denselben
 * Dienst - sonst entschiede jede der vier Stellen für sich, und eine spätere
 * Änderung der Schwelle bliebe an einer davon liegen.
 *
 * Gelesen wird durchweg ohne `file_content`, außer in `attachedFiles()`. Der
 * Inhalt ist ein BLOB; wer ihn für eine Namensliste mitlädt, zieht bei drei PDFs
 * zweistellige Megabytes durch den Speicher.
 */
class NewsletterAttachmentService
{
    public const ENTITY_TYPE = 'newsletter';

    public const MODE_ATTACH = 'attach';
    public const MODE_LINK = 'link';

    /**
     * Bis hierher schlägt die Oberfläche "An die Mail hängen" vor, darüber
     * "In der Mail verlinken". Umschalten darf die Redaktion in beide
     * Richtungen, solange das Gesamtlimit hält - ein wichtiges Blatt von 3 MB
     * soll sich anhängen lassen.
     */
    public const ATTACH_SUGGESTION_LIMIT = 2 * 1024 * 1024;

    /**
     * Harte Grenze für die Summe aller echten Anhänge einer Mail. Darüber lehnen
     * verbreitete Postfächer die Zustellung ab, und der Versand liefe für jeden
     * Empfänger einzeln in denselben Fehler - sichtbar erst im Fehlerbericht,
     * lange nachdem der Newsletter als versendet gilt.
     */
    public const MAX_ATTACHED_TOTAL = 10 * 1024 * 1024;

    public function suggestMode(int $sizeBytes): string
    {
        return $sizeBytes < self::ATTACH_SUGGESTION_LIMIT ? self::MODE_ATTACH : self::MODE_LINK;
    }

    /**
     * Metadaten der Dateien eines Newsletters, wahlweise auf einen Modus begrenzt.
     *
     * @return Collection<int, Attachment>
     */
    public function metadataFor(int $newsletterId, ?string $mode = null): Collection
    {
        $query = Attachment::query()
            ->select(array_merge(EntityAttachmentService::METADATA_COLUMNS, ['delivery_mode']))
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $newsletterId)
            ->orderBy('id');

        if ($mode !== null) {
            $query->where('delivery_mode', $mode);
        }

        return $query->get();
    }

    public function attachedTotalBytes(int $newsletterId): int
    {
        return (int) Attachment::query()
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $newsletterId)
            ->where('delivery_mode', self::MODE_ATTACH)
            ->sum('file_size');
    }

    /**
     * Die Dateien, die an der Mail hängen - hier mit Inhalt, weil PHPMailer ihn
     * braucht. Der einzige Zugriff dieses Dienstes, der den BLOB anfasst.
     *
     * @return array<int, array{content: string, name: string, mime: string}>
     */
    public function attachedFiles(int $newsletterId): array
    {
        return Attachment::query()
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $newsletterId)
            ->where('delivery_mode', self::MODE_ATTACH)
            ->orderBy('id')
            ->get()
            ->map(static fn (Attachment $attachment): array => [
                'content' => (string) $attachment->file_content,
                'name' => (string) $attachment->original_name,
                'mime' => (string) $attachment->mime_type,
            ])
            ->all();
    }

    /**
     * Die Dateien, die als Link in der Mail stehen.
     *
     * @return array<int, array{name: string, size: int, url: string}>
     */
    public function linkedFiles(int $newsletterId, string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');

        return $this->metadataFor($newsletterId, self::MODE_LINK)
            ->map(static fn (Attachment $attachment): array => [
                'name' => (string) $attachment->original_name,
                'size' => (int) $attachment->file_size,
                'url' => $base . '/attachments/' . (int) $attachment->id . '/download',
            ])
            ->values()
            ->all();
    }

    /**
     * Der Newsletter steht bewusst in der Bedingung und nicht in einem Vergleich
     * danach: Eine fremde Anhang-Kennung aus dem Formular darf nicht die Datei
     * eines anderen Newsletters umschalten.
     */
    public function setMode(int $newsletterId, int $attachmentId, string $mode): bool
    {
        if (!in_array($mode, [self::MODE_ATTACH, self::MODE_LINK], true)) {
            return false;
        }

        return Attachment::query()
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $newsletterId)
            ->where('id', $attachmentId)
            ->update(['delivery_mode' => $mode]) > 0;
    }
}
