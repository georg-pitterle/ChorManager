<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Attachment;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\NameFormatterService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Die Personenabfrage des Sponsorings.
 *
 * Sie stand bis hierher als private Methode in ihrem Controller - und fiel
 * damit durch jeden Wächter der Abfrageschicht hindurch, obwohl dessen Regeln
 * für sie genauso gelten: Sie reicht ihre User-Modelle unverändert an die
 * Vorlage weiter. Entsprechend hatte sie die ganze Zeile samt Passwort-Hash
 * geladen und ihre Reihenfolge hart nach dem Nachnamen gesetzt, statt sie aus
 * NameFormatterService zu nehmen.
 *
 * Hier greift UserColumnExposureFeatureTest von selbst, ohne dass jemand einen
 * eigenen Test dafür schreiben muss.
 *
 * Eine eigene Klasse und nicht eine Methode in UserQuery: Die Grenze zwischen
 * den Abfrageklassen ist hier nicht die Entität, sondern der Aufrufer - wie
 * schon bei ProjectQuery, das die nutzerbezogenen Abfragen der Projektmaske
 * führt. getActiveUsers() wäre auch der falsche Weg gewesen: Es lädt Rollen,
 * Stimmgruppen und Projekte vorab, für eine Auswahlliste drei Abfragen, die
 * niemand ausliest.
 *
 * Seit Lauf 67 steht hier nicht nur die Auswahl, sondern auch ihre Gegenprobe.
 * Die drei Abfragen standen bis dahin im SponsorshipController, und Angebot und
 * Prüfung stimmten nur deshalb überein, weil beide Seiten zufällig denselben
 * Scope aufriefen. Eine Bedingung mehr an der Auswahl hätte ein Formular
 * ergeben, das eine Person anbietet, die das Speichern danach ablehnt.
 */
class SponsoringQuery
{
    private NameFormatterService $nameFormatter;

    public function __construct(NameFormatterService $nameFormatter)
    {
        $this->nameFormatter = $nameFormatter;
    }

    /**
     * Wen eine Zuständigkeit überhaupt angeboten bekommt: die Personen mit
     * einem der beiden Sponsoring-Rechte.
     *
     * Jede andere bekäme auf /sponsoring eine 403, und der Vorgang wirkte
     * versorgt, ohne dass sich jemand kümmern kann. Beschränkt auf das
     * Vollrecht ist die Menge dagegen nicht: Die Zuständigkeit ist gerade die
     * Erweiterung für Beitragende - wer "Sponsoring verwalten" hält, darf
     * ohnehin alles, erst ein Mitglied mit "eigene Vorgänge" gewinnt durch sie
     * Zugang zu einem Vorgang, den jemand anderes angelegt hat
     * (SponsoringPolicy::assignmentCounts()).
     *
     * Eine Methode für beide Auswahlen - das Zuweisen aus "Ohne Zuständigkeit"
     * und den Bearbeiten-Dialog am Sponsor. Der Dialog war bis hierher breiter
     * und bot jedes aktive Mitglied an; zwei Mengen für dieselbe Frage sind eine
     * Einladung zum Auseinanderlaufen.
     *
     * Nur die Listenspalten, weil das Ergebnis unverändert in die View geht -
     * der Passwort-Hash bleibt damit in der Datenbank (siehe
     * UserQuery::orderedListQuery()). Und die Reihenfolge kommt aus
     * NameFormatterService, weil die Vorlage daneben `person_name` ausgibt: Wer
     * hier nach dem Nachnamen sortiert, während "Vorname Nachname" eingestellt
     * ist, liefert eine Auswahl, die sich unsortiert liest.
     *
     * Dass die Auswahl eng ist, ist dabei keine Absicherung:
     * SponsorshipController::validateAssignedUser() prüft dieselbe Menge beim
     * Speichern nach.
     *
     * @return Collection<int, User>
     */
    public function getAssignableUsers(): Collection
    {
        $query = User::withSponsoringAccess()->select(User::LIST_COLUMNS);

        $this->nameFormatter->applyNameOrder($query);

        return $query->get();
    }

    /**
     * Die Gegenprobe zu getAssignableUsers(): Steht diese Person in der Menge,
     * die das Formular anbietet?
     *
     * Dieselbe Frage, dieselbe Quelle - `User::withSponsoringAccess()`. Dass
     * beides hier nebeneinander steht, ist der ganze Zweck der Methode: Die
     * Prüfung lag bis Lauf 67 als eigene Abfrage im SponsorshipController und
     * hätte eine Änderung an der Auswahl überlebt, ohne davon zu erfahren.
     *
     * Eine Kennung <= 0 trägt kein Konto und kommt ohne Datenbankzugriff aus -
     * dieselbe Abkürzung wie in ProjectQuery::getUserVoiceGroupIds().
     */
    public function isAssignable(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return User::withSponsoringAccess()->whereKey($userId)->exists();
    }

    /**
     * Ob hinter der Kennung überhaupt ein aktives Mitglied steht.
     *
     * Trennt die beiden Ablehnungen des Speicherns voneinander: Ein aktives
     * Mitglied ohne Sponsoring-Recht kommt aus einer Rollenpflege, die jemand
     * nebenbei geändert hat, eine unbekannte oder archivierte Kennung aus einer
     * veralteten oder manipulierten Eingabe. Die Oberfläche sagt deshalb zwei
     * verschiedene Sätze dazu (SponsorshipController::ASSIGNEE_NO_ACCESS_ERROR
     * und ::ASSIGNEE_ERROR).
     *
     * Gefragt wird erst, wenn isAssignable() schon nein gesagt hat - auf dem
     * Weg, der durchgeht, kostet die Unterscheidung keine Abfrage.
     */
    public function isActiveMember(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return User::query()->whereKey($userId)->where('is_active', 1)->exists();
    }

    /**
     * Die eingetragene Person für die Meldung nach dem Zuweisen.
     *
     * Nur die Listenspalten: Gebraucht werden Vor- und Nachname für
     * NameFormatterService, und ein User::find() hätte dafür die ganze Zeile
     * samt Passwort-Hash geholt. Dass das Ergebnis nicht in eine Vorlage geht,
     * sondern in eine Flash-Meldung, ändert an der Regel nichts.
     *
     * Ohne Filter auf `is_active`, anders als bei isActiveMember(): Gefragt wird
     * unmittelbar nach einem erfolgreichen Zuweisen, die Person ist an dieser
     * Stelle schon geprüft. Ein Filter hier würde aus einem Konto, das in der
     * Zwischenzeit archiviert wurde, eine namenlose Meldung machen.
     */
    public function findAssignee(int $userId): ?User
    {
        if ($userId <= 0) {
            return null;
        }

        return User::select(User::LIST_COLUMNS)->find($userId);
    }

    /**
     * Ob die Vereinbarung noch keine Betreuung hat, die sie tragen kann - nur dann
     * darf aus "Ohne Zuständigkeit" heraus zugewiesen werden.
     */
    public function hasNoCapableAssignee(int $sponsorshipId): bool
    {
        return Sponsorship::query()->whereKey($sponsorshipId)->withoutCapableAssignee()->exists();
    }

    /** Ob der Sponsor sich weitere Anfragen verbeten hat. */
    public function isSponsorBlocked(int $sponsorId): bool
    {
        return Sponsor::whereKey($sponsorId)->where('requests_blocked', true)->exists();
    }

    /**
     * Die Dateinamen, die an der Vereinbarung schon hängen.
     *
     * @return list<string>
     */
    public function attachmentNames(int $sponsorshipId): array
    {
        return $this->attachmentsQuery($sponsorshipId)->pluck('original_name')->all();
    }

    /**
     * Ob dieser Name an der Vereinbarung schon vergeben ist - die Gegenprobe zum
     * Umbenennen, so wie isAssignable() die Gegenprobe zu getAssignableUsers()
     * ist.
     *
     * Verglichen wird ohne Rücksicht auf Groß- und Kleinschreibung, und zwar mit
     * `mb_strtolower()` und nicht über die Kollation der Spalte: Das Hochladen
     * zählt seine Namen mit genau dieser Funktion durch
     * (SponsorshipFileName::build()), und zwei Seiten derselben Frage sollen
     * nicht an zwei verschiedenen Begriffen von "gleich" hängen.
     *
     * `$exceptAttachmentId` lässt den Anhang aus, der gerade umbenannt wird: Sein
     * eigener Name ist für ihn nicht vergeben, sonst wäre schon das Abschicken
     * derselben Schreibweise ein Fehler.
     */
    public function isAttachmentNameTaken(int $sponsorshipId, string $name, int $exceptAttachmentId = 0): bool
    {
        $wanted = mb_strtolower($name);
        $rows = $this->attachmentsQuery($sponsorshipId)->get(['id', 'original_name']);

        foreach ($rows as $row) {
            if ((int) $row->id === $exceptAttachmentId) {
                continue;
            }
            if (mb_strtolower((string) $row->original_name) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /** Ein Anhang der Vereinbarung - nur Kennung und Name; null, wenn er woanders hängt. */
    public function findAttachment(int $sponsorshipId, int $attachmentId): ?Attachment
    {
        return $this->attachmentsQuery($sponsorshipId)
            ->whereKey($attachmentId)
            ->first(['id', 'original_name']);
    }

    /**
     * Die Anhänge genau dieser Vereinbarung. Das Spaltenpaar stand dreimal
     * wortgleich daneben; eine Stelle bedeutet, dass eine Änderung an der
     * Verknüpfung nicht zwei davon vergisst.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Attachment>
     */
    private function attachmentsQuery(int $sponsorshipId)
    {
        return Attachment::where('entity_type', Sponsorship::ENTITY_TYPE)
            ->where('entity_id', $sponsorshipId);
    }
}
