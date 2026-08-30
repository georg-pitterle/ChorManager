<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Project;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\NameFormatterService;
use App\Util\VoiceGroupOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class ProjectQuery
{
    /**
     * Sammelschlüssel der Besetzungs-Gruppierung für Mitglieder ohne Stimmgruppe
     * bzw. ohne Teilstimme. Der führende Unterstrich hält sie von echten
     * Stimmgruppennamen getrennt; die deutschen Überschriften dazu setzt das
     * Template, der Schlüssel selbst ist ein Bezeichner und bleibt englisch.
     */
    public const NO_VOICE_GROUP_KEY = '_no_voice_group';
    public const NO_SUB_VOICE_KEY = '_no_sub_voice';

    private NameFormatterService $nameFormatter;

    public function __construct(NameFormatterService $nameFormatter)
    {
        $this->nameFormatter = $nameFormatter;
    }

    public function findById(int $id): ?Project
    {
        return Project::find($id);
    }

    public function getAllProjects(): Collection
    {
        return Project::orderBy('start_date', 'desc')->get();
    }

    /**
     * Projekte, die der Nutzer auswählen darf: mit dem übergreifenden Recht alle,
     * sonst nur die eigenen. Termine und Auswertungen filtern beide danach und
     * hielten dafür bis hierher je eine wortgleiche Kopie.
     *
     * Ohne Sitzungsnutzer bleibt die Liste leer statt vollständig - das ist die
     * sichere Richtung, wenn die Kennung fehlt.
     */
    public function getAccessibleProjects(int $userId, bool $seesAllProjects): Collection
    {
        if ($seesAllProjects) {
            return Project::orderBy('name')->get();
        }

        if ($userId <= 0) {
            // Leer ohne Datenbankzugriff - dieselbe Abkürzung wie in
            // getUsersNotInProjectForVoiceGroups(). Eine Abfrage, deren Ergebnis
            // schon feststeht, kostet nur eine Runde zum Server.
            return new Collection();
        }

        return Project::query()
            ->select('projects.*')
            ->join('project_users', 'project_users.project_id', '=', 'projects.id')
            ->where('project_users.user_id', $userId)
            ->distinct()
            ->orderBy('projects.name')
            ->get();
    }

    /**
     * Returns the id of the project running today (start_date <= today <= end_date),
     * restricted to the given accessible project ids. If several projects run in
     * parallel, the one ending first wins. Returns 0 if none is running.
     *
     * @param int[] $accessibleProjectIds
     */
    public function findCurrentProjectId(array $accessibleProjectIds): int
    {
        if ($accessibleProjectIds === []) {
            return 0;
        }

        $today = date('Y-m-d');

        $project = Project::whereIn('id', $accessibleProjectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->orderBy('end_date', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        return $project ? (int) $project->id : 0;
    }

    /**
     * Alle dem Projekt zugeordneten Mitglieder, archivierte eingeschlossen.
     *
     * Ein Filter auf is_active würde ein archiviertes Mitglied unsichtbar machen,
     * ohne die Zuordnung zu lösen: als Kandidat erscheint es ebenfalls nicht, weil
     * getUsersNotInProject() bereits Zugeordnete ausblendet. Es hinge damit still
     * am Projekt und ließe sich über die Oberfläche nicht mehr entfernen.
     */
    public function getProjectMembers(int $projectId): Collection
    {
        $query = User::select(User::LIST_COLUMNS)
            ->whereHas('projects', function ($query) use ($projectId) {
                $query->where('project_id', $projectId);
            })
            ->with([
                // sub_voice_id kommt über die Relation selbst mit (User::voiceGroups()
                // deklariert withPivot); hier reicht die Spaltenauswahl.
                'voiceGroups' => function ($query) {
                    $query->select('voice_groups.id', 'voice_groups.name');
                },
                'subVoices'
            ]);

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }

    /**
     * Returns all users that are not yet assigned to the project, including archived (inactive) ones.
     * Archived users can be assigned to a project and are reactivated on assignment.
     */
    public function getUsersNotInProject(int $projectId): Collection
    {
        $query = User::select(User::LIST_COLUMNS)
            ->whereDoesntHave('projects', function ($query) use ($projectId) {
                $query->where('project_id', $projectId);
            });

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }

    /**
     * Like getUsersNotInProject(), but restricted to users that belong to at
     * least one of the given voice groups. Used for the voice-group-scoped
     * project assignment right, where the candidate list must not leak members
     * of foreign voice groups. An empty voice group list yields no candidates.
     *
     * Archivierte Mitglieder der eigenen Stimmgruppe stehen hier bewusst mit in der
     * Auswahl: die Zuordnung reaktiviert sie (siehe ProjectPersistence::addProjectMember()),
     * und das ist auch fuer dieses eingeschraenkte Recht so gewollt.
     *
     * @param array<int> $voiceGroupIds
     */
    public function getUsersNotInProjectForVoiceGroups(int $projectId, array $voiceGroupIds): Collection
    {
        if ($voiceGroupIds === []) {
            return new Collection();
        }

        $query = User::select(User::LIST_COLUMNS)
            ->whereDoesntHave('projects', function ($query) use ($projectId) {
                $query->where('project_id', $projectId);
            })->whereHas('voiceGroups', function ($query) use ($voiceGroupIds) {
                $query->whereIn('voice_group_id', $voiceGroupIds);
            });

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        return $query->get();
    }

    /**
     * The voice group ids a single user belongs to. Used to authorize
     * voice-group-scoped project member changes. A missing user yields [].
     *
     * Geladen wird nur der Schlüssel: für eine Id-Liste werden die
     * Stimmgruppen-Modelle selbst nicht gebraucht. Ein fehlendes Konto darf
     * nicht durchschlagen - zeigt die Session auf ein gelöschtes Mitglied,
     * bräche ein direktes User::find(...)->voiceGroups() mit einem Fatal Error ab.
     *
     * @return array<int>
     */
    public function getUserVoiceGroupIds(int $userId): array
    {
        $user = User::select(['id'])->find($userId);
        if (!$user) {
            return [];
        }

        return self::toIntList($user->voiceGroups()->pluck('voice_groups.id'));
    }

    /**
     * Wandelt eine über pluck() geladene Id-Spalte in eine Liste von Integern.
     *
     * Nicht `->map('intval')`: Collection::map() reicht den Schlüssel als zweites
     * Argument weiter, und das ist bei intval() die Zahlenbasis. Aus der zweiten
     * Id einer Liste würde damit intval($id, 1), aus der dritten intval($id, 2) -
     * bei String-Werten aus dem Treiber also stillschweigend falsche Ids.
     *
     * @param SupportCollection<int, mixed> $ids
     * @return array<int>
     */
    private static function toIntList(SupportCollection $ids): array
    {
        return $ids->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * True when the given member exists at all.
     *
     * getUserVoiceGroupIds() liefert für ein unbekanntes Mitglied dasselbe leere
     * Array wie für eines ohne Stimmgruppe - die Existenz muss deshalb getrennt
     * geprüft werden, bevor eine Zuordnung in den Fremdschlüssel läuft.
     */
    public function userExists(int $userId): bool
    {
        return User::whereKey($userId)->exists();
    }

    /**
     * Returns project members grouped by voice group and sub-voice for the evaluation view.
     *
     * Jedes Mitglied erscheint genau einmal: unter seiner ersten Stimmgruppe. Ist ein
     * Filter gesetzt, zählt die erste Stimmgruppe, die der Filter zulässt.
     *
     * @param array<int>|null $filterVoiceGroupIds null = kein Filter, [] = keine Treffer
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    public function getProjectMembersGroupedByVoice(int $projectId, ?array $filterVoiceGroupIds = null): array
    {
        // Eine leere Stimmgruppen-Liste schränkt auf nichts ein und darf nicht als
        // "kein Filter" durchgehen - sonst wäre der Filter im Zweifel wirkungslos.
        // Dieselbe Semantik wie in getUsersNotInProjectForVoiceGroups().
        if ($filterVoiceGroupIds === []) {
            return [];
        }

        $allowedVoiceGroupIds = $filterVoiceGroupIds === null
            ? null
            : array_map('intval', $filterVoiceGroupIds);

        // Nur die Namen der Teilstimmen werden gebraucht; die Stimmgruppe hinter einer
        // Teilstimme mitzuladen wäre eine zusätzliche Query ohne Verwendung.
        $query = User::select(User::LIST_COLUMNS)
            ->whereHas('projects', function ($query) use ($projectId) {
                $query->where('project_id', $projectId);
            })
            ->where('is_active', 1)
            ->with(['voiceGroups', 'subVoices']);

        if ($allowedVoiceGroupIds !== null) {
            $query->whereHas('voiceGroups', function ($q) use ($allowedVoiceGroupIds) {
                $q->whereIn('voice_group_id', $allowedVoiceGroupIds);
            });
        }

        foreach ($this->nameFormatter->orderColumns() as $column) {
            $query->orderBy($column);
        }

        $users = $query->get();

        $grouped = [];
        foreach ($users as $user) {
            // Find the active voice group (and subvoice if any) for this user.
            // If none, fall back to the collecting keys above.
            $vgName = self::NO_VOICE_GROUP_KEY;
            $svName = self::NO_SUB_VOICE_KEY;

            $voiceGroup = $this->resolveVoiceGroup($user, $allowedVoiceGroupIds);
            if ($voiceGroup) {
                $vgName = $voiceGroup->name;
                // sub_voice_id is stored in the pivot table for user_voice_groups
                $subVoiceId = $voiceGroup->pivot->sub_voice_id;
                if ($subVoiceId) {
                    $subVoice = $user->subVoices->firstWhere('id', $subVoiceId);
                    if ($subVoice) {
                        $svName = $subVoice->name;
                    }
                }
            }

            if (!isset($grouped[$vgName])) {
                $grouped[$vgName] = [];
            }
            if (!isset($grouped[$vgName][$svName])) {
                $grouped[$vgName][$svName] = [];
            }
            // store raw array structure compatible with legacy twig templates
            //(or just pass the user object if preferred)
            // for minimal twig breakage, we can store array data
            $grouped[$vgName][$svName][] = [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'voice_group_name' => $vgName !== self::NO_VOICE_GROUP_KEY ? $vgName : null,
                'sub_voice_name' => $svName !== self::NO_SUB_VOICE_KEY ? $svName : null,
            ];
        }

        // Sort voice groups into canonical SATB order, "ohne Stimmgruppe" last
        $grouped = VoiceGroupOrder::sortNameKeyedMap($grouped, [self::NO_VOICE_GROUP_KEY]);

        // Sort sub-voices within each voice group by name, collecting key last
        foreach ($grouped as &$subVoices) {
            ksort($subVoices);
            if (isset($subVoices[self::NO_SUB_VOICE_KEY])) {
                $ungroupedSv = $subVoices[self::NO_SUB_VOICE_KEY];
                unset($subVoices[self::NO_SUB_VOICE_KEY]);
                $subVoices[self::NO_SUB_VOICE_KEY] = $ungroupedSv;
            }
        }
        // Die Referenz zeigt nach der Schleife noch auf das letzte Element und würde
        // bei jeder späteren Verwendung von $subVoices dorthin schreiben.
        unset($subVoices);

        return $grouped;
    }

    /**
     * Die Stimmgruppe, unter der ein Mitglied in der Besetzung erscheint.
     *
     * Ohne Filter ist das die erste Stimmgruppe des Mitglieds. Mit Filter muss es die
     * erste zugelassene sein: gefiltert wird über whereHas, also über *irgendeine*
     * passende Stimmgruppe - ein Mitglied, das nur über seine zweite Stimmgruppe in
     * den Filter fällt, landete sonst unter einer Überschrift, die der Filter gar
     * nicht enthält.
     *
     * @param array<int>|null $allowedVoiceGroupIds
     */
    private function resolveVoiceGroup(User $user, ?array $allowedVoiceGroupIds): ?VoiceGroup
    {
        if ($allowedVoiceGroupIds === null) {
            return $user->voiceGroups->first();
        }

        return $user->voiceGroups->first(static function (VoiceGroup $voiceGroup) use ($allowedVoiceGroupIds): bool {
            return in_array((int) $voiceGroup->id, $allowedVoiceGroupIds, true);
        });
    }
}
