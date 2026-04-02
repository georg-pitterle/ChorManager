<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\SponsoringContact;
use Carbon\Carbon;

class SponsoringDashboardController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $totalActive = Sponsor::where('status', 'active')->count();

        $totalAmount = (float) Sponsorship::where('status', 'active')->sum('amount');

        $pipeline = (float) Sponsorship::where('status', 'negotiating')->sum('amount');

        $today = Carbon::today();
        $todayIso = $today->format('Y-m-d');

        $openFollowUps = SponsoringContact::where('follow_up_done', 0)
            ->whereNotNull('follow_up_date')
            ->where('follow_up_date', '<=', $todayIso)
            ->count();

        $in7Days = $today->copy()->addDays(7)->format('Y-m-d');

        $upcomingFollowUps = SponsoringContact::where('follow_up_done', 0)
            ->whereNotNull('follow_up_date')
            ->where('follow_up_date', '<=', $in7Days)
            ->with(['sponsor', 'user', 'sponsorship.package'])
            ->orderBy('follow_up_date')
            ->get()
            ->map(fn(SponsoringContact $contact): array => $this->mapUpcomingFollowUp($contact, $todayIso))
            ->values()
            ->all();

        $recentContacts = SponsoringContact::with(['sponsor', 'user'])
            ->orderBy('contact_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn(SponsoringContact $contact): array => $this->mapRecentContact($contact))
            ->values()
            ->all();

        return $this->view->render($response, 'sponsoring/dashboard.twig', [
            'total_active'      => $totalActive,
            'total_amount'      => $totalAmount,
            'pipeline'          => $pipeline,
            'open_follow_ups'   => $openFollowUps,
            'upcoming_follow_ups' => $upcomingFollowUps,
            'recent_contacts'   => $recentContacts,
            'active_nav'        => 'sponsoring',
        ]);
    }

    private function mapUpcomingFollowUp(SponsoringContact $contact, string $todayIso): array
    {
        $statusLabels = [
            'prospect' => 'Interessent',
            'contacted' => 'Kontaktiert',
            'negotiating' => 'Verhandlung',
            'active' => 'Aktiv',
            'paused' => 'Pausiert',
            'closed' => 'Abgeschlossen',
        ];

        $followUpDate = $contact->follow_up_date;
        $followUpDateSort = $followUpDate ? $followUpDate->format('Y-m-d') : '';
        $sponsor = $contact->sponsor;
        $sponsorship = $contact->sponsorship;
        $ownerName = $this->buildOwnerName($contact);
        $amount = $sponsorship ? (float) $sponsorship->amount : 0.0;

        if (!$sponsorship) {
            $packageName = '-';
            $amountDisplay = '-';
            $statusLabel = '-';
        } else {
            $packageName = $sponsorship->package ? (string) $sponsorship->package->name : 'Ohne Paket';
            $amountDisplay = number_format($amount, 2, '.', ',') . ' EUR';
            $status = (string) $sponsorship->status;
            $statusLabel = $statusLabels[$status] ?? 'Sonstiges';
        }

        return [
            'follow_up_date_display' => $followUpDate ? $followUpDate->format('d.m.Y') : '-',
            'follow_up_date_sort' => $followUpDateSort,
            'sponsor_name' => $sponsor ? (string) $sponsor->name : '-',
            'sponsor_url' => $sponsor ? '/sponsoring/sponsors/' . $sponsor->id : '',
            'agreement_package_name' => $packageName,
            'agreement_amount_display' => $amountDisplay,
            'agreement_amount_sort' => $amount,
            'agreement_status_label' => $statusLabel,
            'owner_name' => $ownerName,
            'owner_name_sort' => strtolower($ownerName),
            'is_overdue' => $followUpDateSort !== '' && $followUpDateSort <= $todayIso,
            'mark_done_url' => '/sponsoring/contacts/' . $contact->id . '/done?redirect_to=dashboard',
        ];
    }

    private function mapRecentContact(SponsoringContact $contact): array
    {
        $typeLabels = [
            'call' => 'Anruf',
            'email' => 'E-Mail',
            'meeting' => 'Treffen',
            'letter' => 'Brief',
            'other' => 'Sonstiges',
        ];

        $contactDate = $contact->contact_date;
        $sponsor = $contact->sponsor;
        $ownerName = $this->buildOwnerName($contact);
        $contactType = (string) ($contact->type ?? 'other');

        return [
            'contact_date_display' => $contactDate ? $contactDate->format('d.m.Y') : '-',
            'contact_date_sort' => $contactDate ? $contactDate->format('Y-m-d') : '',
            'sponsor_name' => $sponsor ? (string) $sponsor->name : '-',
            'sponsor_url' => $sponsor ? '/sponsoring/sponsors/' . $sponsor->id : '',
            'contact_type_label' => $typeLabels[$contactType] ?? 'Sonstiges',
            'contact_type_sort' => $contactType,
            'summary' => (string) ($contact->summary ?? ''),
            'owner_name' => $ownerName,
            'owner_name_sort' => strtolower($ownerName),
        ];
    }

    private function buildOwnerName(SponsoringContact $contact): string
    {
        if ($contact->user === null) {
            return '-';
        }

        return trim((string) $contact->user->first_name . ' ' . (string) $contact->user->last_name);
    }
}
