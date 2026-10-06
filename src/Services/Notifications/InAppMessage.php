<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Was ein Anlass in der Glocke zeigt: Titel, eine Zeile Text und wohin der
 * Eintrag führt.
 *
 * Der Text stammt oft aus Eingaben - Kommentare, Bemerkungen -, die HTML aus
 * dem Editor tragen. Hier wird er einmal zu einer kurzen Zeile reinen Textes,
 * damit weder der Controller noch das Frontend daran denken müssen.
 */
final class InAppMessage
{
    public const BODY_MAX_LENGTH = 140;

    private const TITLE_MAX_LENGTH = 255;

    public readonly string $title;

    public readonly ?string $body;

    public function __construct(
        string $title,
        ?string $body,
        public readonly string $link,
        public readonly ?string $entityType = null,
        public readonly ?int $entityId = null,
        public readonly ?int $commentId = null
    ) {
        $title = self::plainText($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Ein Glocken-Eintrag braucht einen Titel.');
        }

        // Genau ein führender Schrägstrich: `//host` und `/\host` behandeln
        // Browser als Adresse eines fremden Servers.
        if (preg_match('#^/(?![/\\\\])#', $link) !== 1) {
            throw new \InvalidArgumentException('Ein Glocken-Eintrag verlinkt nur innerhalb der Anwendung: ' . $link);
        }

        $this->title = self::truncate($title, self::TITLE_MAX_LENGTH);

        $this->body = self::plainBody($body);
    }

    /**
     * Die Zeile so, wie sie in der Glocke steht - auch für das Nachziehen eines
     * geänderten Kommentars.
     */
    public static function plainBody(?string $body): ?string
    {
        $text = self::plainText((string) $body);

        return $text === '' ? null : self::truncate($text, self::BODY_MAX_LENGTH);
    }

    private static function plainText(string $value): string
    {
        // Blockgrenzen werden zu Leerzeichen, sonst klebten zwei Absätze aneinander.
        $withSpaces = preg_replace('/<(br|\/p|\/div|\/li)[^>]*>/i', ' ', $value) ?? $value;
        // Nach dem Dekodieren noch einmal: Aus `&lt;script&gt;` würde sonst ein
        // echtes Tag, das nur harmlos bleibt, solange jede Ausgabe escaped.
        $decoded = html_entity_decode(self::withoutTags($withSpaces), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = self::withoutTags($decoded);
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Entfernt nur, was wirklich ein Tag ist - `<` gefolgt von einem Buchstaben
     * oder `/` bis zum nächsten `>`. `strip_tags()` nähme bei „Danke <3 bis
     * morgen“ den ganzen Rest der Zeile mit.
     */
    private static function withoutTags(string $value): string
    {
        $withoutComments = preg_replace('/<!--.*?-->/s', '', $value) ?? $value;

        return preg_replace('#</?[a-zA-Z][^<>]*>#', '', $withoutComments) ?? $withoutComments;
    }

    private static function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLength - 1)) . '…';
    }
}
