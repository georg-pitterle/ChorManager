<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\VoiceGroupListController;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Models\VoiceGroupList;
use App\Models\VoiceGroupListColumn;
use App\Models\VoiceGroupListEntry;
use App\Policies\VoiceGroupListPolicy;
use App\Services\NameFormatterService;
use App\Services\VoiceGroupListRows;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tests\Unit\Bootstrap;

/**
 * Spalten einer Stimmgruppen-Liste und die Häkchen und Notizen darin.
 *
 * Häkchen und Notizen speichern ohne Neuladen: Dieselben Formulare gehen per
 * fetch mit Accept: application/json hinaus, und die Antwort sagt dem Skript,
 * was in der Zelle und im Fortschritt zu stehen hat - auch im Fehlerfall, nie
 * als Weiterleitung.
 */
final class VoiceGroupListColumnsAndEntriesFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;
    use VoiceGroupListFixtures;

    private const JSON = ['Accept' => 'application/json'];

    private VoiceGroup $group;
    private User $representative;
    private User $member;
    private VoiceGroupList $list;
    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->group = $this->makeVoiceGroup('Alt');
        $this->representative = $this->makeMember($this->group, null, true, 'Vertretung');
        $this->member = $this->makeMember($this->group, null, true, 'Mitglied');
        $this->list = $this->makeList($this->group);
        $_SESSION = $this->representativeSession($this->representative, $this->group);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    public function testAddRenameMoveAndDeleteAColumn(): void
    {
        $this->post('addColumn', ['id' => $this->list->id], ['label' => 'Beitrag bezahlt', 'type' => 'checkbox']);
        $this->post('addColumn', ['id' => $this->list->id], ['label' => 'Kleidergröße', 'type' => 'note']);
        [$paid, $size] = $this->list->columns()->get()->all();
        $this->assertSame(['checkbox', 'note'], [$paid->type, $size->type]);

        $this->post('renameColumn', ['column_id' => $paid->id], ['label' => 'Beitrag 2027']);
        $this->assertSame('Beitrag 2027', $paid->fresh()?->label);

        $this->post('moveColumn', ['column_id' => $size->id], ['direction' => 'up']);
        $this->assertSame(['Kleidergröße', 'Beitrag 2027'], $this->list->columns()->pluck('label')->all());

        $entry = $this->entry($paid, $this->member, ['checked_at' => '2026-10-26 10:00:00']);
        $response = $this->post('deleteColumn', ['column_id' => $paid->id]);
        $this->assertRedirect($response, '/voice-group-lists/' . $this->list->id);
        $this->assertSame(['Kleidergröße'], $this->list->columns()->pluck('label')->all());
        $this->assertNull(VoiceGroupListEntry::find($entry->id));
    }

    public function testAnInvalidColumnTypeIsRejected(): void
    {
        $this->post('addColumn', ['id' => $this->list->id], ['label' => 'Auswahl', 'type' => 'select']);

        $this->assertSame('Bitte Häkchen oder Notiz wählen.', $_SESSION['error'] ?? null);
        $this->assertSame(0, $this->list->columns()->count());
    }

    public function testTheTypeOfAColumnCannotBeChanged(): void
    {
        $column = $this->makeColumn($this->list);

        $this->post('renameColumn', ['column_id' => $column->id], ['label' => 'Neu', 'type' => 'note']);

        $this->assertSame('checkbox', $column->fresh()?->type);
    }

    public function testTickingAndUntickingAMember(): void
    {
        $column = $this->makeColumn($this->list);

        $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $this->member->id], ['checked' => '1']);
        $entry = VoiceGroupListEntry::where('column_id', $column->id)->where('user_id', $this->member->id)->first();
        $this->assertNotNull($entry?->checked_at);
        $this->assertSame((int) $this->representative->id, (int) $entry->updated_by_user_id);

        $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $this->member->id], ['checked' => '0']);
        $this->assertSame(0, VoiceGroupListEntry::where('column_id', $column->id)->count());
    }

    public function testTickingAnAlreadyTickedMemberKeepsWhoTickedIt(): void
    {
        $column = $this->makeColumn($this->list);
        $this->entry($column, $this->member, ['checked_at' => '2026-10-24 18:30:00']);
        $second = $this->makeMember($this->group, null, true, 'Zweitvertretung');
        $_SESSION = $this->representativeSession($second, $this->group);

        $response = $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $this->member->id], ['checked' => '1'], self::JSON);

        $entry = VoiceGroupListEntry::where('column_id', $column->id)->where('user_id', $this->member->id)->firstOrFail();
        $this->assertSame((int) $this->representative->id, (int) $entry->updated_by_user_id);
        $name = (new NameFormatterService())->formatPerson($this->representative);
        $this->assertSame('Abgehakt von ' . $name . ' am 24.10.2026', json_decode((string) $response->getBody(), true)['tooltip']);
    }

    public function testSavingAndClearingANote(): void
    {
        $column = $this->makeColumn($this->list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße');
        $args = ['column_id' => $column->id, 'user_id' => $this->member->id];

        $this->post('saveEntry', $args, ['note' => '  M  ']);
        $this->assertSame('M', VoiceGroupListEntry::where('column_id', $column->id)->value('note'));

        $this->post('saveEntry', $args, ['note' => '']);
        $this->assertSame(0, VoiceGroupListEntry::where('column_id', $column->id)->count());
    }

    public function testAWhitespaceNoteRemovesTheEntry(): void
    {
        $column = $this->makeColumn($this->list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße');
        $this->entry($column, $this->member, ['note' => 'L']);

        $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $this->member->id], ['note' => " \n\t "]);

        $this->assertSame(0, VoiceGroupListEntry::where('column_id', $column->id)->count());
    }

    public function testATooLongNoteIsRejectedAndTheOldOneStays(): void
    {
        $column = $this->makeColumn($this->list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße');
        $this->entry($column, $this->member, ['note' => 'L']);

        $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $this->member->id], ['note' => str_repeat('ä', 1001)]);

        $this->assertSame('Eine Notiz darf höchstens 1000 Zeichen lang sein.', $_SESSION['error'] ?? null);
        $this->assertSame('L', VoiceGroupListEntry::where('column_id', $column->id)->value('note'));
    }

    public function testANoteOfExactlyTheLimitIsAccepted(): void
    {
        $column = $this->makeColumn($this->list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße');

        $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $this->member->id], ['note' => str_repeat('ä', 1000)]);

        $this->assertSame(1000, mb_strlen((string) VoiceGroupListEntry::where('column_id', $column->id)->value('note')));
    }

    public function testAnEntryForAMemberWhoIsNoRowIsRejected(): void
    {
        $column = $this->makeColumn($this->list);
        $stranger = $this->makeMember($this->makeVoiceGroup('Tenor'));

        $response = $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $stranger->id], ['checked' => '1'], self::JSON);
        $this->assertSame(422, $response->getStatusCode());

        $projectList = $this->makeList($this->group, $this->makeProject([$this->representative]), 'Projektliste');
        $projectColumn = $this->makeColumn($projectList);
        $response = $this->post(
            'saveEntry',
            ['column_id' => $projectColumn->id, 'user_id' => $this->member->id],
            ['checked' => '1'],
            self::JSON
        );
        $this->assertSame(422, $response->getStatusCode());

        $this->assertSame(0, VoiceGroupListEntry::query()->count());
    }

    public function testAForeignColumnAnswersLikeAMissingOne(): void
    {
        $foreignGroup = $this->makeVoiceGroup('Bass');
        $foreignMember = $this->makeMember($foreignGroup);
        $foreignList = $this->makeList($foreignGroup, null, 'Fremde Liste');
        $foreignColumn = $this->makeColumn($foreignList);

        foreach ([(int) $foreignColumn->id, 999999] as $columnId) {
            $calls = [
                ['addColumn', ['id' => $columnId === 999999 ? 999999 : $foreignList->id], ['label' => 'X', 'type' => 'note']],
                ['renameColumn', ['column_id' => $columnId], ['label' => 'Übernommen']],
                ['moveColumn', ['column_id' => $columnId], ['direction' => 'up']],
                ['deleteColumn', ['column_id' => $columnId], []],
                ['saveEntry', ['column_id' => $columnId, 'user_id' => $foreignMember->id], ['checked' => '1']],
            ];

            foreach ($calls as [$method, $args, $body]) {
                $response = $this->post($method, $args, $body);
                $this->assertSame(404, $response->getStatusCode(), $method);
                $this->assertSame('Liste nicht gefunden.', (string) $response->getBody(), $method);
            }
        }

        $this->assertSame(['Beitrag bezahlt'], $foreignList->columns()->pluck('label')->all());
        $this->assertSame(0, VoiceGroupListEntry::query()->count());
    }

    public function testAnArchivedListAcceptsNoColumnOrEntryChanges(): void
    {
        $column = $this->makeColumn($this->list);
        $this->list->update(['archived_at' => '2026-10-01 10:00:00']);

        $this->post('addColumn', ['id' => $this->list->id], ['label' => 'Neu', 'type' => 'note']);
        $this->post('renameColumn', ['column_id' => $column->id], ['label' => 'Neu']);
        $this->post('deleteColumn', ['column_id' => $column->id]);
        $response = $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $this->member->id], ['checked' => '1'], self::JSON);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(['Beitrag bezahlt'], $this->list->columns()->pluck('label')->all());
        $this->assertSame(0, VoiceGroupListEntry::query()->count());
        $this->assertSame('Die Liste ist archiviert und nur lesbar.', $_SESSION['error'] ?? null);
    }

    public function testLogsNeverContainColumnLabelOrNoteText(): void
    {
        $this->post('addColumn', ['id' => $this->list->id], ['label' => 'Gespräch Fehlzeiten', 'type' => 'note']);
        $column = $this->list->columns()->firstOrFail();
        $this->post('addColumn', ['id' => $this->list->id], ['label' => 'Zweite', 'type' => 'checkbox']);
        $this->post('renameColumn', ['column_id' => $column->id], ['label' => 'Gespräch Fehlzeiten 2']);
        $this->post('moveColumn', ['column_id' => $column->id], ['direction' => 'down']);
        $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $this->member->id], ['note' => 'Heikle Notiz']);
        $this->post('deleteColumn', ['column_id' => $column->id]);

        $events = [];
        foreach ($this->logs->getRecords() as $record) {
            $events[] = $record->context['event'] ?? null;
            $json = json_encode($record->toArray(), JSON_UNESCAPED_UNICODE) ?: '';
            $this->assertStringNotContainsString('Fehlzeiten', $json);
            $this->assertStringNotContainsString('Heikle', $json);
        }
        $this->assertSame([
            'voice_group_list_column.created',
            'voice_group_list_column.created',
            'voice_group_list_column.renamed',
            'voice_group_list_column.moved',
            'voice_group_list_column.deleted',
        ], $events);
    }

    public function testTheTickCellNamesWhoTickedItAndWhen(): void
    {
        $column = $this->makeColumn($this->list);
        $this->entry($column, $this->member, ['checked_at' => '2026-10-24 18:30:00']);

        $html = $this->showHtml();

        $name = (new NameFormatterService())->formatPerson($this->representative);
        $this->assertStringContainsString('title="Abgehakt von ' . $name . ' am 24.10.2026"', $html);
    }

    public function testATickAsJsonAnswersWithStateTooltipAndProgress(): void
    {
        $column = $this->makeColumn($this->list);
        $this->entry($column, $this->representative, ['checked_at' => '2026-10-24 18:30:00']);

        $response = $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $this->member->id], ['checked' => '1'], self::JSON);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $payload = json_decode((string) $response->getBody(), true);
        $name = (new NameFormatterService())->formatPerson($this->representative);
        $this->assertSame(true, $payload['ok']);
        $this->assertSame((int) $column->id, $payload['column_id']);
        $this->assertSame((int) $this->member->id, $payload['user_id']);
        $this->assertSame(true, $payload['checked']);
        $this->assertNull($payload['note']);
        $this->assertSame('Abgehakt von ' . $name . ' am ' . date('d.m.Y'), $payload['tooltip']);
        $this->assertSame(['done' => 2, 'total' => 2], $payload['progress']);

        $response = $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $this->member->id], ['checked' => '0'], self::JSON);
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(false, $payload['checked']);
        $this->assertNull($payload['tooltip']);
        $this->assertSame(['done' => 1, 'total' => 2], $payload['progress']);
    }

    public function testANoteAsJsonHasNoProgress(): void
    {
        $column = $this->makeColumn($this->list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße');

        $response = $this->post('saveEntry', ['column_id' => $column->id, 'user_id' => $this->member->id], ['note' => 'S'], self::JSON);

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('S', $payload['note']);
        $this->assertFalse($payload['checked']);
        $this->assertNull($payload['progress']);
        $this->assertStringStartsWith('Zuletzt geändert von ', (string) $payload['tooltip']);
    }

    public function testJsonErrorsCarryStatusAndMessage(): void
    {
        $column = $this->makeColumn($this->list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße');
        $args = ['column_id' => $column->id, 'user_id' => $this->member->id];

        $tooLong = $this->post('saveEntry', $args, ['note' => str_repeat('x', 1001)], self::JSON);
        $this->assertJsonError($tooLong, 422, 'Eine Notiz darf höchstens 1000 Zeichen lang sein.');

        $missing = $this->post('saveEntry', ['column_id' => 999999, 'user_id' => $this->member->id], ['note' => 'x'], self::JSON);
        $this->assertJsonError($missing, 404, 'Liste nicht gefunden.');

        $this->list->update(['archived_at' => '2026-10-01 10:00:00']);
        $archived = $this->post('saveEntry', $args, ['note' => 'x'], self::JSON);
        $this->assertJsonError($archived, 409, 'Die Liste ist archiviert und nur lesbar.');
    }

    public function testTheCellsCarryTheScriptHooks(): void
    {
        $column = $this->makeColumn($this->list);

        $html = $this->showHtml();

        $cell = $column->id . '-' . $this->member->id;
        $this->assertStringContainsString('data-vgl-entry', $html);
        $this->assertStringContainsString('data-vgl-cell="' . $cell . '"', $html);
        $this->assertStringContainsString('data-vgl-progress="' . $column->id . '"', $html);
        $this->assertStringContainsString('data-vgl-error', $html);
        $this->assertStringContainsString('/js/voice-group-lists.js', $html);
    }

    public function testAnArchivedListShowsNoForms(): void
    {
        $this->makeColumn($this->list);
        $this->list->update(['archived_at' => '2026-10-01 10:00:00']);

        $html = $this->showHtml();

        $this->assertStringNotContainsString('data-vgl-entry', $html);
        $this->assertStringNotContainsString('/columns', $html);
    }

    private function assertJsonError(ResponseInterface $response, int $status, string $message): void
    {
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['ok' => false, 'error' => $message], json_decode((string) $response->getBody(), true));
    }

    /**
     * @param array<string, int|string> $args
     * @param array<string, string> $body
     * @param array<string, string> $headers
     */
    private function post(string $method, array $args, array $body = [], array $headers = []): ResponseInterface
    {
        $stringArgs = array_map(static fn(int|string $value): string => (string) $value, $args);

        return $this->controller()->{$method}(
            $this->makeRequest('POST', '/voice-group-lists/test', $body, [], $headers),
            $this->makeResponse(),
            $stringArgs
        );
    }

    private function showHtml(): string
    {
        $response = $this->controller()->show(
            $this->makeRequest('GET', '/voice-group-lists/' . $this->list->id),
            $this->makeResponse(),
            ['id' => (string) $this->list->id]
        );

        return (string) $response->getBody();
    }

    /** @param array<string, string> $values */
    private function entry(VoiceGroupListColumn $column, User $member, array $values): VoiceGroupListEntry
    {
        return VoiceGroupListEntry::create([
            'column_id' => $column->id,
            'user_id' => $member->id,
            'updated_by_user_id' => $this->representative->id,
        ] + $values);
    }

    private function controller(): VoiceGroupListController
    {
        [$logger, $handler] = $this->logger();
        $this->logs ??= $handler;
        $logger->setHandlers([$this->logs]);

        return new VoiceGroupListController(
            $this->createAppTwig('/voice-group-lists'),
            new VoiceGroupListPolicy($_SESSION),
            new VoiceGroupListRows(),
            new NameFormatterService(),
            $logger
        );
    }
}
