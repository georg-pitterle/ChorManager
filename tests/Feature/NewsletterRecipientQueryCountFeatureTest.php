<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Newsletter;
use App\Models\NewsletterRecipientSource;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\NewsletterRecipientService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Ein Newsletter darf beliebig viele Termine als Empfängerquelle nennen, und
 * genau das tut der Regelfall: "Infos zur Probenwoche" nimmt die ganze Serie.
 *
 * Aufgelöst wurde jeder dieser Termine für sich - eine Abfrage für den Termin,
 * zwei für seine Zielgruppen-Filter, eine für die Mitglieder. Bei vierzig
 * Terminen einer Serie sind das über hundertsechzig Rundreisen, obwohl eine
 * Serie sich ihre Zielgruppe teilt und zwangsläufig dieselben Mitglieder
 * liefert. EventAudienceService::eligibleUserIdsForEvents() beantwortet genau
 * das für mehrere Termine auf einmal.
 *
 * Gemessen wird nicht eine absolute Zahl, sondern die Steigung: Kommen Termine
 * mit derselben Zielgruppe hinzu, darf die Abfragezahl nicht mitwachsen. Eine
 * absolute Schranke müsste bei jeder harmlosen Änderung nachgezogen werden und
 * sagte über das eigentliche Problem nichts aus.
 */
final class NewsletterRecipientQueryCountFeatureTest extends TestCase
{
    use AudienceFixtures;
    use EventScopeFixtures;

    private User $member;
    private VoiceGroup $voiceGroup;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $this->beginFixtureTransaction();

        $this->voiceGroup = VoiceGroup::create(['name' => 'Zählgruppe ' . bin2hex(random_bytes(4))]);
        $this->member = User::create([
            'email' => 'recipient_count_' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Zähl',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
        $this->member->voiceGroups()->attach($this->voiceGroup->id);
    }

    protected function tearDown(): void
    {
        $this->rollBackFixtureTransaction();

        parent::tearDown();
    }

    public function testResolvingRecipientsDoesNotQueryPerEventSource(): void
    {
        $withOne = $this->countQueries(1);
        $withFive = $this->countQueries(5);

        $this->assertSame(
            $withOne,
            $withFive,
            'Vier weitere Termine derselben Zielgruppe dürfen keine einzige Abfrage kosten'
        );
    }

    public function testEveryEventSourceStillContributesItsAudience(): void
    {
        $newsletter = $this->newsletterWithEventSources(3);

        $recipientIds = (new NewsletterRecipientService())
            ->resolveRecipients($newsletter)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertSame([(int) $this->member->id], $recipientIds);
    }

    /**
     * Legt einen Newsletter mit $eventCount Terminquellen an und zählt die
     * Abfragen, die seine Empfängerauflösung kostet.
     */
    private function countQueries(int $eventCount): int
    {
        $newsletter = $this->newsletterWithEventSources($eventCount);
        $service = new NewsletterRecipientService();

        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $service->resolveRecipients($newsletter);
        } finally {
            $count = count($connection->getQueryLog());
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        return $count;
    }

    private function newsletterWithEventSources(int $eventCount): Newsletter
    {
        $newsletter = Newsletter::create([
            'title' => 'Infos zur Probenwoche',
            'content_html' => '<p>Hallo!</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $this->member->id,
        ]);

        for ($index = 0; $index < $eventCount; $index++) {
            $event = Event::create([
                'title' => 'Zählprobe ' . $index,
                'starts_at' => Carbon::now()->addDays($index + 2)->format('Y-m-d') . ' 19:00:00',
                'ends_at' => Carbon::now()->addDays($index + 2)->format('Y-m-d') . ' 21:00:00',
                'type' => 'Probe',
            ]);
            // Dieselbe Zielgruppe für jeden Termin - eine Serie teilt sie sich.
            $this->giveAudience('event_id', (int) $event->id, ['voice_group' => [(int) $this->voiceGroup->id]]);

            NewsletterRecipientSource::create([
                'newsletter_id' => $newsletter->id,
                'source_type' => NewsletterRecipientSource::TYPE_EVENT_ATTENDEES,
                'reference_id' => $event->id,
            ]);
        }

        return $newsletter;
    }
}
