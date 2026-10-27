<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\RootPrivilegeDrop;
use PHPUnit\Framework\TestCase;

/**
 * Ein Backup-Befehl, der per `docker exec` (also als root) lief, legte im Datei-Pool
 * Verzeichnisse mit 0750 root:root an. PHP-FPM läuft als www-data und konnte sie
 * danach nicht mehr lesen - die Speicheranzeige meldete "Permission denied".
 */
class RootPrivilegeDropTest extends TestCase
{
    public function testDoesNothingWhenNotRoot(): void
    {
        $switched = null;
        $drop = new RootPrivilegeDrop(
            static fn (): int => 1000,
            static fn (string $name): array => ['uid' => 33, 'gid' => 33],
            static function (string $name, int $uid, int $gid) use (&$switched): bool {
                $switched = [$name, $uid, $gid];
                return true;
            }
        );

        $this->assertFalse($drop->dropTo('www-data'));
        $this->assertNull($switched);
    }

    public function testSwitchesToTheNamedUserWhenRoot(): void
    {
        $switched = null;
        $drop = new RootPrivilegeDrop(
            static fn (): int => 0,
            static fn (string $name): array => ['uid' => 33, 'gid' => 34],
            static function (string $name, int $uid, int $gid) use (&$switched): bool {
                $switched = [$name, $uid, $gid];
                return true;
            }
        );

        $this->assertTrue($drop->dropTo('www-data'));
        $this->assertSame(['www-data', 33, 34], $switched);
    }

    /**
     * Lieber gar nicht laufen als als root weiterlaufen und wieder unlesbare Dateien
     * hinterlassen.
     */
    public function testFailsLoudlyWhenTheUserIsUnknown(): void
    {
        $drop = new RootPrivilegeDrop(
            static fn (): int => 0,
            static fn (string $name): bool => false,
            static fn (string $name, int $uid, int $gid): bool => true
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('www-data');
        $drop->dropTo('www-data');
    }

    public function testFailsLoudlyWhenTheSwitchFails(): void
    {
        $drop = new RootPrivilegeDrop(
            static fn (): int => 0,
            static fn (string $name): array => ['uid' => 33, 'gid' => 33],
            static fn (string $name, int $uid, int $gid): bool => false
        );

        $this->expectException(\RuntimeException::class);
        $drop->dropTo('www-data');
    }
}
