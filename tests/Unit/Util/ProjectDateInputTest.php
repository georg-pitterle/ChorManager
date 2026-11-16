<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\ProjectDateInput;
use PHPUnit\Framework\TestCase;

/**
 * Projektdaten und Logo-Fristen aus dem Formular: gültiges Datum, leer oder abgelehnt.
 *
 * Die Regeln standen im ProjectController, und der SponsoringDashboardController
 * rief sie von dort, damit dieselbe Regel an beiden Stellen gilt. Ein Wert, den
 * Carbon nicht lesen kann, endete sonst als 500 statt als Formularhinweis, und
 * `2026-13-45` rechnete Carbon stillschweigend auf den 14.02.2027 weiter.
 */
final class ProjectDateInputTest extends TestCase
{
    public function testMissingDeadlineFieldsBecomeNullOnANewProject(): void
    {
        self::assertSame(
            ['logo_deadline_print' => null, 'logo_deadline_online' => null],
            ProjectDateInput::logoDeadlines([], true)
        );
    }

    public function testMissingDeadlineFieldsAreLeftAloneOnAnExistingProject(): void
    {
        self::assertSame([], ProjectDateInput::logoDeadlines([], false));
    }

    public function testAnOverflowingDateIsRejected(): void
    {
        self::assertFalse(ProjectDateInput::logoDeadlines(['logo_deadline_print' => '2026-13-45'], false));
    }

    public function testAnEmptyFieldClearsTheDeadline(): void
    {
        self::assertSame(
            ['logo_deadline_online' => null],
            ProjectDateInput::logoDeadlines(['logo_deadline_online' => ''], false)
        );
    }
}
