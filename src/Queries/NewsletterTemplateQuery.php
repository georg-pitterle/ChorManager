<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\NewsletterTemplate;

class NewsletterTemplateQuery
{
    public function findById(int $id): ?NewsletterTemplate
    {
        return NewsletterTemplate::find($id);
    }
}
