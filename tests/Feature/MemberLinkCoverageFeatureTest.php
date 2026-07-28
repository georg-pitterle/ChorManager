<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemberLinkCoverageFeatureTest extends TestCase
{
    /**
     * @return array<int, array{0: string}>
     */
    public static function managementTemplates(): array
    {
        return [
            ['templates/attendance/show.twig'],
            ['templates/evaluations/index.twig'],
            ['templates/evaluations/project_members.twig'],
            ['templates/projects/members.twig'],
        ];
    }

    #[DataProvider('managementTemplates')]
    public function testManagementTemplatesUseMemberLinkMacro(string $relativePath): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);

        $this->assertIsString($template);
        $this->assertStringContainsString('macros/person.twig', $template);
        $this->assertStringContainsString('person.member_link(', $template);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function controllers(): array
    {
        return [
            ['src/Controllers/AttendanceController.php'],
            ['src/Controllers/EvaluationController.php'],
            ['src/Controllers/ProjectController.php'],
        ];
    }

    #[DataProvider('controllers')]
    public function testControllersProvideEditableMemberMap(string $relativePath): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);

        $this->assertIsString($source);
        $this->assertStringContainsString('editableUserIdMap', $source);
        $this->assertStringContainsString('can_edit_member', $source);
    }
}
