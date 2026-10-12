<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Sponsorship;
use App\Models\SponsorshipBenefit;
use App\Models\SponsoringContact;
use App\Policies\SponsoringPolicy;
use App\Services\NameFormatterService;
use App\Util\SponsorshipStatus;
use Carbon\Carbon;

class SponsoringDashboardController
{
    private Twig $view;
    private NameFormatterService $nameFormatter;
    private SponsoringPolicy $policy;

    public function __construct(
        Twig $view,
        NameFormatterService $nameFormatter,
        SponsoringPolicy $policy
    ) {
        $this->view = $view;
        $this->nameFormatter = $nameFormatter;
        $this->policy = $policy;
    }

    public function index(Request $request, Response $response): Response
    {
        // Gezählt werden Vereinbarungen, nicht Sponsoren. Als Sponsorenzahl ließ
        // sich die Kachel nicht an der Sponsorenliste nachprüfen: deren Zustand
        // beschreibt den Stand der Akquise (eine laufende Anfrage oder eine
        // Generalabsage verdecken dort eine Zusage), die Kachel dagegen die
        // eingegangenen Verpflichtungen. Zwei Fragen, zwei Einheiten - eine
        // Zusage bleibt bestehen, auch wenn der Sponsor keine Anfragen mehr will
        // oder für das nächste Projekt schon wieder angefragt wurde.
        $totalActive = Sponsorship::where('status', SponsorshipStatus::ACCEPTED)->count();

        $totalAmount = (float) Sponsorship::where('status', SponsorshipStatus::ACCEPTED)->sum('amount');

        $requestedAmount = (float) Sponsorship::whereIn('status', SponsorshipStatus::OPEN)->sum('amount');

        // Zugesagt heißt noch nicht bezahlt: Das Dashboard nennt die Lücke
        // ausdrücklich, statt nur die Zusagen zu addieren.
        $unpaidAmount = (float) Sponsorship::where('status', SponsorshipStatus::ACCEPTED)
            ->whereNull('paid_at')
            ->sum('amount');

        $today = Carbon::today();
        $todayIso = $today->format('Y-m-d');

        $in7Days = $today->copy()->addDays(7)->format('Y-m-d');

        // Wer nicht das Vollrecht hat, sieht hier die eigene Arbeitsliste. Welche
        // Zeilen das sind, entscheidet die Policy - und zwar je Tabelle getrennt:
        // die Wiedervorlagen umfassen auch fremde Einträge auf einer Vereinbarung,
        // für die man zuständig ist (die darf man abhaken), die Historie darunter
        // nicht (dort steht die fremde Zusammenfassung).
        $ownUserId = $this->policy->ownContactUserIdFilter();
        $restrictToOwn = static function ($query) use ($ownUserId): void {
            if ($ownUserId !== null) {
                $query->where('user_id', $ownUserId);
            }
        };

        $followUpQuery = SponsoringContact::where('follow_up_done', 0)
            ->whereNotNull('follow_up_date')
            ->where('follow_up_date', '<=', $in7Days);

        $upcomingFollowUps = $this->policy->restrictFollowUpsToOwnWorkload($followUpQuery)
            ->with(['sponsor', 'user', 'sponsorship.package', 'sponsorship.project'])
            ->orderBy('follow_up_date')
            ->get()
            ->map(fn(SponsoringContact $contact): array => $this->mapUpcomingFollowUp($contact, $today))
            ->values()
            ->all();

        // Die überfälligen sind eine Teilmenge der eben geladenen Zeilen -
        // dafür braucht es keine zweite Abfrage.
        $openFollowUps = count(array_filter(
            $upcomingFollowUps,
            static fn (array $followUp): bool => $followUp['is_overdue']
        ));

        $recentContacts = SponsoringContact::with(['sponsor', 'user'])
            ->where($restrictToOwn)
            ->orderBy('contact_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn(SponsoringContact $contact): array => $this->mapRecentContact($contact))
            ->values()
            ->all();

        $seesTotals = $this->policy->canSeeFinancialTotals();

        return $this->view->render($response, 'sponsoring/dashboard.twig', [
            'total_active'      => $totalActive,
            // Summen über alle Vereinbarungen lassen sich keiner Urheberschaft
            // zuordnen und bleiben deshalb dem Vollrecht vorbehalten. Sie gar
            // nicht erst zu übergeben ist sicherer, als sie im Template zu
            // verstecken.
            'sees_totals'       => $seesTotals,
            'total_amount'      => $seesTotals ? $totalAmount : null,
            'requested_amount'  => $seesTotals ? $requestedAmount : null,
            'unpaid_amount'     => $seesTotals ? $unpaidAmount : null,
            'open_benefits'     => $this->openBenefits(),
            'unpaid'            => $seesTotals ? $this->unpaidCommitments() : [],
            'shows_own_only'    => $ownUserId !== null,
            'open_follow_ups'   => $openFollowUps,
            'upcoming_follow_ups' => $upcomingFollowUps,
            'recent_contacts'   => $recentContacts,
            'active_nav'        => 'sponsoring',
        ]);
    }

    /**
     * Zugesagte Vereinbarungen mit noch nicht erbrachten Gegenleistungen - die
     * Liste, die vor jedem Konzert gebraucht wird: Welche Logos gehören ins
     * Programmheft, wer bekommt Freikarten?
     *
     * Für alle mit Sponsoring-Zugang, wie die Leistungen selbst. Abhaken darf je
     * Vereinbarung, wen die Policy lässt; das steht an jeder Zeile.
     *
     * Sortiert nach dem Ende der Vereinbarung: was bald vorbei ist, muss zuerst
     * erledigt sein. Ohne Enddatum ganz hinten.
     *
     * @return list<array<string, mixed>>
     */
    private function openBenefits(): array
    {
        $sponsorships = Sponsorship::with([
            'sponsor',
            'project',
            'package',
            'benefits' => static fn ($query) => $query->whereNull('delivered_at'),
        ])
            ->where('status', SponsorshipStatus::ACCEPTED)
            ->whereHas('benefits', static fn ($query) => $query->whereNull('delivered_at'))
            ->orderByRaw('end_date IS NULL, end_date')
            ->get();

        $rows = [];
        foreach ($sponsorships as $sponsorship) {
            $mayTick = $this->policy->canTickBenefits($sponsorship);
            $rows[] = [
                'sponsor_name' => $sponsorship->sponsor ? (string) $sponsorship->sponsor->name : '-',
                'sponsor_url' => '/sponsoring/sponsors/' . $sponsorship->sponsor_id,
                'project_name' => $sponsorship->project ? (string) $sponsorship->project->name : 'Ohne Projekt',
                'package_name' => $sponsorship->package ? (string) $sponsorship->package->name : null,
                'package_badge' => $sponsorship->package?->badge_class,
                'end_date_display' => $sponsorship->end_date?->format('d.m.Y'),
                'benefits' => $sponsorship->benefits
                    ->map(static fn (SponsorshipBenefit $benefit): array => [
                        'id' => (int) $benefit->id,
                        'description' => (string) $benefit->description,
                        'may_tick' => $mayTick,
                        'toggle_url' => '/sponsoring/sponsorship-benefits/' . $benefit->id
                            . '/toggle?redirect_to=dashboard',
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $rows;
    }

    /**
     * Zusagen, bei denen das Geld noch nicht da ist. Nur für das Vollrecht -
     * es sind Beträge über alle Vereinbarungen, wie die Summen.
     *
     * @return list<array<string, mixed>>
     */
    private function unpaidCommitments(): array
    {
        // Eine reine Sachleistung (0 €) wartet auf kein Geld und stünde sonst
        // für immer in der Liste.
        return Sponsorship::with(['sponsor', 'project'])
            ->where('status', SponsorshipStatus::ACCEPTED)
            ->whereNull('paid_at')
            ->where('amount', '>', 0)
            ->orderByRaw('start_date IS NULL, start_date')
            ->get()
            ->map(static fn (Sponsorship $sponsorship): array => [
                'sponsor_name' => $sponsorship->sponsor ? (string) $sponsorship->sponsor->name : '-',
                'sponsor_url' => '/sponsoring/sponsors/' . $sponsorship->sponsor_id,
                'project_name' => $sponsorship->project ? (string) $sponsorship->project->name : 'Ohne Projekt',
                'amount' => (float) $sponsorship->amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Wie dringend eine Wiedervorlage ist, in Worten statt nur als Farbe.
     *
     * @return array{label: string, state: string}
     */
    public static function dueLabel(Carbon $followUpDate, Carbon $today): array
    {
        $days = (int) $today->copy()->startOfDay()->diffInDays($followUpDate->copy()->startOfDay(), false);

        return match (true) {
            $days < -1 => ['label' => 'seit ' . abs($days) . ' Tagen überfällig', 'state' => 'overdue'],
            $days === -1 => ['label' => 'seit gestern überfällig', 'state' => 'overdue'],
            $days === 0 => ['label' => 'heute fällig', 'state' => 'today'],
            $days === 1 => ['label' => 'morgen', 'state' => 'soon'],
            default => ['label' => 'in ' . $days . ' Tagen', 'state' => 'soon'],
        };
    }

    private function mapUpcomingFollowUp(SponsoringContact $contact, Carbon $today): array
    {
        $todayIso = $today->format('Y-m-d');
        $statusLabels = SponsorshipStatus::labels();

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
            $amount = 0.0;
        } else {
            $packageName = $sponsorship->package ? (string) $sponsorship->package->name : 'Ohne Paket';
            $status = (string) $sponsorship->status;
            $statusLabel = $statusLabels[$status] ?? 'Sonstiges';

            // Ein eigener Kontakt kann an einer fremden Vereinbarung hängen -
            // deren Betrag gehört trotzdem nicht auf dieses Dashboard.
            if ($this->policy->canSeeSponsorshipDetails($sponsorship)) {
                $amountDisplay = number_format($amount, 2, ',', '.') . ' €';
            } else {
                $amountDisplay = '-';
                $amount = 0.0;
            }
        }

        $due = $followUpDate !== null
            ? self::dueLabel(Carbon::parse($followUpDate->format('Y-m-d')), $today)
            : ['label' => '', 'state' => 'soon'];

        return [
            'follow_up_date_display' => $followUpDate ? $followUpDate->format('d.m.Y') : '-',
            'due_label' => $due['label'],
            'due_state' => $due['state'],
            'summary' => $this->policy->canSeeContactDetails($contact) ? (string) ($contact->summary ?? '') : null,
            'project_name' => $sponsorship?->project ? (string) $sponsorship->project->name : null,
            'follow_up_date_sort' => $followUpDateSort,
            'sponsor_name' => $sponsor ? (string) $sponsor->name : '-',
            'sponsor_url' => $sponsor ? '/sponsoring/sponsors/' . $sponsor->id : '',
            'agreement_package_name' => $packageName,
            'agreement_amount_display' => $amountDisplay,
            'agreement_amount_sort' => $amount,
            'agreement_status_label' => $statusLabel,
            'owner_name' => $ownerName,
            'owner_name_sort' => mb_strtolower($ownerName),
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
            'owner_name_sort' => mb_strtolower($ownerName),
        ];
    }

    private function buildOwnerName(SponsoringContact $contact): string
    {
        if ($contact->user === null) {
            return '-';
        }

        return $this->nameFormatter->formatPerson($contact->user);
    }
}
