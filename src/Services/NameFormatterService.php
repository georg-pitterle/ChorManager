<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Formats person names according to the globally configured display order.
 */
class NameFormatterService
{
    public const FORMAT_FIRST_LAST = 'first_last';
    public const FORMAT_LAST_FIRST = 'last_first';
    public const DEFAULT_FORMAT = self::FORMAT_FIRST_LAST;

    private string $format;

    public function __construct(?string $format = null)
    {
        $this->format = self::normalizeFormat($format);
    }

    public static function normalizeFormat(?string $format): string
    {
        $candidate = strtolower(trim((string) $format));
        $allowed = [self::FORMAT_FIRST_LAST, self::FORMAT_LAST_FIRST];

        return in_array($candidate, $allowed, true) ? $candidate : self::DEFAULT_FORMAT;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function format(?string $firstName, ?string $lastName): string
    {
        $first = trim((string) $firstName);
        $last = trim((string) $lastName);

        if ($first === '') {
            return $last;
        }

        if ($last === '') {
            return $first;
        }

        return $this->format === self::FORMAT_LAST_FIRST
            ? $last . ', ' . $first
            : $first . ' ' . $last;
    }

    /**
     * Accepts Eloquent models, plain objects and arrays with first_name/last_name keys.
     */
    public function formatPerson(mixed $person): string
    {
        if (is_array($person)) {
            return $this->format($person['first_name'] ?? null, $person['last_name'] ?? null);
        }

        if (is_object($person)) {
            return $this->format($person->first_name ?? null, $person->last_name ?? null);
        }

        return '';
    }

    /**
     * Column order for ORDER BY chains and collection sorts.
     *
     * @return array<int, string>
     */
    public function orderColumns(): array
    {
        return $this->format === self::FORMAT_LAST_FIRST
            ? ['last_name', 'first_name']
            : ['first_name', 'last_name'];
    }
}
