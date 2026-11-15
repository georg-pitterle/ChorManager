<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AppSettingController;
use App\Controllers\SponsoringDashboardController;
use App\Models\AppSetting;
use App\Policies\SponsoringPolicy;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Kurzanleitung auf der Sponsoring-Übersicht bietet die Erklärvideos an,
 * deren YouTube-Kennung in den Einstellungen steht - das Mitglieder-Video für
 * alle, das Verwaltungs-Video nur mit dem Vollrecht. Die Seite lädt dabei
 * nichts von YouTube: Der Rahmen entsteht erst nach einem Klick.
 */
final class SponsoringGuideVideoFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private const MEMBER_ID = 'aB3_dE-6gH9';
    private const ADMIN_ID = 'Zz9_yY-8xX7';
    private const PORTRAIT_ID = 'Pp7_qQ-5rR3';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = ['user_id' => 1, 'can_create_own_sponsorships' => true];
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

    public function testTheSettingsStoreTheIdFromAPastedLink(): void
    {
        $this->saveSettings([
            'sponsoring_video_member' => 'https://youtu.be/' . self::MEMBER_ID,
            'sponsoring_video_admin' => 'kein Video',
        ]);

        $this->assertSame(self::MEMBER_ID, $this->setting('sponsoring_video_member'));
        $this->assertSame('', $this->setting('sponsoring_video_admin'));
    }

    /**
     * Fehlt das Feld - etwa weil das Sponsoring-Modul aus ist und die Karte
     * nicht angezeigt wird -, bleibt die gespeicherte Kennung stehen.
     */
    public function testSavingWithoutTheFieldKeepsTheStoredId(): void
    {
        $this->store('sponsoring_video_member', self::MEMBER_ID);

        $this->saveSettings([]);

        $this->assertSame(self::MEMBER_ID, $this->setting('sponsoring_video_member'));
    }

    public function testAContributorIsOfferedTheMemberVideoOnly(): void
    {
        $this->store('sponsoring_video_member', self::MEMBER_ID);
        $this->store('sponsoring_video_admin', self::ADMIN_ID);

        $html = $this->renderDashboard();

        $this->assertStringContainsString('data-video-id="' . self::MEMBER_ID . '"', $html);
        $this->assertStringNotContainsString(self::ADMIN_ID, $html);
        // Kein Rahmen und keine fremde Adresse, bevor jemand klickt.
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('youtube', strtolower((string) preg_replace('/\(YouTube\)|YouTube/', '', $html)));
    }

    public function testTheManagerIsOfferedBothVideos(): void
    {
        $_SESSION = ['user_id' => 1, 'can_manage_sponsoring' => true];
        $this->store('sponsoring_video_member', self::MEMBER_ID);
        $this->store('sponsoring_video_admin', self::ADMIN_ID);

        $html = $this->renderDashboard();

        $this->assertStringContainsString('data-video-id="' . self::MEMBER_ID . '"', $html);
        $this->assertStringContainsString('data-video-id="' . self::ADMIN_ID . '"', $html);
    }

    public function testWithoutAStoredIdTheGuideShowsNoVideo(): void
    {
        $html = $this->renderDashboard();

        $this->assertStringContainsString('data-sponsoring-guide', $html);
        $this->assertStringNotContainsString('data-video-id', $html);
    }

    /**
     * Eine von Hand in die Datenbank geschriebene Kennung wird beim Anzeigen
     * noch einmal geprüft - sie landet in der Adresse eines Rahmens.
     */
    public function testAMalformedStoredIdIsNotOffered(): void
    {
        $this->store('sponsoring_video_member', '"><script>x');

        $this->assertStringNotContainsString('data-video-id', $this->renderDashboard());
    }

    /**
     * Das Mitglieder-Video gibt es zusätzlich im Hochformat fürs Handy. Im
     * 16:9-Rahmen stünde es mit breiten schwarzen Rändern da. Die Seite trägt
     * beide Kennungen; welche geladen wird, entscheidet der Browser nach der
     * Bildschirmbreite (public/js/video-embed.js).
     */
    public function testThePortraitVersionTravelsWithTheMemberVideo(): void
    {
        $this->saveSettings([
            'sponsoring_video_member' => self::MEMBER_ID,
            'sponsoring_video_member_portrait' => 'https://youtu.be/' . self::PORTRAIT_ID,
        ]);
        $_SESSION = ['user_id' => 1, 'can_manage_sponsoring' => true];
        $this->store('sponsoring_video_admin', self::ADMIN_ID);

        $html = $this->renderDashboard();

        $this->assertSame(self::PORTRAIT_ID, $this->setting('sponsoring_video_member_portrait'));
        $this->assertSame(1, substr_count($html, 'data-video-portrait-id="' . self::PORTRAIT_ID . '"'));
        $this->assertStringContainsString('data-video-id="' . self::MEMBER_ID . '"', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function testWithoutAPortraitVersionThePhoneGetsTheRegularVideo(): void
    {
        $this->store('sponsoring_video_member', self::MEMBER_ID);
        $this->store('sponsoring_video_member_portrait', '"><script>x');

        $html = $this->renderDashboard();

        $this->assertStringContainsString('data-video-id="' . self::MEMBER_ID . '"', $html);
        $this->assertStringNotContainsString('data-video-portrait-id', $html);
    }

    /**
     * @param array<string, string> $data
     */
    private function saveSettings(array $data): void
    {
        (new AppSettingController($this->createStub(Twig::class), new NullLogger()))
            ->save($this->makeRequest('POST', '/settings', $data + ['app_name' => 'Videotest']), $this->makeResponse());
    }

    private function renderDashboard(): string
    {
        $controller = new SponsoringDashboardController(
            $this->createAppTwig('/sponsoring'),
            new NameFormatterService(),
            new SponsoringPolicy($_SESSION)
        );

        return (string) $controller
            ->index($this->makeRequest('GET', '/sponsoring'), $this->makeResponse())
            ->getBody();
    }

    private function store(string $key, string $value): void
    {
        AppSetting::updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => $value, 'binary_content' => '', 'mime_type' => 'text/plain']
        );
    }

    private function setting(string $key): ?string
    {
        return AppSetting::where('setting_key', $key)->value('setting_value');
    }
}
