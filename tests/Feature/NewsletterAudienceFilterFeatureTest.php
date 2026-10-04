<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Newsletter;
use App\Services\Audience\InvalidAudienceFilterException;
use App\Services\NewsletterRecipientService;
use PHPUnit\Framework\TestCase;

class NewsletterAudienceFilterFeatureTest extends TestCase
{
    use FileFixtures;
    use AudienceFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function newsletter(): Newsletter
    {
        return Newsletter::create([
            'title' => 'Info ' . bin2hex(random_bytes(3)),
            'content_html' => '<p>Hallo</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => (int) $this->createMember('Autor')->id,
        ]);
    }

    public function testRecipientsAreFilterMembersOrEventAudience(): void
    {
        $sopranoInProject = $this->createMember('Sopran');
        $altoInProject = $this->createMember('Alt');
        $eventGuest = $this->createMember('Gast');
        $soprano = $this->createVoiceGroupFor($sopranoInProject);
        $project = $this->createProjectFor($sopranoInProject);
        $altoInProject->projects()->attach($project->id);
        $event = Event::create([
            'title' => 'Probe', 'starts_at' => '2030-01-01 19:00:00', 'ends_at' => '2030-01-01 21:00:00', 'type' => 'Probe',
        ]);
        $this->giveAudience('event_id', (int) $event->id, ['user' => [(int) $eventGuest->id]]);

        $service = new NewsletterRecipientService();
        $newsletter = $this->newsletter();
        $service->setAudience(
            $newsletter,
            [['voice_group' => [(int) $soprano->id], 'project' => [(int) $project->id]]],
            [(int) $event->id]
        );

        $ids = $service->resolveRecipients($newsletter->fresh())->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $expected = [(int) $sopranoInProject->id, (int) $eventGuest->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertSame(2, (int) $newsletter->fresh()->recipient_count);
    }

    public function testWithoutRowsAndEventsNobodyIsRecipient(): void
    {
        $this->createMember();
        $service = new NewsletterRecipientService();
        $newsletter = $this->newsletter();
        $service->setAudience($newsletter, [], []);

        $this->assertCount(0, $service->resolveRecipients($newsletter->fresh()));
    }

    public function testRowWithOnlyDeletedRoleIsRejected(): void
    {
        $this->expectException(InvalidAudienceFilterException::class);
        (new NewsletterRecipientService())->readAudience(['audience' => [['conditions' => ['role' => ['999999999']]]]]);
    }

    public function testDeletingNewsletterRemovesFilters(): void
    {
        $service = new NewsletterRecipientService();
        $newsletter = $this->newsletter();
        $service->setAudience($newsletter, [[]], []);
        $id = (int) $newsletter->id;

        Newsletter::query()->whereKey($id)->delete();

        $this->assertSame(0, \App\Models\AudienceFilter::query()->where('newsletter_id', $id)->count());
    }
}
