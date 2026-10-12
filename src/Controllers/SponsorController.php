<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Sponsor;
use App\Models\SponsorPackage;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Services\EntityAttachmentService;
use App\Services\EntityCleanupService;
use App\Services\Notifications\InAppNotificationStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use App\Util\SponsorEngagementState;
use App\Util\SponsorshipStatus;
use App\Util\InputValidator;

class SponsorController
{
    private const MAX_NAME_LENGTH = 255;
    private const MAX_CONTACT_PERSON_LENGTH = 255;
    private const MAX_EMAIL_LENGTH = 255;
    private const MAX_PHONE_LENGTH = 80;
    private const MAX_WEBSITE_LENGTH = 2048;

    private const MAX_BLOCK_NOTE_LENGTH = 2000;

    /** Anhänge, die unmittelbar am Sponsor hängen: Logo, Mediadaten, Rahmenvertrag. */
    public const ENTITY_TYPE = 'sponsor';

    private Twig $view;
    private SponsoringPolicy $policy;
    private EntityAttachmentService $attachments;

    private LoggerInterface $logger;

    public function __construct(
        Twig $view,
        SponsoringPolicy $policy,
        EntityAttachmentService $attachments,
        ?LoggerInterface $logger = null
    ) {
        $this->view = $view;
        $this->policy = $policy;
        $this->attachments = $attachments;
        $this->logger = $logger ?? new NullLogger();
    }

    public function index(Request $request, Response $response): Response
    {
        // Nur Statusspalte laden: mehr braucht weder der abgeleitete Zustand
        // noch die Zähler-Spalte der Tabelle, und die Notizen der Vereinbarungen
        // wären bei vielen Sponsoren reiner Ballast.
        $sponsors = Sponsor::with([
            'sponsorships:id,sponsor_id,package_id,status,amount,created_at',
            'sponsorships.package',
            // Nur offene Wiedervorlagen: die Spalte "Nächste Frist" braucht die
            // früheste davon, alles Erledigte wäre Ballast.
            'contacts' => static fn ($query) => $query
                ->select(['id', 'sponsor_id', 'follow_up_date'])
                ->where('follow_up_done', 0)
                ->whereNotNull('follow_up_date'),
        ])
            ->orderBy('name')
            ->get();

        $success = $_SESSION['success'] ?? null;
        $error   = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'sponsoring/sponsors/index.twig', [
            'sponsors'       => $sponsors,
            'can_manage_all' => $this->policy->canManageAll(),
            'sponsor_states' => $this->buildSponsorStates($sponsors),
            'sponsor_rows'   => $this->buildSponsorRows($sponsors),
            'sees_totals'    => $this->policy->canSeeFinancialTotals(),
            'state_options'  => SponsorEngagementState::options(),
            'state_badges'   => SponsorEngagementState::badgeClasses(),
            'state_labels'   => SponsorEngagementState::labels(),
            'success'        => $success,
            'error'          => $error,
            'active_nav'     => 'sponsoring',
        ]);
    }

    /**
     * Was die Sponsorenliste je Zeile über die Stammdaten hinaus zeigt.
     *
     * - Paket: das der maßgeblichen Vereinbarung - eine Zusage vor einer
     *   laufenden Anfrage, sonst die jüngste.
     * - Nächste Frist: die früheste offene Wiedervorlage.
     * - Zugesagt: Summe der Zusagen, nur für das Vollrecht. Wie die Summen auf
     *   dem Dashboard lässt sie sich keiner Urheberschaft zuordnen.
     *
     * @param iterable<Sponsor> $sponsors
     * @return array<int, array<string, mixed>>
     */
    private function buildSponsorRows(iterable $sponsors): array
    {
        $seesTotals = $this->policy->canSeeFinancialTotals();
        $rank = [
            SponsorshipStatus::ACCEPTED => 0,
            SponsorshipStatus::REQUESTED => 1,
            SponsorshipStatus::REMINDED => 1,
        ];
        $rows = [];

        foreach ($sponsors as $sponsor) {
            $leading = $sponsor->sponsorships
                ->sortBy([
                    static fn ($a, $b): int => ($rank[$a->status] ?? 2) <=> ($rank[$b->status] ?? 2),
                    static fn ($a, $b): int => (string) $b->created_at <=> (string) $a->created_at,
                ])
                ->first();
            $nextFollowUp = $sponsor->contacts
                ->sortBy(static fn ($contact): string => $contact->follow_up_date->format('Y-m-d'))
                ->first();
            $accepted = $sponsor->sponsorships->where('status', SponsorshipStatus::ACCEPTED);

            $rows[(int) $sponsor->id] = [
                'package_name' => $leading?->package?->name,
                'package_badge' => $leading?->package?->badge_class,
                'next_follow_up_display' => $nextFollowUp?->follow_up_date->format('d.m.Y'),
                'next_follow_up_sort' => $nextFollowUp?->follow_up_date->format('Y-m-d') ?? '9999-12-31',
                'next_follow_up_overdue' => $nextFollowUp !== null
                    && $nextFollowUp->follow_up_date->format('Y-m-d') < date('Y-m-d'),
                'accepted_amount' => $seesTotals
                    ? (float) $accepted->sum(static fn ($sponsorship): float => (float) $sponsorship->amount)
                    : null,
            ];
        }

        return $rows;
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->policy->canCreateSponsor()) {
            return $this->deny($response);
        }

        $data = (array) $request->getParsedBody();
        $validation = $this->validateSponsorInput($data);

        if ($validation['error'] !== null) {
            $_SESSION['error'] = $validation['error'];
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        try {
            Sponsor::create($validation['values'] + [
                'created_by_user_id' => $this->policy->currentUserId(),
            ]);
            $_SESSION['success'] = 'Sponsor erfolgreich angelegt.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Anlegen des Sponsors.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        // Anhänge ohne file_content: die Seite zeigt nur Namen. Mit dem BLOB
        // zöge ein Sponsor mit ein paar Vertrags-PDFs zweistellige Megabytes
        // durch den Speicher, nur um eine Liste von Dateinamen zu rendern.
        $metadataOnly = static fn ($query) => $query->select(EntityAttachmentService::METADATA_COLUMNS);

        $sponsor = Sponsor::with([
            'sponsorships.package',
            'sponsorships.project',
            'sponsorships.assignedUser',
            'sponsorships.attachments' => $metadataOnly,
            'sponsorships.contacts.user',
            'sponsorships.benefits',
            'attachments' => $metadataOnly,
            'contacts.user',
            'contacts.sponsorship.package',
            'contacts.sponsorship.project',
        ])->findOrFail((int) $args['id']);

        (new InAppNotificationStore())->markEntityReadQuietly(
            (int) $this->policy->currentUserId(),
            'sponsor',
            (int) $sponsor->id,
            $this->logger
        );

        $users    = User::where('is_active', 1)->orderBy('last_name')->get();
        $projects = $this->policy->selectableProjects();
        $packages = SponsorPackage::with('benefits')->orderBy('min_amount')->get();

        $contactPermissions = $this->buildContactPermissions($sponsor);

        $success = $_SESSION['success'] ?? null;
        $error   = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'sponsoring/sponsors/detail.twig', [
            'sponsor'        => $sponsor,
            'can_manage_all' => $this->policy->canManageAll(),
            'can_edit_sponsor' => $this->policy->canEditSponsor($sponsor),
            'current_user_id' => $this->policy->currentUserId(),
            'sponsor_state'  => SponsorEngagementState::forSponsor($sponsor),
            'state_labels'   => SponsorEngagementState::labels(),
            'state_badges'   => SponsorEngagementState::badgeClasses(),
            // Je Zeile vorberechnet, damit das Template nur ein Kennzeichen
            // abfragt und die Regel an einer Stelle steht.
            'visible_details' => $this->buildDetailVisibility($sponsor),
            'visible_contacts' => $this->buildContactVisibility($sponsor),
            'may_edit_contacts' => $contactPermissions['editable'],
            'may_complete_follow_ups' => $contactPermissions['completable'],
            'sees_totals'    => $this->policy->canSeeFinancialTotals(),
            'accepted_total' => $this->policy->canSeeFinancialTotals()
                ? $this->acceptedTotal($sponsor)
                : null,
            'users'          => $users,
            'projects'       => $projects,
            // Nur für die Bearbeiten-Formulare: das bereits zugeordnete Projekt
            // bleibt wählbar, auch wenn es nicht mehr in $projects steht.
            'retained_projects' => $this->policy->retainedProjects($sponsor->sponsorships, $projects),
            'packages'       => $packages,
            'status_options' => SponsorshipStatus::options(),
            'status_labels'  => SponsorshipStatus::labels(),
            'status_badges'  => SponsorshipStatus::badgeClasses(),
            'benefit_rows'   => $this->buildBenefitRows($sponsor),
            'next_follow_up' => $this->nextOpenFollowUp($sponsor),
            'success'        => $success,
            'error'          => $error,
            'active_nav'     => 'sponsoring',
        ]);
    }

    /**
     * Leistungen je Vereinbarung, schon mit der Entscheidung, ob diese Person
     * abhaken darf - das Template fragt nur noch das Kennzeichen ab, wie bei
     * visible_details.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function buildBenefitRows(Sponsor $sponsor): array
    {
        $rows = [];
        foreach ($sponsor->sponsorships as $sponsorship) {
            $mayTick = $this->policy->canTickBenefits($sponsorship);
            $rows[(int) $sponsorship->id] = $sponsorship->benefits
                ->map(static fn ($benefit): array => [
                    'id' => (int) $benefit->id,
                    'description' => (string) $benefit->description,
                    'may_tick' => $mayTick,
                    'toggle_url' => '/sponsoring/sponsorship-benefits/' . $benefit->id . '/toggle',
                    'delivered_display' => $benefit->delivered_at?->format('d.m.Y'),
                ])
                ->values()
                ->all();
        }

        return $rows;
    }

    /**
     * Die nächste offene Wiedervorlage zu diesem Sponsor, für den Steckbrief
     * oben auf der Seite - oder null, wenn nichts ansteht.
     */
    private function nextOpenFollowUp(Sponsor $sponsor): ?\App\Models\SponsoringContact
    {
        return $sponsor->contacts
            ->filter(static fn ($contact): bool => !$contact->follow_up_done && $contact->follow_up_date !== null)
            ->sortBy(static fn ($contact): string => $contact->follow_up_date->format('Y-m-d'))
            ->first();
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $sponsor = Sponsor::find($id);
        if ($sponsor === null) {
            $_SESSION['error'] = 'Sponsor nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        // Stammdaten fremder Sponsoren pflegt nur das Sponsoring-Team; wer den
        // Eintrag selbst angelegt hat, darf ihn nachbessern.
        if (!$this->policy->canEditSponsor($sponsor)) {
            return $this->deny($response);
        }

        $data = (array) $request->getParsedBody();
        $validation = $this->validateSponsorInput($data);

        if ($validation['error'] !== null) {
            $_SESSION['error'] = $validation['error'];
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
        }

        try {
            $sponsor->update($validation['values']);
            $_SESSION['success'] = 'Sponsor erfolgreich aktualisiert.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Aktualisieren des Sponsors.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        if (!$this->policy->canManageAll()) {
            return $this->deny($response);
        }

        try {
            $sponsor = Sponsor::findOrFail($id);

            // `attachments` und `user_notifications` sind polymorph und haben
            // keinen Fremdschlüssel: was die Datenbank per Kaskade wegräumt (die
            // Vereinbarungen), nimmt sie nicht mit. Ohne diese Zeilen blieben die
            // Dateien als unerreichbare BLOB-Zeilen liegen und die Wiedervorlage
            // aus NotificationReminderService stünde weiter in der Glocke, mit
            // einem Link ins Nichts - die Bestätigung verspricht aber, dass alle
            // verknüpften Daten mitgehen.
            //
            // Über den gemeinsamen Dienst und nicht über eigene Abfragen: Welche
            // Tabellen so auf ihr Objekt zeigen, gehört an eine Stelle, sonst
            // laufen die Kopien wieder auseinander.
            $cleanup = new EntityCleanupService($this->attachments);
            $sponsorshipIds = Sponsorship::where('sponsor_id', $id)->pluck('id')->all();
            $cleanup->purgeForEntities(
                SponsorshipController::ENTITY_TYPE,
                array_map('intval', $sponsorshipIds)
            );
            $cleanup->purgeForEntity(self::ENTITY_TYPE, $id);

            $sponsor->delete();
            $_SESSION['success'] = 'Sponsor erfolgreich gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Fehler beim Löschen des Sponsors.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
    }

    /**
     * Anhänge am Sponsor selbst: Logo, Mediadaten, Rahmenvereinbarung. Vorher
     * ließen sich Dateien nur an einer einzelnen Vereinbarung ablegen - ein
     * Logo gehört aber zu keiner davon.
     */
    public function uploadAttachment(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $sponsor = Sponsor::find($id);
        if ($sponsor === null) {
            $_SESSION['error'] = 'Sponsor nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        if (!$this->policy->canEditSponsor($sponsor)) {
            return $this->deny($response);
        }

        $result = $this->attachments->storeUploads(
            $request->getUploadedFiles()['attachments'] ?? null,
            self::ENTITY_TYPE,
            $id
        );

        if ($result['error'] !== null) {
            $_SESSION['error'] = $result['error'];
        } elseif ($result['stored'] > 0) {
            $_SESSION['success'] = 'Anhang erfolgreich hochgeladen.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
    }

    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        $sponsorId    = (int) $args['id'];
        $attachmentId = (int) $args['attachment_id'];

        $sponsor = Sponsor::find($sponsorId);
        if ($sponsor === null) {
            $_SESSION['error'] = 'Sponsor nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        if (!$this->policy->canEditSponsor($sponsor)) {
            return $this->deny($response);
        }

        if ($this->attachments->deleteForEntity(self::ENTITY_TYPE, $sponsorId, $attachmentId)) {
            $_SESSION['success'] = 'Anhang erfolgreich gelöscht.';
        } else {
            $_SESSION['error'] = 'Anhang nicht gefunden.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    private function deny(Response $response): Response
    {
        $response->getBody()->write('Zugriff verweigert.');
        return $response->withStatus(403);
    }

    private function normalizeOptionalText(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Prüft die Stammdaten eines Sponsors und gibt entweder die erste
     * Beanstandung oder die fertigen Spaltenwerte zurück. Anlegen und Ändern
     * teilten sich vorher zwei wortgleiche Blöcke von je siebzig Zeilen; eine
     * neue Regel wäre unweigerlich nur in einem davon gelandet.
     *
     * @param array<string, mixed> $data
     * @return array{error: ?string, values: array<string, mixed>}
     */
    private function validateSponsorInput(array $data): array
    {
        $fail = static fn (string $message): array => ['error' => $message, 'values' => []];

        $name = trim(InputValidator::asString($data['name'] ?? null));
        if ($name === '') {
            return $fail('Name ist ein Pflichtfeld.');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return $fail('Der Name ist zu lang (max. 255 Zeichen).');
        }

        $contactPerson = $this->normalizeOptionalText($data['contact_person'] ?? null);
        $email         = $this->normalizeOptionalText($data['email'] ?? null);
        $phone         = $this->normalizeOptionalText($data['phone'] ?? null);
        $address       = $this->normalizeOptionalText($data['address'] ?? null);
        $website       = $this->normalizeOptionalText($data['website'] ?? null);
        $notes         = $this->normalizeOptionalText($data['notes'] ?? null);
        $blockNote     = $this->normalizeOptionalText($data['requests_blocked_note'] ?? null);

        if ($contactPerson !== null && mb_strlen($contactPerson) > self::MAX_CONTACT_PERSON_LENGTH) {
            return $fail('Die Kontaktperson ist zu lang (max. 255 Zeichen).');
        }

        if ($email !== null) {
            if (mb_strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return $fail('Bitte eine gültige E-Mail-Adresse angeben.');
            }
        }

        if ($phone !== null && mb_strlen($phone) > self::MAX_PHONE_LENGTH) {
            return $fail('Die Telefonnummer ist zu lang (max. 80 Zeichen).');
        }

        if ($website !== null) {
            // Nur Web-Adressen: FILTER_VALIDATE_URL nimmt auch javascript:-URLs an,
            // und der Steckbrief zeigt die Website als klickbaren Link.
            $scheme = strtolower((string) parse_url($website, PHP_URL_SCHEME));
            if (
                mb_strlen($website) > self::MAX_WEBSITE_LENGTH
                || filter_var($website, FILTER_VALIDATE_URL) === false
                || !in_array($scheme, ['http', 'https'], true)
            ) {
                return $fail('Bitte eine gültige Website-URL angeben.');
            }
        }

        if ($blockNote !== null && mb_strlen($blockNote) > self::MAX_BLOCK_NOTE_LENGTH) {
            return $fail('Die Begründung zur Absage ist zu lang (max. 2000 Zeichen).');
        }

        return [
            'error' => null,
            'values' => [
                'type'           => in_array(InputValidator::asString($data['type'] ?? null), ['organization', 'person'], true)
                    ? (string) $data['type']
                    : 'organization',
                'name'           => $name,
                'contact_person' => $contactPerson,
                'email'          => $email,
                'phone'          => $phone,
                'address'        => $address,
                'website'        => $website,
                'notes'          => $notes,
                'requests_blocked' => !empty($data['requests_blocked']),
                // Die Begründung hängt bewusst nicht am Schalter: wer eine
                // Sperre vorübergehend aufhebt, verlöre sonst beim Speichern die
                // festgehaltene Begründung und könnte sie nie wiederherstellen.
                'requests_blocked_note' => $blockNote,
            ],
        ];
    }

    /**
     * Zustand je Sponsor-Id, damit das Template ihn nicht pro Zeile neu
     * berechnen muss.
     *
     * @param iterable<Sponsor> $sponsors
     * @return array<int, string>
     */
    private function buildSponsorStates(iterable $sponsors): array
    {
        $states = [];
        foreach ($sponsors as $sponsor) {
            $states[(int) $sponsor->id] = SponsorEngagementState::forSponsor($sponsor);
        }

        return $states;
    }

    /**
     * Je Vereinbarung: darf Betrag und Anhang gesehen werden?
     *
     * @return array<int, bool>
     */
    private function buildDetailVisibility(Sponsor $sponsor): array
    {
        $visible = [];
        foreach ($sponsor->sponsorships as $sponsorship) {
            $visible[(int) $sponsorship->id] = $this->policy->canSeeSponsorshipDetails($sponsorship);
        }

        return $visible;
    }

    /**
     * Je Kontakt: darf die Zusammenfassung gelesen werden? Deckt beide
     * Darstellungen ab - die Liste unter der Vereinbarung und den Reiter.
     *
     * @return array<int, bool>
     */
    private function buildContactVisibility(Sponsor $sponsor): array
    {
        $visible = [];
        foreach ($sponsor->contacts as $contact) {
            $visible[(int) $contact->id] = $this->policy->canSeeContactDetails($contact);
        }

        foreach ($sponsor->sponsorships as $sponsorship) {
            foreach ($sponsorship->contacts as $contact) {
                $visible[(int) $contact->id] = $this->policy->canSeeContactDetails($contact);
            }
        }

        return $visible;
    }

    /**
     * Je Kontakt: darf die Zusammenfassung geändert und die Wiedervorlage
     * abgehakt werden?
     *
     * Zwei getrennte Fragen, weil sie seit der Freigabe der Zuständigen
     * auseinanderfallen: Ändern bleibt beim Urheber, Abhaken darf zusätzlich,
     * wer bei der Vereinbarung als zuständig eingetragen ist. Das Template
     * setzte die Regel vorher selbst aus Urheber und Vollrecht zusammen - der
     * neue Weg wäre dort nie angekommen.
     *
     * @return array{editable: array<int, bool>, completable: array<int, bool>}
     */
    private function buildContactPermissions(Sponsor $sponsor): array
    {
        $editable = [];
        $completable = [];

        $collect = function ($contact) use (&$editable, &$completable): void {
            $editable[(int) $contact->id] = $this->policy->canEditContact($contact);
            $completable[(int) $contact->id] = $this->policy->canCompleteFollowUp($contact);
        };

        foreach ($sponsor->contacts as $contact) {
            $collect($contact);
        }

        foreach ($sponsor->sponsorships as $sponsorship) {
            foreach ($sponsorship->contacts as $contact) {
                // Beziehung setzen statt nachladen: canCompleteFollowUp() fragt
                // die zuständige Person der Vereinbarung ab, und die liegt hier
                // bereits vor.
                $contact->setRelation('sponsorship', $sponsorship);
                $collect($contact);
            }
        }

        return ['editable' => $editable, 'completable' => $completable];
    }

    /**
     * Summe der zugesagten Beträge. Gehört hierher und nicht ins Template: dort
     * stand der Status als nackte Zeichenkette und liefe bei der nächsten
     * Statusänderung von den Dashboard-Zahlen weg.
     */
    private function acceptedTotal(Sponsor $sponsor): float
    {
        $total = 0.0;
        foreach ($sponsor->sponsorships as $sponsorship) {
            if ((string) $sponsorship->status === SponsorshipStatus::ACCEPTED) {
                $total += (float) $sponsorship->amount;
            }
        }

        return $total;
    }
}
