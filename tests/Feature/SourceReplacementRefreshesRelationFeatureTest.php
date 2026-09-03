<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Newsletter;
use App\Models\NewsletterRecipientSource;
use App\Models\User;
use App\Services\EventAudienceService;
use App\Services\NewsletterRecipientService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Zielgruppen und Empfängerquellen werden beim Speichern ersetzt: gelöscht und
 * neu angelegt. Eine bereits geladene Beziehung weiß davon nichts und trägt
 * danach den alten Stand.
 *
 * Für die Empfängerquellen ist das kein Schönheitsfehler: setSources() löst am
 * Ende selbst die Empfänger auf und läse dabei genau diese veraltete Liste -
 * der Newsletter ginge an die vorherige Zielgruppe.
 */
final class SourceReplacementRefreshesRelationFeatureTest extends TestCase
{
    private User $first;
    private User $second;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->first = $this->createUser('Erste', 'Empfängerin');
        $this->second = $this->createUser('Zweiter', 'Empfänger');
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEventAudienceSourcesAreFreshAfterReplacement(): void
    {
        $event = Event::create([
            'title' => 'Probe mit Zielgruppenwechsel',
            'starts_at' => Carbon::now()->addDays(3)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(3)->setTime(21, 0),
            'type' => 'Probe',
        ]);

        $service = new EventAudienceService();
        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $this->first->id],
        ]);

        // So kommt der Termin aus einer Übersicht: mit vorab geladener Zielgruppe.
        $loaded = Event::query()->with('audienceSources')->findOrFail($event->id);

        $service->setSources($loaded, [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $this->second->id],
        ]);

        $referenceIds = $loaded->audienceSources
            ->map(static fn ($source): int => (int) $source->reference_id)
            ->all();

        $this->assertSame([(int) $this->second->id], $referenceIds);
        $this->assertSame(
            [(int) $this->second->id],
            $service->eligibleUserIdsForEvents([$loaded])[(int) $loaded->id]
        );
    }

    public function testNewsletterRecipientsFollowTheNewSourcesEvenWhenPreloaded(): void
    {
        $newsletter = Newsletter::create([
            'title' => 'Rundbrief mit Quellenwechsel',
            'content_html' => '<p>Hallo</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => (int) $this->first->id,
        ]);

        $service = new NewsletterRecipientService();
        $service->setSources($newsletter, [
            ['type' => NewsletterRecipientSource::TYPE_USER, 'reference_id' => (int) $this->first->id],
        ]);

        $loaded = Newsletter::query()->with('recipientSources')->findOrFail($newsletter->id);

        $service->setSources($loaded, [
            ['type' => NewsletterRecipientSource::TYPE_USER, 'reference_id' => (int) $this->second->id],
        ]);

        $storedRecipients = $service->getRecipients((int) $loaded->id)
            ->map(static fn ($recipient): int => (int) $recipient->user_id)
            ->all();

        $this->assertSame([(int) $this->second->id], $storedRecipients);
        $this->assertSame(1, (int) $loaded->recipient_count);
        $this->assertSame(
            [(int) $this->second->id],
            $service->resolveRecipients($loaded)->map(static fn ($user): int => (int) $user->id)->all()
        );
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'email' => 'source.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => 1,
        ]);
    }
}
