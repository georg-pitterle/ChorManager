<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\NewsletterTemplate;
use Illuminate\Support\Collection;

class NewsletterTemplateQuery
{
    public function findById(int $id): ?NewsletterTemplate
    {
        return NewsletterTemplate::find($id);
    }

    public function getForProjectContext(?int $projectId): Collection
    {
        return NewsletterTemplate::query()
            ->where('project_id', $projectId)
            ->orWhereNull('project_id')
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    public function getForAccessibleProjects(array $projectIds): Collection
    {
        return NewsletterTemplate::query()
            ->whereNull('project_id')
            ->orWhere(function ($query) use ($projectIds) {
                if ($projectIds === []) {
                    $query->whereRaw('1 = 0');
                    return;
                }
                $query->whereIn('project_id', $projectIds);
            })
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }
}
