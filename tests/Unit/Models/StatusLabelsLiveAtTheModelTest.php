<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\EventRegistration;
use App\Models\MailQueue;
use PHPUnit\Framework\TestCase;

/**
 * Die Beschriftung eines Zustands steht am Modell, nicht in der Vorlage.
 *
 * `MailQueue::MAIL_TYPE_LABELS` und `Task::STATUS_LABELS` sind aus demselben
 * Grund dorthin gewandert: Eine Zuordnung, die in zwei Vorlagen wortgleich
 * dasteht, wird irgendwann nur an einer gepflegt. Genau so kannte die
 * Mailarten-Zuordnung in den Vorlagen nur drei der fünf Arten, und die
 * Zustands-Zuordnung der Aufgaben widersprach sich zwischen Kanban-Spalte und
 * Auswahlfeld.
 *
 * Offen blieben danach zwei: die sechs Zustände der Warteschlange, die in
 * `admin/mail_queue/index.twig` und `admin/mail_queue/show.twig` samt
 * Abzeichen-Klassen doppelt standen, und die drei Antworten eines Termins, die
 * es viermal gab - in drei Vorlagen und in `public/js/registrations.js`.
 *
 * Dieser Wächter hält beides fest: jede Beschriftung deckt ihre Werteliste
 * vollständig ab, und keine Vorlage legt eine solche Zuordnung noch selbst an.
 */
final class StatusLabelsLiveAtTheModelTest extends TestCase
{
    /**
     * Zuordnungen, die eine Vorlage nicht selbst anlegen darf - sie kommen vom
     * Controller aus einer Konstante am Modell.
     *
     * @var list<string>
     */
    private const FORBIDDEN_TEMPLATE_MAPS = [
        'status_labels',
        'status_badges',
        'answer_labels',
        'type_labels',
        'priority_labels',
    ];

    public function testJederZustandDerWarteschlangeHatLabelUndAbzeichen(): void
    {
        $statuses = array_map('strval', MailQueue::SUPPORTED_STATUSES);
        sort($statuses);

        $labelled = array_map('strval', array_keys(MailQueue::STATUS_LABELS));
        sort($labelled);
        self::assertSame($statuses, $labelled, 'MailQueue::STATUS_LABELS deckt nicht alle Zustände ab.');

        $badged = array_map('strval', array_keys(MailQueue::STATUS_BADGE_CLASSES));
        sort($badged);
        self::assertSame($statuses, $badged, 'MailQueue::STATUS_BADGE_CLASSES deckt nicht alle Zustände ab.');

        foreach (MailQueue::STATUS_LABELS as $status => $label) {
            self::assertNotSame('', trim($label), sprintf('Zustand "%s" hat kein Label.', $status));
            self::assertNotSame(
                $status,
                $label,
                sprintf('Zustand "%s" trägt den Schlüssel als Label - das ist kein deutscher Text.', $status)
            );
        }
    }

    public function testJedeAntwortEinesTerminsHatEinLabel(): void
    {
        $statuses = array_map('strval', EventRegistration::STATUSES);
        sort($statuses);

        $labelled = array_map('strval', array_keys(EventRegistration::STATUS_LABELS));
        sort($labelled);

        self::assertSame($statuses, $labelled, 'EventRegistration::STATUS_LABELS deckt nicht alle Antworten ab.');

        foreach (EventRegistration::STATUS_LABELS as $status => $label) {
            self::assertNotSame('', trim($label), sprintf('Antwort "%s" hat kein Label.', $status));
            self::assertNotSame(
                $status,
                $label,
                sprintf('Antwort "%s" trägt den Schlüssel als Label - das ist kein deutscher Text.', $status)
            );
        }
    }

    public function testKeineVorlageLegtEineBeschriftungsZuordnungSelbstAn(): void
    {
        $problems = [];
        $checked = 0;

        foreach ($this->templateFiles() as $file) {
            $checked++;
            $content = (string) file_get_contents($file);

            foreach (self::FORBIDDEN_TEMPLATE_MAPS as $name) {
                if (str_contains($content, '{% set ' . $name . ' =')) {
                    $problems[] = sprintf(
                        '%s legt "%s" selbst an - die Zuordnung gehört als Konstante an das Modell und '
                            . 'vom Controller in die Vorlage.',
                        substr($file, strlen(dirname(__DIR__, 3)) + 1),
                        $name
                    );
                }
            }
        }

        self::assertGreaterThan(100, $checked, 'Es wurden kaum Vorlagen gefunden - der Pfad stimmt nicht.');
        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * @return list<string>
     */
    private function templateFiles(): array
    {
        $directory = new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/templates');
        $files = [];

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
