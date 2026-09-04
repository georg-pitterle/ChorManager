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
     * Stellen treffen. Hier ist sie einmal benannt und ansprechbar - `$fillable`
     * und `casts()` leiten sich daraus ab, ein neues Recht wird also nur noch
     * hier eingetragen.
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

    /**
     * Die Rechte kommen aus PERMISSIONS dazu, statt dort ein zweites Mal zu
     * stehen. Sonst wäre ein neues Recht anlegbar, ohne massenzuweisbar zu sein -
     * das Formular schriebe es dann stillschweigend nicht.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'hierarchy_level',
        ...self::PERMISSIONS,
    ];

    /**
     * Die Rechte sind tinyint(1) und kamen ohne Cast als 1/0 zurück. Ein
     * `$role->can_manage_users === true` wäre damit still falsch gewesen,
     * obwohl das Recht gesetzt ist. Templates prüfen die Rechte auf
     * Wahrheitswert oder über `? '1' : '0'` - beides bleibt unverändert.
     *
     * Als Methode und nicht als `$casts`-Eigenschaft, weil die Liste erst
     * berechnet werden muss: `array_fill_keys()` ist in einem
     * Eigenschafts-Initialisierer nicht erlaubt.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hierarchy_level' => 'integer',
            ...array_fill_keys(self::PERMISSIONS, 'boolean'),
        ];
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id');
    }
}
