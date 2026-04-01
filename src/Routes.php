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
use App\Controllers\TaskController;
use App\Controllers\AttendanceController;
use App\Controllers\EvaluationController;
use App\Controllers\RoleController;
use App\Controllers\VoiceGroupController;
use App\Controllers\FinanceController;
use App\Controllers\ProfileController;
use App\Controllers\AppSettingController;
use App\Controllers\EventTypeController;
use App\Controllers\DevSeedController;
use App\Controllers\SponsoringDashboardController;
use App\Controllers\SponsorController;
use App\Controllers\SponsorshipController;
use App\Controllers\SponsoringContactController;
use App\Controllers\SponsorPackageController;
use App\Controllers\SongLibraryController;
use App\Controllers\NewsletterController;
use App\Controllers\DownloadController;
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
    $app->get('/theme.css', [AppSettingController::class, 'themeCss']);

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

    // Healthcheck endpoint
    $app->get('/health', function (Request $request, Response $response) {
        $response->getBody()->write('OK');
        return $response->withHeader('Content-Type', 'text/plain');
    });


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
                    $userGroup->post('/bulk-deactivate', [UserController::class, 'bulkDeactivate']);
                    $userGroup->post('/restore/{id:[0-9]+}', [UserController::class, 'restore']);
                }
            )->add(new RoleMiddleware(false, 0, true)); // allow manage_users OR userLevel >= 40

            // Here we'll add /attendance, etc.
            $group->get('/events', [EventController::class, 'index']);

            // Attendance Routes
            $group->get('/attendance', [AttendanceController::class, 'show']);
            $group->get('/attendance/{event_id:[0-9]+}', [AttendanceController::class, 'show']);
            $group->post('/attendance/{event_id:[0-9]+}', [AttendanceController::class, 'save']);

            // Download section for project members
            $group->get('/downloads', [DownloadController::class, 'index']);
            $group->get(
                '/downloads/attachments/{attachment_id:[0-9]+}/download',
                [DownloadController::class, 'downloadAttachment']
            );
            $group->get('/downloads/attachments/{attachment_id:[0-9]+}/stream', [DownloadController::class, 'streamAttachment']);

            // Evaluations - accessible for all logged-in users
            $group->get('/evaluations', [EvaluationController::class, 'index']);
            $group->get('/evaluations/project-members', [EvaluationController::class, 'projectMembers']);

            // Items requiring user management rights (Obmann, Chorleiter)
            $group->group(
                '',
                function (RouteCollectorProxy $adminGroup) {
                    $adminGroup->post('/events', [EventController::class, 'create']);
                    $adminGroup->get('/events/{id:[0-9]+}/edit', [EventController::class, 'edit']);
                    $adminGroup->post('/events/{id:[0-9]+}/update', [EventController::class, 'update']);
                    $adminGroup->post('/events/{id:[0-9]+}/delete', [EventController::class, 'delete']);
                    $adminGroup->post('/events/{id:[0-9]+}/delete-series', [EventController::class, 'deleteSeries']);
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

                    $masterGroup->post(
                        '/voice-groups/{id:[0-9]+}/sub',
                        [
                            VoiceGroupController::class,
                            'createSubVoice'
                        ]
                    );
                    $masterGroup->post(
                        '/voice-groups/{id:[0-9]+}/sub/{sub_id:[0-9]+}/update',
                        [
                            VoiceGroupController::class,
                            'updateSubVoice'
                        ]
                    );
                    $masterGroup->post(
                        '/voice-groups/{id:[0-9]+}/sub/{sub_id:[0-9]+}/delete',
                        [
                            VoiceGroupController::class,
                            'deleteSubVoice'
                        ]
                    );

                    // Event Type Management
                    $masterGroup->get('/event-types', [EventTypeController::class, 'index']);
                    $masterGroup->post('/event-types', [EventTypeController::class, 'create']);
                    $masterGroup->post('/event-types/{id:[0-9]+}/update', [EventTypeController::class, 'update']);
                    $masterGroup->post('/event-types/{id:[0-9]+}/delete', [EventTypeController::class, 'delete']);

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
                    $financeGroup->get(
                        '/finances/attachments/{id:[0-9]+}',
                        [FinanceController::class, 'viewAttachment']
                    );
                    $financeGroup->post(
                        '/finances/attachments/{id:[0-9]+}/delete',
                        [FinanceController::class, 'deleteAttachment']
                    );
                }
            )->add(new RoleMiddleware(false, 0, false, false, true));

            // Shared Evaluation Groups (Project Member Management)derverwaltung (eigenes Recht)
            $group->group(
                '/projects',
                function (RouteCollectorProxy $projGroup) {
                    $projGroup->get('/{id:[0-9]+}/members', [ProjectController::class, 'showMembers']);
                    $projGroup->post('/{id:[0-9]+}/members', [ProjectController::class, 'addMember']);
                    $projGroup->post(
                        '/{id:[0-9]+}/members/{user_id:[0-9]+}/remove',
                        [ProjectController::class, 'removeMember']
                    );
                    $projGroup->get('/{project_id:[0-9]+}/tasks', [TaskController::class, 'index']);
                    $projGroup->post('/{project_id:[0-9]+}/tasks', [TaskController::class, 'create']);
                }
            )->add(new RoleMiddleware(false, 0, false, true)); // requiresProjectMemberManagement

            // Task Detail Routes (accessible by project members or task managers)
            $group->group(
                '/tasks',
                function (RouteCollectorProxy $taskGroup) {
                    $taskGroup->get('/{id:[0-9]+}', [TaskController::class, 'detail']);
                    $taskGroup->post('/{id:[0-9]+}/update', [TaskController::class, 'update']);
                    $taskGroup->post('/{id:[0-9]+}/delete', [TaskController::class, 'delete']);
                    $taskGroup->post('/{id:[0-9]+}/comments', [TaskController::class, 'addComment']);
                    $taskGroup->post('/{id:[0-9]+}/attachments', [TaskController::class, 'uploadAttachment']);
                    $taskGroup->get('/{id:[0-9]+}/attachments/{attachment_id:[0-9]+}/download', [TaskController::class, 'downloadAttachment']);
                    $taskGroup->post('/{id:[0-9]+}/attachments/{attachment_id:[0-9]+}/delete', [TaskController::class, 'deleteAttachment']);
                }
            )->add(new RoleMiddleware(false, 0, false, false, false, false, false, false, true)); // requiresTaskManagement

            // Sponsoring Routes
            $group->group(
                '/sponsoring',
                function (RouteCollectorProxy $sponsoringGroup) {
                    $sponsoringGroup->get('', [SponsoringDashboardController::class, 'index']);

                    // Sponsor-Stammdaten
                    $sponsoringGroup->get('/sponsors', [SponsorController::class, 'index']);
                    $sponsoringGroup->post('/sponsors', [SponsorController::class, 'create']);
                    $sponsoringGroup->get('/sponsors/{id:[0-9]+}', [SponsorController::class, 'detail']);
                    $sponsoringGroup->post('/sponsors/{id:[0-9]+}', [SponsorController::class, 'update']);
                    $sponsoringGroup->post('/sponsors/{id:[0-9]+}/delete', [SponsorController::class, 'delete']);

                    // Vereinbarungen
                    $sponsoringGroup->post('/sponsorships', [SponsorshipController::class, 'create']);
                    $sponsoringGroup->post('/sponsorships/{id:[0-9]+}', [SponsorshipController::class, 'update']);
                    $sponsoringGroup->post('/sponsorships/{id:[0-9]+}/delete', [SponsorshipController::class, 'delete']);
                    $sponsoringGroup->get(
                        '/sponsorships/{id:[0-9]+}/attachments/{attachment_id:[0-9]+}',
                        [SponsorshipController::class, 'downloadAttachment']
                    );
                    $sponsoringGroup->post(
                        '/sponsorships/{id:[0-9]+}/attachments/{attachment_id:[0-9]+}/delete',
                        [SponsorshipController::class, 'deleteAttachment']
                    );

                    // Kontakthistorie
                    $sponsoringGroup->post('/contacts', [SponsoringContactController::class, 'create']);
                    $sponsoringGroup->post('/contacts/{id:[0-9]+}/done', [SponsoringContactController::class, 'markDone']);
                    $sponsoringGroup->post('/contacts/{id:[0-9]+}/delete', [SponsoringContactController::class, 'delete']);

                    // Paketverwaltung
                    $sponsoringGroup->get('/packages', [SponsorPackageController::class, 'index']);
                    $sponsoringGroup->post('/packages', [SponsorPackageController::class, 'create']);
                    $sponsoringGroup->post('/packages/{id:[0-9]+}', [SponsorPackageController::class, 'update']);
                    $sponsoringGroup->post('/packages/{id:[0-9]+}/delete', [SponsorPackageController::class, 'delete']);
                }
            )->add(new RoleMiddleware(false, 0, false, false, false, false, true));

            // Song library management
            $group->group(
                '/song-library',
                function (RouteCollectorProxy $songsGroup) {
                    $songsGroup->get('', [SongLibraryController::class, 'index']);
                    $songsGroup->post('/songs', [SongLibraryController::class, 'createSong']);
                    $songsGroup->post('/songs/{id:[0-9]+}/update', [SongLibraryController::class, 'updateSong']);
                    $songsGroup->post('/songs/{id:[0-9]+}/delete', [SongLibraryController::class, 'deleteSong']);
                    $songsGroup->post('/songs/{id:[0-9]+}/attachments', [SongLibraryController::class, 'uploadAttachments']);
                    $songsGroup->post(
                        '/songs/{song_id:[0-9]+}/attachments/{attachment_id:[0-9]+}/delete',
                        [SongLibraryController::class, 'deleteAttachment']
                    );
                }
            )->add(new RoleMiddleware(false, 0, false, false, false, false, false, true));

            // Newsletter management
            $group->get('/newsletters', [NewsletterController::class, 'index']);
            $group->get('/newsletters/create', [NewsletterController::class, 'create']);
            $group->post('/newsletters', [NewsletterController::class, 'store']);
            $group->get('/newsletters/{id:[0-9]+}/edit', [NewsletterController::class, 'edit']);
            $group->post('/newsletters/{id:[0-9]+}', [NewsletterController::class, 'update']);
            $group->get('/newsletters/{id:[0-9]+}/preview', [NewsletterController::class, 'preview']);
            $group->post('/newsletters/{id:[0-9]+}/send', [NewsletterController::class, 'send']);
            $group->post('/newsletters/{id:[0-9]+}/save-as-template', [NewsletterController::class, 'saveAsTemplate']);
            $group->get('/newsletters/template/{id:[0-9]+}', [NewsletterController::class, 'getTemplate']);
            $group->get('/newsletters/{id:[0-9]+}/check-lock', [NewsletterController::class, 'checkLock']);
            $group->post('/newsletters/{id:[0-9]+}/delete', [NewsletterController::class, 'deleteDraft']);

            // Dev-only seed endpoint, still protected by admin permission.
            $group->post('/dev/seed', [DevSeedController::class, 'run'])
                ->add(new RoleMiddleware(true));
        }
    )->add(AuthMiddleware::class);
};
