<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Services\NameFormatterService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Die Personenabfragen des Sponsorings.
 *
 * Beide standen bis hierher als private Methoden in ihrem Controller - und
 * fielen damit durch jeden Wächter der Abfrageschicht hindurch, obwohl dessen
 * Regeln für sie genauso gelten: Sie reichen ihre User-Modelle unverändert an
 * die Vorlage weiter. Entsprechend hatten beide die ganze Zeile samt
 * Passwort-Hash geladen und ihre Reihenfolge hart nach dem Nachnamen gesetzt,
 * statt sie aus NameFormatterService zu nehmen.
 *
 * Hier greift UserColumnExposureFeatureTest von selbst, ohne dass jemand einen
 * eigenen Test dafür schreiben muss.
 *
 * Eine eigene Klasse und nicht zwei Methoden in UserQuery: Die Grenze zwischen
 * den Abfrageklassen ist hier nicht die Entität, sondern der Aufrufer - wie
 * schon bei ProjectQuery, das die nutzerbezogenen Abfragen der Projektmaske
 * führt. getActiveUsers() wäre auch der falsche Weg gewesen: Es lädt Rollen,
 * Stimmgruppen und Projekte vorab, für eine Auswahlliste drei Abfragen, die
 * niemand ausliest.
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
     * Dass die Auswahl eng ist, ist dabei keine Absicherung:
     * SponsorshipController::validateAssignedUser() prüft dieselbe Menge beim
     * Speichern nach.
     *
     * @return Collection<int, User>
     */
    public function getAssignableUsers(): Collection
    {
        return $this->orderedSelect(User::withSponsoringAccess()->select(User::LIST_COLUMNS));
    }

    /**
     * Die gemeinsame Hälfte beider Auswahlen: Listenspalten und die eingestellte
     * Namensreihenfolge.
     *
     * Nur die Listenspalten, weil das Ergebnis unverändert in die View geht -
     * der Passwort-Hash bleibt damit in der Datenbank (siehe
     * UserQuery::orderedListQuery()). Und die Reihenfolge kommt aus
     * NameFormatterService, weil die Vorlage daneben `person_name` ausgibt: Wer
     * hier nach dem Nachnamen sortiert, während "Vorname Nachname" eingestellt
     * ist, liefert eine Auswahl, die sich unsortiert liest.
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     * @return Collection<int, User>
     */
    private function orderedSelect($query): Collection
    {
        return $this->nameFormatter->applyNameOrder($query)->get();
    }
}
