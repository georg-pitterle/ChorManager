<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\SheetArchive;
use App\Models\SheetArchiveLineItem;
use App\Models\Song;
use App\Models\User;
use App\Newsletter\RenderContext;
use App\Services\NameFormatterService;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\SheetArchiveService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;
use Throwable;

/**
 * Befunde des rotierenden Code-Reviews von `src/Services`.
 *
 * Jeder Test hält einen einzelnen Fehler fest, der beim Durchgang aufgefallen ist,
 * damit er nicht stillschweigend zurückkehrt.
 */
final class ServiceLayerReviewFixesFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Die Empfängerzeilen werden entdoppelt angelegt, `recipient_count` zählte
     * dagegen die rohe Eingabe. Beide Angaben stehen nebeneinander in der
     * Oberfläche - eine höhere Zahl als Zeilen liest sich wie verlorene Post.
     */
    public function testRecipientCountMatchesTheNumberOfStoredRecipientRows(): void
    {
        $creator = $this->createUser();
        $recipient = $this->createUser();

        $newsletter = Newsletter::create([
            'title' => 'Doppelte Empfängerkennung',
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => (int) $creator->id,
        ]);

        $service = new NewsletterRecipientService();
        $service->setRecipients($newsletter, [(int) $recipient->id, (int) $recipient->id]);

        $storedRows = $service->getRecipients((int) $newsletter->id)->count();

        $this->assertSame(1, $storedRows, 'Je Mitglied darf nur eine Empfängerzeile entstehen.');
        $this->assertSame(
            $storedRows,
            (int) $newsletter->fresh()->recipient_count,
            'recipient_count muss die tatsächlich angelegten Empfängerzeilen zählen.'
        );
    }

    /**
     * Löschen und Neuanlegen der Positionen gehören zusammen. Scheitert eine
     * Position, stand das Archiv bisher ohne jede Position da - der alte Stand war
     * bereits gelöscht, der neue nie vollständig geschrieben.
     */
    public function testFailedLineItemWriteLeavesThePreviousArchiveDataIntact(): void
    {
        $song = Song::create([
            'title' => 'Review-Probe ' . bin2hex(random_bytes(4)),
            'composer' => 'Testkomponist',
        ]);

        $service = new SheetArchiveService();
        $service->saveArchiveData((int) $song->id, 'ARCH-100', 'Regal A', [
            ['voice_category' => 'Sopran', 'count' => 5],
            ['voice_category' => 'Alt', 'count' => 4],
        ]);

        $archiveId = (int) SheetArchive::where('song_id', (int) $song->id)->firstOrFail()->id;
        $this->assertSame(2, SheetArchiveLineItem::where('sheet_archive_id', $archiveId)->count());

        // Der Wert überschreitet den Wertebereich der Spalte `count` (INT) und
        // scheitert deshalb erst beim Schreiben, nicht schon in der Prüfung.
        $overflowing = [
            ['voice_category' => 'Sopran', 'count' => 7],
            ['voice_category' => 'Bass', 'count' => '99999999999999'],
        ];

        $failed = false;
        try {
            $service->saveArchiveData((int) $song->id, 'ARCH-100', 'Regal A', $overflowing);
        } catch (Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Ein nicht speicherbarer Wert muss den Aufruf scheitern lassen.');

        $remaining = SheetArchiveLineItem::where('sheet_archive_id', $archiveId)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $remaining, 'Der vorherige Stand muss vollständig erhalten bleiben.');
        $this->assertSame(
            ['Sopran', 'Alt'],
            $remaining->pluck('voice_category')->all()
        );
    }

    /**
     * Der Platzhalter `archiv_link` liefert fertiges Markup und wird deshalb nicht
     * escaped. Die Basisadresse darin stammt ohne gesetztes APP_URL aus dem
     * Host-Kopf der Anfrage - ein Anführungszeichen brach damit aus dem
     * href-Attribut aus und konnte eigenes Markup in die Mail schreiben.
     */
    public function testArchiveLinkEscapesTheBaseUrlBeforeBuildingTheAnchor(): void
    {
        $context = new RenderContext(
            appName: 'Chor-Manager',
            baseUrl: 'https://chor.example"><script>alert(1)</script><a href="',
            newsletterId: 42,
            title: 'Probenplan',
            projectName: '',
            senderName: 'Anna Berger',
            date: '27.08.2026'
        );

        $service = new NewsletterPlaceholderService(new NameFormatterService());
        $rendered = $service->renderHtml('<p>{{ archiv_link }}</p>', $context, null);

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&quot;', $rendered);
        $this->assertStringContainsString('/newsletters/42/preview', $rendered);
    }

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "service_review_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }
}
