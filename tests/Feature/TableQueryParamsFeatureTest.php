<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\TableQueryParams;
use PHPUnit\Framework\TestCase;

class TableQueryParamsFeatureTest extends TestCase
{
    public function testSanitizesSortDirAndPagination(): void
    {
        $parsed = TableQueryParams::from([
            'sort' => 'last_name',
            'dir' => 'DESC',
            'page' => '2',
            'per_page' => '1000',
        ], ['last_name', 'first_name']);

        $this->assertSame('last_name', $parsed['sort']);
        $this->assertSame('desc', $parsed['dir']);
        $this->assertSame(2, $parsed['page']);
        $this->assertSame(100, $parsed['per_page']);
    }

    public function testFallsBackToAllowlistedDefaults(): void
    {
        $parsed = TableQueryParams::from([
            'sort' => 'dangerous_column',
            'dir' => 'sideways',
        ], ['last_name', 'first_name']);

        $this->assertSame('last_name', $parsed['sort']);
        $this->assertSame('asc', $parsed['dir']);
    }
}
