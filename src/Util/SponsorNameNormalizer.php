<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Vergleichsschlüssel für die Dublettenwarnung.
 *
 * Gleich ist, was sich nur in Groß- und Kleinschreibung, Satzzeichen, Leerraum
 * oder einer Rechtsform am Ende unterscheidet: "Bäckerei Huber GmbH" und
 * "bäckerei huber." sind derselbe Betrieb. Bewusst keine unscharfe Ähnlichkeit
 * (Tippfehler-Abstand) - die meldete "Huber" und "Hubert" als Dublette, und
 * eine Warnung, die man ständig wegklickt, liest bald niemand mehr.
 */
final class SponsorNameNormalizer
{
    /**
     * Rechtsformen in der Schreibweise, nachdem Satzzeichen zu Leerzeichen
     * wurden ("e.U." => "e u"), die längsten zuerst, damit "gmbh co kg" vor
     * "kg" greift.
     */
    public const LEGAL_FORMS = [
        'ges m b h',
        'gmbh co kg',
        'gesmbh',
        'co kg',
        'gmbh',
        'mbh',
        'e u',
        'e v',
        'kg',
        'og',
        'ag',
        'eu',
        'ev',
        'co',
    ];

    /**
     * Der Schlüssel enthält am Ende keine Leerzeichen: "Sport-Müller",
     * "Sport Müller" und "SportMüller" sind derselbe Betrieb. Satzzeichen
     * werden dafür zuerst zu Leerzeichen und nicht ersatzlos gestrichen, sonst
     * bliebe bei "Huber,KG" die Rechtsform am Namen kleben.
     */
    public static function normalize(string $name): string
    {
        $normalized = mb_strtolower($name);
        $normalized = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $normalized));

        do {
            $before = $normalized;
            foreach (self::LEGAL_FORMS as $form) {
                if ($normalized === $form) {
                    $normalized = '';
                    break;
                }
                if (str_ends_with($normalized, ' ' . $form)) {
                    $normalized = rtrim(mb_substr($normalized, 0, mb_strlen($normalized) - mb_strlen($form) - 1));
                    break;
                }
            }
        } while ($normalized !== $before && $normalized !== '');

        return str_replace(' ', '', $normalized);
    }
}
