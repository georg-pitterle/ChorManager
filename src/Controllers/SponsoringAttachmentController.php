<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Attachment;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Policies\SponsoringPolicy;

/**
 * Zentrale Sammlung aller Sponsoring-Anhänge.
 *
 * Verträge hingen bisher an je einer Vereinbarung und waren nur über den
 * jeweiligen Sponsor auffindbar; für Logos gab es überhaupt keinen Ort. Diese
 * Übersicht führt beide Ablagen zusammen, ohne eine dritte einzuführen: sie
 * liest dieselben Anhänge, die an Sponsor und Vereinbarung hängen.
 *
 * Gezeigt wird nur, was die anfragende Person auch einzeln herunterladen
 * dürfte. Ohne diese Einschränkung wäre die Übersicht der bequemste Weg an
 * fremde Verträge - eine Liste mit Download-Link je Zeile.
 */
class SponsoringAttachmentController
{
    private Twig $view;
    private SponsoringPolicy $policy;

    public function __construct(Twig $view, SponsoringPolicy $policy)
    {
        $this->view = $view;
        $this->policy = $policy;
    }

    public function index(Request $request, Response $response): Response
    {
        $attachments = Attachment::whereIn('entity_type', ['sponsor', 'sponsorship'])
            ->orderBy('created_at', 'desc')
            ->get([
                'id',
                'entity_type',
                'entity_id',
                'original_name',
                'mime_type',
                'file_size',
                'created_at',
            ]);

        $sponsorships = Sponsorship::with(['sponsor', 'project', 'package'])
            ->whereIn('id', $this->idsFor($attachments, 'sponsorship'))
            ->get()
            ->keyBy('id');

        $sponsors = Sponsor::whereIn('id', $this->idsFor($attachments, 'sponsor'))
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($attachments as $attachment) {
            $row = $attachment->entity_type === 'sponsorship'
                ? $this->mapSponsorshipAttachment($attachment, $sponsorships->get($attachment->entity_id))
                : $this->mapSponsorAttachment($attachment, $sponsors->get($attachment->entity_id));

            // Null heißt: verwaist (der Eintrag ist gelöscht) oder für diese
            // Person nicht einsehbar. Beides gehört nicht in die Liste.
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $success = $_SESSION['success'] ?? null;
        $error   = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'sponsoring/attachments/index.twig', [
            'attachments' => $rows,
            'can_manage_all' => $this->policy->canManageAll(),
            'success'     => $success,
            'error'       => $error,
            'active_nav'  => 'sponsoring',
        ]);
    }

    /**
     * @param iterable<Attachment> $attachments
     * @return list<int>
     */
    private function idsFor(iterable $attachments, string $entityType): array
    {
        $ids = [];
        foreach ($attachments as $attachment) {
            if ($attachment->entity_type === $entityType) {
                $ids[] = (int) $attachment->entity_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapSponsorshipAttachment(Attachment $attachment, ?Sponsorship $sponsorship): ?array
    {
        if ($sponsorship === null || $sponsorship->sponsor === null) {
            return null;
        }

        if (!$this->policy->canSeeSponsorshipDetails($sponsorship)) {
            return null;
        }

        $package = $sponsorship->package ? (string) $sponsorship->package->name : 'Ohne Paket';

        return $this->baseRow($attachment) + [
            'context_label' => 'Vereinbarung',
            'sponsor_name'  => (string) $sponsorship->sponsor->name,
            'sponsor_url'   => '/sponsoring/sponsors/' . $sponsorship->sponsor->id,
            'reference'     => $package,
            'project_name'  => $sponsorship->project ? (string) $sponsorship->project->name : '–',
            'download_url'  => '/sponsoring/sponsorships/' . $sponsorship->id
                . '/attachments/' . $attachment->id,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapSponsorAttachment(Attachment $attachment, ?Sponsor $sponsor): ?array
    {
        if ($sponsor === null) {
            return null;
        }

        if (!$this->policy->canSeeSponsorDetails($sponsor)) {
            return null;
        }

        return $this->baseRow($attachment) + [
            'context_label' => 'Sponsor',
            'sponsor_name'  => (string) $sponsor->name,
            'sponsor_url'   => '/sponsoring/sponsors/' . $sponsor->id,
            'reference'     => 'Stammdaten',
            'project_name'  => '–',
            'download_url'  => '/sponsoring/sponsors/' . $sponsor->id
                . '/attachments/' . $attachment->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRow(Attachment $attachment): array
    {
        $createdAt = $attachment->created_at;

        return [
            'id'                => (int) $attachment->id,
            'name'              => (string) $attachment->original_name,
            'name_sort'         => mb_strtolower((string) $attachment->original_name),
            'mime_type'         => (string) $attachment->mime_type,
            'size_bytes'        => (int) $attachment->file_size,
            'size_display'      => $this->formatSize((int) $attachment->file_size),
            'created_at_display' => $createdAt ? $createdAt->format('d.m.Y') : '–',
            'created_at_sort'   => $createdAt ? $createdAt->format('Y-m-d') : '',
        ];
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        }

        return $bytes . ' B';
    }
}
