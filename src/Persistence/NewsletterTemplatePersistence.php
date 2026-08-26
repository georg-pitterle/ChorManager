<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Models\NewsletterTemplate;
use Illuminate\Database\Capsule\Manager as Capsule;

class NewsletterTemplatePersistence
{
    /**
     * @param array<string, mixed> $data
     * @param array<int, array{type:string, reference_id:int}> $recipientSources
     */
    public function createTemplate(
        array $data,
        int $createdBy,
        ?int $projectId,
        array $recipientSources = []
    ): NewsletterTemplate {
        $template = NewsletterTemplate::create([
            'name' => $data['name'],
            'default_title' => $data['default_title'] ?? null,
            'description' => $data['description'],
            'content_html' => $data['content_html'],
            'project_id' => $projectId,
            'created_by' => $createdBy,
        ]);

        $this->setRecipientSources($template, $recipientSources);

        return $template;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array{type:string, reference_id:int}>|null $recipientSources
     *        null lässt die gespeicherten Quellen unangetastet.
     */
    public function updateTemplate(
        NewsletterTemplate $template,
        array $data,
        ?array $recipientSources = null
    ): void {
        $template->update($data);

        if ($recipientSources !== null) {
            $this->setRecipientSources($template, $recipientSources);
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
            $this->getRecipientSources($source)
        );
    }

    /**
     * @return array<int, array{type:string, reference_id:int}>
     */
    public function getRecipientSources(NewsletterTemplate $template): array
    {
        return $template->recipientSources()
            ->orderBy('id')
            ->get()
            ->map(static fn ($source): array => [
                'type' => (string) $source->source_type,
                'reference_id' => (int) $source->reference_id,
            ])
            ->all();
    }

    /**
     * Löschen und Neuanlegen gehören zusammen: Bricht der Austausch in der Mitte
     * ab, stünde die Vorlage mit einer halben Empfängerauswahl da.
     *
     * @param array<int, array{type:string, reference_id:int}> $recipientSources
     */
    private function setRecipientSources(NewsletterTemplate $template, array $recipientSources): void
    {
        Capsule::connection()->transaction(function () use ($template, $recipientSources): void {
            $template->recipientSources()->delete();

            foreach ($recipientSources as $source) {
                $type = (string) ($source['type'] ?? '');
                $referenceId = (int) ($source['reference_id'] ?? 0);
                if ($type === '' || $referenceId <= 0) {
                    continue;
                }

                $template->recipientSources()->create([
                    'source_type' => $type,
                    'reference_id' => $referenceId,
                ]);
            }
        });
    }
}
