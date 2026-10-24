<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorController;
use App\Models\Sponsor;
use App\Models\SponsorCategory;
use App\Policies\SponsoringPolicy;
use App\Util\PasswordHasher;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Kategorie am Sponsor: wählbar aus den vorhandenen Werten, direkt im Formular
 * erweiterbar.
 *
 * In der Excel-Liste stand die Branche als Dropdown mit festen Werten eines
 * einzelnen Chors. Das Modul ist allgemein: Die Migration bringt ein paar
 * allgemeine Standard-Kategorien mit, alles Weitere entsteht beim Tippen.
 */
final class SponsorCategoryFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private const DEFAULT_CATEGORIES = [
        'Einzelhandel',
        'Gastronomie',
        'Handwerk',
        'Dienstleistung',
        'Banken & Versicherungen',
        'Öffentliche Einrichtungen',
        'Kultur',
        'Medien',
        'Privatperson',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $manager = User::create([
            'first_name' => 'Kategorie',
            'last_name' => 'Testperson',
            'email' => 'category-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
        $_SESSION = [
            'user_id' => (int) $manager->id,
            'can_manage_sponsoring' => true,
            'can_create_own_sponsorships' => false,
        ];

        // Die Suite leert Tabellen zwischen den Klassen; die Vorgaben der
        // Migration sollen hier trotzdem so dastehen wie nach einer Installation.
        foreach (self::DEFAULT_CATEGORIES as $name) {
            SponsorCategory::firstOrCreate(['name' => $name]);
        }
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

    public function testTheMigrationProvidesGeneralDefaultCategories(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 2) . '/db/migrations/20261023120000_add_sponsor_categories.php'
        );

        foreach (self::DEFAULT_CATEGORIES as $name) {
            $this->assertStringContainsString("'" . $name . "'", $migration);
        }
        $this->assertSame(self::DEFAULT_CATEGORIES, SponsorCategory::DEFAULT_NAMES);
    }

    public function testCreatingASponsorWithAnExistingCategoryInOtherCaseReusesIt(): void
    {
        $before = SponsorCategory::count();

        $this->createSponsor('Kategorietest Wirt', ' gastronomie ');

        $sponsor = Sponsor::where('name', 'Kategorietest Wirt')->firstOrFail();
        $this->assertSame(
            (int) SponsorCategory::where('name', 'Gastronomie')->value('id'),
            (int) $sponsor->category_id
        );
        $this->assertSame($before, SponsorCategory::count());
    }

    public function testCreatingASponsorWithANewCategoryCreatesIt(): void
    {
        $before = SponsorCategory::count();

        $this->createSponsor('Kategorietest Lift', 'Skigebiete');

        $sponsor = Sponsor::where('name', 'Kategorietest Lift')->firstOrFail();
        $this->assertSame($before + 1, SponsorCategory::count());
        $this->assertSame('Skigebiete', $sponsor->category?->name);
    }

    public function testSpacesInsideANewCategoryAreCollapsed(): void
    {
        $this->createSponsor('Kategorietest Bank', '  Banken   & Versicherungen ');

        $sponsor = Sponsor::where('name', 'Kategorietest Bank')->firstOrFail();
        $this->assertSame('Banken & Versicherungen', $sponsor->category?->name);
    }

    public function testAnEmptyCategoryLeavesTheSponsorWithoutCategory(): void
    {
        $this->createSponsor('Kategorietest Leer', 'Kultur');
        $sponsor = Sponsor::where('name', 'Kategorietest Leer')->firstOrFail();

        $this->controller($this->createStub(Twig::class))->update(
            $this->makeRequest('POST', '/sponsoring/sponsors/' . $sponsor->id, [
                'name' => 'Kategorietest Leer',
                'category' => '',
            ]),
            $this->makeResponse(),
            ['id' => (string) $sponsor->id]
        );

        $this->assertNull($sponsor->fresh()->category_id);
    }

    public function testATooLongCategoryIsRejected(): void
    {
        $before = SponsorCategory::count();

        $this->createSponsor('Kategorietest Lang', str_repeat('x', 101));

        $this->assertSame('Eine Kategorie darf höchstens 100 Zeichen lang sein.', $_SESSION['error'] ?? null);
        $this->assertSame($before, SponsorCategory::count());
        $this->assertFalse(Sponsor::where('name', 'Kategorietest Lang')->exists());
    }

    public function testTheSponsorListOffersTheCategoriesAsFilterAndMarksEachRow(): void
    {
        $this->createSponsor('Kategorietest Laden', 'Einzelhandel');
        $sponsor = Sponsor::where('name', 'Kategorietest Laden')->firstOrFail();

        $html = $this->renderIndex();

        $this->assertStringContainsString('data-category-options=', $html);
        $this->assertStringContainsString('data-category="' . $sponsor->category_id . '"', $html);
        // In der Unterzeile unter dem Namen, keine eigene Spalte: Eine weitere
        // Spalte ließ die Tabelle schon bei 1440 px in die Kartenansicht kippen.
        $this->assertStringContainsString('Organisation · Einzelhandel', $html);
        $this->assertStringNotContainsString('data-label="Kategorie"', $html);
    }

    public function testTheFormOffersExistingCategoriesAsDropdown(): void
    {
        $html = $this->renderIndex();

        // Ein echtes Auswahlfeld, das im Browser über Tom Select neue Werte
        // annimmt - eine datalist zeigte die Werte erst beim Tippen.
        $this->assertMatchesRegularExpression('/<select name="category"[^>]*data-sponsor-category/', $html);
        $this->assertStringContainsString('<option value="Gastronomie">Gastronomie</option>', $html);
        $this->assertStringNotContainsString('<datalist', $html);
        $this->assertStringContainsString('/js/sponsor-category-select.js', $html);
    }

    public function testTheEditFormPreselectsTheCurrentCategory(): void
    {
        $this->createSponsor('Kategorietest Auswahl', 'Kultur');
        $sponsor = Sponsor::where('name', 'Kategorietest Auswahl')->firstOrFail();

        $response = $this->controller($this->createAppTwig('/sponsoring/sponsors'))->detail(
            $this->makeRequest('GET', '/sponsoring/sponsors/' . $sponsor->id),
            $this->makeResponse(),
            ['id' => (string) $sponsor->id]
        );

        $this->assertStringContainsString('<option value="Kultur" selected>Kultur</option>', (string) $response->getBody());
    }

    private function createSponsor(string $name, string $category): void
    {
        $this->controller($this->createStub(Twig::class))->create(
            $this->makeRequest('POST', '/sponsoring/sponsors', [
                'name' => $name,
                'category' => $category,
            ]),
            $this->makeResponse()
        );
    }

    private function renderIndex(): string
    {
        $response = $this->controller($this->createAppTwig('/sponsoring/sponsors'))->index(
            $this->makeRequest('GET', '/sponsoring/sponsors'),
            $this->makeResponse()
        );
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function controller(Twig $twig): SponsorController
    {
        return new SponsorController($twig, new SponsoringPolicy($_SESSION), $this->attachmentService());
    }
}
