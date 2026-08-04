<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * The edit modal is no longer rendered once per user (which bloated the /users
 * response beyond nginx's FastCGI buffer). Instead a single reusable modal shell
 * loads its form fragment lazily from GET /users/{id}/edit-form.
 */
final class UserEditLazyModalFeatureTest extends TestCase
{
    private function read(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);
        $this->assertIsString($source, $relativePath . ' must be readable');

        return $source;
    }

    public function testEditFormRouteIsRegistered(): void
    {
        $routes = $this->read('src/Routes.php');

        $this->assertStringContainsString("'/{id:[0-9]+}/edit-form'", $routes);
        $this->assertStringContainsString("[UserController::class, 'editForm']", $routes);
    }

    public function testControllerExposesEditFormEndpoint(): void
    {
        $this->assertTrue(
            method_exists(\App\Controllers\UserController::class, 'editForm'),
            'UserController::editForm() must exist'
        );

        $controller = $this->read('src/Controllers/UserController.php');
        // Endpoint must enforce edit permission through the existing policy.
        $this->assertStringContainsString('userEditPolicy->canEdit', $controller);
        // Fragment must render the extracted partial.
        $this->assertStringContainsString('partials/user_edit_form.twig', $controller);
    }

    public function testEditFormPartialExists(): void
    {
        $partial = $this->read('templates/partials/user_edit_form.twig');

        $this->assertStringContainsString('name="first_name"', $partial);
        $this->assertStringContainsString('name="roles[]"', $partial);
        $this->assertStringContainsString('name="voice_groups[]"', $partial);
        // The form still posts to the per-user update endpoint.
        $this->assertStringContainsString('action="/users/{{ user.id }}"', $partial);
    }

    public function testManageTemplateUsesSingleModalShellInsteadOfPerUserLoop(): void
    {
        $twig = $this->read('templates/users/manage.twig');

        // Single reusable shell, not one modal per user.
        $this->assertStringContainsString('id="editUserModal"', $twig);
        $this->assertStringNotContainsString('id="editUserModal{{ user.id }}"', $twig);
        $this->assertStringNotContainsString('data-bs-target="#editUserModal{{ user.id }}"', $twig);

        // Edit buttons are JS-driven and carry the target user id.
        $this->assertStringContainsString('js-edit-user', $twig);
        $this->assertStringContainsString('data-user-id="{{ user.id }}"', $twig);

        // Deep-link auto-open id is still surfaced to the client.
        $this->assertStringContainsString('open_edit_user_id', $twig);
    }

    public function testJavascriptLazyLoadsTheFragment(): void
    {
        $js = $this->read('public/js/users.js');

        $this->assertStringContainsString('/edit-form', $js);
        $this->assertStringContainsString('js-edit-user', $js);
        // vg-checkbox handling must be delegated so it works on injected markup.
        $this->assertStringContainsString("addEventListener('change'", $js);
    }

    public function testScriptsBlockIsNotNestedInsideContentBlock(): void
    {
        // Regression: when {% block scripts %} was nested inside {% block content %},
        // Twig emitted users.js twice (once inline, once via the layout placeholder).
        // The doubled DOMContentLoaded handler fetched the read-and-clear edit fragment
        // twice, so the second (now stateless) response wiped the validation message.
        $twig = $this->read('templates/users/manage.twig');

        $endContent = strpos($twig, '{% endblock content %}');
        $scriptsBlock = strpos($twig, '{% block scripts %}');

        $this->assertNotFalse($endContent);
        $this->assertNotFalse($scriptsBlock);
        $this->assertGreaterThan(
            $endContent,
            $scriptsBlock,
            'The scripts block must live at top level (after endblock content), not nested inside it.'
        );
    }

    public function testUpdateErrorReopensModalViaEditDeepLink(): void
    {
        $controller = $this->read('src/Controllers/UserController.php');

        // Validation failures during update() must redirect back with ?edit={id}
        // so the single modal shell reopens and reloads the fragment.
        $this->assertStringContainsString("'Location', '/users?edit=' . \$userId", $controller);
    }
}
