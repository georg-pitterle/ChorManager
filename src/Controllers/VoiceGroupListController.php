<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Project;
use App\Models\VoiceGroup;
use App\Models\VoiceGroupList;
use App\Models\VoiceGroupListColumn;
use App\Models\VoiceGroupListEntry;
use App\Policies\VoiceGroupListPolicy;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use App\Services\VoiceGroupListRows;
use App\Util\InputValidator;
use App\Util\RequestFormat;
use App\Util\VoiceGroupOrder;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;

/**
 * Stimmgruppen-Listen: Arbeitslisten der Stimmvertretungen mit selbst
 * formulierten Häkchen- und Notizspalten, eine Zeile je Mitglied.
 *
 * Eine Liste einer fremden Stimmgruppe antwortet überall genau wie eine, die
 * es nicht gibt (notFound()). Ins Protokoll gehen nur Ids - Titel, Spaltennamen
 * und Notizen können etwas über Personen verraten.
 */
class VoiceGroupListController
{
    public const NOT_FOUND = 'Liste nicht gefunden.';
    public const ARCHIVED = 'Die Liste ist archiviert und nur lesbar.';
    public const NOTE_MAX_LENGTH = 1000;
    private const TITLE_MISSING = 'Bitte einen Titel eingeben.';
    private const TITLE_TOO_LONG = 'Der Titel darf höchstens 255 Zeichen lang sein.';
    private const VOICE_GROUP_INVALID = 'Bitte eine eigene Stimmgruppe wählen.';
    private const PROJECT_INVALID = 'Dieses Projekt passt nicht zur Stimmgruppe.';
    private const COLUMN_LABEL_MISSING = 'Bitte einen Namen für die Spalte eingeben.';
    private const COLUMN_LABEL_TOO_LONG = 'Der Name der Spalte darf höchstens 255 Zeichen lang sein.';
    private const COLUMN_TYPE_INVALID = 'Bitte Häkchen oder Notiz wählen.';
    private const NOT_A_ROW = 'Dieses Mitglied steht nicht in der Liste.';
    private const INPUT_INVALID = 'Ungültige Eingabe.';
    private const NOTE_TOO_LONG = 'Eine Notiz darf höchstens 1000 Zeichen lang sein.';

    private Twig $view;
    private VoiceGroupListPolicy $policy;
    private VoiceGroupListRows $rows;
    private NameFormatterService $names;
    private LoggerInterface $logger;

    public function __construct(
        Twig $view,
        VoiceGroupListPolicy $policy,
        VoiceGroupListRows $rows,
        NameFormatterService $names,
        ?LoggerInterface $logger = null
    ) {
        $this->view = $view;
        $this->policy = $policy;
        $this->rows = $rows;
        $this->names = $names;
        $this->logger = $logger ?? new NullLogger();
    }

    public function index(Request $request, Response $response): Response
    {
        $groupIds = $this->policy->accessibleVoiceGroupIds();
        $voiceGroups = VoiceGroup::query()->whereIn('id', $groupIds)->get()->keyBy('name')->all();
        $lists = VoiceGroupList::query()
            ->with('project')
            ->whereIn('voice_group_id', $groupIds)
            ->orderBy('title')
            ->get();

        // Je Liste der Stand der ersten Häkchen-Spalte - daran erkennt man in der
        // Übersicht, welche Liste noch offen ist.
        $summaries = [];
        foreach ($lists as $list) {
            $summaries[(int) $list->id] = $this->summary($list);
        }

        $sections = [];
        foreach (VoiceGroupOrder::sortNameKeyedMap($voiceGroups) as $voiceGroup) {
            $own = $lists->where('voice_group_id', (int) $voiceGroup->id);
            $sections[] = [
                'voice_group' => $voiceGroup,
                'lists' => $own->filter(static fn(VoiceGroupList $list): bool => !$list->isArchived())->values(),
                'archived' => $own->filter(static fn(VoiceGroupList $list): bool => $list->isArchived())->values(),
            ];
        }

        // Vorausgewählt ist das laufende Projekt - dieselbe Regel wie in den
        // Auswertungen (ProjectQuery::findCurrentProjectId).
        $projects = $this->allowedProjects($groupIds);
        $currentProjectId = (new ProjectQuery($this->names))->findCurrentProjectId(
            $projects->pluck('id')->map(static fn($id): int => (int) $id)->all()
        );

        return $this->view->render($response, 'voice_group_lists/index.twig', [
            'sections' => $sections,
            'voice_groups' => array_values(VoiceGroupOrder::sortNameKeyedMap($voiceGroups)),
            'projects' => $projects,
            'current_project_id' => $currentProjectId,
            'summaries' => $summaries,
            'active_nav' => 'voice_group_lists',
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $back = '/voice-group-lists';

        $title = $this->validTitle($data['title'] ?? null);
        if ($title === null) {
            return $this->redirect($response, $back);
        }

        $voiceGroupId = $this->chosenVoiceGroupId($data['voice_group_id'] ?? null);
        if ($voiceGroupId === null) {
            $_SESSION['error'] = self::VOICE_GROUP_INVALID;
            return $this->redirect($response, $back);
        }

        $projectId = $this->chosenProjectId($data['project_id'] ?? null, $voiceGroupId);
        if ($projectId === false) {
            $_SESSION['error'] = self::PROJECT_INVALID;
            return $this->redirect($response, $back);
        }

        $list = VoiceGroupList::create([
            'voice_group_id' => $voiceGroupId,
            'title' => $title,
            'project_id' => $projectId,
            'created_by_user_id' => $this->policy->currentUserId() ?: null,
        ]);
        $this->log('voice_group_list.created', $list);
        $_SESSION['success'] = 'Liste angelegt.';

        return $this->redirect($response, '/voice-group-lists/' . $list->id);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $list = $this->findAccessibleList((int) ($args['id'] ?? 0));
        if ($list === null) {
            return $this->notFound($response);
        }

        $columns = $list->columns()->get();
        $members = $this->rows->members($list);
        $memberIds = array_map(static fn(array $row): int => (int) $row['user']->id, $members);
        $entries = $this->rows->entriesByColumnAndUser($list, $memberIds);
        $memberTotal = count($members);

        $openColumnId = (int) InputValidator::asString($request->getQueryParams()['open'] ?? '');
        $openColumn = $columns->first(
            static fn(VoiceGroupListColumn $column): bool => (int) $column->id === $openColumnId && $column->isCheckbox()
        );
        if ($openColumn !== null) {
            $ticked = $entries[(int) $openColumn->id] ?? [];
            $members = array_values(array_filter(
                $members,
                static fn(array $row): bool => !isset($ticked[(int) $row['user']->id])
            ));
        }

        $tooltips = [];
        foreach ($columns as $column) {
            foreach ($entries[(int) $column->id] ?? [] as $userId => $entry) {
                $tooltips[(int) $column->id][$userId] = $this->tooltip($column, $entry);
            }
        }

        return $this->view->render($response, 'voice_group_lists/show.twig', [
            'list' => $list,
            'voice_group' => $list->voiceGroup,
            'project' => $list->project,
            'columns' => $columns,
            'members' => $members,
            'member_total' => $memberTotal,
            'entries' => $entries,
            'tooltips' => $tooltips,
            'progress' => $this->rows->progress($list, $memberIds),
            'open_column' => $openColumn,
            'projects' => $this->allowedProjects([(int) $list->voice_group_id], $list->project_id),
            'is_archived' => $list->isArchived(),
            'note_max_length' => self::NOTE_MAX_LENGTH,
            'active_nav' => 'voice_group_lists',
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $list = $this->findAccessibleList((int) ($args['id'] ?? 0));
        if ($list === null) {
            return $this->notFound($response);
        }

        $back = '/voice-group-lists/' . $list->id;
        if ($list->isArchived()) {
            $_SESSION['error'] = self::ARCHIVED;
            return $this->redirect($response, $back);
        }

        $data = (array) $request->getParsedBody();
        $title = $this->validTitle($data['title'] ?? null);
        if ($title === null) {
            return $this->redirect($response, $back);
        }

        $projectId = $this->chosenProjectId($data['project_id'] ?? null, (int) $list->voice_group_id, $list->project_id);
        if ($projectId === false) {
            $_SESSION['error'] = self::PROJECT_INVALID;
            return $this->redirect($response, $back);
        }

        $list->update(['title' => $title, 'project_id' => $projectId]);
        $this->log('voice_group_list.updated', $list);
        $_SESSION['success'] = 'Liste gespeichert.';

        return $this->redirect($response, $back);
    }

    public function archive(Request $request, Response $response, array $args): Response
    {
        $list = $this->findAccessibleList((int) ($args['id'] ?? 0));
        if ($list === null) {
            return $this->notFound($response);
        }

        if (!$list->isArchived()) {
            $list->update(['archived_at' => Carbon::now()]);
            $this->log('voice_group_list.archived', $list);
        }
        $_SESSION['success'] = 'Liste archiviert.';

        return $this->redirect($response, '/voice-group-lists');
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        $list = $this->findAccessibleList((int) ($args['id'] ?? 0));
        if ($list === null) {
            return $this->notFound($response);
        }

        if ($list->isArchived()) {
            $list->update(['archived_at' => null]);
            $this->log('voice_group_list.restored', $list);
        }
        $_SESSION['success'] = 'Liste zurückgeholt.';

        return $this->redirect($response, '/voice-group-lists/' . $list->id);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $list = $this->findAccessibleList((int) ($args['id'] ?? 0));
        if ($list === null) {
            return $this->notFound($response);
        }

        $this->log('voice_group_list.deleted', $list);
        $list->delete();
        $_SESSION['success'] = 'Liste gelöscht.';

        return $this->redirect($response, '/voice-group-lists');
    }

    public function addColumn(Request $request, Response $response, array $args): Response
    {
        $list = $this->findAccessibleList((int) ($args['id'] ?? 0));
        if ($list === null) {
            return $this->notFound($response);
        }

        $back = '/voice-group-lists/' . $list->id;
        if ($list->isArchived()) {
            $_SESSION['error'] = self::ARCHIVED;
            return $this->redirect($response, $back);
        }

        $data = (array) $request->getParsedBody();
        $label = $this->validColumnLabel($data['label'] ?? null);
        if ($label === null) {
            return $this->redirect($response, $back);
        }

        $type = InputValidator::asString($data['type'] ?? null);
        if (!in_array($type, VoiceGroupListColumn::TYPES, true)) {
            $_SESSION['error'] = self::COLUMN_TYPE_INVALID;
            return $this->redirect($response, $back);
        }

        $column = VoiceGroupListColumn::create([
            'list_id' => $list->id,
            'label' => $label,
            'type' => $type,
            'sort_order' => (int) $list->columns()->max('sort_order') + 1,
        ]);
        $this->log('voice_group_list_column.created', $list, ['column_id' => (int) $column->id]);

        return $this->redirect($response, $back);
    }

    public function renameColumn(Request $request, Response $response, array $args): Response
    {
        $column = $this->findAccessibleColumn((int) ($args['column_id'] ?? 0));
        if ($column === null) {
            return $this->notFound($response);
        }

        $list = $column->list;
        $back = '/voice-group-lists/' . $list->id;
        if ($list->isArchived()) {
            $_SESSION['error'] = self::ARCHIVED;
            return $this->redirect($response, $back);
        }

        // Nur der Name: Die Art steht mit dem Anlegen fest, sonst würden aus
        // Häkchen stille Notizen ohne Text.
        $label = $this->validColumnLabel(((array) $request->getParsedBody())['label'] ?? null);
        if ($label !== null) {
            $column->update(['label' => $label]);
            $this->log('voice_group_list_column.renamed', $list, ['column_id' => (int) $column->id]);
        }

        return $this->redirect($response, $back);
    }

    public function moveColumn(Request $request, Response $response, array $args): Response
    {
        $column = $this->findAccessibleColumn((int) ($args['column_id'] ?? 0));
        if ($column === null) {
            return $this->notFound($response);
        }

        $list = $column->list;
        $back = '/voice-group-lists/' . $list->id;
        if ($list->isArchived()) {
            $_SESSION['error'] = self::ARCHIVED;
            return $this->redirect($response, $back);
        }

        $direction = InputValidator::asString(((array) $request->getParsedBody())['direction'] ?? null);
        $columns = $list->columns()->get()->values()->all();
        $index = array_search((int) $column->id, array_map(static fn($c): int => (int) $c->id, $columns), true);
        $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);

        if ($index !== false && $target !== null && isset($columns[$target])) {
            [$columns[$index], $columns[$target]] = [$columns[$target], $columns[$index]];
            // Neu durchnummeriert statt getauscht: Gleiche sort_order-Werte
            // (etwa aus einem Altbestand) ließen sich sonst nicht trennen.
            foreach ($columns as $position => $each) {
                if ((int) $each->sort_order !== $position) {
                    $each->update(['sort_order' => $position]);
                }
            }
            $this->log('voice_group_list_column.moved', $list, ['column_id' => (int) $column->id]);
        }

        return $this->redirect($response, $back);
    }

    public function deleteColumn(Request $request, Response $response, array $args): Response
    {
        $column = $this->findAccessibleColumn((int) ($args['column_id'] ?? 0));
        if ($column === null) {
            return $this->notFound($response);
        }

        $list = $column->list;
        $back = '/voice-group-lists/' . $list->id;
        if ($list->isArchived()) {
            $_SESSION['error'] = self::ARCHIVED;
            return $this->redirect($response, $back);
        }

        $this->log('voice_group_list_column.deleted', $list, ['column_id' => (int) $column->id]);
        $column->delete();

        return $this->redirect($response, $back);
    }

    /**
     * Setzt oder nimmt ein Häkchen, speichert oder leert eine Notiz.
     *
     * Mit Accept: application/json (voice-group-lists.js) kommt jede Antwort
     * als JSON zurück, auch jeder Fehler - eine Weiterleitung lieferte dem
     * fetch-Aufruf sonst eine HTML-Seite mit Status 200. Einzelne Einträge
     * werden bewusst nicht protokolliert: wer zuletzt geändert hat, steht am
     * Eintrag selbst.
     */
    public function saveEntry(Request $request, Response $response, array $args): Response
    {
        $json = RequestFormat::expectsJson($request);
        $column = $this->findAccessibleColumn((int) ($args['column_id'] ?? 0));
        if ($column === null) {
            return $json ? $this->jsonError($response, 404, self::NOT_FOUND) : $this->notFound($response);
        }

        $list = $column->list;
        $userId = (int) ($args['user_id'] ?? 0);
        $data = (array) $request->getParsedBody();

        $error = null;
        $status = 422;
        $value = null;
        if ($list->isArchived()) {
            [$error, $status] = [self::ARCHIVED, 409];
        } elseif (!$this->rows->isRow($list, $userId)) {
            $error = self::NOT_A_ROW;
        } elseif ($column->isCheckbox()) {
            $checked = InputValidator::asString($data['checked'] ?? null);
            $error = in_array($checked, ['0', '1'], true) ? null : self::INPUT_INVALID;
            $value = $checked === '1';
        } else {
            $value = trim(InputValidator::asString($data['note'] ?? null));
            $error = mb_strlen($value) > self::NOTE_MAX_LENGTH ? self::NOTE_TOO_LONG : null;
        }

        if ($error !== null) {
            if ($json) {
                return $this->jsonError($response, $status, $error);
            }
            $_SESSION['error'] = $error;
            return $this->redirect($response, '/voice-group-lists/' . $list->id);
        }

        $entry = $this->storeEntry($column, $userId, $value);

        if (!$json) {
            return $this->redirect($response, '/voice-group-lists/' . $list->id);
        }

        $progress = $this->rows->progressFor($list, $column);
        $response->getBody()->write((string) json_encode([
            'ok' => true,
            'column_id' => (int) $column->id,
            'user_id' => $userId,
            'checked' => $column->isCheckbox() && $entry !== null,
            'note' => $column->isCheckbox() || $entry === null ? null : (string) $entry->note,
            'tooltip' => $entry === null ? null : $this->tooltip($column, $entry),
            'progress' => $progress,
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    /**
     * Name und Stand der ersten Häkchen-Spalte einer Liste, null ohne eine.
     *
     * @return array{label: string, done: int, total: int}|null
     */
    private function summary(VoiceGroupList $list): ?array
    {
        $column = $list->columns()->where('type', VoiceGroupListColumn::TYPE_CHECKBOX)->first();
        if ($column === null) {
            return null;
        }

        $progress = $this->rows->progressFor($list, $column) ?? ['done' => 0, 'total' => 0];

        return ['label' => (string) $column->label] + $progress;
    }

    /**
     * Die Liste, wenn es sie gibt und sie zu den eigenen Stimmgruppen gehört;
     * sonst null - für fremde und fehlende gleichermaßen.
     */
    public function findAccessibleList(int $id): ?VoiceGroupList
    {
        $list = $id > 0 ? VoiceGroupList::find($id) : null;

        return $list !== null && $this->policy->canAccessList($list) ? $list : null;
    }

    public function notFound(Response $response): Response
    {
        $response->getBody()->write(self::NOT_FOUND);

        return $response->withStatus(404);
    }

    /**
     * "Abgehakt von … am …" bzw. "Zuletzt geändert von … am …".
     */
    public function tooltip(VoiceGroupListColumn $column, VoiceGroupListEntry $entry): string
    {
        $who = $entry->updatedBy !== null ? $this->names->formatPerson($entry->updatedBy) : 'unbekannt';

        if ($column->isCheckbox()) {
            $when = $entry->checked_at ?? $entry->updated_at;
            return 'Abgehakt von ' . $who . ' am ' . Carbon::parse($when)->format('d.m.Y');
        }

        return 'Zuletzt geändert von ' . $who . ' am ' . Carbon::parse($entry->updated_at)->format('d.m.Y');
    }

    private function findAccessibleColumn(int $id): ?VoiceGroupListColumn
    {
        $column = $id > 0 ? VoiceGroupListColumn::with('list')->find($id) : null;

        return $column !== null && $column->list !== null && $this->policy->canAccessList($column->list)
            ? $column
            : null;
    }

    /**
     * Ein leeres Häkchen und eine leere Notiz sind kein Eintrag: Die Zeile
     * verschwindet, eine neue Spalte ist damit für alle offen.
     */
    private function storeEntry(VoiceGroupListColumn $column, int $userId, bool|string $value): ?VoiceGroupListEntry
    {
        $existing = VoiceGroupListEntry::where('column_id', $column->id)->where('user_id', $userId)->first();

        if ($value === false || $value === '') {
            $existing?->delete();
            return null;
        }

        // Ein schon gesetztes Häkchen bleibt, wie es ist: Klickt eine zweite
        // Stimmvertretung auf einer veralteten Seite erneut, hieße es sonst
        // "Abgehakt von ihr" - mit dem Datum der ersten.
        if ($column->isCheckbox() && $existing?->checked_at !== null) {
            return $existing->load('updatedBy');
        }

        $attributes = $column->isCheckbox()
            ? ['checked_at' => Carbon::now()]
            : ['note' => $value];
        $attributes['updated_by_user_id'] = $this->policy->currentUserId() ?: null;

        $entry = $existing ?? new VoiceGroupListEntry(['column_id' => $column->id, 'user_id' => $userId]);
        $entry->fill($attributes)->save();

        return $entry->load('updatedBy');
    }

    private function jsonError(Response $response, int $status, string $message): Response
    {
        $response->getBody()->write((string) json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function validColumnLabel(mixed $raw): ?string
    {
        $label = trim(InputValidator::asString($raw));
        if ($label === '') {
            $_SESSION['error'] = self::COLUMN_LABEL_MISSING;
            return null;
        }

        if (mb_strlen($label) > 255) {
            $_SESSION['error'] = self::COLUMN_LABEL_TOO_LONG;
            return null;
        }

        return $label;
    }

    private function validTitle(mixed $raw): ?string
    {
        $title = trim(InputValidator::asString($raw));
        if ($title === '') {
            $_SESSION['error'] = self::TITLE_MISSING;
            return null;
        }

        if (mb_strlen($title) > 255) {
            $_SESSION['error'] = self::TITLE_TOO_LONG;
            return null;
        }

        return $title;
    }

    /**
     * Die gewählte Stimmgruppe; ohne Angabe die eine eigene, falls es genau
     * eine gibt.
     */
    private function chosenVoiceGroupId(mixed $raw): ?int
    {
        $input = trim(InputValidator::asString($raw));
        if ($input === '') {
            $own = $this->policy->accessibleVoiceGroupIds();
            return count($own) === 1 ? $own[0] : null;
        }

        $id = (int) $input;

        return $this->policy->canUseVoiceGroup($id) ? $id : null;
    }

    /**
     * Null für "ohne Projekt", false für ein Projekt, das nicht passt. Das
     * bisherige Projekt einer Liste passt immer - auch wenn kein Mitglied der
     * Stimmgruppe mehr darin ist, sonst ginge der Bezug beim Umbenennen verloren.
     */
    private function chosenProjectId(mixed $raw, int $voiceGroupId, ?int $currentProjectId = null): int|false|null
    {
        $input = trim(InputValidator::asString($raw));
        if ($input === '') {
            return null;
        }

        $id = (int) $input;
        $allowed = $this->allowedProjects([$voiceGroupId], $currentProjectId)
            ->pluck('id')
            ->map(static fn($value): int => (int) $value);

        return $allowed->contains($id) ? $id : false;
    }

    /**
     * Projekte mit mindestens einem Mitglied der genannten Stimmgruppen,
     * neueste zuerst; dazu das bisherige Projekt einer Liste.
     *
     * @param list<int> $voiceGroupIds
     * @return \Illuminate\Support\Collection<int, Project>
     */
    private function allowedProjects(array $voiceGroupIds, ?int $currentProjectId = null): \Illuminate\Support\Collection
    {
        if ($voiceGroupIds === []) {
            return collect();
        }

        return Project::query()
            ->whereExists(static function ($sub) use ($voiceGroupIds): void {
                $sub->selectRaw('1')
                    ->from('project_users')
                    ->join('user_voice_groups', 'user_voice_groups.user_id', '=', 'project_users.user_id')
                    ->whereColumn('project_users.project_id', 'projects.id')
                    ->whereIn('user_voice_groups.voice_group_id', $voiceGroupIds);
            })
            ->when($currentProjectId !== null, static fn($query) => $query->orWhere('projects.id', $currentProjectId))
            ->orderByDesc('start_date')
            ->orderBy('name')
            ->get(['id', 'name', 'start_date']);
    }

    private function log(string $event, VoiceGroupList $list, array $extra = []): void
    {
        $this->logger->info('Voice group list changed.', [
            'event' => $event,
            'list_id' => (int) $list->id,
            'voice_group_id' => (int) $list->voice_group_id,
            'actor_user_id' => $this->policy->currentUserId(),
        ] + $extra);
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
