<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Models\Newsletter;
use App\Services\NameFormatterService;
use App\Util\MailBranding;
use Carbon\Carbon;

/**
 * Alles, was beim Rendern eines Newsletters unabhängig vom einzelnen Empfänger ist.
 * Wird einmal je Versand aufgebaut, nicht je Empfänger.
 */
final class RenderContext
{
    public function __construct(
        public readonly string $appName,
        public readonly string $baseUrl,
        public readonly ?int $newsletterId,
        public readonly string $title,
        public readonly string $projectName,
        public readonly string $senderName,
        public readonly string $date
    ) {
    }

    public static function fromNewsletter(
        Newsletter $newsletter,
        string $baseUrl,
        NameFormatterService $nameFormatter
    ): self {
        $sentAt = $newsletter->sent_at instanceof Carbon ? $newsletter->sent_at : Carbon::now();

        return new self(
            appName: (string) MailBranding::resolve()['app_name'],
            baseUrl: rtrim($baseUrl, '/'),
            newsletterId: $newsletter->id === null ? null : (int) $newsletter->id,
            title: (string) $newsletter->title,
            projectName: (string) ($newsletter->project->name ?? ''),
            senderName: $nameFormatter->formatPerson($newsletter->createdBy),
            date: $sentAt->format('d.m.Y')
        );
    }
}
