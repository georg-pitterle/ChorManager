<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\MailBranding;
use App\Util\NotificationType;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Jede Benachrichtigungs-Vorlage einmal wirklich rendern.
 *
 * twigcs prüft den Stil, nicht die Sprache: Ein Filter, den diese Installation
 * gar nicht geladen hat, und ein Konstrukt, das Twig 3 nicht kennt, kommen dort
 * sauber durch und fallen erst beim Versand auf - also genau dann, wenn die
 * Mail schon jemandem fehlt.
 */
final class NotificationTemplatesRenderFeatureTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function templates(): array
    {
        $task = (object) [
            'id' => 7,
            'name' => 'Saalreservierung bestätigen',
            'status' => 'Offen',
            'priority' => 'Hoch',
            'end_date' => new \DateTimeImmutable('2026-09-15'),
            'project' => (object) ['id' => 3, 'name' => 'HS 2026 - Lametta und Legato'],
        ];

        $event = (object) [
            'id' => 11,
            'title' => 'Hauptprobe',
            'starts_at' => new \DateTimeImmutable('2026-09-20 19:00'),
            'ends_at' => new \DateTimeImmutable('2026-09-20 21:30'),
            'location' => 'Pfarrsaal Zentrum',
        ];

        $secondEvent = (object) [
            'id' => 12,
            'title' => 'Hauptprobe',
            'starts_at' => new \DateTimeImmutable('2026-09-27 19:00'),
            'ends_at' => new \DateTimeImmutable('2026-09-27 21:30'),
            'location' => 'Pfarrsaal Zentrum',
        ];

        $contact = (object) [
            'id' => 4,
            'contact_date' => new \DateTimeImmutable('2026-08-01'),
            'follow_up_date' => new \DateTimeImmutable('2026-09-01'),
            'summary' => str_repeat('Sehr ausführliche Notiz. ', 20),
            'sponsor' => (object) ['id' => 2, 'name' => 'Bäckerei Steinmüller'],
        ];

        $project = (object) [
            'id' => 3,
            'name' => 'HS 2026 - Lametta und Legato',
            'description' => 'Herbstprojekt mit Adventprogramm.',
            'start_date' => new \DateTimeImmutable('2026-09-01'),
            'end_date' => new \DateTimeImmutable('2026-12-20'),
        ];

        return [
            'task_assigned' => ['emails/notification_task_assigned.twig', [
                'task' => $task,
                'actor_name' => 'Clara Stimmig',
                'co_assignees' => ['Robert Mehrstimm', 'Patricia Vogel'],
            ]],
            'task_comment' => ['emails/notification_task_comment.twig', [
                'task' => $task,
                'actor_name' => 'Clara Stimmig',
                'comment_text' => 'Der Saal ist reserviert, Bestätigung liegt vor.',
            ]],
            'task_due_soon' => ['emails/notification_task_due_soon.twig', ['task' => $task]],
            'event_created_single' => ['emails/notification_event_created.twig', [
                'events' => [$event],
                'series_title' => 'Hauptprobe',
            ]],
            'event_created_series' => ['emails/notification_event_created.twig', [
                'events' => array_fill(0, 20, $event),
                'series_title' => 'Wöchentliche Probe',
            ]],
            'event_changed' => ['emails/notification_event_changed.twig', [
                'event' => $event,
                'changes' => [
                    ['label' => 'Beginn', 'before' => '20.09.2026 19:00 Uhr', 'after' => '20.09.2026 20:00 Uhr'],
                    ['label' => 'Ort', 'before' => 'Pfarrsaal Zentrum', 'after' => 'Aula der Musikschule'],
                ],
            ]],
            'event_cancelled_single' => ['emails/notification_event_cancelled.twig', ['events' => [$event]]],
            'event_cancelled_series' => ['emails/notification_event_cancelled.twig', [
                'events' => [$event, $secondEvent],
            ]],
            'event_note' => ['emails/notification_event_note.twig', [
                'event' => $event,
                'actor_name' => 'Sandra Harmonisch-Lenz',
                'note_text' => 'Bitte Notenmappen mitbringen.',
            ]],
            'project_member_added' => ['emails/notification_project_member_added.twig', ['project' => $project]],
            'sponsoring_follow_up_due' => ['emails/notification_sponsoring_follow_up_due.twig', [
                'contact' => $contact,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('templates')]
    public function testTemplateRenders(string $template, array $context): void
    {
        Bootstrap::setupTestDatabase();

        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');

        $html = $twig->fetch($template, array_merge(MailBranding::resolve(), $context, [
            'user' => (object) ['first_name' => 'Anna', 'last_name' => 'Amsel'],
            'notification_label' => 'Test-Anlass',
            'profile_url' => 'https://chor.example/profile',
            'link' => 'https://chor.example/ziel',
        ]));

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('Anna', $html, 'Die Anrede fehlt.');
        // Der Abschalt-Hinweis kommt aus _notification_layout.twig und muss in
        // jeder Benachrichtigung stehen.
        $this->assertStringContainsString('abschalten', $html);
    }

    /**
     * Nicht nur, dass die vorhandenen Vorlagen rendern - auch, dass es zu jedem
     * Anlass eine gibt. Sonst scheitert erst der Versand.
     */
    public function testEveryNotificationTypeHasATemplate(): void
    {
        $templateDir = dirname(__DIR__, 2) . '/templates/emails/';

        foreach (NotificationType::all() as $type) {
            $this->assertFileExists(
                $templateDir . 'notification_' . $type . '.twig',
                'Zum Anlass ' . $type . ' fehlt die Mail-Vorlage.'
            );
        }
    }

    public function testTheRenderedTemplatesCoverEveryType(): void
    {
        $covered = new Collection(array_map(
            static fn (array $case): string => basename($case[0], '.twig'),
            self::templates()
        ));

        foreach (NotificationType::all() as $type) {
            $this->assertTrue(
                $covered->contains('notification_' . $type),
                'Der Anlass ' . $type . ' wird von keinem Rendertest abgedeckt.'
            );
        }
    }
}
