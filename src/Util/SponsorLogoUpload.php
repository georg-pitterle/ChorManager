<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Was als Logo-Datei eines Sponsors durchgeht.
 *
 * Logos kommen in vielen Formaten und Varianten - SVG, EPS, AI, PNG in
 * mehreren Farben, oft als ZIP vom Grafikbüro -, und wer sie hochlädt, weiß
 * selten, was der Grafiker braucht. Deshalb eigene Regeln statt der engeren
 * Grenzen der übrigen Anhänge (UploadValidator: Bilder 2 MB, keine Vektor-
 * oder Archivformate): Grafikformate bis 50 MB.
 *
 * Ausgeliefert werden die Dateien weiter über die gemeinsame Anhang-Route.
 * Im Browser angezeigt wird dort nur, was AttachmentPreview als sicher
 * einstuft; SVG, EPS, AI und ZIP gibt es nur zum Herunterladen - ein SVG kann
 * Skripte enthalten.
 */
final class SponsorLogoUpload
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    /** @var list<string> */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/tiff',
        'image/svg+xml',
        'application/pdf',
        'application/postscript',
        'application/eps',
        'application/x-eps',
        'image/x-eps',
        'application/illustrator',
        'application/zip',
        'application/x-zip-compressed',
    ];

    /**
     * Dieselbe Rückgabe wie UploadValidator::validateFileSize(), damit
     * EntityAttachmentService die Regel austauschen kann.
     *
     * @return array{valid: bool, error?: string, reason?: string}
     */
    public static function validate(int $sizeBytes, string $mimeType): array
    {
        // Parameter wie "; charset=utf-8" hängen manche Erkennungen an SVG an.
        $mimeType = UploadValidator::normalizeMimeType(explode(';', $mimeType, 2)[0]);

        if ($sizeBytes <= 0) {
            return ['valid' => false, 'error' => 'Datei hat keine gültige Größe.', 'reason' => 'invalid_size'];
        }

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return [
                'valid' => false,
                'error' => 'Dieser Dateityp ist kein erlaubtes Logo-Format. '
                    . 'Möglich sind Bilder, SVG, PDF, EPS, AI und ZIP.',
                'reason' => 'invalid_mime_type',
            ];
        }

        if ($sizeBytes > self::MAX_BYTES) {
            return [
                'valid' => false,
                'error' => 'Eine Logo-Datei darf höchstens 50 MB groß sein.',
                'reason' => 'size_exceeded',
            ];
        }

        return ['valid' => true];
    }
}
