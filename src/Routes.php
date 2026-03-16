<?php

declare(strict_types=1);

use Slim\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\UserController;
use App\Controllers\ProjectController;
use App\Controllers\EventController;
use App\Controllers\AttendanceController;
use App\Controllers\EvaluationController;
use App\Controllers\RoleController;
use App\Controllers\VoiceGroupController;
use App\Controllers\FinanceController;
use App\Controllers\ProfileController;
use App\Controllers\AppSettingController;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    // Auth Routes
    $app->get('/login', [AuthController::class, 'showLogin']);
    $app->post('/login', [AuthController::class, 'processLogin']);
    $app->get('/logout', [AuthController::class, 'logout']);
    $app->get('/setup', [AuthController::class, 'showSetup']);
    $app->post('/setup', [AuthController::class, 'processSetup']);
    $app->get('/logo', [AppSettingController::class, 'logo']);

    // Password Reset Routes
    $app->get('/forgot-password', [\App\Controllers\PasswordResetController::class, 'showForgotForm']);
    $app->post('/forgot-password', [\App\Controllers\PasswordResetController::class, 'sendResetLink']);
    $app->get('/reset-password', [\App\Controllers\PasswordResetController::class, 'showResetForm']);
    $app->post('/reset-password', [\App\Controllers\PasswordResetController::class, 'processReset']);


    // Redirect root to dashboard
    $app->get(
        '/',
        function (Request $request, Response $response) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
    );

    // Protected Routes
    $app->group(
        '',
        function (RouteCollectorProxy $group) {
            $group->get('/dashboard', [DashboardController::class, 'index']);

            // Profile Routes
            $group->get('/profile', [ProfileController::class, 'index']);
            $group->post('/profile', [ProfileController::class, 'updateProfile']);
            $group->post('/profile/password', [ProfileController::class, 'updatePassword']);

            // Admin / User Management Routes
            $group->group(
                '/users',
                function (RouteCollectorProxy $userGroup) {
                    $userGroup->get('', [UserController::class, 'index']);
                    $userGroup->post('', [UserController::class, 'create']);
                    $userGroup->post('/{id:[0-9]+}', [UserController::class, 'update']);
                    $userGroup->post('/deactivate/{id:[0-9]+}', [UserController::class, 'deactivate']);
                }
            )->add(new RoleMiddleware(false, 0, true)); // allow manage_users OR userLevel >= 40

            // Here we'll add /attendance, etc.
            $group->get('/events', [EventController::class, 'index']);

            // Attendance Routes
            $group->get('/attendance', [AttendanceController::class, 'show']);
            $group->get('/attendance/{event_id:[0-9]+}', [AttendanceController::class, 'show']);
            $group->post('/attendance/{event_id:[0-9]+}', [AttendanceController::class, 'save']);

            // Evaluations - accessible for all logged-in users
            $group->get('/evaluations', [EvaluationController::class, 'index']);
            $group->get('/evaluations/project-members', [EvaluationController::class, 'projectMembers']);

            // Items requiring user management rights (Obmann, Chorleiter)
            $group->group(
                '',
                function (RouteCollectorProxy $adminGroup) {
                    $adminGroup->post('/events', [EventController::class, 'create']);
                }
            )->add(new RoleMiddleware(true)); // Global "manage users" level

            // Stammdaten Management (dedicated permission)
            $group->group(
                '',
                function (RouteCollectorProxy $masterGroup) {
                    $masterGroup->get('/projects', [ProjectController::class, 'index']);
                    $masterGroup->post('/projects', [ProjectController::class, 'create']);

                // Role Management
                    $masterGroup->get('/roles', [RoleController::class, 'index']);
                    $masterGroup->post('/roles', [RoleController::class, 'create']);
                    $masterGroup->post('/roles/{id:[0-9]+}', [RoleController::class, 'update']);

                // Voice Group Management
                    $masterGroup->get('/voice-groups', [VoiceGroupController::class, 'index']);
                    $masterGroup->post('/voice-groups', [VoiceGroupController::class, 'createGroup']);
                    $masterGroup->post('/voice-groups/{id:[0-9]+}/update', [VoiceGroupController::class, 'updateGroup']);
                    $masterGroup->post('/voice-groups/{id:[0-9]+}/delete', [VoiceGroupController::class, 'deleteGroup']);

                    $masterGroup->post('/voice-groups/{id:[0-9]+}/sub', [VoiceGroupController::class, 'createSubVoice']);
                    $masterGroup->post('/voice-groups/{id:[0-9]+}/sub/{sub_id:[0-9]+}/update', [VoiceGroupController::class, 'updateSubVoice']);
                    $masterGroup->post('/voice-groups/{id:[0-9]+}/sub/{sub_id:[0-9]+}/delete', [VoiceGroupController::class, 'deleteSubVoice']);

                // App Settings
                    $masterGroup->get('/settings', [AppSettingController::class, 'index']);
                    $masterGroup->post('/settings', [AppSettingController::class, 'save']);
                }
            )->add(new RoleMiddleware(false, 0, false, false, false, true)); // requiresMasterDataManagement


            // Finance (Kassa) Group - Needs can_manage_finances OR global manage
            $group->group(
                '',
                function ($financeGroup) {
                    $financeGroup->get('/finances', [FinanceController::class, 'index']);
                    $financeGroup->post('/finances/save', [FinanceController::class, 'save']);
                    $financeGroup->post('/finances/{id:[0-9]+}/delete', [FinanceController::class, 'delete']);
                    $financeGroup->get('/finances/report', [FinanceController::class, 'report']);
                    $financeGroup->post('/finances/settings', [FinanceController::class, 'updateSettings']);
                    $financeGroup->get('/finances/attachments/{id:[0-9]+}', [FinanceController::class, 'viewAttachment']);
                    $financeGroup->post('/finances/attachments/{id:[0-9]+}/delete', [FinanceController::class, 'deleteAttachment']);
                }
            )->add(new RoleMiddleware(false, 0, false, false, true));

            // Shared Evaluation Groups (Project Member Management)derverwaltung (eigenes Recht)
            $group->group(
                '/projects',
                function (RouteCollectorProxy $projGroup) {
                    $projGroup->get('/{id:[0-9]+}/members', [ProjectController::class, 'showMembers']);
                    $projGroup->post('/{id:[0-9]+}/members', [ProjectController::class, 'addMember']);
                    $projGroup->post('/{id:[0-9]+}/members/{user_id:[0-9]+}/remove', [ProjectController::class, 'removeMember']);
                }
            )->add(new RoleMiddleware(false, 0, false, true)); // requiresProjectMemberManagement
        }
    )->add(AuthMiddleware::class);
};
