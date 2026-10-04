<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Models\NewsletterTemplate;
use App\Models\NewsletterTemplateRecipientSource;
use App\Services\Audience\AudienceFilterService;
use Illuminate\Database\Capsule\Manager as Capsule;

class NewsletterTemplatePersistence
{
    public const OWNER = 'newsletter_template_id';

    /** Leere Empfängerauswahl: keine Zielgruppen-Zeile, kein Termin. */
    public const NO_AUDIENCE = ['sets' => [], 'event_ids' => []];

    public function __construct(
        private readonly AudienceFilterService $filters = new AudienceFilterService()
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array{sets: list<array<string, list<int>>>, event_ids: list<int>} $audience
     */
    public function createTemplate(
        array $data,
        int $createdBy,
        ?int $projectId,
        array $audience = self::NO_AUDIENCE
    ): NewsletterTemplate {
        $template = NewsletterTemplate::create([
            'name' => $data['name'],
            'default_title' => $data['default_title'] ?? null,
            'description' => $data['description'],
            'content_html' => $data['content_html'],
            'project_id' => $projectId,
            'created_by' => $createdBy,
        ]);

        $this->setAudience($template, $audience);

        return $template;
    }

    /**
     * @param array<string, mixed> $data
     * @param array{sets: list<array<string, list<int>>>, event_ids: list<int>}|null $audience
     *        null lässt die gespeicherte Empfängerauswahl unangetastet.
     */
    public function updateTemplate(
        NewsletterTemplate $template,
        array $data,
        ?array $audience = null
    ): void {
        $template->update($data);

        if ($audience !== null) {
            $this->setAudience($template, $audience);
        }
    }

    public function cloneTemplate(NewsletterTemplate $source, int $createdBy): NewsletterTemplate
    {
        return $this->createTemplate(
            [
                'name' => $source->name . ' (Kopie)',
                'default_title' => $source->default_title,
                'description' => (string) ($source->description ?? ''),
                'content_html' => $source->content_html,
            ],
            $createdBy,
            $source->project_id === null ? null : (int) $source->project_id,
            $this->audienceOf($source)
        );
    }

    /**
     * @return array{sets: list<array<string, list<int>>>, event_ids: list<int>}
     */
    public function audienceOf(NewsletterTemplate $template): array
    {
        $id = (int) $template->id;

        return [
            'sets' => $this->filters->conditionSetsForOwners(self::OWNER, [$id])[$id],
            'event_ids' => $template->recipientSources()
                ->orderBy('id')
                ->pluck('reference_id')
                ->map(static fn ($referenceId): int => (int) $referenceId)
                ->all(),
        ];
    }

    /**
     * Zielgruppen-Zeilen und Termine gehören zusammen: Bricht der Austausch in
     * der Mitte ab, stünde die Vorlage mit einer halben Empfängerauswahl da.
     *
     * @param array{sets: list<array<string, list<int>>>, event_ids: list<int>} $audience
     */
    private function setAudience(NewsletterTemplate $template, array $audience): void
    {
        Capsule::connection()->transaction(function () use ($template, $audience): void {
            $this->filters->replaceForOwner(self::OWNER, (int) $template->id, $audience['sets']);
            $template->recipientSources()->delete();

            foreach ($audience['event_ids'] as $eventId) {
                $template->recipientSources()->create([
                    'source_type' => NewsletterTemplateRecipientSource::TYPE_EVENT_ATTENDEES,
                    'reference_id' => (int) $eventId,
                ]);
            }
        });
    }
}
