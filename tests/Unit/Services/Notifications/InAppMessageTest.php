<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Notifications;

use App\Services\Notifications\InAppMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der Glockentext kommt aus Kommentaren und Bemerkungen - also aus Eingaben.
 * Er muss als eine kurze Zeile reinen Textes ankommen, und der Link darf nie
 * aus der Anwendung hinausführen.
 */
final class InAppMessageTest extends TestCase
{
    public function testAPlainMessageKeepsItsValues(): void
    {
        $message = new InAppMessage('Neuer Kommentar: Saal', 'Ist reserviert.', '/tasks/7', 'task', 7);

        $this->assertSame('Neuer Kommentar: Saal', $message->title);
        $this->assertSame('Ist reserviert.', $message->body);
        $this->assertSame('/tasks/7', $message->link);
        $this->assertSame('task', $message->entityType);
        $this->assertSame(7, $message->entityId);
    }

    public function testHtmlAndLineBreaksBecomeOneLineOfText(): void
    {
        $message = new InAppMessage('T', "<p>Erste&nbsp;Zeile</p>\n<p><strong>zweite</strong></p>", '/x');

        $this->assertSame('Erste Zeile zweite', $message->body);
    }

    /**
     * Kodierte Tags dürfen beim Dekodieren nicht zu echten werden - sonst stünde
     * nach dem Bereinigen ein `<script>` in der Datenbank, das nur so lange
     * harmlos bleibt, wie jede Ausgabe daran denkt zu escapen.
     */
    public function testEncodedTagsDoNotComeBackAsRealTags(): void
    {
        $message = new InAppMessage(
            '&lt;img src=x onerror=alert(1)&gt;Titel',
            '&lt;script&gt;alert(1)&lt;/script&gt;Hallo',
            '/x'
        );

        $this->assertStringNotContainsString('<', $message->title);
        $this->assertStringNotContainsString('<', (string) $message->body);
        $this->assertSame('Titel', $message->title);
    }

    /**
     * Kommentare sind reiner Text. Ein „<“, das kein Tag beginnt, gehört zum
     * Inhalt - es darf nicht den ganzen Rest der Zeile mitnehmen.
     */
    public function testALessThanSignThatIsNoTagStaysInTheText(): void
    {
        $this->assertSame('Danke <3 bis morgen', (new InAppMessage('T', 'Danke <3 bis morgen', '/x'))->body);
        $this->assertSame(
            'Treffpunkt 18 Uhr <- Haupteingang',
            (new InAppMessage('T', 'Treffpunkt 18 Uhr <- Haupteingang', '/x'))->body
        );
        $this->assertSame('a<b und c', (new InAppMessage('T', 'a<b und c', '/x'))->body);
    }

    public function testALongBodyIsCutWithAnEllipsis(): void
    {
        $message = new InAppMessage('T', str_repeat('ä', 200), '/x');

        $this->assertSame(InAppMessage::BODY_MAX_LENGTH, mb_strlen((string) $message->body));
        $this->assertStringEndsWith('…', (string) $message->body);
    }

    public function testAnEmptyBodyBecomesNull(): void
    {
        $this->assertNull((new InAppMessage('T', '  <br> ', '/x'))->body);
    }

    public function testAnEmptyTitleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InAppMessage('  ', null, '/x');
    }

    #[DataProvider('foreignLinks')]
    public function testALinkOutOfTheApplicationIsRejected(string $link): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InAppMessage('T', null, $link);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignLinks(): array
    {
        return [
            'absolut' => ['https://evil.example/'],
            'protokollrelativ' => ['//evil.example/'],
            'Backslash' => ['/\\evil.example/'],
            'relativ ohne Schrägstrich' => ['tasks/7'],
            'leer' => [''],
            'javascript' => ['javascript:alert(1)'],
        ];
    }
}
