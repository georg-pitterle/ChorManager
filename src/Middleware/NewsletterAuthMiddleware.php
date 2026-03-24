<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use Slim\Psr7\Response as Psr7Response;

class NewsletterAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $userId = $_SESSION['user_id'] ?? null;

        if (!$userId) {
            return $this->forbiddenResponse('Authentifizierung erforderlich');
        }

        $path = $request->getUri()->getPath();

        // Extract newsletter ID or project ID from route
        if (preg_match('/\/newsletters\/(\d+)/', $path, $matches)) {
            $newsletterId = (int)$matches[1];

            // Check if user can access/edit this newsletter
            if (!$this->canAccessNewsletter($userId, $newsletterId, $request->getMethod())) {
                return $this->forbiddenResponse('Keine Berechtigung für diesen Newsletter');
            }
        } elseif (preg_match('/\/newsletters\?project_id=(\d+)/', $request->getUri()->getQuery(), $matches)) {
            $projectId = (int)$matches[1];

            if (!$this->canAccessProject($userId, $projectId)) {
                return $this->forbiddenResponse('Keine Berechtigung für dieses Projekt');
            }
        }

        return $handler->handle($request);
    }

    private function canAccessNewsletter(int $userId, int $newsletterId, string $method): bool
    {
        $newsletter = Newsletter::find($newsletterId);
        if (!$newsletter) {
            return false;
        }

        $user = User::find($userId);
        if (!$user) {
            return false;
        }

        // Admin users can access all newsletters
        $roles = $user->roles()->pluck('roles.name')->toArray();
        if (in_array('Admin', $roles)) {
            return true;
        }

        // Creator can always access
        if ($newsletter->created_by === $userId) {
            return true;
        }

        // Project members can access their project newsletters
        $isProjectMember = $user->projects()
            ->where('project_id', $newsletter->project_id)
            ->exists();

        return $isProjectMember;
    }

    private function canAccessProject(int $userId, int $projectId): bool
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }

        // Admin users can access all projects
        $roles = $user->roles()->pluck('roles.name')->toArray();
        if (in_array('Admin', $roles)) {
            return true;
        }

        // Check if user is project member
        return $user->projects()
            ->where('project_id', $projectId)
            ->exists();
    }

    private function forbiddenResponse(string $message): Response
    {
        $response = new Psr7Response(403);
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
