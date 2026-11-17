<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\EventSeriesInput;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Die Eingaberegeln der Terminserie, ohne Controller und ohne Datenbank.
 *
 * Herausgezogen aus dem EventController (Antwort auf Review-Lauf 77, Frage 2b).
 * Geprüft werden die Ränder: Ein unbrauchbarer Wert wird nicht zurechtgebogen,
 * sondern abgelehnt - sonst entstünde eine Serie, die niemand so bestellt hat.
 * Dass die Ablehnung im Formular als Meldung ankommt, hält
 * EventSeriesRecurrenceValidationFeatureTest fest.
 */
final class EventSeriesInputTest extends TestCase
{
    public function testAMissingFrequencyFieldStaysWeekly(): void
    {
        $this->assertSame('weekly', EventSeriesInput::frequency(null));
    }

    public function testTheFrequencyAlsoArrivesInCapitals(): void
    {
        $this->assertSame('monthly', EventSeriesInput::frequency(' MONTHLY '));
    }

    public function testAnUnknownFrequencyIsRejected(): void
    {
        $this->assertNull(EventSeriesInput::frequency('fortnightly'));
        $this->assertNull(EventSeriesInput::frequency(['weekly']));
    }

    public function testAnEmptyIntervalStaysOne(): void
    {
        $this->assertSame(1, EventSeriesInput::interval(''));
        $this->assertSame(1, EventSeriesInput::interval(null));
    }

    public function testZeroAndNegativeIntervalsAreRejected(): void
    {
        $this->assertNull(EventSeriesInput::interval('0'));
        $this->assertNull(EventSeriesInput::interval('-2'));
        $this->assertNull(EventSeriesInput::interval('zwei'));
        $this->assertSame(3, EventSeriesInput::interval('3'));
    }

    public function testWeekdaysSurviveOnlyAsIsoNumbers(): void
    {
        $this->assertSame([1, 7], EventSeriesInput::weekdays(['1', '0', '8', 'Montag', '7', '1']));
        $this->assertSame([], EventSeriesInput::weekdays([]));
    }

    public function testTheEndDateIsReadOrRejected(): void
    {
        $parsed = EventSeriesInput::endDate('2026-12-24');
        $this->assertInstanceOf(CarbonImmutable::class, $parsed);
        $this->assertSame('2026-12-24', $parsed->format('Y-m-d'));

        $this->assertNull(EventSeriesInput::endDate(''));
        $this->assertNull(EventSeriesInput::endDate('irgendwann'));
    }

    public function testWithoutASelectionAllFieldGroupsApply(): void
    {
        $this->assertSame(EventSeriesInput::FIELD_GROUPS, EventSeriesInput::fieldGroups(null));
        $this->assertSame(EventSeriesInput::FIELD_GROUPS, EventSeriesInput::fieldGroups('title'));
    }

    public function testUnknownFieldGroupsFallAwayAndDuplicatesCountOnce(): void
    {
        $this->assertSame(
            ['title', 'audience'],
            EventSeriesInput::fieldGroups(['title', 'Audience', 'farbe', 'title'])
        );
        $this->assertSame([], EventSeriesInput::fieldGroups(['farbe']));
    }

    public function testEveryFieldGroupCarriesALabelForTheForm(): void
    {
        $options = EventSeriesInput::fieldGroupOptions();

        $this->assertCount(count(EventSeriesInput::FIELD_GROUPS), $options);
        $this->assertSame(['key' => 'title', 'label' => 'Titel und Terminart'], $options[0]);
        foreach ($options as $option) {
            $this->assertNotSame('', $option['label']);
        }
    }
}
