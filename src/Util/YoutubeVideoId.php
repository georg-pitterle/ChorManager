<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Die Kennung eines YouTube-Videos aus einer Eingabe - der nackten Kennung
 * oder einem eingefügten Link.
 *
 * Der Wert wird Teil der Adresse eines eingebetteten Rahmens. Übernommen wird
 * deshalb ausschließlich die Kennung selbst: elf Zeichen aus Buchstaben,
 * Ziffern, Binde- und Unterstrich. Alles andere ergibt die leere Zeichenkette,
 * also "kein Video".
 */
final class YoutubeVideoId
{
    private const ID = '[A-Za-z0-9_-]{11}';

    private const HOSTS = ['youtu.be', 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com'];

    public static function normalize(mixed $value): string
    {
        $input = trim(InputValidator::asString($value));

        if (preg_match('/^' . self::ID . '$/', $input) === 1) {
            return $input;
        }

        $parts = parse_url($input);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true)) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        $candidate = match (true) {
            strtolower((string) $parts['host']) === 'youtu.be' => ltrim($path, '/'),
            str_starts_with($path, '/embed/') => substr($path, 7),
            str_starts_with($path, '/shorts/') => substr($path, 8),
            default => is_string($query['v'] ?? null) ? $query['v'] : '',
        };

        return preg_match('/^' . self::ID . '$/', $candidate) === 1 ? $candidate : '';
    }
}
