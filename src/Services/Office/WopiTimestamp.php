<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Models\FileVersion;

/**
 * `LastModifiedTime` einer Datei für WOPI. Collabora schickt den Wert beim
 * Speichern als `X-COOL-WOPI-Timestamp` unverändert zurück; verglichen wird
 * deshalb die Zeichenkette, und die muss immer gleich gebildet werden.
 */
final class WopiTimestamp
{
    public static function of(?FileVersion $version): string
    {
        $moment = $version?->office_saved_at ?? $version?->created_at;

        return $moment === null ? '' : $moment->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
