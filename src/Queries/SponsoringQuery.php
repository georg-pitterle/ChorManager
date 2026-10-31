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
     * Wen die Auswahl "Zuständig" im Bearbeiten-Dialog am Sponsor anbietet: die
     * aktiven Mitglieder.
     *
     * Breiter als getAssignableUsers() - das ist heute Absicht und steht an
     * SponsorshipController::assign(). Ob der Dialog nachziehen soll, ist eine
     * offene Frage und keine dieser Klasse.
     *
     * @return Collection<int, User>
     */
    public function getSelectableUsers(): Collection
    {
        return $this->orderedSelect(User::select(User::LIST_COLUMNS)->where('is_active', 1));
    }

    /**
     * Wen das Zuweisen aus "Ohne Zuständigkeit" anbietet: nur Personen mit
     * einem der beiden Sponsoring-Rechte.
     *
     * Jede andere bekäme auf /sponsoring eine 403, und der Vorgang wirkte
     * versorgt, ohne dass sich jemand kümmert. Dieselbe Menge prüft
     * SponsorshipController::assign() beim Speichern nach - die Auswahl allein
     * ist keine Absicherung.
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
