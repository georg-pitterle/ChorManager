<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the scope of the member edit deep link: only the Mitgliederliste
 * (templates/users/manage.twig) turns member names into edit links. Alle anderen
 * Listen zeigen den Namen als reinen Text, damit die Bearbeitung ein bewusster
 * Einstieg über die Mitgliederliste bleibt.
 */
final class MemberLinkCoverageFeatureTest extends TestCase
{
    /**
     * @return array<int, array{0: string}>
     */
    public static function listTemplatesWithoutEditLink(): array
    {
        return [
            ['templates/attendance/show.twig'],
            ['templates/evaluations/index.twig'],
            ['templates/evaluations/project_members.twig'],
            ['templates/projects/members.twig'],
        ];
    }

    #[DataProvider('listTemplatesWithoutEditLink')]
    public function testListTemplatesRenderNamesWithoutEditLink(string $relativePath): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);

        $this->assertIsString($template);
        $this->assertStringNotContainsString('person.member_link(', $template);
        $this->assertStringNotContainsString('can_edit_member', $template);
        $this->assertStringContainsString('person_name', $template);
    }

    public function testMemberListKeepsEditLink(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/users/manage.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('macros/person.twig', $template);
        $this->assertStringContainsString('person.member_link(', $template);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function controllersWithoutEditableMap(): array
    {
        return [
            ['src/Controllers/AttendanceController.php'],
            ['src/Controllers/EvaluationController.php'],
            ['src/Controllers/ProjectController.php'],
        ];
    }

    #[DataProvider('controllersWithoutEditableMap')]
    public function testListControllersDoNotProvideEditableMemberMap(string $relativePath): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);

        $this->assertIsString($source);
        $this->assertStringNotContainsString('editableUserIdMap', $source);
        $this->assertStringNotContainsString('can_edit_member', $source);
    }
}
