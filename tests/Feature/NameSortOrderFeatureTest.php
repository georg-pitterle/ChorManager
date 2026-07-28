<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameSortOrderFeatureTest extends TestCase
{
    /**
     * @return array<int, array{0: string}>
     */
    public static function sortingSources(): array
    {
        return [
            ['src/Queries/UserQuery.php'],
            ['src/Queries/ProjectQuery.php'],
            ['src/Controllers/AttendanceController.php'],
            ['src/Controllers/EvaluationController.php'],
            ['src/Controllers/EventController.php'],
            ['src/Controllers/NewsletterController.php'],
            ['src/Controllers/RegistrationController.php'],
            ['src/Controllers/TaskController.php'],
        ];
    }

    #[DataProvider('sortingSources')]
    public function testNoHardcodedNameOrdering(string $relativePath): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);

        $this->assertIsString($source);
        $this->assertStringNotContainsString("orderBy('last_name')", $source);
        $this->assertStringNotContainsString("orderBy('first_name')", $source);
        $this->assertStringNotContainsString("sortBy(['last_name', 'first_name'])", $source);
        $this->assertStringContainsString('orderColumns()', $source);
    }
}
