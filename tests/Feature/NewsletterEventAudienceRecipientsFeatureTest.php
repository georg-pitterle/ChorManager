<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Newsletter;
use App\Models\NewsletterRecipientSource;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\NewsletterRecipientService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Newsletter-Quelle zu einem Termin meint die Zielgruppe des Termins,
 * nicht seine Anwesenheitsliste.
 *
 * Aufgelöst wurde bisher über `attendances.status = 'present'`. Diese Spalte
 * füllt sich erst, nachdem der Termin stattgefunden hat und jemand die Liste
 * eingetragen hat. In der Auswahl stehen aber alle Termine: Wer einen
 * bevorstehenden nahm - der übliche Fall, "Infos zur Probe am Freitag" -,
 * bekam kommentarlos null Empfänger. Der Newsletter liess sich dann gar nicht
 * senden, ohne dass irgendwo stand, woran es lag.
 *
 * Massgeblich ist jetzt dieselbe Zielgruppe, über die auch die Einladung und
 * die Anwesenheitsliste laufen (`Event::eligibleUsersQuery()`). Damit meint
 * dieselbe Auswahl im Formular auch überall dasselbe.
 */
final class NewsletterEventAudienceRecipientsFeatureTest extends TestCase
{
    use EventScopeFixtures;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $this->beginFixtureTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackFixtureTransaction();
    }

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "event_audience_{$suffix}@example.test",
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }

    private function createEventForVoiceGroup(VoiceGroup $voiceGroup, Carbon $startsAt): Event
    {
        $event = Event::create([
            'title' => 'Probe ' . bin2hex(random_bytes(4)),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
            'type' => 'Probe',
        ]);

        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_VOICE_GROUP,
            'reference_id' => $voiceGroup->id,
        ]);

        return $event;
    }

    private function newsletterForEvent(Event $event, User $creator): Newsletter
    {
        $newsletter = Newsletter::create([
            'title' => 'Infos zur Probe',
            'content_html' => '<p>Hallo!</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_EVENT_ATTENDEES,
            'reference_id' => $event->id,
        ]);

        return $newsletter;
    }

    public function testAnUpcomingEventStillResolvesItsAudience(): void
    {
        $voiceGroup = VoiceGroup::create(['name' => 'Testgruppe ' . bin2hex(random_bytes(4))]);

        $inAudience = $this->createUser();
        $inAudience->voiceGroups()->attach($voiceGroup->id);
        $outsider = $this->createUser();

        // Ein Termin in der Zukunft: Niemand kann dort "anwesend" sein.
        $event = $this->createEventForVoiceGroup($voiceGroup, Carbon::now()->addWeek());
        $newsletter = $this->newsletterForEvent($event, $inAudience);

        $recipientIds = (new NewsletterRecipientService())
            ->resolveRecipients($newsletter)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains(
            (int) $inAudience->id,
            $recipientIds,
            'Wer zur Zielgruppe des Termins gehört, muss den Newsletter bekommen.'
        );
        $this->assertNotContains((int) $outsider->id, $recipientIds);
    }

    public function testAttendanceDoesNotDecideWhoGetsTheNewsletter(): void
    {
        $voiceGroup = VoiceGroup::create(['name' => 'Testgruppe ' . bin2hex(random_bytes(4))]);

        $absent = $this->createUser();
        $absent->voiceGroups()->attach($voiceGroup->id);
        $presentButOutside = $this->createUser();

        $event = $this->createEventForVoiceGroup($voiceGroup, Carbon::now()->subWeek());
        $newsletter = $this->newsletterForEvent($event, $absent);

        // Abwesend, gehört aber zur Zielgruppe.
        Attendance::create([
            'event_id' => $event->id,
            'user_id' => $absent->id,
            'status' => 'absent',
        ]);
        // Anwesend eingetragen, gehört aber nicht zur Zielgruppe - etwa als
        // Gast. Die Nachlese zum Termin geht trotzdem an die Zielgruppe.
        Attendance::create([
            'event_id' => $event->id,
            'user_id' => $presentButOutside->id,
            'status' => 'present',
        ]);

        $recipientIds = (new NewsletterRecipientService())
            ->resolveRecipients($newsletter)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains((int) $absent->id, $recipientIds);
        $this->assertNotContains((int) $presentButOutside->id, $recipientIds);
    }
}
