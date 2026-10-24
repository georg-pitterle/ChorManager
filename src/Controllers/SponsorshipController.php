<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Project;
use App\Models\Sponsor;
use App\Models\SponsorPackage;
use App\Models\Sponsorship;
use App\Models\SponsorshipBenefit;
use App\Models\SponsorshipDeliverable;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Services\EntityAttachmentService;
use App\Util\AmountNormalizer;
use App\Util\BenefitList;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Util\SponsorshipStatus;
use App\Util\InputValidator;

class SponsorshipController
{
    public const AMOUNT_ERROR = 'Ungültiger Betrag. Bitte eine Zahl ab 0 eingeben.';
    public const STATUS_ERROR = 'Ungültiger Status für die Vereinbarung.';
    public const PROJECT_ERROR = 'Vereinbarungen lassen sich nur zu einem laufenden Projekt erfassen.';
    public const ASSIGNEE_ERROR = 'Die zuständige Person muss ein aktives Mitglied sein.';
    public const BLOCKED_ERROR = 'Dieser Sponsor hat sich weitere Anfragen verbeten.';
    public const PAID_AT_ERROR = 'Bitte ein gültiges Datum für "Bezahlt am" angeben.';

    /** Anhänge, die an einer Vereinbarung hängen: Verträge, Angebote. */
    /** Derselbe Wert wie am Modell - festgelegt wird er nur dort. */
    public const ENTITY_TYPE = Sponsorship::ENTITY_TYPE;

    private SponsoringPolicy $policy;
    private EntityAttachmentService $attachments;
    private LoggerInterface $logger;

    public function __construct(
        SponsoringPolicy $policy,
        EntityAttachmentService $attachments,
        ?LoggerInterface $logger = null
    ) {
        $this->policy = $policy;
        $this->attachments = $attachments;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Anhänge der Vereinbarung speichern. Eine abgelehnte Datei meldet sich als
     * Fehlermeldung, hält die übrigen aber nicht auf.
     */
    private function handleAttachments(Request $request, int $sponsorshipId): void
    {
        $result = $this->attachments->storeUploads(
            $request->getUploadedFiles()['attachments'] ?? null,
            self::ENTITY_TYPE,
            $sponsorshipId
        );

        if ($result['error'] !== null) {
            $_SESSION['error'] = $result['error'];
        }
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->policy->canCreateSponsorship()) {
            return $this->deny($response);
        }

        $data      = (array) $request->getParsedBody();
        $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        $amount    = trim(InputValidator::asString($data['amount'] ?? null));

        if (!$sponsorId) {
            $_SESSION['error'] = 'Sponsor ist ein Pflichtfeld.';
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $normalizedAmount = self::validateAmount($amount);
        if ($normalizedAmount === null) {
            $_SESSION['error'] = self::AMOUNT_ERROR;
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        // Ohne diese Prüfung landet ein unbekannter Wert direkt im Enum der
        // Spalte: MySQL weist ihn ab, und der Fehler kam bisher als
        // nichtssagendes "Fehler beim Anlegen" zurück.
        $status = InputValidator::asString($data['status'] ?? SponsorshipStatus::DEFAULT);
        if (!SponsorshipStatus::isValid($status)) {
            $_SESSION['error'] = self::STATUS_ERROR;
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        // Die Generalabsage ist kein Anzeigehinweis, sondern eine Zusage an den
        // Sponsor: wer sich weitere Anfragen verbeten hat, bekommt keine neue
        // Vereinbarung angelegt.
        if ($this->isBlocked($sponsorId)) {
            $_SESSION['error'] = self::BLOCKED_ERROR;
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $projectId = !empty($data['project_id']) ? (int) $data['project_id'] : null;
        if (!$this->policy->canUseProject($projectId)) {
            $_SESSION['error'] = self::PROJECT_ERROR;
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $assignee = $this->validateAssignedUser($data, null);
        if ($assignee['error'] !== null) {
            $_SESSION['error'] = $assignee['error'];
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $period = $this->prefillPeriodFromProject($data, $projectId);

        $benefits = BenefitList::parse($data['benefits'] ?? null);
        if ($benefits['error'] !== null) {
            $_SESSION['error'] = $benefits['error'];
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $deliverables = BenefitList::parse($data['deliverables'] ?? null, BenefitList::DELIVERABLE_LABELS);
        if ($deliverables['error'] !== null) {
            $_SESSION['error'] = $deliverables['error'];
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $paidAt = self::validatePaidAt($data['paid_at'] ?? null);
        if ($paidAt === false) {
            $_SESSION['error'] = self::PAID_AT_ERROR;
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        $packageId = !empty($data['package_id']) ? (int) $data['package_id'] : null;

        try {
            $sponsorship = Capsule::connection()->transaction(function () use (
                $sponsorId,
                $projectId,
                $packageId,
                $assignee,
                $normalizedAmount,
                $paidAt,
                $status,
                $period,
                $data,
                $benefits,
                $deliverables
            ): Sponsorship {
                $package = $packageId !== null ? SponsorPackage::find($packageId) : null;
                $sponsorship = Sponsorship::create($this->logoNeeds($data, $package) + [
                'sponsor_id'       => $sponsorId,
                'project_id'       => $projectId,
                'package_id'       => $packageId,
                'assigned_user_id' => $assignee['value'],
                'created_by_user_id' => $this->policy->currentUserId(),
                'amount'           => $normalizedAmount,
                'paid_at'          => $paidAt,
                'status'           => $status,
                'start_date'       => $period['start_date'],
                'end_date'         => $period['end_date'],
                'notes'            => trim(InputValidator::asString($data['notes'] ?? null)) ?: null,
                ]);

                // Fehlt das Feld ganz (Formular ohne JavaScript-Vorschlag, ältere
                // Aufrufe), gilt die Vorgabe des Pakets. Ein mitgeschicktes leeres
                // Feld ist dagegen eine Entscheidung: keine Gegenleistung.
                // Kopiert, nicht verwiesen: was ausgehandelt ist, ändert sich
                // nicht mit dem Paket.
                // Dasselbe für die Leistungen des Sponsors.
                $this->syncLines(
                    $sponsorship->benefits(),
                    $benefits['items'] ?? ($package?->benefits()->pluck('description')->all() ?? [])
                );
                $this->syncLines(
                    $sponsorship->deliverables(),
                    $deliverables['items'] ?? ($package?->deliverables()->pluck('description')->all() ?? [])
                );

                return $sponsorship;
            });

            $this->handleAttachments($request, $sponsorship->id);

            $_SESSION['success'] = 'Vereinbarung angelegt.';
        } catch (\Exception $e) {
            // Der Grund gehört ins Protokoll, nicht vor die Augen des
            // Sponsoring-Teams: Hier stand früher der rohe SQLSTATE-Text des
            // Treibers, dann nur noch der Doppelpunkt, der von ihm übrig blieb.
            $this->logger->error('Creating a sponsorship failed.', [
                'event' => 'sponsorship.create.failed',
                'sponsor_id' => $sponsorId,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Die Vereinbarung konnte nicht angelegt werden.';
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        try {
            $sponsorship = Sponsorship::findOrFail($id);
            $sponsorId   = $sponsorship->sponsor_id;
            $providedSponsorId = (int) ($data['sponsor_id'] ?? 0);

            if ($providedSponsorId > 0 && $providedSponsorId !== (int) $sponsorship->sponsor_id) {
                return $this->deny($response);
            }

            // Fremde Vereinbarungen ändert nur das Sponsoring-Team.
            if (!$this->policy->canEditSponsorship($sponsorship)) {
                return $this->deny($response);
            }

            $normalizedAmount = self::validateAmount(trim(InputValidator::asString($data['amount'] ?? null)));
            if ($normalizedAmount === null) {
                $_SESSION['error'] = self::AMOUNT_ERROR;
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
            }

            $status = InputValidator::asString($data['status'] ?? $sponsorship->status);
            if (!SponsorshipStatus::isValid($status)) {
                $_SESSION['error'] = self::STATUS_ERROR;
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
            }

            $projectId = !empty($data['project_id']) ? (int) $data['project_id'] : null;
            if ($projectId !== (int) $sponsorship->project_id && !$this->policy->canUseProject($projectId)) {
                $_SESSION['error'] = self::PROJECT_ERROR;
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
            }

            $currentAssignee = $sponsorship->assigned_user_id === null
                ? null
                : (int) $sponsorship->assigned_user_id;
            $assignee = $this->validateAssignedUser($data, $currentAssignee);
            if ($assignee['error'] !== null) {
                $_SESSION['error'] = $assignee['error'];
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
            }

            $benefits = BenefitList::parse($data['benefits'] ?? null);
            if ($benefits['error'] !== null) {
                $_SESSION['error'] = $benefits['error'];
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
            }

            $deliverables = BenefitList::parse($data['deliverables'] ?? null, BenefitList::DELIVERABLE_LABELS);
            if ($deliverables['error'] !== null) {
                $_SESSION['error'] = $deliverables['error'];
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
            }

            // Fehlt das Feld, bleibt der Zahlungsstand unverändert - ältere
            // Formulare und Aufrufe ohne das Feld sollen ihn nicht löschen.
            $paidAt = array_key_exists('paid_at', $data)
                ? self::validatePaidAt($data['paid_at'])
                : $sponsorship->paid_at?->format('Y-m-d');
            if ($paidAt === false) {
                $_SESSION['error'] = self::PAID_AT_ERROR;
                return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
            }

            // Beim Ändern wird der Zeitraum NICHT aus dem Projekt nachgefüllt:
            // ein leeres Feld ist hier eine bewusste Eingabe ("unbefristet"),
            // und eine Vorbelegung machte das Leeren unmöglich.
            Capsule::connection()->transaction(function () use (
                $sponsorship,
                $projectId,
                $data,
                $assignee,
                $normalizedAmount,
                $paidAt,
                $status,
                $benefits,
                $deliverables
            ): void {
                $sponsorship->update($this->logoNeeds($data, null, $sponsorship) + [
                'project_id'       => $projectId,
                'package_id'       => !empty($data['package_id']) ? (int) $data['package_id'] : null,
                'assigned_user_id' => $assignee['value'],
                'amount'           => $normalizedAmount,
                'paid_at'          => $paidAt,
                'status'           => $status,
                'start_date'       => !empty($data['start_date']) ? (string) $data['start_date'] : null,
                'end_date'         => !empty($data['end_date']) ? (string) $data['end_date'] : null,
                'notes'            => trim(InputValidator::asString($data['notes'] ?? null)) ?: null,
                ]);

                if ($benefits['items'] !== null) {
                    $this->syncLines($sponsorship->benefits(), $benefits['items']);
                }
                if ($deliverables['items'] !== null) {
                    $this->syncLines($sponsorship->deliverables(), $deliverables['items']);
                }
            });

            $this->handleAttachments($request, $sponsorship->id);

            $_SESSION['success'] = 'Vereinbarung gespeichert.';
        } catch (ModelNotFoundException $e) {
            // Der häufigste Weg hierher: Die Seite lag offen, während jemand
            // anderes dieselbe Vereinbarung entfernt hat. Kein Betriebsfehler.
            $_SESSION['error'] = 'Die Vereinbarung wurde nicht gefunden. '
                . 'Möglicherweise wurde sie bereits gelöscht.';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->error('Updating a sponsorship failed.', [
                'event' => 'sponsorship.update.failed',
                'sponsorship_id' => $id,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Die Vereinbarung konnte nicht aktualisiert werden.';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    /**
     * Die zuständige Person aus dem Formular, oder null für "niemand".
     *
     * Der Wert kam zuvor ungeprüft aus der Anfrage in die Zeile. Eine Kennung,
     * die keinem Mitglied gehört, lief damit in den Fremdschlüssel auf `users`
     * und kam als nichtssagendes "konnte nicht angelegt werden" zurück - dieselbe
     * Art Lücke, die SponsoringPolicy::canUseProject() für das Projekt und
     * ProjectController::addMember() für das Mitglied schon schließt.
     *
     * Geprüft wird gegen die **aktiven** Mitglieder, also genau die, die das
     * Formular anbietet. Eine Ausnahme gilt für die bereits eingetragene Person:
     * Sie bleibt gültig, auch wenn sie inzwischen archiviert wurde. Sonst
     * scheiterte ein Speichern aus einem ganz anderen Grund - ein korrigierter
     * Betrag - an einer Zuständigkeit, die niemand angefasst hat, oder das
     * Formular hätte sie still gelöscht. Dieselbe Überlegung steht an
     * SponsoringPolicy::retainedProjects().
     *
     * @param array<string, mixed> $data
     * @param int|null $current bisher eingetragene Person, null beim Anlegen
     * @return array{error: string|null, value: int|null}
     */
    private function validateAssignedUser(array $data, ?int $current): array
    {
        if (empty($data['assigned_user_id'])) {
            return ['error' => null, 'value' => null];
        }

        $submitted = (int) $data['assigned_user_id'];

        if ($submitted <= 0) {
            return ['error' => self::ASSIGNEE_ERROR, 'value' => null];
        }

        if ($current !== null && $submitted === $current) {
            return ['error' => null, 'value' => $submitted];
        }

        $isActiveMember = User::query()
            ->whereKey($submitted)
            ->where('is_active', 1)
            ->exists();

        return $isActiveMember
            ? ['error' => null, 'value' => $submitted]
            : ['error' => self::ASSIGNEE_ERROR, 'value' => null];
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        try {
            $sponsorship = Sponsorship::findOrFail($id);
            $sponsorId   = $sponsorship->sponsor_id;
            $providedSponsorId = (int) ($data['sponsor_id'] ?? 0);

            if ($providedSponsorId > 0 && $providedSponsorId !== (int) $sponsorship->sponsor_id) {
                return $this->deny($response);
            }

            if (!$this->policy->canDeleteSponsorship($sponsorship)) {
                return $this->deny($response);
            }

            $this->attachments->deleteAllForEntities(self::ENTITY_TYPE, [$id]);
            $sponsorship->delete();
            $_SESSION['success'] = 'Vereinbarung erfolgreich gelöscht.';
        } catch (ModelNotFoundException $e) {
            $_SESSION['error'] = 'Die Vereinbarung wurde nicht gefunden. '
                . 'Möglicherweise wurde sie bereits gelöscht.';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->error('Deleting a sponsorship failed.', [
                'event' => 'sponsorship.delete.failed',
                'sponsorship_id' => $id,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Die Vereinbarung konnte nicht gelöscht werden.';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        $sponsorshipId = (int) $args['id'];
        $attachmentId  = (int) $args['attachment_id'];
        $data          = (array) $request->getParsedBody();

        $sponsorship = Sponsorship::find($sponsorshipId);
        if ($sponsorship === null) {
            $_SESSION['error'] = 'Vereinbarung nicht gefunden.';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
            return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
        }

        if (!$this->policy->canEditSponsorship($sponsorship)) {
            return $this->deny($response);
        }

        if ($this->attachments->deleteForEntity(self::ENTITY_TYPE, $sponsorshipId, $attachmentId)) {
            $_SESSION['success'] = 'Anhang erfolgreich gelöscht.';
        } else {
            $_SESSION['error'] = 'Anhang nicht gefunden.';
        }

        return $response
            ->withHeader('Location', '/sponsoring/sponsors/' . $sponsorship->sponsor_id)
            ->withStatus(302);
    }

    /**
     * Eine Gegenleistung abhaken oder den Haken zurücknehmen.
     *
     * Das Formular schickt den gewünschten Zustand (`delivered` = 1 oder 0),
     * nicht "umdrehen": Bei einem Doppelklick oder von einer veralteten Seite
     * aus nähme ein Umschalten einen Haken still wieder zurück. Gilt der
     * Zustand schon, ändert sich nichts - auch nicht, wer abgehakt hat.
     *
     * Zurück geht es dorthin, wo geklickt wurde: aufs Dashboard, wenn die
     * Liste der offenen Leistungen der Ausgangspunkt war, sonst zum Sponsor.
     */
    public function toggleBenefit(Request $request, Response $response, array $args): Response
    {
        $benefit = SponsorshipBenefit::with('sponsorship')->find((int) $args['id']);
        if ($benefit === null || $benefit->sponsorship === null) {
            $_SESSION['error'] = 'Die Gegenleistung wurde nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring')->withStatus(302);
        }

        if (!$this->policy->canTickBenefits($benefit->sponsorship)) {
            return $this->deny($response);
        }

        return $this->tickLine($request, $response, $benefit, 'delivered');
    }

    /**
     * Hakt eine Leistung des Sponsors als erhalten ab oder nimmt den Haken
     * zurück. Dasselbe Recht wie bei den Gegenleistungen: Beides wird von
     * denselben Leuten nachgehalten.
     */
    public function toggleDeliverable(Request $request, Response $response, array $args): Response
    {
        $deliverable = SponsorshipDeliverable::with('sponsorship')->find((int) $args['id']);
        if ($deliverable === null || $deliverable->sponsorship === null) {
            $_SESSION['error'] = 'Die Leistung des Sponsors wurde nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring')->withStatus(302);
        }

        if (!$this->policy->canTickBenefits($deliverable->sponsorship)) {
            return $this->deny($response);
        }

        return $this->tickLine($request, $response, $deliverable, 'received');
    }

    /**
     * Setzt oder löscht den Haken einer Leistungszeile. $field ist zugleich der
     * Name des Formularfelds und der Wortstamm der Spalten ("delivered" =>
     * delivered_at, delivered_by_user_id).
     */
    private function tickLine(
        Request $request,
        Response $response,
        SponsorshipBenefit|SponsorshipDeliverable $line,
        string $field
    ): Response {
        $data = (array) $request->getParsedBody();
        $wantDone = InputValidator::asString($data[$field] ?? '1') === '1';
        $isDone = $line->getAttribute($field . '_at') !== null;

        if ($wantDone !== $isDone) {
            $line->update([
                $field . '_at' => $wantDone ? Carbon::today()->format('Y-m-d') : null,
                $field . '_by_user_id' => $wantDone ? $this->policy->currentUserId() : null,
            ]);
        }

        $query = $request->getQueryParams();
        $target = ($query['redirect_to'] ?? '') === 'dashboard'
            ? '/sponsoring'
            : '/sponsoring/sponsors/' . $line->sponsorship->sponsor_id;

        return $response->withHeader('Location', $target)->withStatus(302);
    }

    /**
     * Gleicht eine Leistungsliste der Vereinbarung - Gegenleistungen oder
     * Leistungen des Sponsors - mit der neuen Liste ab.
     *
     * Eine Zeile, deren Text gleich bleibt, behält ihren Haken - sonst müsste
     * nach jeder Korrektur der Liste alles neu abgehakt werden. Neue Zeilen
     * kommen offen hinzu, gestrichene verschwinden.
     *
     * @param HasMany<Model, Sponsorship> $relation
     * @param list<string> $items
     */
    private function syncLines(HasMany $relation, array $items): void
    {
        $existing = (clone $relation)->get();
        $kept = [];

        foreach ($items as $index => $description) {
            $match = $existing->first(
                static fn (Model $line): bool => $line->getAttribute('description') === $description
                    && !in_array($line->getKey(), $kept, true)
            );

            if ($match !== null) {
                $match->update(['sort_order' => $index]);
                $kept[] = $match->getKey();
                continue;
            }

            $created = (clone $relation)->create(['description' => $description, 'sort_order' => $index]);
            $kept[] = $created->getKey();
        }

        (clone $relation)->whereNotIn('id', $kept === [] ? [0] : $kept)->delete();
    }

    /**
     * Wofür die Vereinbarung das Logo braucht.
     *
     * Die Formulare senden logo_needs=1 mit, sobald sie die beiden Häkchen
     * zeigen - nur so lässt sich ein abgewähltes Häkchen von einem fehlenden
     * Feld unterscheiden. Fehlt der Marker, gilt beim Anlegen die Vorgabe des
     * Pakets, beim Ändern bleibt der bisherige Stand.
     *
     * @param array<string, mixed> $data
     * @return array{needs_logo_print: bool, needs_logo_online: bool}
     */
    private function logoNeeds(array $data, ?SponsorPackage $package, ?Sponsorship $current = null): array
    {
        if (!empty($data['logo_needs'])) {
            return [
                'needs_logo_print' => !empty($data['needs_logo_print']),
                'needs_logo_online' => !empty($data['needs_logo_online']),
            ];
        }

        $source = $current ?? $package;

        return [
            'needs_logo_print' => (bool) ($source?->needs_logo_print ?? false),
            'needs_logo_online' => (bool) ($source?->needs_logo_online ?? false),
        ];
    }

    /**
     * "Bezahlt am": leer heißt "noch offen" (null), ein gültiges Datum wird
     * übernommen, alles andere ist ein Fehler (false).
     */
    private static function validatePaidAt(mixed $value): string|false|null
    {
        $date = trim(InputValidator::asString($value));
        if ($date === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : false;
    }

    private function deny(Response $response): Response
    {
        $response->getBody()->write('Zugriff verweigert.');
        return $response->withStatus(403);
    }

    /**
     * Ob der Sponsor sich weitere Anfragen verbeten hat.
     */
    private function isBlocked(int $sponsorId): bool
    {
        return Sponsor::whereKey($sponsorId)->where('requests_blocked', true)->exists();
    }

    /**
     * Zeitraum einer NEUEN Vereinbarung. Bleibt ein Feld leer und hängt die
     * Vereinbarung an einem Projekt, wird der Projektzeitraum übernommen -
     * ausgehandelt wird ohnehin je Projekt, und bisher tippte ihn jede Person
     * von Hand ab. Das Formular schlägt denselben Wert schon im Browser vor;
     * diese Ergänzung greift auch ohne JavaScript.
     *
     * Bewusst nur beim Anlegen: beim Ändern ist ein geleertes Feld eine
     * Entscheidung, die eine Vorbelegung still überschriebe.
     *
     * @param array<string, mixed> $data
     * @return array{start_date: ?string, end_date: ?string}
     */
    private function prefillPeriodFromProject(array $data, ?int $projectId): array
    {
        $startDate = !empty($data['start_date']) ? (string) $data['start_date'] : null;
        $endDate   = !empty($data['end_date']) ? (string) $data['end_date'] : null;

        if (($startDate !== null && $endDate !== null) || $projectId === null) {
            return ['start_date' => $startDate, 'end_date' => $endDate];
        }

        $project = Project::find($projectId);
        if ($project === null) {
            return ['start_date' => $startDate, 'end_date' => $endDate];
        }

        return [
            'start_date' => $startDate ?? $project->start_date?->format('Y-m-d'),
            'end_date'   => $endDate ?? $project->end_date?->format('Y-m-d'),
        ];
    }

    /**
     * Wie im Finanzmodul: erst das Eingabeformat normalisieren ("1.500,00" =>
     * "1500.00"), dann prüfen. Ohne die Prüfung wird "abc" still zu 0,00 und ein
     * negativer Betrag kommentarlos gespeichert.
     *
     * Ein leeres Feld ist dagegen erlaubt und gilt als 0,00: Bei einer
     * Kontaktanfrage stehen Paket und Betrag meist noch nicht fest, und auch
     * eine Absage muss sich ohne Betrag festhalten lassen.
     */
    public static function validateAmount(string $amount): ?string
    {
        if ($amount === '') {
            return '0.00';
        }

        $normalized = AmountNormalizer::normalize($amount);
        if (!is_numeric($normalized) || (float) $normalized < 0) {
            return null;
        }

        return $normalized;
    }
}
