<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\VoiceGroupController;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Wie viele Mitglieder eine Löschung trifft, stand nirgends.
 *
 * `user_voice_groups.voice_group_id` hängt mit ON DELETE CASCADE an
 * `voice_groups`: Wer eine Stimmgruppe entfernt, entfernt damit in einem Zug
 * jede Zuordnung von Mitgliedern zu ihr und alle Unterstimmen der Gruppe. Die
 * Bestätigung sagte das zwar in Worten ("entfernt die Zuweisung bei allen
 * betroffenen Mitgliedern"), nannte aber keine Zahl - und ohne Zahl liest sich
 * derselbe Satz bei einer leeren Gruppe wie bei einer mit 24 Personen.
 */
class VoiceGroupDeleteImpactFeatureTest extends TestCase
{
    use TestHttpHelpers;

    /** @var list<User> */
    private array $users = [];
    private ?VoiceGroup $group = null;
    private ?SubVoice $subVoice = null;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->users as $user) {
            $user->voiceGroups()->detach();
            $user->delete();
        }
        $this->users = [];
        $this->subVoice?->delete();
        $this->group?->delete();
        $_SESSION = [];

        parent::tearDown();
    }

    private function seedGroupWithMembers(int $memberCount, int $withSubVoice): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->group = VoiceGroup::create(['name' => 'Löschtest-Gruppe ' . $suffix]);
        $this->subVoice = SubVoice::create([
            'name' => 'Löschtest-Unterstimme ' . $suffix,
            'voice_group_id' => $this->group->id,
        ]);

        for ($i = 0; $i < $memberCount; $i++) {
            $user = User::create([
                'first_name' => 'Löschtest',
                'last_name' => 'Mitglied ' . $i,
                'email' => 'loeschtest.' . $suffix . '.' . $i . '@example.test', // naming:ascii
                'password' => PasswordHasher::hash('Correct-Horse-1'),
                'is_active' => 1,
            ]);
            $user->voiceGroups()->attach($this->group->id, [
                'sub_voice_id' => $i < $withSubVoice ? $this->subVoice->id : null,
            ]);
            $this->users[] = $user;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function renderData(): array
    {
        $captured = [];
        // Stub statt Mock: Der Test prueft die uebergebenen Daten, nicht den Aufruf. // naming:ascii
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data) use (&$captured) {
                $captured = $data;

                return $response;
            }
        );

        $controller = new VoiceGroupController($twig, new NullLogger());
        $controller->index($this->makeRequest('GET', '/voice-groups'), $this->makeResponse());

        return $captured;
    }

    public function testIndexReportsHowManyMembersAGroupDeletionWouldAffect(): void
    {
        $this->seedGroupWithMembers(3, 2);

        $data = $this->renderData();

        $this->assertArrayHasKey('group_member_counts', $data);
        $this->assertSame(3, $data['group_member_counts'][(int) $this->group->id] ?? null);

        $this->assertArrayHasKey('sub_voice_member_counts', $data);
        $this->assertSame(2, $data['sub_voice_member_counts'][(int) $this->subVoice->id] ?? null);
    }

    public function testAnEmptyGroupReportsZeroInsteadOfBeingMissing(): void
    {
        $this->seedGroupWithMembers(0, 0);

        $data = $this->renderData();

        // Ohne einen Eintrag müsste die Vorlage `default(0)` schreiben und dieselbe
        // Regel ein zweites Mal kennen. Der Controller liefert für jede Gruppe eine
        // Zahl, auch die 0.
        $this->assertSame(0, $data['group_member_counts'][(int) $this->group->id] ?? null);
        $this->assertSame(0, $data['sub_voice_member_counts'][(int) $this->subVoice->id] ?? null);
    }

    public function testDeleteConfirmationTemplateShowsTheCount(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/voice_groups/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('group_member_counts', $template);
        $this->assertStringContainsString('sub_voice_member_counts', $template);
    }
}
