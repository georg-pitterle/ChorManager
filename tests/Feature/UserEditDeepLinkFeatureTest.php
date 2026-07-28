<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class UserEditDeepLinkFeatureTest extends TestCase
{
    private function controllerSource(): string
    {
        $source = file_get_contents(
            dirname(__DIR__) . '/../src/Controllers/UserController.php'
        );
        $this->assertIsString($source);

        return $source;
    }

    public function testControllerEvaluatesEditQueryParameter(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString("\$params['edit']", $source);
        $this->assertStringContainsString('open_edit_user_id', $source);
    }

    public function testControllerUsesPolicyForRowPermissions(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString('UserEditPolicy', $source);
        $this->assertStringContainsString('can_edit_member', $source);
    }

    public function testTemplateLinksNameAndOpensModalFromDeepLink(): void
    {
        $template = file_get_contents(
            dirname(__DIR__) . '/../templates/users/manage.twig'
        );

        $this->assertIsString($template);
        $this->assertStringContainsString('macros/person.twig', $template);
        $this->assertStringContainsString('person.member_link(user, user.id, can_edit_member)', $template);
        $this->assertStringContainsString('open_edit_user_id', $template);
        $this->assertStringNotContainsString(
            '<td data-label="Name">{{ user.first_name }} {{ user.last_name }}</td>',
            $template
        );
    }
}
