<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Services\NameFormatterService;
use Illuminate\Database\Eloquent\Collection;

class UserQuery
{
    /**
     * Relationen, die der Sitzungsaufbau tatsächlich liest: die Rollen für den
     * Rechte-Schnappschuss, die Stimmgruppen für `voice_group_ids`
     * (siehe SessionAuthService::setAuthenticatedUser()).
     *
     * @var list<string>
     */
    private const SESSION_RELATIONS = ['roles', 'voiceGroups'];

    /**
     * Spalten und Relationen der drei OIDC-Zugänge. `external_uid` steht
     * zusätzlich zu den Listenspalten drin: OidcClaimsBuilder baut daraus `sub`.
     *
     * @var list<string>
     */
    private const OIDC_COLUMNS = [...User::LIST_COLUMNS, 'external_uid'];

    /** @var list<string> */
    private const OIDC_RELATIONS = ['roles'];

    private NameFormatterService $nameFormatter;

    public function __construct(NameFormatterService $nameFormatter)
    {
        $this->nameFormatter = $nameFormatter;
    }

    /**
     * Login-Lookup. Das Ergebnis geht ausschließlich in den Sitzungsaufbau, geladen
     * werden deshalb nur die Relationen, die dieser auswertet. Die Spaltenauswahl
     * bleibt vollständig: password_verify() braucht den Hash.
     */
    public function findByEmail(string $email): ?User
    {
        return User::with(self::SESSION_RELATIONS)
            ->where('email', $email)
            ->where('is_active', 1)
            ->first();
    }

    /**
     * Used only to distinguish the audit-log reason for a failed login: does an
     * inactive (deactivated) account exist for this address? Never used to
     * authenticate - findByEmail() above stays the only login-relevant lookup,
     * and its is_active=1 filter is untouched.
     */
    public function existsInactiveByEmail(string $email): bool
    {
        return User::where('email', $email)
            ->where('is_active', 0)
            ->exists();
    }

    /**
     * Lookup für die Anmeldung per Remember-Me und für die Rechte-Auffrischung, die
     * AuthMiddleware bei *jedem* geschützten Request ausführt.
     *
     * Bewusst nicht findIncludingArchived(): dessen Detail-Eager-Loads (Teilstimmen
     * samt Stimmgruppe) kosten hier drei zusätzliche Abfragen pro Seitenaufruf, die
     * niemand liest.
     * Die Spaltenauswahl hält zusätzlich den Passwort-Hash aus einer Abfrage
     * heraus, die auf jedem Seitenaufruf läuft.
     *
     * Deaktivierte Konten bleiben draußen. Die drei Aufrufstellen prüfen das
     * bis heute selbst, eine Lücke war es also nie - aber eine vierte könnte
     * die Prüfung vergessen, und ein archiviertes Konto hätte wieder eine
     * gültige Sitzung. Am Verhalten ändert der Filter nichts: alle drei
     * behandeln "nicht gefunden" und "deaktiviert" gleich. Dieselbe Grenze wie
     * in findByEmail().
     */
    public function findForSession(int $id): ?User
    {
        return User::select(User::LIST_COLUMNS)
            ->with(self::SESSION_RELATIONS)
            ->where('is_active', 1)
            ->find($id);
    }

    /**
     * Lookup der drei OIDC-Zugänge (/oidc/authorize, /oidc/token, /oidc/userinfo).
     *
     * Gelesen wird dort nur, was OidcClaimsBuilder in die Ansprüche schreibt -
     * `external_uid`, Vor- und Nachname, die Adresse und `roles.external_group` -
     * dazu `is_active` für die Sperre.
     *
     * Bewusst nicht findIncludingArchived(): dessen Detail-Eager-Loads
     * (Stimmgruppen, Teilstimmen) kosten drei zusätzliche Abfragen, die im
     * OIDC-Zugang niemand ausliest, und die volle Spaltenauswahl zöge den
     * Passwort-Hash mit. Die enge Auswahl hier hält denselben Abstand wie
     * findForSession().
     *
     * Deaktivierte Konten bleiben draußen - dieselbe Grenze wie in findForSession()
     * und findByEmail(). Die drei Aufrufstellen prüfen `is_active` bis heute selbst
     * und behandeln "nicht gefunden" und "gesperrt" gleich; am Verhalten ändert der
     * Filter nichts, aber eine vierte Aufrufstelle kann ihn nicht mehr vergessen.
     */
    public function findForOidc(int $id): ?User
    {
        return User::select(self::OIDC_COLUMNS)
            ->with(self::OIDC_RELATIONS)
            ->where('is_active', 1)
            ->find($id);
    }

    /**
     * Vollständiger Lookup der Detailmasken (Profil, Mitgliederpflege), die
     * Teilstimmen samt ihrer Stimmgruppe anzeigen.
     *
     * Der Name benennt, was diesen Lookup von jedem anderen hier unterscheidet:
     * Er ist der einzige ohne Filter auf `is_active`. Das muss er sein - die
     * Mitgliederpflege bearbeitet auch archivierte Mitglieder. Unter dem früheren
     * Namen findById() stand die Grenze allein in diesem Kommentar, und genau
     * daran hing der OIDC-Zugang, bis findForOidc() dazukam. Jetzt steht sie an
     * jeder Aufrufstelle.
     *
     * Das Postfach lädt er nicht mehr mit: `mailAccount` liest allein
     * ProfileController::index(); die sechs Aufrufstellen in UserController
     * bezahlten die Abfrage umsonst. Die Profilmaske lädt die Relation jetzt
     * dort, wo sie sie auch anzeigt.
     */
    public function findIncludingArchived(int $id): ?User
    {
        return User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup'])
            ->find($id);
    }

    public function getAllUsers(): Collection
    {
        return $this->orderedListQuery(1);
    }

    public function getArchivedUsers(): Collection
    {
        return $this->orderedListQuery(0);
    }

    /**
     * Aktive Mitglieder, die mindestens einer der übergebenen Stimmgruppen angehören.
     *
     * Für das stimmgruppen-beschränkte Recht in der Mitgliederliste. Die
     * Einschränkung läuft in der Abfrage und nicht als filter() über alle aktiven
     * Mitglieder - dieselbe Richtung wie bei ProjectQuery::getProjectsByIds().
     *
     * Eine leere Stimmgruppenliste heißt "keine Mitglieder" und kommt ohne Abfrage
     * aus; das ist die sichere Richtung und entspricht dem bisherigen Verhalten
     * der Liste.
     *
     * @param array<int> $voiceGroupIds
     */
    public function getUsersForVoiceGroups(array $voiceGroupIds): Collection
    {
        $ids = array_values(array_map('intval', $voiceGroupIds));
        if ($ids === []) {
            return new Collection();
        }

        return $this->orderedListQuery(1, $ids);
    }

    /**
     * Mitgliederliste in der konfigurierten Namensreihenfolge. Geladen werden nur
     * die Listenspalten (User::LIST_COLUMNS) - das Ergebnis geht unverändert an
     * die View-Schicht, der Passwort-Hash bleibt deshalb in der Datenbank.
     *
     * Geladen wird nur, was die Liste auch liest: Rollen (Rechteprüfung je Zeile),
     * Stimmgruppen samt Pivot und Projekte. Den Namen einer Teilstimme löst die
     * Liste über die separat geladene Gesamtliste `sub_voices` auf und braucht
     * dafür nur `pivot.sub_voice_id` - `voiceGroups.subVoices` und
     * `subVoices.voiceGroup` kosteten deshalb drei Abfragen je Seitenaufruf (sieben
     * statt vier), ohne dass sie jemand ausliest; die erste holte zudem sämtliche
     * Teilstimmen sämtlicher Stimmgruppen. Die Detailmasken laden weiterhin
     * vollständig, siehe findIncludingArchived().
     *
     * @param array<int>|null $voiceGroupIds null = keine Einschränkung auf Stimmgruppen
     */
    private function orderedListQuery(int $isActive, ?array $voiceGroupIds = null): Collection
    {
        $query = User::select(User::LIST_COLUMNS)
            ->with(['roles', 'voiceGroups', 'projects'])
            ->where('is_active', $isActive);

        if ($voiceGroupIds !== null) {
            $query->whereHas('voiceGroups', function ($relation) use ($voiceGroupIds) {
                $relation->whereIn('voice_group_id', $voiceGroupIds);
            });
        }

        $this->nameFormatter->applyNameOrder($query);

        return $query->get();
    }
}
