<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Entscheidet, wie ein Anhang ausgeliefert und angezeigt wird.
 *
 * Vorher stand diese Liste dreimal im Code: als `$streamableMimeTypes` im
 * DownloadController, als `isInlineViewableMimeType()` im FinanceController und
 * gar nicht in den Templates - die verlinkten einfach jeden Anhang gleich. Die
 * Kopien liefen bereits auseinander, und die Oberfläche wusste nichts davon.
 *
 * Zwei Fragen, bewusst getrennt:
 *
 *  - `isInlineServable()` beantwortet, was die Vorschau-Route überhaupt mit
 *    `Content-Disposition: inline` herausgibt.
 *  - `isModalPreviewable()` beantwortet, was das gemeinsame Modal auch wirklich
 *    darstellen kann.
 *
 * MIDI fällt auseinander: die Downloads-Seite spielt es mit drei nur dort
 * geladenen Bibliotheken ab und braucht dafür die Inline-Quelle, das globale
 * Modal könnte es nirgends anzeigen.
 */
final class AttachmentPreview
{
    /**
     * Alles außer Audio. Die Audio-Typen stehen bewusst nicht hier, sondern
     * kommen aus UploadValidator::getAudioMimeTypes() - dort entscheidet sich,
     * was überhaupt hochgeladen werden darf. Eine zweite Liste an dieser Stelle
     * war schon einmal der Grund, warum der Abspieler ins Leere lief.
     *
     * @var list<string>
     */
    private const INLINE_SERVABLE_DOCUMENTS = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'text/plain',
    ];

    /** @var list<string> */
    private const MODAL_ONLY_EXCLUDED = [
        'audio/midi',
        'audio/x-midi',
        'application/x-midi',
    ];

    /**
     * `UploadValidator::normalizeMimeType()` reicht hier nicht: es trimmt und
     * schreibt klein, schneidet aber keine Parameter ab. Ein gespeichertes
     * `text/plain; charset=utf-8` fiele damit durch jeden Vergleich.
     */
    public static function normalize(string $mimeType): string
    {
        $withoutParameters = explode(';', $mimeType, 2)[0];

        return strtolower(trim($withoutParameters));
    }

    /**
     * Die vollständige Inline-Liste: eigene Dokumenttypen plus die Audio-Typen,
     * die der Upload durchlässt.
     *
     * @return list<string>
     */
    public static function inlineServableMimeTypes(): array
    {
        return array_values(array_merge(
            self::INLINE_SERVABLE_DOCUMENTS,
            UploadValidator::getAudioMimeTypes()
        ));
    }

    public static function isInlineServable(string $mimeType): bool
    {
        return in_array(self::normalize($mimeType), self::inlineServableMimeTypes(), true);
    }

    public static function isModalPreviewable(string $mimeType): bool
    {
        $normalized = self::normalize($mimeType);

        if (in_array($normalized, self::MODAL_ONLY_EXCLUDED, true)) {
            return false;
        }

        return in_array($normalized, self::inlineServableMimeTypes(), true);
    }
}
