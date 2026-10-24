<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Controllers\Concerns\AttachmentUploadJson;
use App\Models\Sponsor;
use App\Models\SponsorCategory;
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
use App\Util\RequestFormat;
use App\Util\SponsorCategoryResolver;
use App\Util\SponsorDuplicateFinder;
use App\Util\SponsorLogoUpload;
use App\Services\ModalFormService;

class SponsorController
{
    use AttachmentUploadJson;

    private const MAX_NAME_LENGTH = 255;
    private const MAX_CONTACT_PERSON_LENGTH = 255;
    private const MAX_EMAIL_LENGTH = 255;
    private const MAX_PHONE_LENGTH = 80;
    private const MAX_WEBSITE_LENGTH = 2048;

    private const MAX_BLOCK_NOTE_LENGTH = 2000;

    /** Anhänge, die unmittelbar am Sponsor hängen: Logo, Mediadaten, Rahmenvertrag. */
    /** Derselbe Wert wie am Modell - festgelegt wird er nur dort. */
    public const ENTITY_TYPE = Sponsor::ENTITY_TYPE;

    private const CREATE_FORM_SCOPE = 'sponsor_create';
    private const EDIT_FORM_SCOPE_PREFIX = 'sponsor_edit_';

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
        $sponsors = Sponsor::withCount('logos')->with([
            'category',
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
            'categories'     => $this->categories(),
            'create_form'    => $this->takeFormState(self::CREATE_FORM_SCOPE),
            'success'        => $success,
            'error'          => $error,
            'active_nav'     => 'sponsoring',
        ]);
    }

    /**
     * Alle Kategorien für Filter und Vorschlagsliste, alphabetisch.
     *
     * @return \Illuminate\Support\Collection<int, SponsorCategory>
     */
    private function categories(): \Illuminate\Support\Collection
    {
        return SponsorCategory::orderBy('name')->get();
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

        $createForm = new ModalFormService(self::CREATE_FORM_SCOPE);
        if ($this->warnAboutDuplicate($createForm, $data, (string) $validation['values']['name'], null)) {
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }
        $createForm->clear();

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
            'category',
            'logos',
            'sponsorships.package',
            'sponsorships.project',
            'sponsorships.assignedUser',
            'sponsorships.attachments' => $metadataOnly,
            'sponsorships.contacts.user',
            'sponsorships.benefits',
            'sponsorships.deliverables',
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
        $packages = SponsorPackage::with(['benefits', 'deliverables'])->orderBy('min_amount')->get();

        $contactPermissions = $this->buildContactPermissions($sponsor);

        $success = $_SESSION['success'] ?? null;
        $error   = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'sponsoring/sponsors/detail.twig', [
            'sponsor'        => $sponsor,
            'can_manage_all' => $this->policy->canManageAll(),
            'can_edit_sponsor' => $this->policy->canEditSponsor($sponsor),
            // Dieselbe Frage, die AttachmentAccessRegistry::maySeeSponsor() beim
            // Abruf stellt. Ohne sie listete die Seite jeden Dateinamen samt
            // Herunterladen-Knopf, und der Knopf endete in einer Abweisung - der
            // Name selbst verriet dabei schon, was die Datei enthält.
            'sees_sponsor_details' => $this->policy->canSeeSponsorDetails($sponsor),
            // Nur die Anzahl für die Sprungleiste - sie steht auch ohne Einsicht
            // unter 'Anhänge zum Sponsor'; Namen und Links bleiben hinter der Prüfung.
            'attachment_count' => $sponsor->attachments->count(),
            'can_manage_logo' => $this->policy->canManageLogo($sponsor),
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
            'benefit_rows'   => $this->buildLineRows($sponsor, 'benefits', 'delivered', 'sponsorship-benefits'),
            'deliverable_rows' => $this->buildLineRows($sponsor, 'deliverables', 'received', 'sponsorship-deliverables'),
            'next_follow_up' => $this->nextOpenFollowUp($sponsor),
            'categories'     => $this->categories(),
            'edit_form'      => $this->takeFormState(self::EDIT_FORM_SCOPE_PREFIX . $sponsor->id),
            'success'        => $success,
            'error'          => $error,
            'active_nav'     => 'sponsoring',
        ]);
    }

    /**
     * Leistungen je Vereinbarung - Gegenleistungen oder Leistungen des
     * Sponsors -, schon mit der Entscheidung, ob diese Person abhaken darf: das
     * Template fragt nur noch das Kennzeichen ab, wie bei visible_details.
     *
     * $field ist der Wortstamm der Haken-Spalte ("delivered" => delivered_at),
     * $path der Routenteil vor /{id}/toggle.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function buildLineRows(Sponsor $sponsor, string $relation, string $field, string $path): array
    {
        $rows = [];
        foreach ($sponsor->sponsorships as $sponsorship) {
            $mayTick = $this->policy->canTickBenefits($sponsorship);
            $rows[(int) $sponsorship->id] = $sponsorship->{$relation}
                ->map(static fn ($line): array => [
                    'id' => (int) $line->id,
                    'description' => (string) $line->description,
                    'may_tick' => $mayTick,
                    'toggle_url' => '/sponsoring/' . $path . '/' . $line->id . '/toggle',
                    'delivered_display' => $line->{$field . '_at'}?->format('d.m.Y'),
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

        // Nur beim Umbenennen: Wer bloß die Telefonnummer ändert, soll nicht
        // über eine Dublette stolpern, die längst bewusst angelegt wurde.
        $editForm = new ModalFormService(self::EDIT_FORM_SCOPE_PREFIX . $id);
        $newName = (string) $validation['values']['name'];
        if ($newName !== $sponsor->name && $this->warnAboutDuplicate($editForm, $data, $newName, $id)) {
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
        }
        $editForm->clear();

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
            $cleanup->purgeForEntity(Sponsor::LOGO_ENTITY_TYPE, $id);

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
        $expectsJson = RequestFormat::expectsJson($request);
        if ($sponsor === null) {
            if ($expectsJson) {
                return $this->attachmentUploadJsonError($response, 'Sponsor nicht gefunden.', 404);
            }
            $_SESSION['error'] = 'Sponsor nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        if (!$this->policy->canEditSponsor($sponsor)) {
            return $expectsJson
                ? $this->attachmentUploadJsonError($response, 'Zugriff verweigert.', 403)
                : $this->deny($response);
        }

        $result = $this->attachments->storeUploads(
            $request->getUploadedFiles()['attachments'] ?? null,
            self::ENTITY_TYPE,
            $id
        );

        if ($expectsJson) {
            return $this->attachmentUploadJson($response, $result);
        }

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

    /**
     * Lädt eine oder mehrere Logo-Dateien hoch. Logos kommen in vielen
     * Formaten und Varianten, deshalb gibt es kein Ersetzen: hinzufügen, was
     * da ist, und Überholtes löschen. Typ und Größe prüft SponsorLogoUpload
     * statt der engeren Anhang-Regeln. Die Ablegefläche schickt je Datei eine
     * Anfrage und erwartet JSON.
     */
    public function uploadLogo(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $expectsJson = RequestFormat::expectsJson($request);
        $sponsor = Sponsor::find($id);
        if ($sponsor === null) {
            if ($expectsJson) {
                return $this->attachmentUploadJsonError($response, 'Sponsor nicht gefunden.', 404);
            }
            $_SESSION['error'] = 'Sponsor nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        if (!$this->policy->canManageLogo($sponsor)) {
            return $expectsJson
                ? $this->attachmentUploadJsonError($response, 'Zugriff verweigert.', 403)
                : $this->deny($response);
        }

        $result = $this->attachments->storeUploads(
            $request->getUploadedFiles()['logos'] ?? null,
            Sponsor::LOGO_ENTITY_TYPE,
            $id,
            null,
            static fn (int $size, string $mimeType): array => SponsorLogoUpload::validate($size, $mimeType)
        );

        if ($result['stored'] > 0) {
            $this->logger->info('Sponsor logo files uploaded.', [
                'event' => 'sponsoring.logo.uploaded',
                'sponsor_id' => $id,
                'attachment_ids' => $result['ids'],
                'user_id' => $this->policy->currentUserId(),
            ]);
        }

        if ($expectsJson) {
            return $this->attachmentUploadJson($response, $result);
        }

        if ($result['error'] !== null) {
            $_SESSION['error'] = $result['error'];
        } elseif ($result['stored'] > 0) {
            $_SESSION['success'] = $result['stored'] === 1 ? 'Logo-Datei hochgeladen.' : 'Logo-Dateien hochgeladen.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
    }

    /**
     * Löscht eine Logo-Datei. Dasselbe Recht wie beim Hochladen: Ohne
     * "Ersetzen" muss auch die zuständige Person eine falsche Variante wieder
     * entfernen können. Gelöscht wird nur, was wirklich an diesem Sponsor hängt.
     */
    public function deleteLogo(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $attachmentId = (int) $args['attachment_id'];
        $sponsor = Sponsor::find($id);
        if ($sponsor === null) {
            $_SESSION['error'] = 'Sponsor nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        if (!$this->policy->canManageLogo($sponsor)) {
            return $this->deny($response);
        }

        if ($this->attachments->deleteForEntity(Sponsor::LOGO_ENTITY_TYPE, $id, $attachmentId)) {
            $this->logger->info('Sponsor logo file deleted.', [
                'event' => 'sponsoring.logo.deleted',
                'sponsor_id' => $id,
                'attachment_id' => $attachmentId,
                'user_id' => $this->policy->currentUserId(),
            ]);
            $_SESSION['success'] = 'Logo-Datei gelöscht.';
        } else {
            $_SESSION['error'] = 'Logo-Datei nicht gefunden.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $id)->withStatus(302);
    }

    private function deny(Response $response): Response
    {
        $response->getBody()->write('Zugriff verweigert.');
        return $response->withStatus(403);
    }

    /**
     * Merkt sich das Formular samt Treffer und meldet true, wenn es einen
     * ähnlich benannten Sponsor gibt und die Dublette noch nicht bestätigt
     * ist. Ein Hinweis, keine Sperre: "Trotzdem anlegen" sendet dasselbe
     * Formular mit confirm_duplicate=1 erneut.
     *
     * @param array<string, mixed> $data
     */
    private function warnAboutDuplicate(ModalFormService $form, array $data, string $name, ?int $exceptId): bool
    {
        if (!empty($data['confirm_duplicate'])) {
            return false;
        }

        $duplicate = SponsorDuplicateFinder::find($name, $exceptId);
        if ($duplicate === null) {
            return false;
        }

        $form->setError(
            'Es gibt bereits einen ähnlichen Sponsor.',
            array_map(static fn (mixed $value): string => InputValidator::asString($value), $data),
            ['duplicate_id' => (int) $duplicate->id, 'duplicate_name' => (string) $duplicate->name],
            false
        );

        return true;
    }

    /**
     * Zustand eines Sponsor-Formulars nach einer Dublettenwarnung, für genau
     * eine Anzeige: Danach öffnet ein Neuladen den Dialog nicht noch einmal.
     *
     * @return array{form: array<string, mixed>, error: mixed, message: ?string, open_modal: bool}
     */
    private function takeFormState(string $scope): array
    {
        $form = new ModalFormService($scope);
        $state = $form->getState();
        $form->clear();

        return $state;
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

        // Als letzte Prüfung: Eine neue Kategorie entsteht dabei schon, und das
        // soll erst passieren, wenn der Rest der Eingabe in Ordnung ist.
        $category = SponsorCategoryResolver::resolve($data['category'] ?? null);
        if ($category['error'] !== null) {
            return $fail($category['error']);
        }

        return [
            'error' => null,
            'values' => [
                'type'           => in_array(InputValidator::asString($data['type'] ?? null), ['organization', 'person'], true)
                    ? (string) $data['type']
                    : 'organization',
                'category_id'    => $category['id'],
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
