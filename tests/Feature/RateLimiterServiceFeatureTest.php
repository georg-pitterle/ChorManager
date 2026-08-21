<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\RateLimiterService;
use PHPUnit\Framework\TestCase;

class RateLimiterServiceFeatureTest extends TestCase
{
    private string $storeDir;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm_rate_limit_test_' . uniqid('', true);
        @mkdir($this->storeDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storeDir)) {
            return;
        }

        foreach ((array) glob($this->storeDir . DIRECTORY_SEPARATOR . '*') as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testBlocksWhenAttemptsExceedLimit(): void
    {
        $limiter = new RateLimiterService($this->storeDir);

        $first = $limiter->hit('login:test', 2, 60);
        $second = $limiter->hit('login:test', 2, 60);
        $third = $limiter->hit('login:test', 2, 60);

        $this->assertTrue($first['allowed']);
        $this->assertTrue($second['allowed']);
        $this->assertFalse($third['allowed']);
        $this->assertGreaterThan(0, $third['retry_after']);
    }

    /**
     * Ein festes Zeitfenster lässt an der Fenstergrenze bis zu doppelt so viele
     * Versuche durch: Der Zähler springt auf null, obwohl die Versuche von
     * gerade eben noch keine Minute zurückliegen.
     */
    public function testTheLimitAlsoHoldsAcrossTheWindowBoundary(): void
    {
        $now = 1000;
        $limiter = new RateLimiterService($this->storeDir, function () use (&$now): int {
            return $now;
        });

        $this->assertTrue($limiter->hit('login:boundary', 3, 60)['allowed']);

        $now = 1059;
        $this->assertTrue($limiter->hit('login:boundary', 3, 60)['allowed']);
        $this->assertTrue($limiter->hit('login:boundary', 3, 60)['allowed']);

        // Der Versuch von t=1000 ist raus, die beiden von t=1059 nicht.
        $now = 1061;
        $this->assertTrue($limiter->hit('login:boundary', 3, 60)['allowed']);
        $this->assertFalse(
            $limiter->hit('login:boundary', 3, 60)['allowed'],
            'Innerhalb von 60 Sekunden dürfen nie mehr als 3 Versuche durchgehen.'
        );
    }

    public function testAttemptsFallOutOfTheWindowAgain(): void
    {
        $now = 5000;
        $limiter = new RateLimiterService($this->storeDir, function () use (&$now): int {
            return $now;
        });

        $limiter->hit('login:expiry', 2, 60);
        $limiter->hit('login:expiry', 2, 60);
        $this->assertFalse($limiter->hit('login:expiry', 2, 60)['allowed']);

        $now = 5000 + 61;
        $this->assertTrue(
            $limiter->hit('login:expiry', 2, 60)['allowed'],
            'Nach Ablauf des Fensters muss wieder ein Versuch möglich sein.'
        );
    }

    /**
     * Auch ein abgelehnter Versuch zählt als Versuch. Wer weiter hämmert,
     * schiebt die Freigabe damit vor sich her - genau das soll eine Bremse
     * gegen Brute Force leisten.
     */
    public function testRetryAfterPointsAtTheMomentTheNextAttemptBecomesPossible(): void
    {
        $now = 8000;
        $limiter = new RateLimiterService($this->storeDir, function () use (&$now): int {
            return $now;
        });

        $limiter->hit('login:retry', 2, 60);
        $now = 8030;
        $limiter->hit('login:retry', 2, 60);

        $now = 8040;
        $blocked = $limiter->hit('login:retry', 2, 60);

        $this->assertFalse($blocked['allowed']);
        // Im Fenster liegen jetzt 8000, 8030 und der abgelehnte von 8040. Platz
        // ist wieder, sobald der von 8030 herausfällt: 8030 + 60 - 8040 = 50.
        $this->assertSame(50, $blocked['retry_after']);

        $now = 8090;
        $this->assertTrue($limiter->hit('login:retry', 2, 60)['allowed']);
    }

    public function testResetClearsCounters(): void
    {
        $limiter = new RateLimiterService($this->storeDir);
        $limiter->hit('forgot:test', 1, 60);
        $blocked = $limiter->hit('forgot:test', 1, 60);
        $this->assertFalse($blocked['allowed']);

        $limiter->reset('forgot:test');
        $afterReset = $limiter->hit('forgot:test', 1, 60);
        $this->assertTrue($afterReset['allowed']);
    }

    /**
     * Jeder Schlüssel legt eine eigene Datei an - eine je Mailadresse und je
     * IP-Adresse. Ohne Aufräumen bleiben sie für immer im Temp-Verzeichnis
     * liegen, obwohl ihr Inhalt nach wenigen Minuten wertlos ist.
     */
    public function testStaleFilesAreCleanedUp(): void
    {
        $now = 1_000_000;
        $limiter = new RateLimiterService($this->storeDir, function () use (&$now): int {
            return $now;
        });

        $limiter->hit('login:alt@example.test', 5, 60);
        $this->assertCount(1, $this->storeFiles());

        // Einen Tag später ist der alte Eintrag längst aus jedem Fenster
        // gefallen; der nächste Zugriff räumt ihn weg.
        $now += 86_400 + 60;
        $limiter->hit('login:neu@example.test', 5, 60);

        $files = $this->storeFiles();
        $this->assertCount(1, $files, 'Nur der frische Eintrag darf übrig bleiben.');
    }

    /**
     * Aufgeräumt wird nur, was wirklich alt ist: Eine laufende Sperre darf der
     * Kehraus nicht aufheben.
     */
    public function testAnActiveCounterSurvivesTheCleanup(): void
    {
        $now = 1_000_000;
        $limiter = new RateLimiterService($this->storeDir, function () use (&$now): int {
            return $now;
        });

        $limiter->hit('login:alt@example.test', 2, 3600);

        // Einen Tag später ist der alte Eintrag reif für den Kehraus. Der
        // laufende Zähler entsteht im selben Moment und muss ihn überleben.
        $now += 86_400 + 60;
        $this->assertTrue($limiter->hit('login:aktiv@example.test', 2, 3600)['allowed']);
        $this->assertTrue($limiter->hit('login:aktiv@example.test', 2, 3600)['allowed']);
        $this->assertFalse($limiter->hit('login:aktiv@example.test', 2, 3600)['allowed']);
    }

    /**
     * @return list<string>
     */
    private function storeFiles(): array
    {
        return array_values(array_filter(
            (array) glob($this->storeDir . DIRECTORY_SEPARATOR . '*.json'),
            static fn(string $file): bool => is_file($file)
        ));
    }
}
