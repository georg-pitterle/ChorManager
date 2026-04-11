<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Models\NewsletterTemplate;

class NewsletterTemplatePersistence
{
    public function updateTemplate(NewsletterTemplate $template, array $data): void
    {
        $template->update($data);
    }

    public function cloneTemplate(NewsletterTemplate $source, int $createdBy): NewsletterTemplate
    {
        return NewsletterTemplate::create([
            'name' => $source->name . ' (Kopie)',
            'description' => (string) ($source->description ?? ''),
            'content_html' => $source->content_html,
            'project_id' => $source->project_id,
            'created_by' => $createdBy,
        ]);
    }
}
