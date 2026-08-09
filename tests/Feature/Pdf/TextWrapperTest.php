<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Services\Pdf\TextWrapper;
use PHPUnit\Framework\TestCase;

final class TextWrapperTest extends TestCase
{
    /** Fake-Metrik: jedes Zeichen 1 Einheit breit bei fontSize 10. */
    private function fakeWidth(): callable
    {
        return static fn (string $t, float $size): float => strlen($t) * ($size / 10.0);
    }

    public function testShortTextStaysOnOneLine(): void
    {
        $lines = TextWrapper::wrap('kurz', 100.0, 10.0, $this->fakeWidth());
        $this->assertSame(['kurz'], $lines);
    }

    public function testLongTextWrapsByWords(): void
    {
        $lines = TextWrapper::wrap('aaaa bbbb cccc dddd', 9.0, 10.0, $this->fakeWidth());
        $this->assertSame(['aaaa', 'bbbb', 'cccc', 'dddd'], $lines);
    }

    public function testWordLongerThanWidthGetsOwnLine(): void
    {
        $lines = TextWrapper::wrap('ab cdefghij k', 5.0, 10.0, $this->fakeWidth());
        $this->assertContains('cdefghij', $lines);
    }

    public function testEmptyTextYieldsSingleEmptyLine(): void
    {
        $this->assertSame([''], TextWrapper::wrap('', 100.0, 10.0, $this->fakeWidth()));
    }
}
