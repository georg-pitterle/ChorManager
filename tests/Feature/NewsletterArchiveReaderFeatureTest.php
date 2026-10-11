<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\Project;
use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * Das Archiv ist eine Leseliste für Mitglieder, keine Verwaltungstabelle: Monatsgruppen,
 * eine tippbare Zeile je Newsletter, und im geöffneten Newsletter nur der Newsletter selbst -
 * ohne Empfängerzahl und Versandstatus, die nur den Vorstand etwas angehen.
 */
final class NewsletterArchiveReaderFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testArchiveGroupsNewslettersByMonthNewestFirst(): void
    {
        $creator = $this->createUser('Anna');
        $reader = $this->createUser('Georg');
        $this->archiveFor($creator, $reader, 'Probenplan Herbst', '2026-09-12 18:30:00');
        $this->archiveFor($creator, $reader, 'Konzertinfo', '2026-10-03 09:15:00');

        $body = $this->renderArchiveAs($reader);

        $this->assertStringContainsString('Oktober 2026', $body);
        $this->assertStringContainsString('September 2026', $body);
        $this->assertLessThan(
            strpos($body, 'Probenplan Herbst'),
            strpos($body, 'Konzertinfo'),
            'Der neueste Newsletter steht oben.'
        );
    }

    public function testEachEntryOpensTheReaderAndNamesSenderAndSendDate(): void
    {
        $creator = $this->createUser('Anna');
        $reader = $this->createUser('Georg');
        $newsletter = $this->archiveFor($creator, $reader, 'Konzertinfo', '2026-10-03 09:15:00');

        $body = $this->renderArchiveAs($reader);

        $this->assertStringContainsString('data-newsletter-open', $body);
        $this->assertStringContainsString('Gesendet am 03.10.2026 09:15', $body);
        $this->assertMatchesRegularExpression('/von [^<]*Anna/', $body);
    }

    public function testArchiveDropsManagementVocabulary(): void
    {
        $creator = $this->createUser('Anna');
        $reader = $this->createUser('Georg');
        $this->archiveFor($creator, $reader, 'Konzertinfo', '2026-10-03 09:15:00');

        $body = $this->renderArchiveAs($reader);

        $this->assertStringNotContainsString('Erstellt von', $body);
        $this->assertStringNotContainsString('>Aktionen<', $body);
        $this->assertStringNotContainsString('Vorschau', $body);
        $this->assertStringNotContainsString('data-table-engine', $body);
        $this->assertStringNotContainsString('Event:', $body);
    }

    public function testProjectAndEventAppearAsReaderTerms(): void
    {
        $creator = $this->createUser('Anna');
        $reader = $this->createUser('Georg');
        $project = Project::create(['name' => 'Herbstkonzert', 'description' => '']);
        $newsletter = $this->archiveFor($creator, $reader, 'Konzertinfo', '2026-10-03 09:15:00');
        $newsletter->project_id = $project->id;
        $newsletter->save();

        $body = $this->renderArchiveAs($reader);

        $this->assertStringContainsString('Herbstkonzert', $body);
    }

    public function testRecentNewsletterCarriesRelativeLabel(): void
    {
        $creator = $this->createUser('Anna');
        $reader = $this->createUser('Georg');
        $this->archiveFor($creator, $reader, 'Heute verschickt', date('Y-m-d H:i:s'));
        $this->archiveFor($creator, $reader, 'Alt', '2024-01-10 10:00:00');

        $body = $this->renderArchiveAs($reader);

        $this->assertStringContainsString('>heute<', $body);
        $this->assertSame(1, substr_count($body, 'class="event-relative"'));
    }

    public function testArchiveListsOnlyNewslettersSentToTheReader(): void
    {
        $creator = $this->createUser('Anna');
        $reader = $this->createUser('Georg');
        $other = $this->createUser('Lisa');
        $this->archiveFor($creator, $reader, 'Für Georg', '2026-10-03 09:15:00');
        $this->archiveFor($creator, $other, 'Nur für Lisa', '2026-10-04 09:15:00');

        $body = $this->renderArchiveAs($reader);

        $this->assertStringContainsString('Für Georg', $body);
        $this->assertStringNotContainsString('Nur für Lisa', $body);
    }

    public function testEmptyArchiveShowsFriendlyStateWithoutListChrome(): void
    {
        $reader = $this->createUser('Georg');

        $body = $this->renderArchiveAs($reader);

        $this->assertStringContainsString('Noch keine Newsletter', $body);
        $this->assertStringContainsString('class="event-empty"', $body);
        $this->assertStringNotContainsString('id="newsletterArchiveSearch"', $body);
        $this->assertStringNotContainsString('0 versendete', $body);
    }

    public function testSearchAppearsOnlyForLongerArchives(): void
    {
        $creator = $this->createUser('Anna');
        $reader = $this->createUser('Georg');
        $this->archiveFor($creator, $reader, 'Einzelner', '2026-10-03 09:15:00');

        $this->assertStringNotContainsString('id="newsletterArchiveSearch"', $this->renderArchiveAs($reader));

        for ($i = 1; $i <= 9; $i++) {
            $this->archiveFor($creator, $reader, 'Rundschreiben ' . $i, sprintf('2026-0%d-01 10:00:00', $i));
        }

        $this->assertStringContainsString('id="newsletterArchiveSearch"', $this->renderArchiveAs($reader));
    }

    public function testReaderViewInModalShowsOnlyTheNewsletter(): void
    {
        $creator = $this->createUser('Anna');
        $reader = $this->createUser('Georg');
        $newsletter = $this->archiveFor($creator, $reader, 'Konzertinfo', '2026-10-03 09:15:00');

        $body = $this->renderPreviewAs($reader, $newsletter, true);

        $this->assertStringContainsString('data-newsletter-reader', $body);
        $this->assertStringContainsString('newsletter-preview-frame', $body);
        $this->assertStringNotContainsString('Empfänger:', $body);
        $this->assertStringNotContainsString('Status:', $body);
        $this->assertStringNotContainsString('Erstellt:', $body);
        $this->assertStringNotContainsString('card-footer', $body);
    }

    public function testReaderViewAsPageNamesTheSendDateOfTheReader(): void
    {
        $creator = $this->createUser('Anna');
        $reader = $this->createUser('Georg');
        $newsletter = $this->archiveFor($creator, $reader, 'Konzertinfo', '2026-10-03 09:15:00');

        $body = $this->renderPreviewAs($reader, $newsletter, false);

        $this->assertStringContainsString('Gesendet am 03.10.2026 09:15', $body);
        $this->assertStringNotContainsString('Newsletter-Vorschau</h1>', $body);
        $this->assertStringNotContainsString('Status:', $body);
    }

    /**
     * Auch wer Newsletter verwaltet, hat ein eigenes Archiv - und liest dort, ohne
     * Vorstandsdaten. Bisher fand der Lesedialog in der Verwaltungsansicht keinen Lesebereich.
     */
    public function testManagerReadingFromOwnArchiveGetsReaderView(): void
    {
        $creator = $this->createUser('Anna');
        $newsletter = $this->archiveFor($creator, $creator, 'Konzertinfo', '2026-10-03 09:15:00');

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        $request = $this->makeRequest(
            'GET',
            "/newsletters/{$newsletter->id}/preview",
            [],
            ['modal' => '1', 'reader' => '1']
        )->withAttribute('id', (string) $newsletter->id);
        $body = (string) $this->controller()->preview($request, $this->makeResponse())->getBody();

        $this->assertStringContainsString('data-newsletter-reader', $body);
        $this->assertStringNotContainsString('Status:', $body);
    }

    public function testArchiveLinksRequestReaderView(): void
    {
        $creator = $this->createUser('Anna');
        $reader = $this->createUser('Georg');
        $newsletter = $this->archiveFor($creator, $reader, 'Konzertinfo', '2026-10-03 09:15:00');

        $body = $this->renderArchiveAs($reader);

        $this->assertStringContainsString(
            'data-newsletter-url="/newsletters/' . $newsletter->id . '/preview?modal=1&amp;reader=1"',
            $body
        );
        $this->assertStringContainsString('href="/newsletters/' . $newsletter->id . '/preview?reader=1"', $body);
    }

    public function testManagerPreviewNamesStatusInGerman(): void
    {
        $creator = $this->createUser('Anna');
        $reader = $this->createUser('Georg');
        $newsletter = $this->archiveFor($creator, $reader, 'Konzertinfo', '2026-10-03 09:15:00');

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview")
            ->withAttribute('id', (string) $newsletter->id);
        $body = (string) $this->controller()->preview($request, $this->makeResponse())->getBody();

        $this->assertStringContainsString('Versendet', $body);
        $this->assertStringNotContainsString('<strong>sent</strong>', $body);
        $this->assertStringNotContainsString('text-bg-info', $body);
    }

    public function testArchivePageUsesReaderScriptWithoutEditor(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/newsletters/archive.twig');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/newsletter-archive.js');

        $this->assertStringNotContainsString('tinymce', $template);
        $this->assertStringNotContainsString('/js/newsletters.js', $template);
        $this->assertStringContainsString('/js/newsletter-archive.js', $template);
        $this->assertStringContainsString('modal-fullscreen-sm-down', $template);
        $this->assertStringContainsString('data-reader-step="newer"', $template);
        $this->assertStringContainsString('data-reader-step="older"', $template);

        $this->assertStringContainsString('data-reader-step', $script);
        $this->assertStringContainsString('ArrowRight', $script);
        $this->assertStringContainsString('Erneut versuchen', $script);
    }

    private function archiveFor(User $creator, User $recipient, string $title, string $sentAt): Newsletter
    {
        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => $title,
            'content_html' => '<p>{{anrede}}</p>',
            'status' => Newsletter::STATUS_SENT,
            'recipient_count' => 1,
            'created_by' => $creator->id,
            'sent_at' => $sentAt,
        ]);

        (new \App\Services\Audience\AudienceFilterService())
            ->create(['user' => [(int) $recipient->id]], 'newsletter_id', (int) $newsletter->id);

        NewsletterArchive::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $recipient->id,
            'email' => $recipient->email,
            'sent_at' => $sentAt,
        ]);

        return $newsletter;
    }

    private function renderArchiveAs(User $reader): string
    {
        $_SESSION['user_id'] = (int) $reader->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest('GET', '/newsletters/archive');

        return (string) $this->controller()->archive($request, $this->makeResponse())->getBody();
    }

    private function renderPreviewAs(User $reader, Newsletter $newsletter, bool $modal): string
    {
        $_SESSION['user_id'] = (int) $reader->id;
        $_SESSION['can_manage_newsletters'] = false;

        $query = $modal ? ['modal' => '1'] : [];
        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview", [], $query)
            ->withAttribute('id', (string) $newsletter->id);

        return (string) $this->controller()->preview($request, $this->makeResponse())->getBody();
    }
}
