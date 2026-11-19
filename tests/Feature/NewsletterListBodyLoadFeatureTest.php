<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\NewsletterTemplate;
use App\Models\User;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Übersichten von Newsletter und Vorlage laden nie den Mailtext.
 *
 * `newsletters.content_html` und `newsletter_templates.content_html` sind
 * `longtext`. Beide Übersichten zeigen den Text nicht an - sie brauchen Titel,
 * Zustand, Empfängerzahl und Datum -, holten ihn aber über ein `select *` für
 * jede Zeile mit, und zwar ohne Blätterwerk: `NewsletterController::index()`
 * gibt alle Newsletter eines Zustands auf einmal aus.
 *
 * Das ist dieselbe Grenze, die `MailQueue::LIST_COLUMNS` schon einmal gezogen
 * hat - `body_html` ist dort ebenfalls `longtext` -, und derselbe Fehler, der
 * das Repertoire ins Speicherlimit gefahren hat
 * (AttachmentRelationBlobLoadFeatureTest): Ein Fatal Error im Speicherlimit
 * geht an Monolog vorbei, die Seite antwortet mit einem leeren 500, und im Log
 * steht nichts.
 *
 * Der Mailtext bleibt über den Eigenschaftszugriff erreichbar - Vorschau,
 * Versand und Klonen lesen `$newsletter->content_html` direkt.
 */
final class NewsletterListBodyLoadFeatureTest extends TestCase
{
    /**
     * Die Spalten, die `newsletters/index.twig` tatsächlich anfasst, dazu die
     * beiden, über die der Aufbau der Liste selbst läuft: `project_id` und
     * `created_by` tragen die vorab geladenen Beziehungen.
     *
     * @var list<string>
     */
    private const NEWSLETTER_VIEW_COLUMNS = [
        'id',
        'project_id',
        'title',
        'status',
        'recipient_count',
        'created_by',
        'created_at',
    ];

    /**
     * Was `newsletters/templates_index.twig` und das Auswahlfeld in
     * `newsletters/create.twig` brauchen. Mehr nicht: Der Bearbeiten-Dialog
     * holt sich die Vorlage über `/newsletters/templates/{id}/edit?modal=1`
     * nach und bekommt den Text dort.
     *
     * @var list<string>
     */
    private const TEMPLATE_VIEW_COLUMNS = [
        'id',
        'name',
        'project_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testNewsletterListColumnsLeaveTheBodyOut(): void
    {
        $this->assertNotContains(
            'content_html',
            Newsletter::LIST_COLUMNS,
            'Der Mailtext ist longtext und steht in keiner Spalte der Übersicht.'
        );
        $this->assertContains(
            'id',
            Newsletter::LIST_COLUMNS,
            'Ohne id lassen sich die vorab geladenen Beziehungen nicht zuordnen.'
        );

        foreach (self::NEWSLETTER_VIEW_COLUMNS as $column) {
            $this->assertContains(
                $column,
                Newsletter::LIST_COLUMNS,
                'Die Übersicht liest ' . $column . ' und braucht die Spalte deshalb.'
            );
        }
    }

    public function testTemplateListColumnsLeaveTheBodyOut(): void
    {
        $this->assertNotContains(
            'content_html',
            NewsletterTemplate::LIST_COLUMNS,
            'Der Mailtext ist longtext und steht in keiner Spalte der Übersicht.'
        );

        foreach (self::TEMPLATE_VIEW_COLUMNS as $column) {
            $this->assertContains(
                $column,
                NewsletterTemplate::LIST_COLUMNS,
                'Die Übersicht liest ' . $column . ' und braucht die Spalte deshalb.'
            );
        }
    }

    /**
     * Jede genannte Spalte gibt es auch: Eine Liste, die auf eine entfernte
     * Spalte zeigt, fällt erst beim ersten Aufruf der Seite auf - gleiche
     * Begründung wie bei ModelSchemaConsistencyTest.
     */
    public function testEveryListColumnIsARealColumn(): void
    {
        foreach ([Newsletter::class, NewsletterTemplate::class] as $class) {
            $model = new $class();
            $existing = Capsule::connection()->getSchemaBuilder()->getColumnListing($model->getTable());

            foreach ($class::LIST_COLUMNS as $column) {
                $this->assertContains(
                    $column,
                    $existing,
                    $class . '::LIST_COLUMNS nennt ' . $column . ', die Tabelle hat die Spalte nicht.'
                );
            }
        }
    }

    public function testAListQueryKeepsTheBodyOutOfTheResult(): void
    {
        $newsletter = $this->newsletterWithBody();

        $loaded = Newsletter::query()
            ->select(Newsletter::LIST_COLUMNS)
            ->whereKey($newsletter->id)
            ->firstOrFail();

        $this->assertArrayNotHasKey('content_html', $loaded->getAttributes());
        $this->assertSame('Rundbrief', $loaded->title, 'Den Titel braucht die Übersicht weiterhin.');
    }

    public function testThePreviewPathStillReceivesTheBody(): void
    {
        $newsletter = $this->newsletterWithBody();

        $loaded = Newsletter::findOrFail($newsletter->id);

        $this->assertSame(
            strlen(self::body()),
            strlen((string) $loaded->content_html),
            'Vorschau, Versand und Klonen lesen die Eigenschaft direkt.'
        );
    }

    /**
     * Zweite Absicherung neben den Spaltenlisten: Wird ein Newsletter doch
     * einmal mit allen Spalten geladen und danach serialisiert - JSON-Antwort,
     * Logzeile, Fehlerausgabe -, bleibt der Mailtext aus der Ausgabe. Gleiche
     * Grenze wie bei `MailQueue::$body_html`.
     */
    public function testTheBodyStaysOutOfTheSerialization(): void
    {
        $body = '<p>Grüße an alle Sängerinnen und Sänger.</p>';

        foreach ([Newsletter::class, NewsletterTemplate::class] as $class) {
            $model = new $class();
            $model->setRawAttributes(['id' => 1, 'content_html' => $body]);

            $this->assertArrayNotHasKey('content_html', $model->toArray(), $class);
            $this->assertSame(
                $body,
                $model->getAttribute('content_html'),
                $class . ': Der Eigenschaftszugriff muss weiter herankommen.'
            );
        }
    }

    /**
     * Bewusst kein Kurztext: Genau die Größe ist der Grund, warum der Mailtext
     * in keine Übersicht gehört.
     */
    private static function body(): string
    {
        return str_repeat('<p>Grüße an den Chor.</p>', 2000);
    }

    private function newsletterWithBody(): Newsletter
    {
        $author = User::create([
            'email' => 'newsletter_body_' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Nora',
            'last_name' => 'Rundbrief',
            'is_active' => 1,
        ]);

        return Newsletter::create([
            'title' => 'Rundbrief',
            'content_html' => self::body(),
            'status' => Newsletter::STATUS_DRAFT,
            'recipient_count' => 0,
            'created_by' => $author->id,
        ]);
    }
}
