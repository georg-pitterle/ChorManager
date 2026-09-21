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
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertTrue($policy->canEdit($session, $this->makeUser(7, [])));
    }

    public function testManagerWithoutEditPermissionCannotEdit(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => false, 'can_manage_users' => true];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [1])));
    }

    public function testVoiceGroupRepresentativeCanEditOwnVoiceGroupMember(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2, 3],
        ];

        $this->assertTrue($policy->canEdit($session, $this->makeUser(7, [3])));
    }

    public function testVoiceGroupRepresentativeCannotEditForeignMember(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2],
        ];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [5])));
    }

    /**
     * Die gemeinsame Stimmgruppe allein darf keinen Bearbeiten-Einstieg oeffnen:
     * UserController::update() verlangt zusaetzlich can_manage_own_voice_group.
     */
    public function testSharedVoiceGroupWithoutCapabilityFlagCannotEdit(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_users' => true,
            'can_manage_own_voice_group' => false,
            'voice_group_ids' => [2],
        ];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [2])));
    }

    public function testArchivedMemberIsNeverEditable(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [1], 0)));
    }

    public function testEmptySessionCannotEdit(): void
    {
        $policy = new UserEditPolicy();

        $this->assertFalse($policy->canEdit([], $this->makeUser(7, [1])));
    }

    /**
     * can_manage_project_members darf die Projektzuordnung pflegen und deshalb das
     * Bearbeiten-Formular oeffnen - sonst bliebe die Zuordnung ueber die
     * Mitgliederliste unerreichbar.
     */
    public function testProjectMemberManagerMayOpenTheEditForm(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_users' => true,
            'can_manage_project_members' => true,
            'voice_group_ids' => [],
        ];

        $this->assertTrue($policy->canEdit($session, $this->makeUser(7, [1])));
    }

    /**
     * ... schreiben darf es dort aber nur die Projekte. Name, E-Mail, Rollen und
     * Stimmgruppen bleiben tabu: eine fremde E-Mail-Adresse plus Passwort-Reset
     * waere sonst ein Uebernahmepfad auf das Zielkonto.
     */
    public function testProjectMemberManagerMayNotEditProfileFields(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_users' => true,
            'can_manage_project_members' => true,
            'voice_group_ids' => [],
        ];

        $this->assertFalse($policy->canEditProfile($session, $this->makeUser(7, [1])));
    }

    public function testProjectMemberManagerCannotOpenFormForHigherRankedMember(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_project_members' => true,
            'role_level' => 50,
            'voice_group_ids' => [],
        ];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [1], 1, [80])));
    }

    public function testGlobalEditPermissionAllowsProfileEditing(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertTrue($policy->canEditProfile($session, $this->makeUser(7, [])));
    }

    public function testVoiceGroupRepresentativeMayEditProfileOfOwnGroupMember(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2],
        ];

        $this->assertTrue($policy->canEditProfile($session, $this->makeUser(7, [2])));
    }

    public function testArchivedMemberIsNeverProfileEditable(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true];

        $this->assertFalse($policy->canEditProfile($session, $this->makeUser(7, [1], 0)));
    }

    public function testHigherRankedMemberIsNotEditableDespiteGlobalEditPermission(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true, 'role_level' => 80];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [1], 1, [100])));
    }

    public function testSameRankedMemberStaysEditable(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_users' => true, 'role_level' => 100];

        $this->assertTrue($policy->canEdit($session, $this->makeUser(7, [1], 1, [100, 50])));
    }

    public function testVoiceGroupRepresentativeCannotEditHigherRankedOwnGroupMember(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2],
            'role_level' => 10,
        ];

        $this->assertFalse($policy->canEdit($session, $this->makeUser(7, [2], 1, [50])));
    }

    /**
     * Die dritte Stufe: canEditProjects() beantwortet, was UserController::update()
     * bisher selbst aus der Session zusammengesetzt hat.
     */
    public function testProjectMemberManagerMayEditProjectsOfActiveMember(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_project_members' => true,
            'voice_group_ids' => [],
        ];

        $this->assertTrue($policy->canEditProjects($session, $this->makeUser(7, [1])));
    }

    /**
     * Ein archiviertes Mitglied bleibt auf allen drei Stufen unantastbar. Ohne diese
     * Regel ließe sich das Formular zwar nicht mehr öffnen, ein direkt abgesetzter
     * POST auf /users/{id} hätte die Projektzuordnung aber weiterhin geschrieben.
     */
    public function testProjectMemberManagerMayNotEditProjectsOfArchivedMember(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_project_members' => true,
            'voice_group_ids' => [],
        ];

        $this->assertFalse($policy->canEditProjects($session, $this->makeUser(7, [1], 0)));
    }

    public function testProjectMemberManagerMayNotEditProjectsOfHigherRankedMember(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_project_members' => true,
            'voice_group_ids' => [],
            'role_level' => 10,
        ];

        $this->assertFalse($policy->canEditProjects($session, $this->makeUser(7, [1], 1, [50])));
    }

    public function testWithoutProjectMemberRightNoProjectEditing(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_project_members' => false,
            'voice_group_ids' => [],
        ];

        $this->assertFalse($policy->canEditProjects($session, $this->makeUser(7, [1])));
    }

    /**
     * Die Rollenhierarchie gehört der Policy. UserController hat sie zuvor ein
     * zweites Mal selbst aus der Sitzung zusammengesetzt - zwei Quellen für
     * dieselbe Sicherheitsregel, die beim nächsten Eingriff auseinanderlaufen.
     */
    public function testTargetOutranksActorFollowsTheHighestRoleOfTheTarget(): void
    {
        $policy = new UserEditPolicy();
        $session = ['role_level' => 50];

        $this->assertTrue($policy->targetOutranksActor($session, $this->makeUser(7, [], 1, [10, 80])));
        $this->assertFalse($policy->targetOutranksActor($session, $this->makeUser(7, [], 1, [10, 50])));
        $this->assertFalse($policy->targetOutranksActor($session, $this->makeUser(7, [], 1, [])));
        // Ohne role_level in der Sitzung zählt jede Rolle über 0 als höher gereiht.
        $this->assertTrue($policy->targetOutranksActor([], $this->makeUser(7, [], 1, [1])));
    }

    /**
     * Archivieren und Wiederherstellen sind dieselbe Befugnis in zwei Richtungen,
     * deshalb darf der archivierte Zustand des Ziels sie nicht sperren - sonst
     * ließe sich ein stillgelegtes Mitglied nie zurückholen. Die Hierarchie und
     * die beiden Rechte gelten aber genau wie beim Bearbeiten.
     */
    public function testArchivePermissionIgnoresTheArchivedStateButNotTheHierarchy(): void
    {
        $policy = new UserEditPolicy();
        $globalSession = ['can_edit_users' => true];

        $this->assertTrue($policy->canArchive($globalSession, $this->makeUser(7, [1], 0)));
        $this->assertFalse($policy->canEditProfile($globalSession, $this->makeUser(7, [1], 0)));

        $outranked = ['can_edit_users' => true, 'role_level' => 10];
        $this->assertFalse($policy->canArchive($outranked, $this->makeUser(7, [1], 0, [50])));
    }

    public function testArchivePermissionOfAVoiceGroupRepresentativeStaysInTheirGroup(): void
    {
        $policy = new UserEditPolicy();
        $session = [
            'can_edit_users' => false,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [2],
        ];

        $this->assertTrue($policy->canArchive($session, $this->makeUser(7, [2], 0)));
        $this->assertFalse($policy->canArchive($session, $this->makeUser(8, [5], 0)));
        $this->assertFalse($policy->canArchive([], $this->makeUser(9, [2], 0)));
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
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'can_manage_project_members' => true];

        $partiallyLoaded = new User();
        $partiallyLoaded->forceFill(['id' => 7]);
        $partiallyLoaded->setRelation('voiceGroups', new Collection([]));
        $partiallyLoaded->setRelation('roles', new Collection([]));

        $this->assertNull($partiallyLoaded->is_active);
        $this->assertFalse($policy->canEdit($session, $partiallyLoaded));
        $this->assertFalse($policy->canEditProfile($session, $partiallyLoaded));
        $this->assertFalse($policy->canEditProjects($session, $partiallyLoaded));
        $this->assertFalse($policy->canEditEmail($session, $partiallyLoaded));
    }

    /**
     * Archiviert wird nur, wer keine Rolle mehr über dem niedrigsten vergebenen
     * Level hält. Ein stillgelegtes Konto trägt damit nie wieder Rechte, die
     * beim Zurückholen - auch über eine Projektzuordnung - jemanden überragen
     * könnten, der es zurückholt.
     */
    public function testArchivingRequiresTheMemberToHoldNoRoleAboveTheMinimalLevel(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'role_level' => 90];

        // Ohne Rolle und mit genau der niedrigsten Rolle: archivierbar.
        $this->assertTrue($policy->canDeactivate($session, $this->makeUser(7, [], 1, []), 0));
        $this->assertTrue($policy->canDeactivate($session, $this->makeUser(7, [], 1, [0]), 0));

        // Eine Rolle darüber sperrt das Archivieren, obwohl die Befugnis besteht.
        $elevated = $this->makeUser(7, [], 1, [0, 30]);
        $this->assertTrue($policy->canArchive($session, $elevated));
        $this->assertFalse($policy->canDeactivate($session, $elevated, 0));
    }

    /**
     * Rollen sind je Installation frei konfigurierbar - die niedrigste muss nicht
     * auf 0 liegen. Maßstab ist das tatsächlich niedrigste vergebene Level.
     */
    public function testTheMinimalLevelIsNotAssumedToBeZero(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'role_level' => 90];

        $this->assertTrue($policy->canDeactivate($session, $this->makeUser(7, [], 1, [10]), 10));
        $this->assertFalse($policy->canDeactivate($session, $this->makeUser(7, [], 1, [20]), 10));
    }

    /**
     * Die Rollenregel gilt zusätzlich zur Befugnis, nicht an ihrer Stelle: ein
     * höher gereihtes Ziel bleibt unantastbar, auch wenn es gar keine Rolle hat.
     */
    public function testTheRoleRuleDoesNotReplaceThePermissionCheck(): void
    {
        $policy = new UserEditPolicy();

        $this->assertFalse((new UserEditPolicy())->canDeactivate([], $this->makeUser(7, [], 1, []), 0));
        $this->assertFalse($policy->canDeactivate(
            ['can_edit_users' => true, 'role_level' => 10],
            $this->makeUser(7, [], 1, [50]),
            0
        ));
    }

    /**
     * Bestand: Mitglieder, die vor dieser Regel archiviert wurden, tragen noch
     * ihre alten Rollen. Sie müssen zurückholbar bleiben, sonst stecken sie für
     * immer im Archiv fest - deshalb greift die Regel nur in die Archivier-
     * Richtung, nicht in canArchive() selbst.
     */
    public function testRestoringStaysPossibleForAnArchivedMemberWithAnElevatedRole(): void
    {
        $policy = new UserEditPolicy();
        $session = ['can_edit_users' => true, 'role_level' => 90];

        $this->assertTrue($policy->canArchive($session, $this->makeUser(7, [], 0, [30])));
    }
}
