<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Ein Eintrag im WebDAV-Baum: entweder eine Sammlung (Projekt, Lied) oder eine
 * Datei (Anhang).
 *
 * `path` ist der entschlüsselte Pfad unterhalb von `/webdav`, mit `/` getrennt
 * und ohne führenden Schrägstrich; die Wurzel ist die leere Zeichenkette. Die
 * Namen darin sind bereits bereinigt und innerhalb ihrer Sammlung eindeutig -
 * sie stammen nicht unverändert aus der Datenbank.
 */
final class WebdavNode
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly bool $isCollection,
        public readonly ?int $attachmentId = null,
        public readonly int $size = 0,
        public readonly string $mimeType = '',
        public readonly ?string $lastModified = null,
    ) {
    }
}
