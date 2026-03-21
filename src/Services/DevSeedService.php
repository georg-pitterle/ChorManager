<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\EventType;
use App\Models\Finance;
use App\Models\FinanceAttachment;
use App\Models\PasswordReset;
use App\Models\Project;
use App\Models\RememberLogin;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;

class DevSeedService
{
    private const MODE_APPEND = 'append';
    private const MODE_RESET = 'reset-and-seed';
    private const DEFAULT_SEED_PASSWORD = 'seed';
    private const ACTIVE_USER_TARGET = 80;

    /** @var array<string,int> */
    private const TARGET_VOICE_DISTRIBUTION = [
        'Sopran' => 25,
        'Alt' => 25,
        'Bass' => 15,
        'Tenor' => 15,
    ];

    /** @var string[] */
    private const CREDENTIAL_ROLES = [
        'Admin',
        'Vorstand',
        'Chorleitung',
        'Stimmvertretung',
        'Ersatzvertretung',
        'Mitglied',
    ];

    private array $report = [];

    public function run(string $mode = self::MODE_APPEND, int $years = 3, int $seed = 20260321): array
    {
        $this->assertDevMode();
        $this->assertMode($mode);

        $startedAt = microtime(true);
        mt_srand($seed);

        $this->report = [
            'mode' => $mode,
            'seed' => $seed,
            'years' => $years,
            'credentials_exposed_for_dev' => true,
            'credentials_by_role' => [],
            'warnings' => [],
            'counts' => [
                'roles' => 0,
                'voice_groups' => 0,
                'sub_voices' => 0,
                'users' => 0,
                'user_roles' => 0,
                'user_voice_groups' => 0,
                'projects' => 0,
                'project_users' => 0,
                'event_types' => 0,
                'event_series' => 0,
                'events' => 0,
                'attendance' => 0,
                'finances' => 0,
                'finance_attachments' => 0,
                'password_resets' => 0,
                'remember_logins' => 0,
                'settings' => 0,
                'app_settings' => 0,
            ],
        ];

        if ($mode === self::MODE_RESET) {
            $this->resetSeedData();
        }

        Capsule::connection()->transaction(function () use ($years) {

            $roles = $this->seedRoles();
            $voiceData = $this->seedVoiceGroups();
            $eventTypes = $this->seedEventTypes();
            $this->seedSettings();

            $users = $this->seedUsers($roles, $voiceData);
            $this->buildCredentialsByRoleReport($users['credentials_candidates']);

            $projects = $this->seedProjects($years);
            $projectMembers = $this->seedProjectMembers($projects, $users['active']);

            $projectEvents = $this->seedProjectEvents($projects, $eventTypes);
            $this->seedGlobalEvents($projects, $eventTypes, 12);

            $this->seedAttendance($projectMembers, $projectEvents);
            $this->seedFinances($projects, 320, 40);
            $this->seedAuthData($users['all']);
            $this->seedAppSettings();
        });

        $this->report['duration_seconds'] = round(microtime(true) - $startedAt, 3);
        $this->report['status'] = 'ok';

        return $this->report;
    }

    private function assertDevMode(): void
    {
        $appEnv = strtolower((string) (getenv('APP_ENV') ?: ''));
        $allowed = in_array($appEnv, ['development', 'dev', 'local'], true);
        $seedAllowed = (string) (getenv('ALLOW_DEV_SEED') ?: '') === '1';

        if (!$allowed || !$seedAllowed) {
            throw new RuntimeException('Dev seed is only allowed with APP_ENV=development|dev|local and ALLOW_DEV_SEED=1.');
        }
    }

    private function assertMode(string $mode): void
    {
        if (!in_array($mode, [self::MODE_APPEND, self::MODE_RESET], true)) {
            throw new RuntimeException('Invalid mode. Allowed values: append, reset-and-seed.');
        }
    }

    private function resetSeedData(): void
    {
        $connection = Capsule::connection();
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');

        $tables = [
            'attendance',
            'finance_attachments',
            'remember_logins',
            'password_resets',
            'project_users',
            'user_voice_groups',
            'user_roles',
            'events',
            'event_series',
            'finances',
            'projects',
            'users',
            'event_types',
            'sub_voices',
            'voice_groups',
            'roles',
            'settings',
            'app_settings',
        ];

        foreach ($tables as $table) {
            $connection->table($table)->truncate();
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function seedRoles(): array
    {
        $definitions = [
            [
                'name' => 'Admin',
                'hierarchy_level' => 100,
                'can_manage_users' => 1,
                'can_edit_users' => 1,
                'can_manage_project_members' => 1,
                'can_manage_finances' => 1,
                'can_manage_master_data' => 1
            ],
            [
                'name' => 'Vorstand',
                'hierarchy_level' => 80,
                'can_manage_users' => 1,
                'can_edit_users' => 1,
                'can_manage_project_members' => 1,
                'can_manage_finances' => 1,
                'can_manage_master_data' => 1
            ],
            [
                'name' => 'Chorleitung',
                'hierarchy_level' => 80,
                'can_manage_users' => 1,
                'can_edit_users' => 0,
                'can_manage_project_members' => 1,
                'can_manage_finances' => 0,
                'can_manage_master_data' => 1
            ],
            [
                'name' => 'Stimmvertretung',
                'hierarchy_level' => 50,
                'can_manage_users' => 0,
                'can_edit_users' => 0,
                'can_manage_project_members' => 1,
                'can_manage_finances' => 0,
                'can_manage_master_data' => 0
            ],
            [
                'name' => 'Ersatzvertretung',
                'hierarchy_level' => 40,
                'can_manage_users' => 0,
                'can_edit_users' => 0,
                'can_manage_project_members' => 0,
                'can_manage_finances' => 0,
                'can_manage_master_data' => 0
            ],
            [
                'name' => 'Mitglied',
                'hierarchy_level' => 0,
                'can_manage_users' => 0,
                'can_edit_users' => 0,
                'can_manage_project_members' => 0,
                'can_manage_finances' => 0,
                'can_manage_master_data' => 0
            ],
        ];

        $roles = [];
        foreach ($definitions as $roleData) {
            $role = Role::firstOrCreate(['name' => $roleData['name']], $roleData);
            $roles[$role->name] = $role;
            if ($role->wasRecentlyCreated) {
                $this->report['counts']['roles']++;
            }
        }

        return $roles;
    }

    private function seedVoiceGroups(): array
    {
        $voiceGroups = [
            'Sopran' => ['Sopran 1', 'Sopran 2'],
            'Alt' => ['Alt 1', 'Alt 2'],
            'Tenor' => ['Tenor 1', 'Tenor 2'],
            'Bass' => ['Bass 1', 'Bass 2'],
        ];

        $groupModels = [];
        $subModels = [];

        foreach ($voiceGroups as $groupName => $subNames) {
            $group = VoiceGroup::firstOrCreate(['name' => $groupName], ['name' => $groupName]);
            if ($group->wasRecentlyCreated) {
                $this->report['counts']['voice_groups']++;
            }
            $groupModels[$groupName] = $group;

            foreach ($subNames as $subName) {
                $sub = SubVoice::firstOrCreate(
                    ['name' => $subName, 'voice_group_id' => $group->id],
                    ['name' => $subName, 'voice_group_id' => $group->id]
                );

                if ($sub->wasRecentlyCreated) {
                    $this->report['counts']['sub_voices']++;
                }
                $subModels[$groupName][] = $sub;
            }
        }

        return ['groups' => $groupModels, 'subs' => $subModels];
    }

    private function seedEventTypes(): array
    {
        $definitions = [
            ['name' => 'Probe', 'color' => 'info'],
            ['name' => 'Registerprobe', 'color' => 'secondary'],
            ['name' => 'Auftritt', 'color' => 'danger'],
            ['name' => 'Sondertermin', 'color' => 'warning'],
            ['name' => 'Sitzung', 'color' => 'primary'],
        ];

        $types = [];
        foreach ($definitions as $def) {
            $type = EventType::firstOrCreate(['name' => $def['name']], $def);
            if ($type->wasRecentlyCreated) {
                $this->report['counts']['event_types']++;
            }
            $types[$type->name] = $type;
        }

        return $types;
    }

    private function seedSettings(): void
    {
        $settings = [
            ['setting_key' => 'app_name', 'setting_value' => 'Chor Manager (Dev Seed)'],
            ['setting_key' => 'fiscal_year_start', 'setting_value' => '01.09.'],
        ];

        foreach ($settings as $setting) {
            $model = Setting::updateOrCreate(['setting_key' => $setting['setting_key']], $setting);
            if ($model->wasRecentlyCreated) {
                $this->report['counts']['settings']++;
            }
        }
    }

    private function seedUsers(array $roles, array $voiceData): array
    {
        $users = [];
        $activeUsers = [];
        $credentialsCandidates = [];
        $usedFullNames = [];

        $rolePlan = [
            'Admin' => 2,
            'Vorstand' => 4,
            'Chorleitung' => 3,
            'Stimmvertretung' => 8,
            'Ersatzvertretung' => 8,
        ];

        $roleQueue = [];
        foreach ($rolePlan as $roleName => $count) {
            for ($i = 0; $i < $count; $i++) {
                $roleQueue[] = $roleName;
            }
        }

        while (count($roleQueue) < 140) {
            $roleQueue[] = 'Mitglied';
        }

        $activeTarget = min(self::ACTIVE_USER_TARGET, 140);
        $voiceQueue = $this->buildVoiceQueue($activeTarget);

        for ($i = 1; $i <= 140; $i++) {
            $isActive = $i <= $activeTarget;
            $groupName = $isActive ? $voiceQueue[$i - 1] : null;
            $personName = $this->buildMemberNameForVoice($i, $groupName, $usedFullNames);
            $firstName = $personName['first_name'];
            $lastName = $personName['last_name'];
            $email = sprintf('seed.%03d@chor.local', $i);

            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'password' => password_hash(self::DEFAULT_SEED_PASSWORD, PASSWORD_DEFAULT),
                    'is_active' => $isActive ? 1 : 0,
                ]
            );

            $user->first_name = $firstName;
            $user->last_name = $lastName;
            $user->password = password_hash(self::DEFAULT_SEED_PASSWORD, PASSWORD_DEFAULT);
            $user->is_active = $isActive ? 1 : 0;
            $user->save();

            if ($user->wasRecentlyCreated) {
                $this->report['counts']['users']++;
            }

            $roleName = $roleQueue[$i - 1];
            $roleId = $roles[$roleName]->id;
            if (!$user->roles()->where('role_id', $roleId)->exists()) {
                $user->roles()->attach($roleId);
                $this->report['counts']['user_roles']++;
            }

            if ((int) $user->is_active === 1 && !isset($credentialsCandidates[$roleName])) {
                $credentialsCandidates[$roleName] = $user;
            }

            if ($isActive && is_string($groupName)) {
                $group = $voiceData['groups'][$groupName];
                $subs = $voiceData['subs'][$groupName];
                $sub = $subs[$i % 2];

                if (!$user->voiceGroups()->where('voice_group_id', $group->id)->exists()) {
                    $user->voiceGroups()->attach($group->id, ['sub_voice_id' => $sub->id]);
                    $this->report['counts']['user_voice_groups']++;
                }
            }

            $users[] = $user;
            if ((int) $user->is_active === 1) {
                $activeUsers[] = $user;
            }
        }

        return [
            'all' => $users,
            'active' => $activeUsers,
            'credentials_candidates' => $credentialsCandidates,
        ];
    }

    private function buildCredentialsByRoleReport(array $seededCandidates): void
    {
        foreach (self::CREDENTIAL_ROLES as $roleName) {
            $candidate = $seededCandidates[$roleName] ?? null;

            if (!$candidate instanceof User) {
                $candidate = User::where('is_active', 1)
                    ->where('email', 'like', 'seed.%@chor.local')
                    ->whereHas('roles', function ($query) use ($roleName) {
                        $query->where('name', $roleName);
                    })
                    ->orderBy('id', 'asc')
                    ->first();
            }

            if (!$candidate instanceof User) {
                $this->report['warnings'][] = 'No active seed user found for role: ' . $roleName;
                $this->report['credentials_by_role'][] = [
                    'role' => $roleName,
                    'email' => null,
                    'password_plain' => null,
                    'user_id' => null,
                ];
                continue;
            }

            $this->report['credentials_by_role'][] = [
                'role' => $roleName,
                'email' => $candidate->email,
                'password_plain' => self::DEFAULT_SEED_PASSWORD,
                'user_id' => (int) $candidate->id,
            ];
        }
    }

    private function seedProjects(int $years): array
    {
        $projects = [];
        $currentYear = (int) date('Y');
        $startYear = $currentYear - max(1, $years) + 1;

        for ($year = $startYear; $year <= $currentYear; $year++) {
            $definitions = [
                [
                    'name' => $this->buildProjectName($year, 'FS'),
                    'description' => $this->buildProjectDescription('FS'),
                    'start_date' => sprintf('%d-02-01', $year),
                    'end_date' => sprintf('%d-06-30', $year),
                ],
                [
                    'name' => $this->buildProjectName($year, 'HS'),
                    'description' => $this->buildProjectDescription('HS'),
                    'start_date' => sprintf('%d-09-01', $year),
                    'end_date' => sprintf('%d-12-20', $year),
                ],
            ];

            foreach ($definitions as $def) {
                $project = Project::firstOrCreate(['name' => $def['name']], $def);
                if ($project->wasRecentlyCreated) {
                    $this->report['counts']['projects']++;
                }
                $projects[] = $project;
            }
        }

        usort($projects, fn(Project $a, Project $b) => strcmp((string) $a->start_date, (string) $b->start_date));

        return $projects;
    }

    private function seedProjectMembers(array $projects, array $activeUsers): array
    {
        $projectMembers = [];
        $activeIds = array_map(fn(User $user) => (int) $user->id, $activeUsers);
        $activeIds = $this->shuffled($activeIds);

        $activeCount = count($activeIds);
        if ($activeCount === 0) {
            return $projectMembers;
        }

        // Ca. 70% sind in ALLEN Projekten
        $coreCount = max(1, (int) round($activeCount * 0.70));

        // Pro Projekt nehmen nicht alle Aktiven teil (z.B. 85%)
        $projectSize = max($coreCount, (int) round($activeCount * 0.85));
        $projectSize = min($projectSize, $activeCount);

        $coreMembers = array_slice($activeIds, 0, $coreCount);
        $optionalPool = array_values(array_diff($activeIds, $coreMembers));

        foreach ($projects as $project) {
            $optionalNeeded = max(0, $projectSize - $coreCount);
            $optionalSelection = array_slice($this->shuffled($optionalPool), 0, $optionalNeeded);

            $selection = array_values(array_unique(array_merge($coreMembers, $optionalSelection)));
            $projectMembers[$project->id] = $selection;

            foreach ($selection as $userId) {
                if (!$project->users()->where('user_id', $userId)->exists()) {
                    $project->users()->attach($userId);
                    $this->report['counts']['project_users']++;
                }
            }
        }

        return $projectMembers;
    }

    private function seedProjectEvents(array $projects, array $eventTypes): array
    {
        $projectEvents = [];

        foreach ($projects as $project) {
            $startDate = new DateTimeImmutable((string) $project->start_date);
            $endDate = new DateTimeImmutable((string) $project->end_date);

            $seriesDefs = [
                [
                    'title' => 'Gesamtprobe',
                    'type' => 'Probe',
                    'frequency' => 'weekly',
                    'interval' => 1,
                    'offsetDays' => 0,
                    'count' => 20,
                    'stepDays' => 7
                ],
                [
                    'title' => 'Registerprobe',
                    'type' => 'Registerprobe',
                    'frequency' => 'weekly',
                    'interval' => 1,
                    'offsetDays' => 2,
                    'count' => 10,
                    'stepDays' => 7
                ],
                [
                    'title' => 'Ensembleprobe',
                    'type' => 'Probe',
                    'frequency' => 'weekly',
                    'interval' => 2,
                    'offsetDays' => 4,
                    'count' => 7,
                    'stepDays' => 14
                ],
                [
                    'title' => 'Technikprobe',
                    'type' => 'Sondertermin',
                    'frequency' => 'monthly',
                    'interval' => 1,
                    'offsetDays' => 9,
                    'count' => 2,
                    'stepDays' => 30
                ],
            ];

            foreach ($seriesDefs as $seriesDef) {
                $series = EventSeries::create([
                    'frequency' => $seriesDef['frequency'],
                    'recurrence_interval' => $seriesDef['interval'],
                    'weekdays' => '2,4',
                    'end_date' => $project->end_date,
                ]);
                $this->report['counts']['event_series']++;

                $cursor = $startDate->modify('+' . $seriesDef['offsetDays'] . ' days')->setTime(19, 30);
                $created = 0;

                while ($created < $seriesDef['count'] && $cursor <= $endDate->setTime(23, 59, 59)) {
                    $event = Event::create([
                        'title' => $seriesDef['title'] . ' - ' . $project->name,
                        'project_id' => $project->id,
                        'event_date' => $cursor->format('Y-m-d H:i:s'),
                        'event_type_id' => $eventTypes[$seriesDef['type']]->id,
                        'series_id' => $series->id,
                        'type' => $seriesDef['type'],
                        'location' => $this->pickLocation(),
                    ]);

                    $this->report['counts']['events']++;
                    $projectEvents[$project->id][] = $event;
                    $created++;
                    $cursor = $cursor->modify('+' . $seriesDef['stepDays'] . ' days');
                }
            }

            $singleDefs = [
                ['title' => 'Generalprobe', 'type' => 'Sondertermin', 'offset' => -10],
                ['title' => 'Auftritt Matinee', 'type' => 'Auftritt', 'offset' => -6],
                ['title' => 'Auftritt Abendkonzert', 'type' => 'Auftritt', 'offset' => -2],
                ['title' => 'Sondertermin Workshop', 'type' => 'Sondertermin', 'offset' => -25],
            ];

            foreach ($singleDefs as $singleDef) {
                $eventDate = $endDate->modify($singleDef['offset'] . ' days')->setTime(19, 0);
                $event = Event::create([
                    'title' => $singleDef['title'] . ' - ' . $project->name,
                    'project_id' => $project->id,
                    'event_date' => $eventDate->format('Y-m-d H:i:s'),
                    'event_type_id' => $eventTypes[$singleDef['type']]->id,
                    'series_id' => null,
                    'type' => $singleDef['type'],
                    'location' => $this->pickLocation(),
                ]);

                $this->report['counts']['events']++;
                $projectEvents[$project->id][] = $event;
            }

            // Ensure fixed project scope target: 43 events per project.
            $currentCount = count($projectEvents[$project->id] ?? []);
            while ($currentCount < 43) {
                $paddingDate = $startDate->modify('+' . (10 + ($currentCount * 3)) . ' days')->setTime(19, 30);
                if ($paddingDate > $endDate->setTime(23, 59, 59)) {
                    $paddingDate = $endDate->modify('-' . (43 - $currentCount) . ' days')->setTime(19, 30);
                }

                $event = Event::create([
                    'title' => 'Zusatzprobe - ' . $project->name,
                    'project_id' => $project->id,
                    'event_date' => $paddingDate->format('Y-m-d H:i:s'),
                    'event_type_id' => $eventTypes['Probe']->id,
                    'series_id' => null,
                    'type' => 'Probe',
                    'location' => $this->pickLocation(),
                ]);

                $this->report['counts']['events']++;
                $projectEvents[$project->id][] = $event;
                $currentCount++;
            }
        }

        return $projectEvents;
    }

    private function seedGlobalEvents(array $projects, array $eventTypes, int $count): void
    {
        if (count($projects) === 0) {
            $this->report['warnings'][] = 'No projects available for deriving global event dates.';
            return;
        }

        $firstYear = (int) substr((string) $projects[0]->start_date, 0, 4);
        $lastYear = (int) substr((string) $projects[count($projects) - 1]->start_date, 0, 4);

        $dates = [];
        for ($year = $firstYear; $year <= $lastYear; $year++) {
            $dates[] = new DateTimeImmutable(sprintf('%d-01-15 18:30:00', $year));
            $dates[] = new DateTimeImmutable(sprintf('%d-07-10 18:30:00', $year));
            $dates[] = new DateTimeImmutable(sprintf('%d-12-28 18:30:00', $year));
            $dates[] = new DateTimeImmutable(sprintf('%d-11-15 18:30:00', $year));
        }

        for ($i = 0; $i < $count; $i++) {
            $eventDate = $dates[$i % count($dates)];
            Event::create([
                'title' => 'Vereinssitzung ' . ($i + 1),
                'project_id' => null,
                'event_date' => $eventDate->format('Y-m-d H:i:s'),
                'event_type_id' => $eventTypes['Sitzung']->id,
                'series_id' => null,
                'type' => 'Sitzung',
                'location' => $this->pickLocation(),
            ]);
            $this->report['counts']['events']++;
        }
    }

    private function seedAttendance(array $projectMembers, array $projectEvents): void
    {
        $statuses = ['present', 'excused', 'unexcused'];

        foreach ($projectEvents as $projectId => $events) {
            $memberIds = $projectMembers[$projectId] ?? [];
            foreach ($events as $event) {
                foreach ($memberIds as $userId) {
                    if (mt_rand(1, 100) > 92) {
                        continue;
                    }

                    $roll = mt_rand(1, 100);
                    if ($roll <= 78) {
                        $status = $statuses[0];
                    } elseif ($roll <= 93) {
                        $status = $statuses[1];
                    } else {
                        $status = $statuses[2];
                    }

                    $note = null;
                    if (mt_rand(1, 100) <= 12) {
                        $note = $status === 'excused' ? 'Krank gemeldet' : 'Automatisch generierte Notiz';
                    }

                    Attendance::updateOrCreate(
                        ['event_id' => $event->id, 'user_id' => $userId],
                        ['status' => $status, 'note' => $note]
                    );
                    $this->report['counts']['attendance']++;
                }
            }
        }
    }

    private function seedFinances(array $projects, int $count, int $attachmentCount): void
    {
        $startDate = new DateTimeImmutable(sprintf('%d-01-01', (int) date('Y') - 2));
        $endDate = new DateTimeImmutable(sprintf('%d-12-31', (int) date('Y')));

        $descriptionsIncome = [
            'Mitgliedsbeitrag',
            'Konzertkarten',
            'Foerderung Gemeinde',
            'Spende Privatperson',
            'Sponsoring',
        ];

        $descriptionsExpense = [
            'Raummiete Probe',
            'Notenmaterial',
            'Technikmiete',
            'Reisekosten',
            'Verpflegung',
            'Druckkosten',
        ];

        $groups = [
            'Mitgliedsbeitraege',
            'Konzert',
            'Foerderung',
            'Notenmaterial',
            'Raummiete',
            'Technik',
            'Reise',
            'Sonstiges',
        ];

        $runningNumber = ((int) Finance::max('running_number')) + 1;
        $attachmentsLeft = $attachmentCount;

        for ($i = 0; $i < $count; $i++) {
            $isIncome = $i < 112;
            $paymentMethod = $i < 224 ? 'bank_transfer' : 'cash';
            $invoiceDate = $this->randomDate($startDate, $endDate);
            $paymentDate = $invoiceDate->modify('+' . mt_rand(0, 20) . ' days');

            $project = $projects[$i % count($projects)];
            $descriptionBase = $isIncome
                ? $descriptionsIncome[$i % count($descriptionsIncome)]
                : $descriptionsExpense[$i % count($descriptionsExpense)];

            $amount = $isIncome
                ? mt_rand(5000, 350000) / 100
                : mt_rand(2000, 240000) / 100;

            $finance = Finance::create([
                'running_number' => $runningNumber,
                'invoice_date' => $invoiceDate->format('Y-m-d'),
                'payment_date' => $paymentDate->format('Y-m-d'),
                'description' => $descriptionBase . ' - ' . $project->name,
                'group_name' => $groups[$i % count($groups)],
                'type' => $isIncome ? 'income' : 'expense',
                'amount' => $amount,
                'payment_method' => $paymentMethod,
            ]);

            $this->report['counts']['finances']++;
            $runningNumber++;

            if ($attachmentsLeft > 0 && mt_rand(1, 100) <= 30) {
                FinanceAttachment::create([
                    'finance_id' => $finance->id,
                    'filename' => sprintf('beleg-%05d.txt', $finance->running_number),
                    'mime_type' => 'text/plain',
                    'file_content' => 'Automatisch generierter Testbeleg fuer Laufnummer ' . $finance->running_number,
                ]);
                $this->report['counts']['finance_attachments']++;
                $attachmentsLeft--;
            }
        }

        while ($attachmentsLeft > 0) {
            $finance = Finance::orderBy('id', 'desc')->skip($attachmentsLeft - 1)->first();
            if (!$finance) {
                break;
            }

            FinanceAttachment::create([
                'finance_id' => $finance->id,
                'filename' => sprintf('beleg-zusatz-%05d.txt', $finance->running_number),
                'mime_type' => 'text/plain',
                'file_content' => 'Zusatzbeleg fuer Testdaten.',
            ]);
            $this->report['counts']['finance_attachments']++;
            $attachmentsLeft--;
        }
    }

    private function seedAuthData(array $users): void
    {
        $selectedResetUsers = array_slice($this->shuffled($users), 0, 40);
        foreach ($selectedResetUsers as $index => $user) {
            $tokenValue = bin2hex(random_bytes(32));
            $createdAt = (new DateTimeImmutable())->modify('-' . ($index % 6) . ' hours');

            PasswordReset::updateOrCreate(
                ['email' => $user->email],
                [
                    'email' => $user->email,
                    'token' => password_hash($tokenValue, PASSWORD_DEFAULT),
                    'created_at' => $createdAt->format('Y-m-d H:i:s'),
                ]
            );
            $this->report['counts']['password_resets']++;
        }

        $selectedRememberUsers = array_slice($this->shuffled($users), 0, 30);
        foreach ($selectedRememberUsers as $index => $user) {
            $expiresAt = (new DateTimeImmutable())->modify(($index < 10 ? '-2' : '+30') . ' days');

            RememberLogin::create([
                'user_id' => $user->id,
                'selector' => bin2hex(random_bytes(9)),
                'token_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'last_used_at' => null,
                'user_agent' => 'SeedAgent/1.0',
                'ip_address' => '127.0.0.1',
            ]);
            $this->report['counts']['remember_logins']++;
        }
    }

    private function seedAppSettings(): void
    {
        $settings = [
            'app_name' => 'Chor Manager (Seed)',
        ];

        foreach ($settings as $key => $value) {
            $model = AppSetting::updateOrCreate(
                ['setting_key' => $key],
                [
                    'setting_value' => $value,
                    'binary_content' => '',
                    'mime_type' => 'text/plain',
                ]
            );

            if ($model->wasRecentlyCreated) {
                $this->report['counts']['app_settings']++;
            }
        }
    }

    private function pickLocation(): string
    {
        $locations = [
            'Pfarrsaal Zentrum',
            'Aula Musikschule',
            'Kulturhaus Saal 1',
            'Stadthalle Probebuehne',
            'Pfarrkirche St. Martin',
        ];

        return $locations[array_rand($locations)];
    }

    private function buildVoiceQueue(int $activeTarget): array
    {
        $queue = [];
        $totalTarget = array_sum(self::TARGET_VOICE_DISTRIBUTION);
        $fractions = [];
        $allocated = 0;

        foreach (self::TARGET_VOICE_DISTRIBUTION as $voice => $weight) {
            $scaled = ($activeTarget * $weight) / $totalTarget;
            $count = (int) floor($scaled);
            $fractions[$voice] = $scaled - $count;
            $allocated += $count;
            $queue = array_merge($queue, array_fill(0, $count, $voice));
        }

        $remaining = $activeTarget - $allocated;
        if ($remaining > 0) {
            arsort($fractions);
            $voicesByRemainder = array_keys($fractions);
            for ($i = 0; $i < $remaining; $i++) {
                $queue[] = $voicesByRemainder[$i % count($voicesByRemainder)];
            }
        }

        return $this->shuffled($queue);
    }

    private function buildMemberNameForVoice(int $index, ?string $voiceGroup, array &$usedFullNames): array
    {
        $femaleFirstNames = [
            'Anna',
            'Lena',
            'Mia',
            'Sofia',
            'Clara',
            'Nora',
            'Leonie',
            'Emma',
            'Paula',
            'Ida',
            'Johanna',
            'Franziska',
            'Nina',
            'Selina',
            'Tanja',
            'Bianca',
            'Marlene',
            'Verena',
            'Patricia',
            'Aurelia',
            'Helena',
            'Carina',
            'Elisa',
            'Nadine',
            'Barbara',
            'Sandra',
            'Melanie',
            'Christine',
        ];

        $maleFirstNames = [
            'Jonas',
            'Lukas',
            'Noah',
            'David',
            'Felix',
            'Simon',
            'Paul',
            'Milan',
            'Jakob',
            'Ben',
            'Leon',
            'Marvin',
            'Samuel',
            'Victor',
            'Valentin',
            'Richard',
            'Gregor',
            'Konrad',
            'Benedikt',
            'Lorenz',
            'Tobias',
            'Moritz',
            'Nils',
            'Florian',
            'Sebastian',
            'Fabian',
            'Jan',
            'Adrian',
            'Maxim',
            'Philipp',
            'Andreas',
            'Christian',
            'Rene',
            'Daniel',
            'Martin',
            'Robert',
            'Thomas',
            'Stefan',
            'Harald',
            'Michael',
            'Dominik',
            'Kevin',
            'Marcel',
            'Raphael',
            'Georg',
            'Oliver',
            'Matthias',
            'Alexander',
            'Manuel',
            'Patrick',
        ];

        $lastNames = [
            'Mayer',
            'Huber',
            'Wagner',
            'Schmid',
            'Hofer',
            'Gruber',
            'Leitner',
            'Pichler',
            'Steiner',
            'Bauer',
            'Berger',
            'Schneider',
            'Fischer',
            'Kraus',
            'Lindner',
            'Winter',
            'Sommer',
            'Aigner',
            'Reiter',
            'Eder',
            'Brandl',
            'Holler',
            'Kirchner',
            'Falkner',
            'Neumann',
            'Kaiser',
            'Schuster',
            'Fink',
            'Arnold',
            'Koller',
            'Freytag',
            'Riedl',
            'Lang',
            'Kurz',
            'Vogel',
            'Singer',
            'Saitenklang',
            'Notenstein',
            'Taktmann',
            'Klangwald',
            'Morgenstern',
            'Tonreich',
            'Notenwald',
            'Schallberger',
            'Refrain',
            'Klanghofer',
            'Stimmgold',
            'Harmonisch',
            'Zwischenton',
            'Finale',
        ];

        if ($voiceGroup === 'Sopran' || $voiceGroup === 'Alt') {
            $firstNames = $femaleFirstNames;
        } elseif ($voiceGroup === 'Tenor' || $voiceGroup === 'Bass') {
            $firstNames = $maleFirstNames;
        } else {
            $firstNames = array_merge($femaleFirstNames, $maleFirstNames);
        }

        $firstBase = ($index * 7 + 11) % count($firstNames);
        $lastBase = ($index * 13 + 5) % count($lastNames);

        for ($attempt = 0; $attempt < count($lastNames) * 2; $attempt++) {
            $firstName = $firstNames[($firstBase + $attempt) % count($firstNames)];
            $lastName = $lastNames[($lastBase + ($attempt * 3)) % count($lastNames)];

            if ($index % 17 === 0) {
                $lastName .= '-Lenz';
            }

            if ($index % 23 === 0) {
                $lastName = 'von ' . $lastName;
            }

            $full = strtolower($firstName . '|' . $lastName);
            if (!isset($usedFullNames[$full])) {
                $usedFullNames[$full] = true;
                return [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ];
            }
        }

        $firstName = $firstNames[$firstBase];
        $lastName = $lastNames[$lastBase] . '-' . sprintf('%02d', $index);
        $usedFullNames[strtolower($firstName . '|' . $lastName)] = true;

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
    }

    private function buildProjectName(int $year, string $semester): string
    {
        $springTitles = [
            'Luft und Liebe',
            'Kaffeekantate Reloaded',
            'Ueber den Wolkenchor',
            'Frische Noten auf Rezept',
            'Singen bis der Fruehling klatscht',
            'Tonleiter mit Aussicht',
        ];

        $autumnTitles = [
            'Kerzen, Keks und Koloratur',
            'Nebelklang und Nachtigall',
            'Herbstwind in 4 Stimmen',
            'Leise rieselt der Groove',
            'Lametta und Legato',
            'Finale mit Punsch',
        ];

        $pool = $semester === 'FS' ? $springTitles : $autumnTitles;
        $title = $pool[$year % count($pool)];

        return sprintf('%s %d - %s', $semester, $year, $title);
    }

    private function buildProjectDescription(string $semester): string
    {
        if ($semester === 'FS') {
            return 'Semesterprojekt mit Konzertschwerpunkt, Registerarbeit und einer grossen Portion Fruehlingsenergie.';
        }

        return 'Herbstprojekt mit Buehnenpraesenz, Adventsprogramm und charmantem Finale im Dezember.';
    }

    private function shuffled(array $items): array
    {
        $copy = $items;
        shuffle($copy);
        return $copy;
    }

    private function randomDate(DateTimeImmutable $start, DateTimeImmutable $end): DateTimeImmutable
    {
        $startTs = $start->getTimestamp();
        $endTs = $end->getTimestamp();
        $randomTs = mt_rand($startTs, $endTs);
        return (new DateTimeImmutable())->setTimestamp($randomTs);
    }
}
