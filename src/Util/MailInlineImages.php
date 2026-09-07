<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Wandelt Bilder, die als `data:`-URI im Mail-HTML stehen, in eingebettete Anhaenge um und
 * ersetzt die Quelle durch einen `cid:`-Verweis.
 *
 * Gmail schreibt eingehendes HTML um und entfernt dabei `data:`-URIs aus `src`-Attributen —
 * das Logo im Mailkopf bleibt dort sonst unsichtbar. Andere Quellen bleiben unangetastet:
 * externe Bilder aus Newsletter-Inhalten sollen externe Bilder bleiben.
 */
final class MailInlineImages
{
    /** Fester Domain-Teil der Content-ID; sie muss nur eindeutig, nicht aufloesbar sein. */
    private const CID_DOMAIN = 'chormanager.local';

    /**
     * @return array{
     *     html: string,
     *     images: list<array{cid: string, data: string, mime: string, name: string}>
     * }
     */
    public static function extract(string $html): array
    {
        $pattern = '/src="(data:(image\/[a-z0-9.+-]+);base64,([^"]*))"/i';

        /** @var array<string, array{cid: string, data: string, mime: string, name: string}> $images */
        $images = [];

        $converted = preg_replace_callback(
            $pattern,
            static function (array $match) use (&$images): string {
                $binaryContent = base64_decode($match[3], true);
                if ($binaryContent === false || $binaryContent === '') {
                    // Kein verwertbares Bild: unveraendert stehen lassen statt Muell anzuhaengen.
                    return $match[0];
                }

                $digest = hash('sha256', $binaryContent);
                if (!isset($images[$digest])) {
                    $images[$digest] = [
                        'cid' => substr($digest, 0, 32) . '@' . self::CID_DOMAIN,
                        'data' => $binaryContent,
                        'mime' => strtolower($match[2]),
                        'name' => 'inline-' . substr($digest, 0, 8),
                    ];
                }

                return 'src="cid:' . $images[$digest]['cid'] . '"';
            },
            $html
        );

        if ($converted === null) {
            // Backtracking-Limit oder aehnliches: lieber das Original ausliefern.
            return ['html' => $html, 'images' => []];
        }

        return ['html' => $converted, 'images' => array_values($images)];
    }
}
