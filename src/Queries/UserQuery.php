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
     * Bewusst nicht findById(): dessen Detail-Eager-Loads (Teilstimmen, Postfach)
     * kosten hier vier zusätzliche Abfragen pro Seitenaufruf, die niemand liest.
     * Die Spaltenauswahl hält zusätzlich den Passwort-Hash aus einer Abfrage
     * heraus, die auf jedem Seitenaufruf läuft.
     */
    public function findForSession(int $id): ?User
    {
        return User::select(User::LIST_COLUMNS)
            ->with(self::SESSION_RELATIONS)
            ->find($id);
    }

    /**
     * Vollständiger Lookup für die Detailmasken (Profil, Mitgliederpflege), die
     * Teilstimmen und Postfach anzeigen.
     */
    public function findById(int $id): ?User
    {
        return User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup', 'mailAccount'])
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
     * Mitgliederliste in der konfigurierten Namensreihenfolge. Geladen werden nur
     * die Listenspalten (User::LIST_COLUMNS) - das Ergebnis geht unverändert an
     * die View-Schicht, der Passwort-Hash bleibt deshalb in der Datenbank.
     */
    private function orderedListQuery(int $isActive): Collection
    {
        $query = User::select(User::LIST_COLUMNS)
            ->with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup', 'projects'])
            ->where('is_active', $isActive);

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }
}
