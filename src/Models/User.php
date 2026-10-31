<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';
    public $timestamps = false;

    /**
     * Spalten, die Listenabfragen laden dürfen. Solche Abfragen reichen ihre
     * Modelle unverändert an die View-Schicht durch; der Passwort-Hash hat dort
     * nichts verloren, `last_project_id` ist reiner Sitzungszustand.
     *
     * `id` muss enthalten bleiben, sonst lassen sich die Relationen nicht zuordnen.
     *
     * @var list<string>
     */
    public const LIST_COLUMNS = [
        'id',
        'email',
        'first_name',
        'last_name',
        'is_active',
    ];

    /**
     * Ob und wie die eigenen Aufgaben im abonnierten Kalender ankommen. Die
     * Werte spiegeln das ENUM aus Migration 20260830130000.
     */
    public const CALENDAR_TASK_FEED_NONE = 'none';
    public const CALENDAR_TASK_FEED_COMBINED = 'combined';
    public const CALENDAR_TASK_FEED_SEPARATE = 'separate';

    /** @var list<string> */
    public const CALENDAR_TASK_FEEDS = [
        self::CALENDAR_TASK_FEED_NONE,
        self::CALENDAR_TASK_FEED_COMBINED,
        self::CALENDAR_TASK_FEED_SEPARATE,
    ];

    public const CALENDAR_TASK_FORMAT_EVENT = 'event';
    public const CALENDAR_TASK_FORMAT_TODO = 'todo';

    /** @var list<string> */
    public const CALENDAR_TASK_FORMATS = [
        self::CALENDAR_TASK_FORMAT_EVENT,
        self::CALENDAR_TASK_FORMAT_TODO,
    ];

    protected $fillable = [
        'email',
        'password',
        'first_name',
        'last_name',
        'is_active',
        // Die Kennung in einer angeschlossenen Anwendung. Leer heißt: das
        // Anmeldezeugnis trägt die abgeleitete Form `cm-<id>`.
        'external_uid',
    ];

    /**
     * Zweite Absicherung neben LIST_COLUMNS: Wo ein Nutzer doch einmal mit allen
     * Spalten geladen und danach serialisiert wird, bleibt der Passwort-Hash aus
     * der Ausgabe. Auf `$user->password` wirkt sich das nicht aus - der
     * Login-Abgleich liest die Eigenschaft direkt.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * `is_active` ist tinyint(1) und kam ohne Cast als 1/0 zurück. Templates
     * prüfen die Spalte auf Wahrheitswert, das bleibt unverändert; neuer Code
     * mit `=== true` liest jetzt aber das, was dasteht.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'invitation_sent_at' => 'datetime',
    ];

    /**
     * Ab hier werden Anmeldungen erfasst (Migration 20261017120000). Davor sagt
     * ein leeres last_login_at nichts.
     */
    public const ACTIVITY_TRACKED_SINCE = '2026-10-17 00:00:00';

    /**
     * Ob eine Einladung nachweislich noch offen ist: verschickt nach Beginn der
     * Erfassung und seither keine Anmeldung. Ältere Einladungen gelten nie als
     * offen - für sie fehlt der Vergleich, und "offen" wäre geraten.
     */
    public function invitationPending(): bool
    {
        $sentAt = $this->invitation_sent_at;
        if ($sentAt === null || $sentAt->lt(Carbon::parse(self::ACTIVITY_TRACKED_SINCE))) {
            return false;
        }

        $lastLogin = $this->last_login_at;

        return $lastLogin === null || $lastLogin->lt($sentAt);
    }

    /**
     * Ob die Person die App nachweislich nutzt: angemeldet nach der letzten
     * Einladung (oder ganz ohne Einladung). Eine Sammel-Einladung überspringt
     * sie - ein neuer Link zum Passwortsetzen wäre für sie nur verwirrend.
     */
    public function usesApp(): bool
    {
        $lastLogin = $this->last_login_at;
        if ($lastLogin === null) {
            return false;
        }

        $sentAt = $this->invitation_sent_at;

        return $sentAt === null || $lastLogin->gte($sentAt);
    }

    /**
     * Hält eine neue Anmeldung fest - per Passwort oder über "angemeldet
     * bleiben".
     *
     * Als gezielte Abfrage statt über `save()`: Das Modell in der Hand des
     * Aufrufers kann Eingaben tragen, die mit der Anmeldung nichts zu tun haben,
     * und `save()` schriebe sie unbemerkt mit.
     */
    public static function recordLogin(int $userId): void
    {
        self::query()->whereKey($userId)->update(['last_login_at' => Carbon::now()]);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    /**
     * Aktive Mitglieder, die den Sponsoring-Bereich betreten dürfen - und damit
     * als Zuständige etwas tun können. Ohne eines der beiden Rechte zählt eine
     * eingetragene Zuständigkeit nicht (SponsoringPolicy::assignmentCounts()).
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeWithSponsoringAccess($query)
    {
        return $query
            ->where('is_active', 1)
            ->whereHas('roles', static function ($role): void {
                $role->where('can_manage_sponsoring', 1)->orWhere('can_create_own_sponsorships', 1);
            });
    }

    /**
     * Dieselbe Frage wie scopeWithSponsoringAccess(), nur am geladenen Modell.
     *
     * Gebraucht wird sie dort, wo die Antwort an einem bereits geladenen
     * Mitglied hängt und keine zweite Abfrage wert ist - SponsorResponsibility
     * fasst die Vorgänge eines Sponsors in PHP zusammen.
     *
     * Die Rollen sollten vorab geladen sein (`assignedUser.roles`), sonst holt
     * Eloquent sie je Mitglied nach. Falsch wird die Antwort dadurch nicht, nur
     * teuer.
     *
     * Dass die Regel zweimal formuliert ist - einmal in SQL, einmal hier -, hält
     * SponsoringResponsibilityFeatureTest::testBothFormsOfTheSponsoringAccessRuleAgree()
     * zusammen.
     */
    public function hasSponsoringAccess(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        foreach ($this->roles as $role) {
            if ($role->can_manage_sponsoring || $role->can_create_own_sponsorships) {
                return true;
            }
        }

        return false;
    }

    public function projects()
    {
        return $this->belongsToMany(Project::class, 'project_users', 'user_id', 'project_id');
    }

    public function voiceGroups()
    {
        return $this->belongsToMany(VoiceGroup::class, 'user_voice_groups', 'user_id', 'voice_group_id')
            ->withPivot('sub_voice_id')
            ->orderBy('voice_groups.id');
    }

    public function subVoices()
    {
        return $this->belongsToMany(SubVoice::class, 'user_voice_groups', 'user_id', 'sub_voice_id')
            ->withPivot('voice_group_id')
            ->orderBy('sub_voices.name');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'user_id', 'id');
    }

    public function eventRegistrations()
    {
        return $this->hasMany(EventRegistration::class, 'user_id', 'id');
    }

    public function newsletterRecipients()
    {
        return $this->hasMany(NewsletterRecipient::class, 'user_id', 'id');
    }

    public function tasks()
    {
        return $this->belongsToMany(Task::class, 'task_assignees', 'user_id', 'task_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'user_id', 'id');
    }

    /**
     * Was diese Person getan hat, das Neueste zuerst. `id` bricht den
     * Gleichstand aus demselben Grund wie bei `Task::activities()`.
     */
    public function activities()
    {
        return $this->hasMany(Activity::class, 'user_id', 'id')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');
    }

    public function mailAccount()
    {
        return $this->hasOne(UserMailAccount::class, 'user_id', 'id');
    }
}
