<?php

declare(strict_types=1);

namespace App\Util;

class UploadValidator
{
    // Size constants in bytes
    public const MAX_IMAGE_SIZE = 2 * 1024 * 1024;           // 2 MB
    public const MAX_NON_IMAGE_SIZE = 10 * 1024 * 1024;      // 10 MB

    // Image MIME types allowed
    private static array $imageMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/svg+xml',
    ];

    // Non-image (document) MIME types allowed
    private static array $nonImageMimeTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];

    /**
     * Validate image file size against 2 MB limit.
     * Returns array with 'valid' (bool) and optional 'error' (string).
     */
    public static function validateImageSize(int $sizeBytes, string $mimeType): array
    {
        if (!self::isImageMimeType($mimeType)) {
            return [
                'valid' => false,
                'error' => "MIME type '$mimeType' ist kein erlaubtes Bildformat.",
            ];
        }

        if ($sizeBytes <= 0) {
            return [
                'valid' => false,
                'error' => 'Bilddatei hat keine gültige Größe.',
            ];
        }

        if ($sizeBytes > self::MAX_IMAGE_SIZE) {
            return [
                'valid' => false,
                'error' => sprintf(
                    'Bilddatei zu groß: %s MB. Maximal erlaubt: 2 MB.',
                    round($sizeBytes / (1024 * 1024), 1)
                ),
            ];
        }

        return ['valid' => true];
    }

    /**
     * Validate file size (image or non-image) against appropriate limit.
     * Returns array with 'valid' (bool) and optional 'error' (string).
     */
    public static function validateFileSize(int $sizeBytes, string $mimeType): array
    {
        if ($sizeBytes < 0) {
            return [
                'valid' => false,
                'error' => 'Datei hat keine gültige Größe.',
            ];
        }

        $isImage = self::isImageMimeType($mimeType);
        $limit = $isImage ? self::MAX_IMAGE_SIZE : self::MAX_NON_IMAGE_SIZE;
        $limitMB = $isImage ? 2 : 10;

        if ($sizeBytes > $limit) {
            $type = $isImage ? 'Bilddatei' : 'Datei';
            return [
                'valid' => false,
                'error' => sprintf(
                    '%s zu groß: %s MB. Maximal erlaubt: %d MB.',
                    $type,
                    round($sizeBytes / (1024 * 1024), 1),
                    $limitMB
                ),
            ];
        }

        return ['valid' => true];
    }

    /**
     * Check if a MIME type is categorized as an image.
     */
    public static function isImageMimeType(string $mimeType): bool
    {
        return in_array(trim(strtolower($mimeType)), self::$imageMimeTypes, true);
    }

    /**
     * Get list of allowed image MIME types.
     */
    public static function getImageMimeTypes(): array
    {
        return self::$imageMimeTypes;
    }

    /**
     * Get list of allowed non-image MIME types.
     */
    public static function getNonImageMimeTypes(): array
    {
        return self::$nonImageMimeTypes;
    }

    /**
     * Get all allowed MIME types in one array.
     */
    public static function getAllowedMimeTypes(): array
    {
        return array_merge(self::$imageMimeTypes, self::$nonImageMimeTypes);
    }
}
