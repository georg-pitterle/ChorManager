<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\PollTextAnswer;
use App\Services\PollVoteException;
use PHPUnit\Framework\TestCase;

/**
 * Die Meldung zur zu langen Antwort nennt eine Zahl, und geprüft wird gegen
 * `PollTextAnswer::MAX_LENGTH`. Standen beide getrennt da, nannte die Meldung
 * nach einer Änderung der Grenze eine Zahl, die nicht mehr gilt - und niemand
 * merkt es, weil die Abweisung selbst weiter richtig ist.
 */
final class PollVoteExceptionNamesTheRealLimitTest extends TestCase
{
    public function testTheMessageNamesTheCheckedLimit(): void
    {
        $this->assertStringContainsString(
            (string) PollTextAnswer::MAX_LENGTH . ' Zeichen',
            (new PollVoteException(PollVoteException::ANSWER_TOO_LONG))->getMessage()
        );
    }

    /**
     * Gegenprobe zur Verankerung: Wäre die Zahl weiterhin fest eingetippt,
     * bestünde der Test oben nur deshalb, weil beide Werte heute 500 lauten.
     */
    public function testTheMessageDoesNotRepeatTheNumberLiterally(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Services/PollVoteException.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('PollTextAnswer::MAX_LENGTH', $source);
    }
}
