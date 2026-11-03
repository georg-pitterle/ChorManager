<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DashboardController;
use App\Models\User;
use App\Policies\TaskPolicy;
use App\Services\MailQueueAdminService;
use App\Services\PollAudienceService;
use App\Services\PollVoteService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * "Offene Umfragen" auf der Startseite: nur, solange die Person noch
 * abstimmen kann - sonst gar kein Abschnitt, keine leere Fläche.
 */
final class PollDashboardFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;
    use PollFixtures;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $this->member = $this->makePollMember();
        $_SESSION = $this->pollSession($this->member);
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

    public function testAnOpenPollWithoutAnswerIsListed(): void
    {
        $poll = $this->makePoll(title: 'Probenwunsch Frühling', closesAt: Carbon::now()->addDays(3)->setTime(23, 59, 59));

        $html = $this->dashboard(true);

        $this->assertStringContainsString('Offene Umfragen', $html);
        $this->assertStringContainsString('href="/polls/' . $poll->id . '"', $html);
        $this->assertStringContainsString('noch 3 Tage', $html);
        $this->assertStringContainsString('Antwort fehlt', $html);
        // Dieselbe Zeile wie in der Übersicht, samt Frist.
        $this->assertStringContainsString('Läuft bis', $html);
    }

    public function testNothingToVoteOnMeansNoSection(): void
    {
        $voted = $this->makePoll(title: 'Schon erledigt');
        (new PollVoteService(new PollAudienceService()))->vote($voted, (int) $this->member->id, [$this->optionId($voted, 0)]);
        $this->makePoll(title: 'Vorbei', closesAt: Carbon::now()->subMinute());
        $this->makePoll(title: 'Fremd', audience: ['user' => [(int) $this->makePollMember()->id]]);

        $this->assertStringNotContainsString('Offene Umfragen', $this->dashboard(true));
    }

    public function testWithTheModuleOffThereIsNoSection(): void
    {
        $this->makePoll(title: 'Probenwunsch Frühling');

        $this->assertStringNotContainsString('Offene Umfragen', $this->dashboard(false));
    }

    private function dashboard(bool $pollsOn): string
    {
        $settings = ['modules' => ['polls' => $pollsOn]];
        $twig = $this->createAppTwig('/dashboard');
        $twig->getEnvironment()->addGlobal('settings', $settings);
        $twig->getEnvironment()->addGlobal('csrf_token', 'test-token');

        $controller = new DashboardController($twig, new MailQueueAdminService(), new TaskPolicy($_SESSION), $settings);

        return (string) $controller->index($this->makeRequest('GET', '/dashboard'), $this->makeResponse())->getBody();
    }
}
