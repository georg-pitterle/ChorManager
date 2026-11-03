<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Primärschlüssel als zufällige UUID v4.
 *
 * Bewusst kein `orderedUuid`, das HasUuids von sich aus erzeugte: Eine nach
 * Zeit sortierbare Id bewahrte die Reihenfolge der Zeilen - bei Stimmen und
 * Antworten anonymer Umfragen genau das, was nicht erhalten bleiben darf.
 */
trait HasRandomUuid
{
    use HasUuids;

    /** Zufällige v4 aus random_bytes (RFC 9562, Abschnitt 5.4). */
    public function newUniqueId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1;
    }
}
