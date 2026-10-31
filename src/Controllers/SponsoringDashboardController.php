<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Controllers\ProjectController;
use App\Models\Project;
use App\Models\Sponsorship;
use App\Models\SponsoringContact;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Queries\SponsoringQuery;
use App\Services\NameFormatterService;
use App\Util\LogoDeadline;
use App\Util\SponsorshipStatus;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SponsoringDashboardController
{
    private Twig $view;
    private NameFormatterService $nameFormatter;
    private SponsoringPolicy $policy;

    private LoggerInterface $logger;
    private SponsoringQuery $sponsoringQuery;

    /**
     * Die Abfrageklasse steht hinten und darf fehlen - wie der Logger; gleiche
     * Begründung wie in SponsorController. Ohne sie bekommt die Auswahl den
     * hier hereingereichten Namensformatierer, im Betrieb kommt sie aus
     * Dependencies.php.
     */
    public function __construct(
        Twig $view,
        NameFormatterService $nameFormatter,
        SponsoringPolicy $policy,
        ?LoggerInterface $logger = null,
        ?SponsoringQuery $sponsoringQuery = null
    ) {
        $this->view = $view;
        $this->nameFormatter = $nameFormatter;
        $this->policy = $policy;
        $this->logger = $logger ?? new NullLogger();
        $this->sponsoringQuery = $sponsoringQuery ?? new SponsoringQuery($nameFormatter);
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
            ->with([
                'sponsor',
                'user',
                'sponsorship.package',
                'sponsorship.project',
                // Die Rollen für assigneeName(): rechtlos zählt als niemand.
                'sponsorship.assignedUser.roles',
            ])
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
        $canManageAll = $this->policy->canManageAll();
        $unassigned = $canManageAll ? $this->unassignedSponsorships() : [];

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
            'can_manage_all'    => $canManageAll,
            'unassigned'        => $unassigned,
            // Die Auswahl steht in jeder Zeile, die Liste der Personen wird nur
            // gebraucht, wenn es überhaupt eine Zeile gibt.
            'assignable_users'  => $unassigned !== [] ? $this->sponsoringQuery->getAssignableUsers() : [],
            'missing_logos'     => $this->missingLogos(),
            'logo_deadline_projects' => $canManageAll ? $this->logoDeadlineProjects() : [],
            'open_benefits'     => $this->openBenefits(),
            'open_deliverables' => $this->openDeliverables(),
            'unpaid'            => $seesTotals ? $this->unpaidCommitments() : [],
            'shows_own_only'    => $ownUserId !== null,
            'open_follow_ups'   => $openFollowUps,
            'upcoming_follow_ups' => $upcomingFollowUps,
            'recent_contacts'   => $recentContacts,
            'active_nav'        => 'sponsoring',
        ]);
    }

    /**
     * Laufende Vorgänge, um die sich niemand kümmert - Zusagen zuerst, denn da
     * schuldet der Verein schon etwas. Nur für das Vollrecht: Die Zeile führt
     * zum Zuweisen, und das darf nur das Sponsoring-Team.
     *
     * @return list<array<string, mixed>>
     */
    private function unassignedSponsorships(): array
    {
        $statusLabels = SponsorshipStatus::labels();
        $statusBadges = SponsorshipStatus::badgeClasses();

        return Sponsorship::with(['sponsor', 'project', 'package', 'assignedUser'])
            ->withoutCapableAssignee()
            ->get()
            ->sortBy([
                static fn (Sponsorship $a, Sponsorship $b): int => ($b->status === SponsorshipStatus::ACCEPTED)
                    <=> ($a->status === SponsorshipStatus::ACCEPTED),
                static fn (Sponsorship $a, Sponsorship $b): int => (string) $a->sponsor?->name
                    <=> (string) $b->sponsor?->name,
            ])
            ->map(fn (Sponsorship $sponsorship): array => [
                'sponsor_name' => $sponsorship->sponsor ? (string) $sponsorship->sponsor->name : '-',
                'sponsor_url' => '/sponsoring/sponsors/' . $sponsorship->sponsor_id . '#agreement-' . $sponsorship->id,
                'project_name' => $sponsorship->project ? (string) $sponsorship->project->name : 'Ohne Projekt',
                'package_name' => $sponsorship->package ? (string) $sponsorship->package->name : null,
                'package_badge' => $sponsorship->package?->badge_class,
                'status_label' => $statusLabels[$sponsorship->status] ?? 'Unbekannt',
                'status_badge' => $statusBadges[$sponsorship->status] ?? 'text-bg-secondary',
                'former_assignee_name' => $sponsorship->assignedUser !== null
                    ? $this->nameFormatter->formatPerson($sponsorship->assignedUser)
                    : null,
                'assign_url' => '/sponsoring/sponsorships/' . $sponsorship->id . '/assignee',
            ])
            ->values()
            ->all();
    }

    /**
     * Wer sich um den Vorgang kümmert, oder null. Eine archivierte oder rechtlos
     * gewordene Person zählt als niemand, wie in SponsorResponsibility:
     * Beitragende sehen "Ohne Zuständigkeit" nicht und hielten den Vorgang sonst
     * für versorgt.
     */
    private function assigneeName(?Sponsorship $sponsorship): ?string
    {
        $person = $sponsorship?->assignedUser;

        return $person !== null && $person->hasSponsoringAccess()
            ? $this->nameFormatter->formatPerson($person)
            : null;
    }

    /** Ab wie vielen Tagen vor der Frist eine Zeile als "bald" gilt. */
    private const LOGO_SOON_DAYS = 14;

    /**
     * Von welchem zugesagten Sponsor fehlt das Logo, und bis wann?
     *
     * Grundlage sind Zusagen, die ein Logo brauchen (Druck oder online), deren
     * Sponsor noch keins hat. Ein Sponsor erscheint einmal, mit der frühesten
     * Frist seiner Vereinbarungen; ohne Frist steht er am Ende. Genannt werden
     * alle Zuständigen dieser Vereinbarungen - jede davon darf die Logos
     * hochladen, keine soll hinter der früheren Frist verschwinden. Sichtbar
     * für alle mit Sponsoring-Zugang, wie die offenen Gegenleistungen.
     *
     * @return list<array{sponsor_id: int, sponsor_name: string, sponsor_url: string, project_name: ?string,
     *                    deadline: ?string, deadline_sort: string, kind: string,
     *                    urgency: string, assignees: list<string>}>
     */
    private function missingLogos(): array
    {
        $sponsorships = Sponsorship::with(['sponsor', 'project', 'assignedUser.roles'])
            ->where('status', SponsorshipStatus::ACCEPTED)
            ->where(static fn ($query) => $query->where('needs_logo_print', true)->orWhere('needs_logo_online', true))
            ->whereHas('sponsor', static fn ($query) => $query->whereDoesntHave('logos'))
            ->get();

        $today = Carbon::today();
        $bySponsor = [];
        $assignees = [];
        foreach ($sponsorships as $sponsorship) {
            $deadline = LogoDeadline::for(
                (bool) $sponsorship->needs_logo_print,
                (bool) $sponsorship->needs_logo_online,
                $sponsorship->project?->logo_deadline_print,
                $sponsorship->project?->logo_deadline_online
            );
            if ($deadline === null || $sponsorship->sponsor === null) {
                continue;
            }

            $date = $deadline['date'];
            $row = [
                'sponsor_id' => (int) $sponsorship->sponsor_id,
                'sponsor_name' => (string) $sponsorship->sponsor->name,
                'sponsor_url' => '/sponsoring/sponsors/' . $sponsorship->sponsor_id,
                'project_name' => $sponsorship->project?->name,
                'deadline' => $date?->format('d.m.Y'),
                'deadline_sort' => $date?->format('Y-m-d') ?? '9999-12-31',
                'kind' => $deadline['kind'],
                'urgency' => match (true) {
                    $date === null => 'none',
                    $date->lt($today) => 'overdue',
                    $date->lte($today->copy()->addDays(self::LOGO_SOON_DAYS)) => 'soon',
                    default => 'later',
                },
            ];

            $assigneeName = $this->assigneeName($sponsorship);
            if ($assigneeName !== null) {
                $assignees[$row['sponsor_id']][(int) $sponsorship->assigned_user_id] = $assigneeName;
            }

            $current = $bySponsor[$row['sponsor_id']] ?? null;
            if ($current === null || $row['deadline_sort'] < $current['deadline_sort']) {
                $bySponsor[$row['sponsor_id']] = $row;
            }
        }

        $rows = [];
        foreach ($bySponsor as $sponsorId => $row) {
            $names = array_values($assignees[$sponsorId] ?? []);
            sort($names);
            $rows[] = $row + ['assignees' => $names];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['deadline_sort'], $a['sponsor_name']]
            <=> [$b['deadline_sort'], $b['sponsor_name']]);

        return $rows;
    }

    /**
     * Projekte, für die das Sponsoring-Team Logo-Fristen pflegt: alle mit
     * mindestens einer Zusage, die ein Logo braucht. Nur für das Vollrecht.
     *
     * @return \Illuminate\Support\Collection<int, Project>
     */
    private function logoDeadlineProjects(): \Illuminate\Support\Collection
    {
        return Project::query()
            ->whereIn('id', Sponsorship::query()
                ->select('project_id')
                ->whereNotNull('project_id')
                ->where('status', SponsorshipStatus::ACCEPTED)
                ->where(static fn ($query) => $query->where('needs_logo_print', true)->orWhere('needs_logo_online', true)))
            ->orderBy('start_date')
            ->orderBy('name')
            ->get();
    }

    /**
     * Logo-Fristen eines Projekts aus dem Sponsoring-Bereich setzen. Die
     * Fristen gehören dem Sponsoring-Team, das oft keine Projektrechte hat;
     * die Prüfung der Daten ist dieselbe wie im Projektformular.
     */
    public function updateLogoDeadlines(Request $request, Response $response, array $args): Response
    {
        if (!$this->policy->canManageAll()) {
            $response->getBody()->write('Zugriff verweigert.');
            return $response->withStatus(403);
        }

        $back = $response->withHeader('Location', '/sponsoring')->withStatus(302);
        $project = Project::find((int) $args['id']);
        if ($project === null) {
            $_SESSION['error'] = 'Projekt nicht gefunden.';
            return $back;
        }

        $deadlines = ProjectController::logoDeadlines((array) $request->getParsedBody(), $project);
        if ($deadlines === false) {
            $_SESSION['error'] = ProjectController::INVALID_LOGO_DEADLINE_MESSAGE;
            return $back;
        }

        $project->update($deadlines);
        // Das Sponsoring-Team ändert hier Daten eines Projekts, für das es oft
        // keine Projektrechte hat - das soll nachvollziehbar bleiben.
        $this->logger->info('Project logo deadlines updated.', [
            'event' => 'sponsoring.logo_deadlines.updated',
            'project_id' => (int) $project->id,
            'user_id' => $this->policy->currentUserId(),
            'logo_deadline_print' => $project->logo_deadline_print?->format('Y-m-d'),
            'logo_deadline_online' => $project->logo_deadline_online?->format('Y-m-d'),
        ]);
        $_SESSION['success'] = 'Logo-Fristen für ' . $project->name . ' gespeichert.';

        return $back;
    }

    /**
     * Zugesagte Vereinbarungen mit noch nicht erbrachten Gegenleistungen - die
     * Liste, die vor jedem Konzert gebraucht wird: Welche Logos gehören ins
     * Programmheft, wer bekommt Freikarten?
     *
     * Für alle mit Sponsoring-Zugang, wie die Leistungen selbst. Abhaken darf nur
     * das Vollrecht; das steht an jeder Zeile.
     *
     * Sortiert nach dem Ende der Vereinbarung: was bald vorbei ist, muss zuerst
     * erledigt sein. Ohne Enddatum ganz hinten.
     *
     * @return list<array<string, mixed>>
     */
    private function openBenefits(): array
    {
        return $this->openLines('benefits', 'delivered_at', 'sponsorship-benefits');
    }

    /**
     * Zugesagte Vereinbarungen, bei denen vom Sponsor noch etwas aussteht -
     * Banner, Vertrag, Sachleistung. Aufgebaut wie openBenefits(), auch in
     * Sichtbarkeit und Sortierung; abhaken dürfen hier aber Urheber und
     * zuständige Person (SponsoringPolicy::canTickDeliverables()).
     *
     * @return list<array<string, mixed>>
     */
    private function openDeliverables(): array
    {
        return $this->openLines('deliverables', 'received_at', 'sponsorship-deliverables');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openLines(string $relation, string $doneColumn, string $path): array
    {
        $open = static fn ($query) => $query->whereNull($doneColumn);
        $sponsorships = Sponsorship::with([
            'sponsor',
            'project',
            'package',
            'assignedUser.roles',
            $relation => $open,
        ])
            ->where('status', SponsorshipStatus::ACCEPTED)
            ->whereHas($relation, $open)
            ->orderByRaw('end_date IS NULL, end_date')
            ->get();

        $rows = [];
        foreach ($sponsorships as $sponsorship) {
            $mayTick = $relation === 'deliverables'
                ? $this->policy->canTickDeliverables($sponsorship)
                : $this->policy->canTickBenefits();
            $rows[] = [
                'sponsor_name' => $sponsorship->sponsor ? (string) $sponsorship->sponsor->name : '-',
                'sponsor_url' => '/sponsoring/sponsors/' . $sponsorship->sponsor_id,
                'project_name' => $sponsorship->project ? (string) $sponsorship->project->name : 'Ohne Projekt',
                'package_name' => $sponsorship->package ? (string) $sponsorship->package->name : null,
                'package_badge' => $sponsorship->package?->badge_class,
                'end_date_display' => $sponsorship->end_date?->format('d.m.Y'),
                'assignee_name' => $this->assigneeName($sponsorship),
                'benefits' => $sponsorship->{$relation}
                    ->map(static fn ($line): array => [
                        'id' => (int) $line->id,
                        'description' => (string) $line->description,
                        'may_tick' => $mayTick,
                        'toggle_url' => '/sponsoring/' . $path . '/' . $line->id
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
        return Sponsorship::with(['sponsor', 'project', 'assignedUser.roles'])
            ->where('status', SponsorshipStatus::ACCEPTED)
            ->whereNull('paid_at')
            ->where('amount', '>', 0)
            ->orderByRaw('start_date IS NULL, start_date')
            ->get()
            ->map(fn (Sponsorship $sponsorship): array => [
                'sponsor_name' => $sponsorship->sponsor ? (string) $sponsorship->sponsor->name : '-',
                'sponsor_url' => '/sponsoring/sponsors/' . $sponsorship->sponsor_id,
                'project_name' => $sponsorship->project ? (string) $sponsorship->project->name : 'Ohne Projekt',
                'assignee_name' => $this->assigneeName($sponsorship),
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
            'assignee_name' => $this->assigneeName($sponsorship),
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
