<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProfileController;
use App\Models\Project;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\MailCredentialCryptoService;
use App\Services\NameFormatterService;
use App\Services\PasswordPolicyService;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class ProfileFeatureTest extends TestCase
{
    public function testProfileStructureExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\ProfileController::class));
        $this->assertTrue(method_exists(\App\Controllers\ProfileController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\ProfileController::class, 'updateProfile'));
        $this->assertTrue(method_exists(\App\Controllers\ProfileController::class, 'updatePassword'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/profile'", $routesContent);
        $this->assertStringContainsString("'/profile/password'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/profile/index.twig'));
    }

    public function testIndexPassesParticipatingProjectsToView(): void
    {
        Bootstrap::setupTestDatabase();

        $user = User::create([
            'first_name' => 'Project',
            'last_name' => 'Member',
            'email' => 'project.member.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('Old-Password-1', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $joined = Project::create([
            'name' => 'Weihnachtskonzert 2026',
            'description' => 'Adventskonzert',
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-24',
        ]);
        $other = Project::create([
            'name' => 'Sommerkonzert 2026',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);
        $user->projects()->attach($joined->id);

        $captured = null;
        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())->method('render')->willReturnCallback(
            function ($response, string $template, array $data) use (&$captured) {
                $captured = $data;

                return $response;
            }
        );

        $controller = new ProfileController(
            $twig,
            new UserQuery(new NameFormatterService()),
            new PasswordPolicyService(),
            new Logger('test'),
            new MailCredentialCryptoService()
        );

        $_SESSION = ['user_id' => $user->id];

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/profile');
        $controller->index($request, new SlimResponse());

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('projects', $captured);

        $names = array_map(static fn ($project): string => (string) $project->name, iterator_to_array($captured['projects']));
        $this->assertContains('Weihnachtskonzert 2026', $names);
        $this->assertNotContains('Sommerkonzert 2026', $names);

        $user->projects()->detach();
        $joined->delete();
        $other->delete();
        $user->delete();
        $_SESSION = [];
    }

    public function testUpdatePasswordLogsPasswordChangedEventWithoutLeakingPasswords(): void
    {
        Bootstrap::setupTestDatabase();

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $user = User::create([
            'first_name' => 'Password',
            'last_name' => 'Changer',
            'email' => 'password.changer.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('Old-Password-1', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $controller = new ProfileController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new PasswordPolicyService(),
            $logger,
            new MailCredentialCryptoService()
        );

        $_SESSION = ['user_id' => $user->id];

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/profile/password')
            ->withParsedBody([
                'old_password' => 'Old-Password-1',
                'new_password' => 'New-Password-2',
                'new_password_confirm' => 'New-Password-2',
            ]);

        $controller->updatePassword($request, new SlimResponse());

        $records = $handler->getRecords();
        $match = array_values(array_filter(
            $records,
            static fn ($record): bool => ($record->context['event'] ?? null) === 'auth.password.changed'
        ));

        $this->assertNotEmpty($match);
        $this->assertSame((int) $user->id, $match[0]->context['user_id']);

        foreach ($records as $record) {
            $this->assertStringNotContainsString('Old-Password-1', (string) json_encode($record->context));
            $this->assertStringNotContainsString('New-Password-2', (string) json_encode($record->context));
        }

        $user->delete();
        $_SESSION = [];
    }

    public function testUpdateMailboxLogsMailCredentialsChangedWithoutLeakingPassword(): void
    {
        Bootstrap::setupTestDatabase();

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $user = User::create([
            'first_name' => 'Mailbox',
            'last_name' => 'Owner',
            'email' => 'mailbox.owner.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('Old-Password-1', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $controller = new ProfileController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new PasswordPolicyService(),
            $logger,
            new MailCredentialCryptoService()
        );

        $_SESSION = ['user_id' => $user->id];

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/profile/mailbox')
            ->withParsedBody([
                'imap_host' => 'imap.example.test',
                'imap_port' => '993',
                'imap_encryption' => 'ssl',
                'imap_username' => 'mailbox-user',
                'imap_password' => 'Super-Secret-Mailbox-1',
            ]);

        $controller->updateMailbox($request, new SlimResponse());

        $records = $handler->getRecords();
        $match = array_values(array_filter(
            $records,
            static fn ($record): bool => ($record->context['event'] ?? null) === 'mail.credentials.changed'
        ));

        $this->assertNotEmpty($match);

        foreach ($records as $record) {
            $this->assertStringNotContainsString('Super-Secret-Mailbox-1', (string) json_encode($record->context));
        }

        \App\Models\UserMailAccount::where('user_id', $user->id)->delete();
        $user->delete();
        $_SESSION = [];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'too long for column' => [str_repeat('a', 250) . '@example.test'],
            'malformed format' => ['not-an-email'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEmailProvider')]
    public function testUpdateProfileRejectsInvalidEmailWithoutDatabaseError(string $invalidEmail): void
    {
        Bootstrap::setupTestDatabase();

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $originalEmail = 'profile.owner.' . bin2hex(random_bytes(4)) . '@example.test';
        $user = User::create([
            'first_name' => 'Profile',
            'last_name' => 'Owner',
            'email' => $originalEmail,
            'password' => password_hash('Old-Password-1', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $controller = new ProfileController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new PasswordPolicyService(),
            $logger,
            new MailCredentialCryptoService()
        );

        $_SESSION = ['user_id' => $user->id];

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/profile')
            ->withParsedBody([
                'first_name' => 'Valid',
                'last_name' => 'User',
                'email' => $invalidEmail,
            ]);

        $controller->updateProfile($request, new SlimResponse());

        // Validation short-circuits before the DB write: no QueryException logged.
        foreach ($handler->getRecords() as $record) {
            $this->assertNotSame('profile.update.failed', $record->context['event'] ?? null);
        }

        // A validation error is surfaced and the stored email stays untouched.
        $this->assertArrayHasKey('error', $_SESSION);
        $this->assertSame($originalEmail, User::find($user->id)->email);

        $user->delete();
        $_SESSION = [];
    }
}
