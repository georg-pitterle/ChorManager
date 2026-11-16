<?php

declare(strict_types=1);

namespace Tests\Unit\TestSuite;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tests bauen die unten genannten Controller über Tests\Feature\BuildsControllers,
 * nie mit `new`.
 *
 * Jede Baustelle mit Positionsargumenten macht die Parameterreihenfolge des
 * Konstruktors wieder zur Schnittstelle - mit den Folgen, die im Kommentar des
 * Traits stehen. Die Liste nennt die umgestellten Controller; für alle anderen
 * gilt die Regel noch nicht.
 */
final class ControllersAreBuiltThroughBuildersTest extends TestCase
{
    /** @var list<string> */
    private const MIGRATED = [
        'EventController',
        'UserController',
        'SponsorController',
        'TaskController',
        'FinanceController',
        'RegistrationController',
        'AttendanceController',
        'EvaluationController',
        'ProfileController',
        'PasswordResetController',
        'DashboardController',
        'VoiceGroupController',
    ];

    public function testMigratedControllersAreNotConstructedByHand(): void
    {
        $tests = dirname(__DIR__, 2);
        $pattern = '/new\s+\\\\?(?:App\\\\Controllers\\\\)?(' . implode('|', self::MIGRATED) . ')\s*\(/';
        $found = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tests, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($tests) + 1));
            $isHere = $relative === 'Unit/TestSuite/ControllersAreBuiltThroughBuildersTest.php';
            if ($file->getExtension() !== 'php' || $isHere || str_starts_with($relative, 'e2e/')) {
                continue;
            }

            if (preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches) > 0) {
                $found[] = $relative . ': ' . implode(', ', array_unique($matches[1]));
            }
        }

        sort($found);
        self::assertSame([], $found, "Von Hand gebaute Controller:\n" . implode("\n", $found));
    }
}
