<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudienceFilter;
use App\Models\Event;
use App\Models\Newsletter;
use App\Models\NewsletterRecipientSource;
use App\Models\Role;
use App\Models\VoiceGroup;
use App\Services\Audience\AudienceFilterService;
use PHPUnit\Framework\TestCase;

/**
 * Newsletter-Formular mit Zielgruppen-Zeilen und Terminen, Listenfilter nach
 * Art der Empfänger.
 */
final class NewsletterAudienceControllerFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testStoreSavesRowsAndEvents(): void
    {
        $author = $this->createUser('Autor');
        $role = Role::create(['name' => 'Rundbrief-Rolle ' . bin2hex(random_bytes(3)), 'hierarchy_level' => 5]);
        $event = Event::create([
            'title' => 'Probe', 'starts_at' => '2030-01-01 19:00:00', 'ends_at' => '2030-01-01 21:00:00', 'type' => 'Probe',
        ]);
        $_SESSION = ['user_id' => (int) $author->id, 'can_manage_newsletters' => true];

        $this->controller()->store(
            $this->makeRequest('POST', '/newsletters', [
                'title' => 'Mit Zielgruppe',
                'content_html' => '<p>Hallo</p>',
                'audience' => [['conditions' => ['role' => [(string) $role->id]]]],
                'event_ids' => [(string) $event->id],
            ], [], ['Accept' => 'application/json']),
            $this->makeResponse()
        );

        $newsletter = Newsletter::query()->where('title', 'Mit Zielgruppe')->firstOrFail();
        $sets = (new AudienceFilterService())->conditionSetsForOwners('newsletter_id', [(int) $newsletter->id]);
        $this->assertSame([['role' => [(int) $role->id]]], $sets[(int) $newsletter->id]);
        $this->assertSame(
            [(int) $event->id],
            NewsletterRecipientSource::query()->where('newsletter_id', $newsletter->id)->pluck('reference_id')
                ->map(static fn ($id): int => (int) $id)->all()
        );
    }

    public function testStoreRejectsRowWithOnlyDeletedValues(): void
    {
        $author = $this->createUser('Autor');
        $_SESSION = ['user_id' => (int) $author->id, 'can_manage_newsletters' => true];

        $response = $this->controller()->store(
            $this->makeRequest('POST', '/newsletters', [
                'title' => 'Kaputte Zielgruppe',
                'content_html' => '<p>Hallo</p>',
                'audience' => [['conditions' => ['role' => ['999999999']]]],
            ], [], ['Accept' => 'application/json']),
            $this->makeResponse()
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Newsletter::query()->where('title', 'Kaputte Zielgruppe')->count());
    }

    public function testListFiltersByCategory(): void
    {
        $author = $this->createUser('Autor');
        $_SESSION = ['user_id' => (int) $author->id, 'can_manage_newsletters' => true];
        $alto = VoiceGroup::create(['name' => 'Listenalt ' . bin2hex(random_bytes(3))]);
        $withVoice = Newsletter::create([
            'title' => 'An die Altstimmen ' . bin2hex(random_bytes(3)),
            'content_html' => '<p>x</p>', 'status' => Newsletter::STATUS_DRAFT, 'created_by' => (int) $author->id,
        ]);
        $withUser = Newsletter::create([
            'title' => 'An eine Person ' . bin2hex(random_bytes(3)),
            'content_html' => '<p>x</p>', 'status' => Newsletter::STATUS_DRAFT, 'created_by' => (int) $author->id,
        ]);
        (new AudienceFilterService())->create(['voice_group' => [(int) $alto->id]], 'newsletter_id', (int) $withVoice->id);
        (new AudienceFilterService())->create(['user' => [(int) $author->id]], 'newsletter_id', (int) $withUser->id);

        $html = (string) $this->controller()->index(
            $this->makeRequest('GET', '/newsletters', [], ['recipient_type' => 'voice_group']),
            $this->makeResponse()
        )->getBody();

        $this->assertStringContainsString($withVoice->title, $html);
        $this->assertStringNotContainsString($withUser->title, $html);
    }

    public function testDeletingTheNewsletterRemovesItsFilters(): void
    {
        $author = $this->createUser('Autor');
        $newsletter = Newsletter::create([
            'title' => 'Wird gelöscht', 'content_html' => '<p>x</p>',
            'status' => Newsletter::STATUS_DRAFT, 'created_by' => (int) $author->id,
        ]);
        (new AudienceFilterService())->create([], 'newsletter_id', (int) $newsletter->id);

        Newsletter::query()->whereKey($newsletter->id)->delete();

        $this->assertSame(0, AudienceFilter::query()->where('newsletter_id', $newsletter->id)->count());
    }
}
