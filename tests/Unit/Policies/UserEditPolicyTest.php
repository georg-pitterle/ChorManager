<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Policies\UserEditPolicy;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

final class UserEditPolicyTest extends TestCase
{
    /**
     * Die Policy bekommt die Sitzung im Konstruktor, wie ihre vier Geschwister.
     * Dass sie hier je Prüfung neu gebaut wird, ist der Punkt: Mehrere Tests
     * stellen dieselbe Frage für zwei verschiedene Sitzungen, und welche gerade
     * gilt, steht jetzt an der Konstruktion statt im Aufruf.
     *
     * @param array<string, mixed> $session
     */
    private function policy(array $session): UserEditPolicy
    {
        return new UserEditPolicy($session);
    }

    /**
     * @param array<int> $voiceGroupIds
     * @param array<int> $roleLevels
     */
    private function makeUser(
        int $id,
        array $voiceGroupIds,
        int $isActive = 1,
        array $roleLevels = []
    ): User {
        $user = new User();
        $user->forceFill(['id' => $id, 'is_active' => $isActive]);

        $groups = array_map(static function (int $groupId): VoiceGroup {
            $group = new VoiceGroup();
            $group->forceFill(['id' => $groupId]);

            return $group;
        }, $voiceGroupIds);

        $roles = array_map(static function (int $level): Role {
            $role = new Role();
            $role->forceFill(['hierarchy_level' => $level]);

            return $role;
        }, $roleLevels);

        $user->setRelation('voiceGroups', new Collection($groups));
        $user->setRelation('roles', new Collection($roles));

        return $user;
    }

    public function testGlobalEditPermissionAllowsEditing(): void
    {
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertTrue($this->policy($session)->canEdit($this->makeUser(7, [])));
    }

    public function testManagerWithoutEditPermissionCannotEdit(): void
    {
        $session = ['can_edit_users' => false, 'can_manage_users' => true];

        $this->assertFalse($this->policy($session)->canEdit($this->makeUser(7, [1])));
    }

    public function testVoiceGroupRepresentativeCanEditOwnVoiceGroupMember(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2, 3],
        ];

        $this->assertTrue($this->policy($session)->canEdit($this->makeUser(7, [3])));
    }

    public function testVoiceGroupRepresentativeCannotEditForeignMember(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2],
        ];

        $this->assertFalse($this->policy($session)->canEdit($this->makeUser(7, [5])));
    }

    /**
     * Die gemeinsame Stimmgruppe allein darf keinen Bearbeiten-Einstieg oeffnen:
     * UserController::update() verlangt zusaetzlich can_manage_own_voice_group.
     */
    public function testSharedVoiceGroupWithoutCapabilityFlagCannotEdit(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_users' => true,
            'can_manage_own_voice_group' => false,
            'voice_group_ids' => [2],
        ];

        $this->assertFalse($this->policy($session)->canEdit($this->makeUser(7, [2])));
    }

    public function testArchivedMemberIsNeverEditable(): void
    {
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertFalse($this->policy($session)->canEdit($this->makeUser(7, [1], 0)));
    }

    public function testEmptySessionCannotEdit(): void
    {
        $this->assertFalse($this->policy([])->canEdit($this->makeUser(7, [1])));
    }

    /**
     * can_manage_project_members darf die Projektzuordnung pflegen und deshalb das
     * Bearbeiten-Formular oeffnen - sonst bliebe die Zuordnung ueber die
     * Mitgliederliste unerreichbar.
     */
    public function testProjectMemberManagerMayOpenTheEditForm(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_users' => true,
            'can_manage_project_members' => true,
            'voice_group_ids' => [],
        ];

        $this->assertTrue($this->policy($session)->canEdit($this->makeUser(7, [1])));
    }

    /**
     * ... schreiben darf es dort aber nur die Projekte. Name, E-Mail, Rollen und
     * Stimmgruppen bleiben tabu: eine fremde E-Mail-Adresse plus Passwort-Reset
     * waere sonst ein Uebernahmepfad auf das Zielkonto.
     */
    public function testProjectMemberManagerMayNotEditProfileFields(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_users' => true,
            'can_manage_project_members' => true,
            'voice_group_ids' => [],
        ];

        $this->assertFalse($this->policy($session)->canEditProfile($this->makeUser(7, [1])));
    }

    public function testProjectMemberManagerCannotOpenFormForHigherRankedMember(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_project_members' => true,
            'role_level' => 50,
            'voice_group_ids' => [],
        ];

        $this->assertFalse($this->policy($session)->canEdit($this->makeUser(7, [1], 1, [80])));
    }

    public function testGlobalEditPermissionAllowsProfileEditing(): void
    {
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertTrue($this->policy($session)->canEditProfile($this->makeUser(7, [])));
    }

    public function testVoiceGroupRepresentativeMayEditProfileOfOwnGroupMember(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2],
        ];

        $this->assertTrue($this->policy($session)->canEditProfile($this->makeUser(7, [2])));
    }

    public function testArchivedMemberIsNeverProfileEditable(): void
    {
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertFalse($this->policy($session)->canEditProfile($this->makeUser(7, [1], 0)));
    }

    public function testHigherRankedMemberIsNotEditableDespiteGlobalEditPermission(): void
    {
        $session = ['can_edit_users' => true, 'can_manage_users' => true, 'role_level' => 80];

        $this->assertFalse($this->policy($session)->canEdit($this->makeUser(7, [1], 1, [100])));
    }

    public function testSameRankedMemberStaysEditable(): void
    {
        $session = ['can_edit_users' => true, 'can_manage_users' => true, 'role_level' => 100];

        $this->assertTrue($this->policy($session)->canEdit($this->makeUser(7, [1], 1, [100, 50])));
    }

    public function testVoiceGroupRepresentativeCannotEditHigherRankedOwnGroupMember(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2],
            'role_level' => 10,
        ];

        $this->assertFalse($this->policy($session)->canEdit($this->makeUser(7, [2], 1, [50])));
    }

    /**
     * Die dritte Stufe: canEditProjects() beantwortet, was UserController::update()
     * bisher selbst aus der Session zusammengesetzt hat.
     */
    public function testProjectMemberManagerMayEditProjectsOfActiveMember(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_project_members' => true,
            'voice_group_ids' => [],
        ];

        $this->assertTrue($this->policy($session)->canEditProjects($this->makeUser(7, [1])));
    }

    /**
     * Ein archiviertes Mitglied bleibt auf allen drei Stufen unantastbar. Ohne diese
     * Regel ließe sich das Formular zwar nicht mehr öffnen, ein direkt abgesetzter
     * POST auf /users/{id} hätte die Projektzuordnung aber weiterhin geschrieben.
     */
    public function testProjectMemberManagerMayNotEditProjectsOfArchivedMember(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_project_members' => true,
            'voice_group_ids' => [],
        ];

        $this->assertFalse($this->policy($session)->canEditProjects($this->makeUser(7, [1], 0)));
    }

    public function testProjectMemberManagerMayNotEditProjectsOfHigherRankedMember(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_project_members' => true,
            'voice_group_ids' => [],
            'role_level' => 10,
        ];

        $this->assertFalse($this->policy($session)->canEditProjects($this->makeUser(7, [1], 1, [50])));
    }

    /**
     * Das Bearbeiten-Recht schreibt die Projektzuordnung NICHT. Es stand dafür bis
     * Lauf 59 als rohes Sitzungsflag neben dieser Stufe in
     * UserController::update() (`$canEditGlobal || $canEditProjects`), während das
     * Formular die Kästchen nach einer dritten Regel freischaltete.
     *
     * Aufgelöst ist das zugunsten der Policy: Die Projektzuordnung gehört allein
     * can_manage_project_members, so wie der Klassenkommentar diese Stufe von
     * Anfang an beschreibt. Für die ausgelieferten Rollen ändert das nichts -
     * Admin und Vorstand halten beide Rechte ohnehin. Wer Mitglieder bearbeitet,
     * aber nicht die Projektbesetzung verwaltet, sieht das Feld jetzt nicht mehr
     * und schreibt es auch nicht.
     */
    public function testUserEditorAloneMayNotEditProjects(): void
    {
        $session = [
            'can_edit_users' => true,
            'can_manage_project_members' => false,
            'voice_group_ids' => [],
        ];

        $this->assertFalse($this->policy($session)->canEditProjects($this->makeUser(7, [1])));
        // Die übrigen Mitgliedsdaten bleiben ihm selbstverständlich offen.
        $this->assertTrue($this->policy($session)->canEditProfile($this->makeUser(7, [1])));
    }

    /**
     * Die eigene Stimmgruppe allein trägt die Projektzuordnung nicht - genau der
     * Fall, in dem das Formular die Kästchen zuvor trotzdem anbot.
     */
    public function testVoiceGroupManagerMayNotEditProjects(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_project_members' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [1],
        ];

        $this->assertFalse($this->policy($session)->canEditProjects($this->makeUser(7, [1])));
        // Bearbeiten darf sie das Mitglied trotzdem - nur eben nicht die Projekte.
        $this->assertTrue($this->policy($session)->canEditProfile($this->makeUser(7, [1])));
    }

    public function testWithoutProjectMemberRightNoProjectEditing(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_project_members' => false,
            'voice_group_ids' => [],
        ];

        $this->assertFalse($this->policy($session)->canEditProjects($this->makeUser(7, [1])));
    }

    /**
     * Die Rollenhierarchie gehört der Policy. UserController hat sie zuvor ein
     * zweites Mal selbst aus der Sitzung zusammengesetzt - zwei Quellen für
     * dieselbe Sicherheitsregel, die beim nächsten Eingriff auseinanderlaufen.
     */
    public function testTargetOutranksActorFollowsTheHighestRoleOfTheTarget(): void
    {
        $session = ['role_level' => 50];

        $this->assertTrue($this->policy($session)->targetOutranksActor($this->makeUser(7, [], 1, [10, 80])));
        $this->assertFalse($this->policy($session)->targetOutranksActor($this->makeUser(7, [], 1, [10, 50])));
        $this->assertFalse($this->policy($session)->targetOutranksActor($this->makeUser(7, [], 1, [])));
        // Ohne role_level in der Sitzung zählt jede Rolle über 0 als höher gereiht.
        $this->assertTrue($this->policy([])->targetOutranksActor($this->makeUser(7, [], 1, [1])));
    }

    /**
     * Archivieren und Wiederherstellen sind dieselbe Befugnis in zwei Richtungen,
     * deshalb darf der archivierte Zustand des Ziels sie nicht sperren - sonst
     * ließe sich ein stillgelegtes Mitglied nie zurückholen. Die Hierarchie und
     * die beiden Rechte gelten aber genau wie beim Bearbeiten.
     */
    public function testArchivePermissionIgnoresTheArchivedStateButNotTheHierarchy(): void
    {
        $globalSession = ['can_edit_users' => true];

        $this->assertTrue($this->policy($globalSession)->canArchive($this->makeUser(7, [1], 0)));
        $this->assertFalse($this->policy($globalSession)->canEditProfile($this->makeUser(7, [1], 0)));

        $outranked = ['can_edit_users' => true, 'role_level' => 10];
        $this->assertFalse($this->policy($outranked)->canArchive($this->makeUser(7, [1], 0, [50])));
    }

    public function testArchivePermissionOfAVoiceGroupRepresentativeStaysInTheirGroup(): void
    {
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2],
        ];

        $this->assertTrue($this->policy($session)->canArchive($this->makeUser(7, [2], 0)));
        $this->assertFalse($this->policy($session)->canArchive($this->makeUser(8, [5], 0)));
        $this->assertFalse($this->policy([])->canArchive($this->makeUser(9, [2], 0)));
    }

    /**
     * Ein Mitglied, dessen aktiver Zustand gar nicht geladen wurde, gilt als
     * nicht bearbeitbar. Bisher nahm die Policy für eine fehlende Spalte "aktiv"
     * an - eine Abfrage mit engerer Spaltenauswahl hätte damit still das
     * Bearbeiten eines archivierten Mitglieds freigegeben, und zwar genau auf dem
     * Weg, auf dem niemand danach sucht. Die sichere Richtung ist die Abweisung.
     */
    public function testAMemberWithoutALoadedActiveStateStaysUntouchable(): void
    {
        $session = ['can_edit_users' => true, 'can_manage_project_members' => true];

        $partiallyLoaded = new User();
        $partiallyLoaded->forceFill(['id' => 7]);
        $partiallyLoaded->setRelation('voiceGroups', new Collection([]));
        $partiallyLoaded->setRelation('roles', new Collection([]));

        $this->assertNull($partiallyLoaded->is_active);
        $this->assertFalse($this->policy($session)->canEdit($partiallyLoaded));
        $this->assertFalse($this->policy($session)->canEditProfile($partiallyLoaded));
        $this->assertFalse($this->policy($session)->canEditProjects($partiallyLoaded));
        $this->assertFalse($this->policy($session)->canEditEmail($partiallyLoaded));
    }

    /**
     * Archiviert wird nur, wer keine Rolle mehr über dem niedrigsten vergebenen
     * Level hält. Ein stillgelegtes Konto trägt damit nie wieder Rechte, die
     * beim Zurückholen - auch über eine Projektzuordnung - jemanden überragen
     * könnten, der es zurückholt.
     */
    public function testArchivingRequiresTheMemberToHoldNoRoleAboveTheMinimalLevel(): void
    {
        $session = ['can_edit_users' => true, 'role_level' => 90];

        // Ohne Rolle und mit genau der niedrigsten Rolle: archivierbar.
        $this->assertTrue($this->policy($session)->canDeactivate($this->makeUser(7, [], 1, []), 0));
        $this->assertTrue($this->policy($session)->canDeactivate($this->makeUser(7, [], 1, [0]), 0));

        // Eine Rolle darüber sperrt das Archivieren, obwohl die Befugnis besteht.
        $elevated = $this->makeUser(7, [], 1, [0, 30]);
        $this->assertTrue($this->policy($session)->canArchive($elevated));
        $this->assertFalse($this->policy($session)->canDeactivate($elevated, 0));
    }

    /**
     * Rollen sind je Installation frei konfigurierbar - die niedrigste muss nicht
     * auf 0 liegen. Maßstab ist das tatsächlich niedrigste vergebene Level.
     */
    public function testTheMinimalLevelIsNotAssumedToBeZero(): void
    {
        $session = ['can_edit_users' => true, 'role_level' => 90];

        $this->assertTrue($this->policy($session)->canDeactivate($this->makeUser(7, [], 1, [10]), 10));
        $this->assertFalse($this->policy($session)->canDeactivate($this->makeUser(7, [], 1, [20]), 10));
    }

    /**
     * Die Rollenregel gilt zusätzlich zur Befugnis, nicht an ihrer Stelle: ein
     * höher gereihtes Ziel bleibt unantastbar, auch wenn es gar keine Rolle hat.
     */
    public function testTheRoleRuleDoesNotReplaceThePermissionCheck(): void
    {
        $this->assertFalse($this->policy([])->canDeactivate($this->makeUser(7, [], 1, []), 0));
        $this->assertFalse(
            $this->policy(['can_edit_users' => true, 'role_level' => 10])
                ->canDeactivate($this->makeUser(7, [], 1, [50]), 0)
        );
    }

    /**
     * Bestand: Mitglieder, die vor dieser Regel archiviert wurden, tragen noch
     * ihre alten Rollen. Sie müssen zurückholbar bleiben, sonst stecken sie für
     * immer im Archiv fest - deshalb greift die Regel nur in die Archivier-
     * Richtung, nicht in canArchive() selbst.
     *
     * Dass ein solches Konto über die Projektzuordnung samt alter Rollen
     * zurückkommt (ProjectPersistence::addProjectMember() reaktiviert, und
     * ProjectMemberPolicy kennt bewusst keine Hierarchie), war im Review von
     * Lauf 45 eine offene Frage. Entschieden wurde: so lassen. Die Anwendung
     * geht erst jetzt erstmals in Betrieb, es gibt also keinen solchen
     * Bestand - und beide Wege ins Archiv (UserController::deactivate() und
     * die Sammelaktion) erzwingen die Regel oben, bevor ein Konto stillgelegt
     * wird. Künftig kann ein archiviertes Konto damit nichts Erhöhtes mehr
     * tragen, das beim Zurückholen jemanden überragen könnte.
     */
    public function testRestoringStaysPossibleForAnArchivedMemberWithAnElevatedRole(): void
    {
        $session = ['can_edit_users' => true, 'role_level' => 90];

        $this->assertTrue($this->policy($session)->canArchive($this->makeUser(7, [], 0, [30])));
    }
}
