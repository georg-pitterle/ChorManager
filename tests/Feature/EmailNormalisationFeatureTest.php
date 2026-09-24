<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProfileController;
use App\Controllers\UserController;
use App\Models\Role;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Policies\UserEditPolicy;
use App\Queries\UserQuery;
use App\Services\MailCredentialCryptoService;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Services\PasswordPolicyService;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * E-Mail-Adressen werden kleingeschrieben gespeichert.
 *
 * Das Zurücksetzen des Passworts schreibt die Adresse seit immer klein
 * (PasswordResetController::sendResetLink), das Anlegen und Bearbeiten eines
 * Mitglieds bisher so, wie sie eingegeben wurde. Heute fällt das nicht auf, weil
 * die Kollation der Tabelle Groß- und Kleinschreibung gleich behandelt - würde
 * die Datenbank je auf eine _bin-Kollation umgestellt, fände das Zurücksetzen
 * das Konto nicht mehr.
 */
final class EmailNormalisationFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private string $suffix = '';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
        $this->suffix = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    public function testCreateStoresTheAddressInLowerCase(): void
    {
        $role = Role::create(['name' => 'Mitglied ' . $this->suffix, 'hierarchy_level' => 10]);
        $_SESSION['can_manage_users'] = true;
        $_SESSION['role_level'] = 100;

        $mixedCase = 'Max.Mustermann.' . $this->suffix . '@Example.TEST';

        $this->userController()->create(
            $this->makeRequest('POST', '/users', [
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
                'email' => '  ' . $mixedCase . '  ',
                'roles' => [(string) $role->id],
            ]),
            $this->makeResponse()
        );

        // Nachgeschlagen wird über den Vornamen, nicht über die Adresse: Die
        // Kollation der Tabelle behandelt Groß- und Kleinschreibung gleich, ein
        // `where('email', ...)` fände die Zeile also in beiden Fällen und die
        // Prüfung ginge ins Leere. Verglichen wird der gespeicherte Text selbst.
        $stored = User::where('first_name', 'Max')
            ->where('last_name', 'Mustermann')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($stored, 'Kein Mitglied angelegt: ' . ($_SESSION['error'] ?? 'kein Fehler'));
        $this->assertSame(strtolower($mixedCase), (string) $stored->email);
    }

    public function testProfileUpdateStoresTheAddressInLowerCase(): void
    {
        $user = $this->member('profil-' . $this->suffix . '@example.test');
        $_SESSION['user_id'] = (int) $user->id;

        $this->profileController()->updateProfile(
            $this->makeRequest('POST', '/profile', [
                'first_name' => 'Rita',
                'last_name' => 'Testperson',
                'email' => 'Rita.Neu.' . $this->suffix . '@Example.TEST',
            ]),
            $this->makeResponse()
        );

        $this->assertSame(
            strtolower('Rita.Neu.' . $this->suffix . '@Example.TEST'),
            (string) $user->fresh()->email
        );
    }

    /**
     * Wer die Adresse nicht bearbeiten darf, soll sie mit einem Speichern der
     * übrigen Felder auch nicht kleingeschrieben zurückschreiben - das löste ein
     * `user.email.changed` aus, obwohl niemand die Adresse angefasst hat.
     */
    public function testUpdateLeavesAStoredAddressAloneWhenTheFieldIsReadOnly(): void
    {
        $role = Role::create(['name' => 'Mitglied ' . $this->suffix, 'hierarchy_level' => 10]);
        $stored = 'Bestand.' . $this->suffix . '@Example.TEST';
        $user = $this->member($stored);
        $user->roles()->attach($role->id);

        // can_edit_users fehlt: Das Adressfeld ist dann nur lesend.
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['role_level'] = 100;

        $this->userController()->update(
            $this->makeRequest('POST', '/users/' . $user->id, [
                'first_name' => 'Bestand',
                'last_name' => 'Testperson',
                'email' => 'jemand.anderes@example.test',
                'roles' => [(string) $role->id],
            ]),
            $this->makeResponse(),
            ['id' => (string) $user->id]
        );

        $this->assertSame($stored, (string) $user->fresh()->email);
    }

    private function member(string $email): User
    {
        return User::create([
            'first_name' => 'Rita',
            'last_name' => 'Testperson',
            'email' => $email,
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }

    private function userController(): UserController
    {
        $logger = new NullLogger();

        return new UserController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new UserPersistence($logger),
            new ProjectPersistence(),
            $this->createStub(MailQueueService::class),
            $logger,
            new UserEditPolicy()
        );
    }

    private function profileController(): ProfileController
    {
        return new ProfileController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new PasswordPolicyService(),
            new NullLogger(),
            // `final` - lässt sich nicht doubeln, also der echte Dienst. Dieser
            // Test verschlüsselt ohnehin nichts.
            new MailCredentialCryptoService()
        );
    }
}
