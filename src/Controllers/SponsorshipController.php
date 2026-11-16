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
use App\Policies\SponsoringPolicy;
use App\Queries\SponsoringQuery;
use App\Services\EntityAttachmentService;
use App\Services\NameFormatterService;
use App\Util\AmountNormalizer;
use App\Util\BenefitList;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Util\SponsorshipStatus;
use App\Util\InputValidator;
use App\Util\RequestFormat;
use App\Util\SponsorshipFileName;
use App\Controllers\Concerns\AttachmentUploadJson;
use App\Models\Attachment;

class SponsorshipController
{
    use AttachmentUploadJson;

    public const RENAME_ERROR = 'Bitte einen Dateinamen eingeben.';

    public const AMOUNT_ERROR = 'Ungültiger Betrag. Bitte eine Zahl ab 0 eingeben.';
    public const STATUS_ERROR = 'Ungültiger Status für die Anfrage.';
    public const PROJECT_ERROR = 'Anfragen lassen sich nur zu einem laufenden Projekt erfassen.';
    public const ASSIGNEE_ERROR = 'Die zuständige Person muss ein aktives Mitglied sein.';
    public const ASSIGNEE_MISSING_ERROR = 'Bitte eine Person auswählen.';
    public const ASSIGNEE_NO_ACCESS_ERROR = 'Diese Person hat kein Sponsoring-Recht und sähe die Anfrage gar nicht.';
    public const ASSIGNEE_NOT_OPEN_ERROR = 'Diese Anfrage ist bereits zugewiesen oder läuft nicht mehr.';
    public const BLOCKED_ERROR = 'Dieser Sponsor hat sich weitere Anfragen verbeten.';
    public const PAID_AT_ERROR = 'Bitte ein gültiges Datum für "Bezahlt am" angeben.';
    public const NOT_ACCEPTED_ERROR = 'Eine Zahlung lässt sich nur zu einer Zusage bestätigen.';

    /** Anhänge, die an einer Vereinbarung hängen: Verträge, Angebote. */
    /** Derselbe Wert wie am Modell - festgelegt wird er nur dort. */
    public const ENTITY_TYPE = Sponsorship::ENTITY_TYPE;

    private SponsoringPolicy $policy;
    private EntityAttachmentService $attachments;
    private LoggerInterface $logger;
    private NameFormatterService $nameFormatter;
    private SponsoringQuery $sponsoringQuery;

    public function __construct(
        SponsoringPolicy $policy,
        EntityAttachmentService $attachments,
        ?LoggerInterface $logger = null,
        ?NameFormatterService $nameFormatter = null,
        ?SponsoringQuery $sponsoringQuery = null
    ) {
        $this->policy = $policy;
        $this->attachments = $attachments;
        $this->logger = $logger ?? new NullLogger();
        $this->nameFormatter = $nameFormatter ?? new NameFormatterService();
        // Dieselbe Namensreihenfolge wie die Auswahl, die die Abfrage ausliefert:
        // Ein zweiter NameFormatterService daneben wäre anders einstellbar.
        $this->sponsoringQuery = $sponsoringQuery ?? new SponsoringQuery($this->nameFormatter);
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

        $normalizedAmount = AmountNormalizer::normalizeNonNegative($amount);
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

        $packageId = self::packageIdFor($data, $status);

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

            $_SESSION['success'] = 'Anfrage angelegt.';
        } catch (\Exception $e) {
            // Der Grund gehört ins Protokoll, nicht vor die Augen des
            // Sponsoring-Teams: Hier stand früher der rohe SQLSTATE-Text des
            // Treibers, dann nur noch der Doppelpunkt, der von ihm übrig blieb.
            $this->logger->error('Creating a sponsorship failed.', [
                'event' => 'sponsorship.create.failed',
                'sponsor_id' => $sponsorId,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Die Anfrage konnte nicht angelegt werden.';
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

            $normalizedAmount = AmountNormalizer::normalizeNonNegative(trim(InputValidator::asString($data['amount'] ?? null)));
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
                'package_id'       => self::packageIdFor($data, $status),
                'assigned_user_id' => $assignee['value'],
                'amount'           => $normalizedAmount,
                'paid_at'          => $paidAt,
                'status'           => $status,
                'start_date'       => InputValidator::asString($data['start_date'] ?? null) ?: null,
                'end_date'         => InputValidator::asString($data['end_date'] ?? null) ?: null,
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

            $_SESSION['success'] = 'Anfrage gespeichert.';
        } catch (ModelNotFoundException $e) {
            // Der häufigste Weg hierher: Die Seite lag offen, während jemand
            // anderes dieselbe Vereinbarung entfernt hat. Kein Betriebsfehler.
            $_SESSION['error'] = 'Die Anfrage wurde nicht gefunden. '
                . 'Möglicherweise wurde er bereits gelöscht.';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->error('Updating a sponsorship failed.', [
                'event' => 'sponsorship.update.failed',
                'sponsorship_id' => $id,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Die Anfrage konnte nicht aktualisiert werden.';
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
     * Geprüft wird gegen die Personen mit Sponsoring-Recht, also genau die, die
     * das Formular anbietet (SponsoringQuery::getAssignableUsers()). Zuvor
     * genügte hier ein aktives Konto - der Dialog war damit breiter als das
     * Zuweisen aus "Ohne Zuständigkeit", und wer dort eine rechtlose Person
     * eintrug, erzeugte einen Vorgang, der versorgt aussah, während die Person
     * auf /sponsoring eine 403 bekam.
     *
     * Gefragt wird dafür dieselbe Klasse, die die Auswahl ausliefert
     * (SponsoringQuery::isAssignable()). Bis Lauf 67 stand die Prüfung als
     * eigene Abfrage am Modell hier im Controller und stimmte mit dem Angebot
     * nur deshalb überein, weil beide Seiten zufällig denselben Scope aufriefen.
     *
     * Eine Ausnahme gilt für die bereits eingetragene Person: Sie bleibt gültig,
     * auch wenn sie inzwischen archiviert wurde oder ihr Recht verloren hat.
     * Sonst scheiterte ein Speichern aus einem ganz anderen Grund - ein
     * korrigierter Betrag - an einer Zuständigkeit, die niemand angefasst hat,
     * oder das Formular hätte sie still gelöscht. Dieselbe Überlegung steht an
     * SponsoringPolicy::retainedProjects(); die Vorlage hält die Person dafür
     * als eigene Option bereit.
     *
     * Zwei Meldungen, weil die beiden Fälle verschieden zu lesen sind: Eine
     * unbekannte oder archivierte Kennung kommt nur aus einer veralteten oder
     * manipulierten Eingabe, ein fehlendes Recht dagegen aus einer Rollenpflege,
     * die jemand nebenbei geändert hat.
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

        if ($this->sponsoringQuery->isAssignable($submitted)) {
            return ['error' => null, 'value' => $submitted];
        }

        $isActiveMember = $this->sponsoringQuery->isActiveMember($submitted);

        return [
            'error' => $isActiveMember ? self::ASSIGNEE_NO_ACCESS_ERROR : self::ASSIGNEE_ERROR,
            'value' => null,
        ];
    }

    /**
     * Trägt die zuständige Person direkt aus der Liste "Ohne Zuständigkeit" ein.
     *
     * Bisher ging das nur über den ganzen Bearbeiten-Dialog am Sponsor; wer zehn
     * Lücken schließen wollte, öffnete zehn Sponsoren. Nur für das Vollrecht:
     * Zuständigkeit gibt Einsicht in Betrag und Belege (SponsoringPolicy::
     * canSeeSponsorshipDetails()), die verteilt das Sponsoring-Team.
     *
     * Leer ist hier anders als im Dialog keine Antwort: Die Liste ist dazu da,
     * eine Person einzutragen, nicht eine zu entfernen. Und sie schließt nur
     * Lücken: Aus einem veralteten Tab soll die Einsicht in Betrag und Belege
     * nicht still zu jemand anderem wandern.
     *
     * Enger als der Dialog: Hier gibt es nur Personen mit Sponsoring-Recht.
     * Jede andere bekäme auf /sponsoring eine 403, und der Vorgang wirkte
     * versorgt, ohne dass sich jemand kümmert.
     */
    public function assign(Request $request, Response $response, array $args): Response
    {
        if (!$this->policy->canManageAll()) {
            return $this->deny($response);
        }

        $back = $response->withHeader('Location', '/sponsoring')->withStatus(302);
        $data = (array) $request->getParsedBody();

        $sponsorship = Sponsorship::with('sponsor')->find((int) $args['id']);
        if ($sponsorship === null) {
            $_SESSION['error'] = 'Anfrage nicht gefunden.';
            return $back;
        }

        if (empty($data['assigned_user_id'])) {
            $_SESSION['error'] = self::ASSIGNEE_MISSING_ERROR;
            return $back;
        }

        // Ohne bisherige Person (`null`): Die Ausnahme für die schon
        // eingetragene greift hier nicht, das Zuweisen schließt eine Lücke und
        // trägt nie etwas fort. Die Rechteprüfung steckt seit der Vereinheitlichung
        // in validateAssignedUser() selbst - sie stand hier einmal eigens daneben,
        // weil der Bearbeiten-Dialog damals breiter war.
        $assignee = $this->validateAssignedUser($data, null);
        if ($assignee['error'] !== null) {
            $_SESSION['error'] = $assignee['error'];
            return $back;
        }

        if (!Sponsorship::query()->whereKey($sponsorship->id)->withoutCapableAssignee()->exists()) {
            $_SESSION['error'] = self::ASSIGNEE_NOT_OPEN_ERROR;
            return $back;
        }

        $sponsorship->update(['assigned_user_id' => $assignee['value']]);
        $this->logger->info('Sponsorship assignee changed.', [
            'event' => 'sponsorship.assignee.changed',
            'sponsorship_id' => (int) $sponsorship->id,
            'assigned_user_id' => $assignee['value'],
            'user_id' => $this->policy->currentUserId(),
        ]);
        $assigned = $this->sponsoringQuery->findAssignee((int) $assignee['value']);
        $_SESSION['success'] = $this->nameFormatter->formatPerson($assigned)
            . ' ist jetzt für ' . ($sponsorship->sponsor->name ?? 'diesen Sponsor') . ' zuständig.';

        return $back;
    }

    /**
     * Bestätigt den Zahlungseingang direkt aus der Liste "Zahlung offen".
     *
     * Bisher ging das nur über den Dialog der Anfrage am Sponsor - für den
     * Kassier, der zehn Eingänge abhaken will, zehn Sponsoren. Ohne Datum gilt
     * heute. Zurück geht es dorthin, wo die Liste für diese Person steht.
     */
    public function confirmPayment(Request $request, Response $response, array $args): Response
    {
        $sponsorship = Sponsorship::with('sponsor')->find((int) $args['id']);
        if ($sponsorship !== null && !$this->policy->canConfirmPayment($sponsorship)) {
            return $this->deny($response);
        }
        if ($sponsorship === null && !$this->policy->canSeeOpenPayments() && !$this->policy->canContribute()) {
            return $this->deny($response);
        }

        $back = $response
            ->withHeader('Location', $this->policy->canContribute() ? '/sponsoring' : '/sponsoring/payments')
            ->withStatus(302);

        if ($sponsorship === null) {
            $_SESSION['error'] = 'Anfrage nicht gefunden.';
            return $back;
        }

        if ($sponsorship->status !== SponsorshipStatus::ACCEPTED) {
            $_SESSION['error'] = self::NOT_ACCEPTED_ERROR;
            return $back;
        }

        $data = (array) $request->getParsedBody();
        $paidAt = self::validatePaidAt($data['paid_at'] ?? null) ?? Carbon::today()->format('Y-m-d');
        if ($paidAt === false) {
            $_SESSION['error'] = self::PAID_AT_ERROR;
            return $back;
        }

        $sponsorship->update(['paid_at' => $paidAt]);
        $this->logger->info('Sponsorship payment confirmed.', [
            'event' => 'sponsorship.payment.confirmed',
            'sponsorship_id' => (int) $sponsorship->id,
            'paid_at' => $paidAt,
            'user_id' => $this->policy->currentUserId(),
        ]);
        $_SESSION['success'] = 'Zahlung von ' . ($sponsorship->sponsor->name ?? 'diesem Sponsor') . ' bestätigt.';

        return $back;
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
            $_SESSION['success'] = 'Anfrage erfolgreich gelöscht.';
        } catch (ModelNotFoundException $e) {
            $_SESSION['error'] = 'Die Anfrage wurde nicht gefunden. '
                . 'Möglicherweise wurde er bereits gelöscht.';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->error('Deleting a sponsorship failed.', [
                'event' => 'sponsorship.delete.failed',
                'sponsorship_id' => $id,
                'exception' => $e,
            ]);
            $_SESSION['error'] = 'Die Anfrage konnte nicht gelöscht werden.';
            $sponsorId = (int) ($data['sponsor_id'] ?? 0);
        }

        return $response->withHeader('Location', '/sponsoring/sponsors/' . $sponsorId)->withStatus(302);
    }

    /**
     * Verträge und Belege aus dem Ablagefeld in der Karte der Anfrage.
     *
     * Bis November 2026 gab es dafür nur das Dateifeld im Bearbeiten-Dialog;
     * im ersten Praxistest fand niemand heraus, wohin der Vertrag gehört. Die
     * Datei bekommt hier ihren sprechenden Namen (SponsorshipFileName).
     */
    public function uploadAttachment(Request $request, Response $response, array $args): Response
    {
        $expectsJson = RequestFormat::expectsJson($request);
        $sponsorship = Sponsorship::with(['sponsor', 'project', 'package'])->find((int) $args['id']);

        if ($sponsorship === null) {
            if ($expectsJson) {
                return $this->attachmentUploadJsonError($response, 'Anfrage nicht gefunden.', 404);
            }
            $_SESSION['error'] = 'Anfrage nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        if (!$this->policy->canEditSponsorship($sponsorship)) {
            return $expectsJson
                ? $this->attachmentUploadJsonError($response, 'Zugriff verweigert.', 403)
                : $this->deny($response);
        }

        $taken = Attachment::where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $sponsorship->id)
            ->pluck('original_name')
            ->all();

        $result = $this->attachments->storeUploads(
            $request->getUploadedFiles()['attachments'] ?? null,
            self::ENTITY_TYPE,
            (int) $sponsorship->id,
            null,
            null,
            static function (string $uploadedName) use ($sponsorship, &$taken): string {
                $name = SponsorshipFileName::build(
                    (string) ($sponsorship->sponsor->name ?? ''),
                    $sponsorship->project?->name,
                    $sponsorship->package?->name,
                    $uploadedName,
                    $taken
                );
                // Mehrere Dateien in einem Zug zählen untereinander weiter.
                $taken[] = $name;

                return $name;
            }
        );

        if ($expectsJson) {
            return $this->attachmentUploadJson($response, $result);
        }

        if ($result['error'] !== null) {
            $_SESSION['error'] = $result['error'];
        } elseif ($result['stored'] > 0) {
            $_SESSION['success'] = $result['stored'] === 1 ? 'Datei abgelegt.' : 'Dateien abgelegt.';
        }

        return $response
            ->withHeader('Location', '/sponsoring/sponsors/' . $sponsorship->sponsor_id)
            ->withStatus(302);
    }

    /**
     * Benennt einen Vertrag oder Beleg um. Die Endung bleibt, und der Anhang
     * muss an genau dieser Anfrage hängen - die Kennung kommt aus dem Formular.
     */
    public function renameAttachment(Request $request, Response $response, array $args): Response
    {
        $sponsorship = Sponsorship::find((int) $args['id']);
        if ($sponsorship === null) {
            $_SESSION['error'] = 'Anfrage nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring/sponsors')->withStatus(302);
        }

        if (!$this->policy->canEditSponsorship($sponsorship)) {
            return $this->deny($response);
        }

        $back = $response
            ->withHeader('Location', '/sponsoring/sponsors/' . $sponsorship->sponsor_id)
            ->withStatus(302);

        $attachment = Attachment::where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $sponsorship->id)
            ->whereKey((int) $args['attachment_id'])
            ->first(['id', 'original_name']);
        if ($attachment === null) {
            $_SESSION['error'] = 'Anhang nicht gefunden.';
            return $back;
        }

        $data = (array) $request->getParsedBody();
        $name = SponsorshipFileName::rename(
            InputValidator::asString($data['name'] ?? null),
            (string) $attachment->original_name
        );
        if ($name === null) {
            $_SESSION['error'] = self::RENAME_ERROR;
            return $back;
        }

        $attachment->update(['original_name' => $name]);
        $_SESSION['success'] = 'Datei umbenannt.';

        return $back;
    }

    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        $sponsorshipId = (int) $args['id'];
        $attachmentId  = (int) $args['attachment_id'];
        $data          = (array) $request->getParsedBody();

        $sponsorship = Sponsorship::find($sponsorshipId);
        if ($sponsorship === null) {
            $_SESSION['error'] = 'Anfrage nicht gefunden.';
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

        if (!$this->policy->canTickBenefits()) {
            return $this->deny($response);
        }

        return $this->tickLine($request, $response, $benefit, 'delivered');
    }

    /**
     * Hakt eine Leistung des Sponsors als erhalten ab oder nimmt den Haken
     * zurück. Ein anderes Recht als bei den Gegenleistungen: Das Material des
     * Sponsors hält das Mitglied nach, das ihn betreut, die Gegenleistungen
     * erbringt das Sponsoring-Team.
     */
    public function toggleDeliverable(Request $request, Response $response, array $args): Response
    {
        $deliverable = SponsorshipDeliverable::with('sponsorship')->find((int) $args['id']);
        if ($deliverable === null || $deliverable->sponsorship === null) {
            $_SESSION['error'] = 'Die Leistung des Sponsors wurde nicht gefunden.';
            return $response->withHeader('Location', '/sponsoring')->withStatus(302);
        }

        if (!$this->policy->canTickDeliverables($deliverable->sponsorship)) {
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
     * Das Paket aus dem Formular - aber nur zu einem Status, in dem es
     * feststeht (SponsorshipStatus::WITH_PACKAGE). Sonst wird es verworfen
     * statt beanstandet: Das Formular sperrt die Auswahl ohnehin, und ein
     * Altbestand mit Paket an einer offenen Anfrage räumt sich so beim
     * nächsten Speichern von selbst auf.
     *
     * @param array<string, mixed> $data
     */
    private static function packageIdFor(array $data, string $status): ?int
    {
        if (empty($data['package_id']) || !SponsorshipStatus::allowsPackage($status)) {
            return null;
        }

        return (int) $data['package_id'];
    }

    /**
     * Wofür die Vereinbarung das Logo braucht.
     *
     * Die Formulare senden logo_needs=1 mit, sobald sie die beiden Häkchen
     * zeigen - nur so lässt sich ein abgewähltes Häkchen von einem fehlenden
     * Feld unterscheiden. Fehlt der Marker, gilt beim Anlegen die Vorgabe des
     * Pakets, beim Ändern bleibt der bisherige Stand.
     *
     * Das Formular zeigt seit November 2026 ein einziges Häkchen
     * (`needs_logo`), weil das Logo derzeit immer für beides gebraucht wird; es
     * setzt beide Spalten. Die Einzelfelder werden weiter angenommen, damit
     * sich Druck und online wieder trennen lassen, ohne diese Stelle anzufassen.
     *
     * @param array<string, mixed> $data
     * @return array{needs_logo_print: bool, needs_logo_online: bool}
     */
    private function logoNeeds(array $data, ?SponsorPackage $package, ?Sponsorship $current = null): array
    {
        if (!empty($data['logo_needs'])) {
            $both = !empty($data['needs_logo']);

            return [
                'needs_logo_print' => $both || !empty($data['needs_logo_print']),
                'needs_logo_online' => $both || !empty($data['needs_logo_online']),
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
        $startDate = InputValidator::asString($data['start_date'] ?? null) ?: null;
        $endDate   = InputValidator::asString($data['end_date'] ?? null) ?: null;

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
}
