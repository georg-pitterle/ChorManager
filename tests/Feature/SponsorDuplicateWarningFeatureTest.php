<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorController;
use App\Models\Sponsor;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Services\ModalFormService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Dublettenwarnung beim Anlegen und Umbenennen eines Sponsors.
 *
 * In der Excel-Liste färbte sich ein doppelt eingetragener Firmenname rot. Das
 * Modul nahm jeden Namen stillschweigend an. Jetzt kommt ein Hinweis mit Link
 * zum vorhandenen Sponsor - als Hinweis, nicht als Sperre: Zwei Filialen
 * derselben Kette können legitim zwei Sponsoren sein.
 */
final class SponsorDuplicateWarningFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private Sponsor $existing;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $manager = User::create([
            'first_name' => 'Dublette',
            'last_name' => 'Testperson',
            'email' => 'duplicate-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
        $_SESSION = [
            'user_id' => (int) $manager->id,
            'can_manage_sponsoring' => true,
            'can_create_own_sponsorships' => false,
        ];

        $this->existing = Sponsor::create(['name' => 'Bäckerei Dublettenhuber GmbH']);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    public function testCreatingASimilarNameWarnsAndDoesNotCreate(): void
    {
        $before = Sponsor::count();

        $this->create(['name' => 'bäckerei dublettenhuber', 'contact_person' => 'Frau Huber']);

        $this->assertSame($before, Sponsor::count());
        $state = (new ModalFormService('sponsor_create'))->getState();
        $this->assertTrue($state['open_modal']);
        $this->assertSame('bäckerei dublettenhuber', $state['form']['name']);
        $this->assertSame('Frau Huber', $state['form']['contact_person']);
        $this->assertSame(
            ['duplicate_id' => (int) $this->existing->id, 'duplicate_name' => 'Bäckerei Dublettenhuber GmbH'],
            $state['error']
        );
    }

    public function testConfirmingTheDuplicateCreatesTheSponsor(): void
    {
        $before = Sponsor::count();

        $this->create(['name' => 'bäckerei dublettenhuber', 'confirm_duplicate' => '1']);

        $this->assertSame($before + 1, Sponsor::count());
        $this->assertFalse((new ModalFormService('sponsor_create'))->getState()['open_modal']);
    }

    public function testADifferentNameIsCreatedWithoutWarning(): void
    {
        $this->create(['name' => 'Bäckerei Dublettenhubert']);

        $this->assertTrue(Sponsor::where('name', 'Bäckerei Dublettenhubert')->exists());
    }

    public function testRenamingToASimilarNameWarns(): void
    {
        $other = Sponsor::create(['name' => 'Ganz anderer Dublettensponsor']);

        $this->update($other, ['name' => 'Bäckerei Dublettenhuber KG']);

        $this->assertSame('Ganz anderer Dublettensponsor', $other->fresh()->name);
        $state = (new ModalFormService('sponsor_edit_' . $other->id))->getState();
        $this->assertTrue($state['open_modal']);
        $this->assertSame((int) $this->existing->id, $state['error']['duplicate_id']);
    }

    public function testSavingASponsorWithoutRenamingDoesNotWarnAboutItself(): void
    {
        $this->update($this->existing, ['name' => 'Bäckerei Dublettenhuber GmbH', 'phone' => '0512 1234']);

        $this->assertSame('0512 1234', $this->existing->fresh()->phone);
        $this->assertFalse((new ModalFormService('sponsor_edit_' . $this->existing->id))->getState()['open_modal']);
    }

    public function testTheWarningLinksTheExistingSponsor(): void
    {
        $this->create(['name' => 'bäckerei dublettenhuber']);

        $response = $this->controller($this->createAppTwig('/sponsoring/sponsors'))->index(
            $this->makeRequest('GET', '/sponsoring/sponsors'),
            $this->makeResponse()
        );
        $html = (string) $response->getBody();

        $this->assertStringContainsString('Es gibt bereits einen ähnlichen Sponsor', $html);
        $this->assertStringContainsString('href="/sponsoring/sponsors/' . $this->existing->id . '"', $html);
        $this->assertStringContainsString('name="confirm_duplicate"', $html);
        $this->assertStringContainsString('Trotzdem anlegen', $html);
        $this->assertStringContainsString('value="bäckerei dublettenhuber"', $html);
        // Gelesen ist gelesen: Ein Neuladen öffnet den Dialog nicht noch einmal.
        $this->assertFalse((new ModalFormService('sponsor_create'))->getState()['open_modal']);
    }

    public function testTheRenameWarningShowsOnTheDetailPage(): void
    {
        $other = Sponsor::create(['name' => 'Ganz anderer Dublettensponsor']);
        $this->update($other, ['name' => 'Bäckerei Dublettenhuber KG']);

        $response = $this->controller($this->createAppTwig('/sponsoring/sponsors'))->detail(
            $this->makeRequest('GET', '/sponsoring/sponsors/' . $other->id),
            $this->makeResponse(),
            ['id' => (string) $other->id]
        );
        $html = (string) $response->getBody();

        $this->assertStringContainsString('Trotzdem speichern', $html);
        $this->assertStringContainsString('value="Bäckerei Dublettenhuber KG"', $html);
    }

    /** @param array<string, string> $data */
    private function create(array $data): void
    {
        $this->controller($this->createStub(Twig::class))->create(
            $this->makeRequest('POST', '/sponsoring/sponsors', $data),
            $this->makeResponse()
        );
    }

    /** @param array<string, string> $data */
    private function update(Sponsor $sponsor, array $data): void
    {
        $this->controller($this->createStub(Twig::class))->update(
            $this->makeRequest('POST', '/sponsoring/sponsors/' . $sponsor->id, $data),
            $this->makeResponse(),
            ['id' => (string) $sponsor->id]
        );
    }

    private function controller(Twig $twig): SponsorController
    {
        return new SponsorController($twig, new SponsoringPolicy($_SESSION), $this->attachmentService());
    }
}
