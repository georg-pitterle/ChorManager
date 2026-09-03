<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    /**
     * Jedes einzeln setzbare Recht einer Rolle.
     *
     * Die Liste stand vorher nur als Aufzählung in `$fillable`, in `$casts` und
     * ausgeschrieben im SessionAuthService. Wer ein Recht ergänzte, musste alle
     * Stellen treffen. Hier ist sie einmal benannt und ansprechbar;
     * `RolePermissionListTest` hält sie mit `$fillable` und `$casts` gleich.
     *
     * Das Hierarchie-Level gehört bewusst nicht dazu: Es vergibt kein Recht,
     * sondern entscheidet allein, wessen Zuordnungen jemand ändern darf.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'can_manage_users',
        'can_manage_roles',
        'can_edit_users',
        'can_manage_attendance_all',
        'can_manage_events',
        'can_manage_project_members',
        'can_read_finances',
        'can_manage_finances',
        'can_manage_master_data',
        'can_manage_sponsoring',
        'can_create_own_sponsorships',
        'can_manage_song_library',
        'can_manage_newsletters',
        'can_manage_mail_queue',
        'can_manage_sheet_archive',
        'can_manage_budget',
        'can_manage_tasks',
        'can_manage_backups',
        'can_manage_own_voice_group',
        'can_assign_own_voice_group_to_project',
    ];

    /**
     * Vollrechte, die ein kleineres Recht einschließen. Wer die Finanzen
     * verwalten darf, darf sie auch lesen; wer das Sponsoring verwaltet, darf
     * auch eigene Patenschaften anlegen. Die Umkehrung gilt nicht.
     *
     * @var array<string, list<string>>
     */
    public const IMPLIED_PERMISSIONS = [
        'can_manage_finances' => ['can_read_finances'],
        'can_manage_sponsoring' => ['can_create_own_sponsorships'],
    ];

    protected $table = 'roles';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'hierarchy_level',
        'can_manage_users',
        'can_manage_roles',
        'can_edit_users',
        'can_manage_attendance_all',
        'can_manage_events',
        'can_manage_project_members',
        'can_read_finances',
        'can_manage_finances',
        'can_manage_master_data',
        'can_manage_sponsoring',
        'can_create_own_sponsorships',
        'can_manage_song_library',
        'can_manage_newsletters',
        'can_manage_mail_queue',
        'can_manage_sheet_archive',
        'can_manage_budget',
        'can_manage_tasks',
        'can_manage_backups',
        'can_manage_own_voice_group',
        'can_assign_own_voice_group_to_project',
    ];

    /**
     * Die Rechte sind tinyint(1) und kamen ohne Cast als 1/0 zurück. Ein
     * `$role->can_manage_users === true` wäre damit still falsch gewesen,
     * obwohl das Recht gesetzt ist. Templates prüfen die Rechte auf
     * Wahrheitswert oder über `? '1' : '0'` - beides bleibt unverändert.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'hierarchy_level' => 'integer',
        'can_manage_users' => 'boolean',
        'can_manage_roles' => 'boolean',
        'can_edit_users' => 'boolean',
        'can_manage_attendance_all' => 'boolean',
        'can_manage_events' => 'boolean',
        'can_manage_project_members' => 'boolean',
        'can_read_finances' => 'boolean',
        'can_manage_finances' => 'boolean',
        'can_manage_master_data' => 'boolean',
        'can_manage_sponsoring' => 'boolean',
        'can_create_own_sponsorships' => 'boolean',
        'can_manage_song_library' => 'boolean',
        'can_manage_newsletters' => 'boolean',
        'can_manage_mail_queue' => 'boolean',
        'can_manage_sheet_archive' => 'boolean',
        'can_manage_budget' => 'boolean',
        'can_manage_tasks' => 'boolean',
        'can_manage_backups' => 'boolean',
        'can_manage_own_voice_group' => 'boolean',
        'can_assign_own_voice_group_to_project' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id');
    }
}
