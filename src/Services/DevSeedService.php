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
use App\Models\Newsletter;
use App\Models\NewsletterTemplate;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipient;
use App\Models\PasswordReset;
use App\Models\Project;
use App\Models\RememberLogin;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Sponsor;
use App\Models\SponsorAttachment;
use App\Models\SponsorPackage;
use App\Models\SponsoringContact;
use App\Models\Sponsorship;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;

/**
 * DevSeedService must cover all persisted production modules.
 * Feature work is incomplete when new tables or relations are missing from the seed run.
 * For every new persisted feature, update imports, counts, resetSeedData(), seed methods, and the run() flow.
 */
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
                'sponsor_packages' => 0,
                'sponsors' => 0,
                'sponsorships' => 0,
                'sponsoring_contacts' => 0,
                'sponsor_attachments' => 0,
                'newsletter_templates' => 0,
                'newsletters' => 0,
                'newsletter_recipients' => 0,
                'newsletter_archive' => 0,
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
            $packages = $this->seedSponsorPackages();
            $sponsors = $this->seedSponsors();
            $sponsorships = $this->seedSponsorships($sponsors, $packages, $projects, $users['active']);
            $this->seedSponsoringContacts($sponsors, $sponsorships, $users['active']);
            $this->seedSponsorAttachments($sponsorships);
            $this->seedNewsletters($projects, $users['active']);
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
            'sponsor_attachments',
            'sponsoring_contacts',
            'sponsorships',
            'sponsors',
            'sponsor_packages',
            'newsletter_recipients',
            'newsletter_archive',
            'newsletters',
            'newsletter_templates',
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
                'can_manage_master_data' => 1,
                'can_manage_sponsoring' => 1,
            ],
            [
                'name' => 'Vorstand',
                'hierarchy_level' => 80,
                'can_manage_users' => 1,
                'can_edit_users' => 1,
                'can_manage_project_members' => 1,
                'can_manage_finances' => 1,
                'can_manage_master_data' => 1,
                'can_manage_sponsoring' => 1,
            ],
            [
                'name' => 'Chorleitung',
                'hierarchy_level' => 80,
                'can_manage_users' => 1,
                'can_edit_users' => 0,
                'can_manage_project_members' => 1,
                'can_manage_finances' => 0,
                'can_manage_master_data' => 1,
                'can_manage_sponsoring' => 1,
            ],
            [
                'name' => 'Stimmvertretung',
                'hierarchy_level' => 50,
                'can_manage_users' => 0,
                'can_edit_users' => 0,
                'can_manage_project_members' => 1,
                'can_manage_finances' => 0,
                'can_manage_master_data' => 0,
                'can_manage_sponsoring' => 0,
            ],
            [
                'name' => 'Ersatzvertretung',
                'hierarchy_level' => 40,
                'can_manage_users' => 0,
                'can_edit_users' => 0,
                'can_manage_project_members' => 0,
                'can_manage_finances' => 0,
                'can_manage_master_data' => 0,
                'can_manage_sponsoring' => 0,
            ],
            [
                'name' => 'Mitglied',
                'hierarchy_level' => 0,
                'can_manage_users' => 0,
                'can_edit_users' => 0,
                'can_manage_project_members' => 0,
                'can_manage_finances' => 0,
                'can_manage_master_data' => 0,
                'can_manage_sponsoring' => 0,
            ],
        ];

        $roles = [];
        foreach ($definitions as $roleData) {
            $role = Role::updateOrCreate(['name' => $roleData['name']], $roleData);
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

    private function seedSponsorPackages(): array
    {
        $definitions = [
            [
                'name' => 'Bronze',
                'description' => 'Kleines Einstiegspaket mit Logo auf ausgewählten Werbemitteln.',
                'min_amount' => 500.00,
                'color' => 'secondary',
            ],
            [
                'name' => 'Silber',
                'description' => 'Sichtbarkeit bei Konzerten, Website und Programmheft.',
                'min_amount' => 1200.00,
                'color' => 'info',
            ],
            [
                'name' => 'Gold',
                'description' => 'Erweiterte Präsenz mit Ansprache bei Veranstaltungen.',
                'min_amount' => 2500.00,
                'color' => 'warning',
            ],
            [
                'name' => 'Hauptsponsor',
                'description' => 'Exklusive Hauptpartnerschaft für Saison oder Projekt.',
                'min_amount' => 5000.00,
                'color' => 'danger',
            ],
        ];

        $packages = [];
        foreach ($definitions as $definition) {
            $package = SponsorPackage::updateOrCreate(
                ['name' => $definition['name']],
                $definition
            );

            if ($package->wasRecentlyCreated) {
                $this->report['counts']['sponsor_packages']++;
            }

            $packages[$package->name] = $package;
        }

        return $packages;
    }

    private function seedSponsors(): array
    {
        $definitions = [
            [
                'type' => 'organization',
                'name' => 'Musikhaus Weber',
                'contact_person' => 'Hannes Weber',
                'email' => 'partnerschaft@musikhaus-weber.local',
                'phone' => '+43 662 401 120',
                'address' => 'Linzer Gasse 12, 5020 Salzburg',
                'website' => 'https://musikhaus-weber.local',
                'notes' => 'Langjähriger Förderer regionaler Kulturprojekte.',
                'status' => 'active',
            ],
            [
                'type' => 'organization',
                'name' => 'Kulturstiftung am Fluss',
                'contact_person' => 'Dr. Eva Sonnleitner',
                'email' => 'foerderungen@kulturstiftung-fluss.local',
                'phone' => '+43 662 401 121',
                'address' => 'Uferstraße 8, 5020 Salzburg',
                'website' => 'https://kulturstiftung-fluss.local',
                'notes' => 'Interessiert an Jugend- und Bildungsprojekten.',
                'status' => 'negotiating',
            ],
            [
                'type' => 'organization',
                'name' => 'Druckerei Klangfarbe',
                'contact_person' => 'Miriam Pichler',
                'email' => 'miriam.pichler@klangfarbe.local',
                'phone' => '+43 662 401 122',
                'address' => 'Musterweg 5, 5071 Wals',
                'website' => null,
                'notes' => 'Bietet Sachleistungen für Drucksorten an.',
                'status' => 'contacted',
            ],
            [
                'type' => 'organization',
                'name' => 'ProAudio Salzburg',
                'contact_person' => 'Robert Unger',
                'email' => 'office@proaudio-salzburg.local',
                'phone' => '+43 662 401 123',
                'address' => 'Technikpark 3, 5020 Salzburg',
                'website' => 'https://proaudio-salzburg.local',
                'notes' => 'Gute Option für Technik-Sponsoring bei Konzerten.',
                'status' => 'prospect',
            ],
            [
                'type' => 'organization',
                'name' => 'Bankhaus Fortschritt',
                'contact_person' => 'Thomas König',
                'email' => 'kultur@bankhaus-fortschritt.local',
                'phone' => '+43 662 401 124',
                'address' => 'Rathausplatz 1, 5020 Salzburg',
                'website' => 'https://bankhaus-fortschritt.local',
                'notes' => 'Fragt nach Gegenleistungen im Jahresbericht.',
                'status' => 'paused',
            ],
            [
                'type' => 'organization',
                'name' => 'Hotel Harmonie',
                'contact_person' => 'Sabine Leitner',
                'email' => 'sabine.leitner@hotel-harmonie.local',
                'phone' => '+43 662 401 125',
                'address' => 'Mozartkai 17, 5020 Salzburg',
                'website' => null,
                'notes' => 'Prüft Unterstützung für Gastkünstler-Unterbringung.',
                'status' => 'negotiating',
            ],
            [
                'type' => 'person',
                'name' => 'Barbara Singer',
                'contact_person' => 'Barbara Singer',
                'email' => 'barbara.singer@privat.local',
                'phone' => '+43 664 401 126',
                'address' => 'Aiglhofstraße 22, 5020 Salzburg',
                'website' => null,
                'notes' => 'Private Förderin mit starkem Bezug zum Chor.',
                'status' => 'active',
            ],
            [
                'type' => 'person',
                'name' => 'Martin Reiter',
                'contact_person' => 'Martin Reiter',
                'email' => 'martin.reiter@privat.local',
                'phone' => null,
                'address' => null,
                'website' => null,
                'notes' => 'Hat nach erstem Konzertbesuch Interesse signalisiert.',
                'status' => 'contacted',
            ],
            [
                'type' => 'organization',
                'name' => 'Bäckerei Morgenstern',
                'contact_person' => 'Lisa Morgenstern',
                'email' => 'kontakt@baeckerei-morgenstern.local',
                'phone' => '+43 662 401 127',
                'address' => 'Marktplatz 6, 5020 Salzburg',
                'website' => 'https://baeckerei-morgenstern.local',
                'notes' => 'Frühere Kooperation abgeschlossen, evtl. Wiederaufnahme.',
                'status' => 'closed',
            ],
            [
                'type' => 'organization',
                'name' => 'Eventagentur Taktvoll',
                'contact_person' => 'Claudia Aigner',
                'email' => 'claudia.aigner@taktvoll.local',
                'phone' => '+43 662 401 128',
                'address' => 'Messeallee 4, 5020 Salzburg',
                'website' => 'https://taktvoll.local',
                'notes' => 'Interesse an Projektpartnerschaft für Herbstkonzert.',
                'status' => 'active',
            ],
        ];

        $sponsors = [];
        foreach ($definitions as $definition) {
            $sponsor = Sponsor::updateOrCreate(
                ['name' => $definition['name']],
                $definition
            );

            if ($sponsor->wasRecentlyCreated) {
                $this->report['counts']['sponsors']++;
            }

            $sponsors[$sponsor->name] = $sponsor;
        }

        return $sponsors;
    }

    private function seedSponsorships(array $sponsors, array $packages, array $projects, array $activeUsers): array
    {
        $definitions = [
            'Musikhaus Weber' => [
                [
                    'package' => 'Gold',
                    'project_offset' => -1,
                    'assigned_user_offset' => 0,
                    'amount' => 3200.00,
                    'status' => 'active',
                    'start_date' => '-8 months',
                    'end_date' => '+4 months',
                    'notes' => 'Aktive Saisonpartnerschaft inklusive Logo auf allen Konzertmedien.',
                ],
                [
                    'package' => 'Silber',
                    'project_offset' => null,
                    'assigned_user_offset' => 1,
                    'amount' => 1500.00,
                    'status' => 'closed',
                    'start_date' => '-20 months',
                    'end_date' => '-8 months',
                    'notes' => 'Abgeschlossene Unterstützung für vergangene Konzertreihe.',
                ],
            ],
            'Kulturstiftung am Fluss' => [
                [
                    'package' => 'Hauptsponsor',
                    'project_offset' => -2,
                    'assigned_user_offset' => 2,
                    'amount' => 7500.00,
                    'status' => 'negotiating',
                    'start_date' => '+1 month',
                    'end_date' => '+13 months',
                    'notes' => 'Förderantrag für Jubiläumsprojekt in finaler Abstimmung.',
                ],
            ],
            'Druckerei Klangfarbe' => [
                [
                    'package' => 'Bronze',
                    'project_offset' => -1,
                    'assigned_user_offset' => 3,
                    'amount' => 650.00,
                    'status' => 'contacted',
                    'start_date' => '-1 month',
                    'end_date' => '+11 months',
                    'notes' => 'Sachleistung für Programmhefte wurde angeboten.',
                ],
            ],
            'ProAudio Salzburg' => [
                [
                    'package' => 'Silber',
                    'project_offset' => -1,
                    'assigned_user_offset' => 4,
                    'amount' => 1400.00,
                    'status' => 'prospect',
                    'start_date' => '+2 months',
                    'end_date' => '+14 months',
                    'notes' => 'Erstgespräch für Technikpartnerschaft geplant.',
                ],
            ],
            'Bankhaus Fortschritt' => [
                [
                    'package' => 'Gold',
                    'project_offset' => null,
                    'assigned_user_offset' => 5,
                    'amount' => 2600.00,
                    'status' => 'paused',
                    'start_date' => '-10 months',
                    'end_date' => '+2 months',
                    'notes' => 'Entscheidung vertagt bis nach Budgetrunde.',
                ],
            ],
            'Hotel Harmonie' => [
                [
                    'package' => 'Silber',
                    'project_offset' => -2,
                    'assigned_user_offset' => 6,
                    'amount' => 1800.00,
                    'status' => 'negotiating',
                    'start_date' => '+3 months',
                    'end_date' => '+15 months',
                    'notes' => 'Kombination aus Zimmerkontingent und Geldleistung in Verhandlung.',
                ],
            ],
            'Barbara Singer' => [
                [
                    'package' => 'Bronze',
                    'project_offset' => null,
                    'assigned_user_offset' => 7,
                    'amount' => 800.00,
                    'status' => 'active',
                    'start_date' => '-4 months',
                    'end_date' => '+8 months',
                    'notes' => 'Private Förderzusage mit jährlicher Verlängerungsoption.',
                ],
            ],
            'Martin Reiter' => [
                [
                    'package' => 'Bronze',
                    'project_offset' => null,
                    'assigned_user_offset' => 8,
                    'amount' => 500.00,
                    'status' => 'contacted',
                    'start_date' => '+1 month',
                    'end_date' => '+12 months',
                    'notes' => 'Nachfassgespräch nach persönlicher Zusage offen.',
                ],
            ],
            'Bäckerei Morgenstern' => [
                [
                    'package' => 'Bronze',
                    'project_offset' => -3,
                    'assigned_user_offset' => 9,
                    'amount' => 700.00,
                    'status' => 'closed',
                    'start_date' => '-24 months',
                    'end_date' => '-13 months',
                    'notes' => 'Frühere Kooperation beendet, Kontakt bleibt erhalten.',
                ],
            ],
            'Eventagentur Taktvoll' => [
                [
                    'package' => 'Hauptsponsor',
                    'project_offset' => -1,
                    'assigned_user_offset' => 10,
                    'amount' => 6200.00,
                    'status' => 'active',
                    'start_date' => '-2 months',
                    'end_date' => '+10 months',
                    'notes' => 'Leitpartnerschaft für Herbstprojekt inklusive Social-Media-Paket.',
                ],
                [
                    'package' => 'Gold',
                    'project_offset' => null,
                    'assigned_user_offset' => 11,
                    'amount' => 3000.00,
                    'status' => 'negotiating',
                    'start_date' => '+5 months',
                    'end_date' => '+17 months',
                    'notes' => 'Zusätzliche Kooperation für Sommergala in Vorbereitung.',
                ],
            ],
        ];

        $projectCount = count($projects);
        $activeUserCount = count($activeUsers);
        $sponsorships = [];

        foreach ($definitions as $sponsorName => $items) {
            if (!isset($sponsors[$sponsorName])) {
                continue;
            }

            $sponsor = $sponsors[$sponsorName];

            foreach ($items as $index => $item) {
                if (!isset($packages[$item['package']])) {
                    continue;
                }

                $package = $packages[$item['package']];
                $project = null;
                if ($item['project_offset'] !== null && $projectCount > 0) {
                    $projectIndex = max(0, $projectCount + (int) $item['project_offset']);
                    $project = $projects[$projectIndex] ?? null;
                }

                $assignedUser = null;
                if ($activeUserCount > 0) {
                    $assignedUser = $activeUsers[$item['assigned_user_offset'] % $activeUserCount];
                }

                $startDate = (new DateTimeImmutable())->modify($item['start_date'])->format('Y-m-d');
                $endDate = (new DateTimeImmutable())->modify($item['end_date'])->format('Y-m-d');

                $sponsorship = Sponsorship::updateOrCreate(
                    [
                        'sponsor_id' => $sponsor->id,
                        'notes' => $item['notes'],
                    ],
                    [
                        'project_id' => $project?->id,
                        'package_id' => $package->id,
                        'assigned_user_id' => $assignedUser?->id,
                        'amount' => max((float) $item['amount'], (float) $package->min_amount),
                        'status' => $item['status'],
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'notes' => $item['notes'],
                    ]
                );

                if ($sponsorship->wasRecentlyCreated) {
                    $this->report['counts']['sponsorships']++;
                }

                $sponsorships[$sponsorName . '-' . $index] = $sponsorship;
            }
        }

        return $sponsorships;
    }

    private function seedSponsoringContacts(array $sponsors, array $sponsorships, array $activeUsers): void
    {
        $definitions = [
            [
                'sponsor' => 'Musikhaus Weber',
                'sponsorship_key' => 'Musikhaus Weber-0',
                'user_offset' => 0,
                'contact_date' => '-21 days',
                'type' => 'meeting',
                'summary' => 'Quartalsgespräch zur Sichtbarkeit beim Sommerkonzert.',
                'follow_up_date' => '+3 days',
                'follow_up_done' => 0,
            ],
            [
                'sponsor' => 'Kulturstiftung am Fluss',
                'sponsorship_key' => 'Kulturstiftung am Fluss-0',
                'user_offset' => 1,
                'contact_date' => '-10 days',
                'type' => 'email',
                'summary' => 'Finale Unterlagen für Förderentscheidung übermittelt.',
                'follow_up_date' => '-1 day',
                'follow_up_done' => 0,
            ],
            [
                'sponsor' => 'Druckerei Klangfarbe',
                'sponsorship_key' => 'Druckerei Klangfarbe-0',
                'user_offset' => 2,
                'contact_date' => '-14 days',
                'type' => 'call',
                'summary' => 'Telefonat zu Druckkontingent und Lieferterminen geführt.',
                'follow_up_date' => '+5 days',
                'follow_up_done' => 0,
            ],
            [
                'sponsor' => 'ProAudio Salzburg',
                'sponsorship_key' => 'ProAudio Salzburg-0',
                'user_offset' => 3,
                'contact_date' => '-5 days',
                'type' => 'email',
                'summary' => 'Angebotsanfrage für Technikpartnerschaft versendet.',
                'follow_up_date' => '+9 days',
                'follow_up_done' => 0,
            ],
            [
                'sponsor' => 'Bankhaus Fortschritt',
                'sponsorship_key' => 'Bankhaus Fortschritt-0',
                'user_offset' => 4,
                'contact_date' => '-35 days',
                'type' => 'meeting',
                'summary' => 'Budgetgespräch mit Hinweis auf spätere Wiedervorlage.',
                'follow_up_date' => '-7 days',
                'follow_up_done' => 0,
            ],
            [
                'sponsor' => 'Hotel Harmonie',
                'sponsorship_key' => 'Hotel Harmonie-0',
                'user_offset' => 5,
                'contact_date' => '-8 days',
                'type' => 'call',
                'summary' => 'Zimmerkontingent für Herbstprojekt mündlich zugesagt.',
                'follow_up_date' => '+4 days',
                'follow_up_done' => 1,
            ],
            [
                'sponsor' => 'Barbara Singer',
                'sponsorship_key' => 'Barbara Singer-0',
                'user_offset' => 6,
                'contact_date' => '-18 days',
                'type' => 'letter',
                'summary' => 'Persönliches Dankschreiben mit Konzertfotos versendet.',
                'follow_up_date' => null,
                'follow_up_done' => 0,
            ],
            [
                'sponsor' => 'Martin Reiter',
                'sponsorship_key' => 'Martin Reiter-0',
                'user_offset' => 7,
                'contact_date' => '-3 days',
                'type' => 'other',
                'summary' => 'Kurzes Gespräch nach Probe, Interesse an Privatförderung bekräftigt.',
                'follow_up_date' => '+11 days',
                'follow_up_done' => 0,
            ],
            [
                'sponsor' => 'Bäckerei Morgenstern',
                'sponsorship_key' => 'Bäckerei Morgenstern-0',
                'user_offset' => 8,
                'contact_date' => '-60 days',
                'type' => 'email',
                'summary' => 'Archivnotiz zum Ende der früheren Kooperation ergänzt.',
                'follow_up_date' => null,
                'follow_up_done' => 1,
            ],
            [
                'sponsor' => 'Eventagentur Taktvoll',
                'sponsorship_key' => 'Eventagentur Taktvoll-0',
                'user_offset' => 9,
                'contact_date' => '-6 days',
                'type' => 'meeting',
                'summary' => 'Abstimmung zum Bühnenbranding für das Herbstprojekt.',
                'follow_up_date' => '+2 days',
                'follow_up_done' => 0,
            ],
            [
                'sponsor' => 'Eventagentur Taktvoll',
                'sponsorship_key' => 'Eventagentur Taktvoll-1',
                'user_offset' => 10,
                'contact_date' => '-2 days',
                'type' => 'email',
                'summary' => 'Zusätzliche Kooperationsidee für Sommergala angefragt.',
                'follow_up_date' => '+12 days',
                'follow_up_done' => 0,
            ],
            [
                'sponsor' => 'Musikhaus Weber',
                'sponsorship_key' => null,
                'user_offset' => 11,
                'contact_date' => '-90 days',
                'type' => 'letter',
                'summary' => 'Jahresrückblick postalisch an Geschäftsführung übermittelt.',
                'follow_up_date' => null,
                'follow_up_done' => 1,
            ],
        ];

        $activeUserCount = count($activeUsers);

        foreach ($definitions as $definition) {
            if (!isset($sponsors[$definition['sponsor']])) {
                continue;
            }

            $sponsor = $sponsors[$definition['sponsor']];
            $sponsorship = $definition['sponsorship_key'] !== null
                ? ($sponsorships[$definition['sponsorship_key']] ?? null)
                : null;
            $user = $activeUserCount > 0
                ? $activeUsers[$definition['user_offset'] % $activeUserCount]
                : null;

            $contactDate = (new DateTimeImmutable())->modify($definition['contact_date'])->format('Y-m-d');
            $followUpDate = $definition['follow_up_date'] !== null
                ? (new DateTimeImmutable())->modify($definition['follow_up_date'])->format('Y-m-d')
                : null;

            $contact = SponsoringContact::updateOrCreate(
                [
                    'sponsor_id' => $sponsor->id,
                    'contact_date' => $contactDate,
                    'type' => $definition['type'],
                    'summary' => $definition['summary'],
                ],
                [
                    'sponsorship_id' => $sponsorship?->id,
                    'user_id' => $user?->id,
                    'follow_up_date' => $followUpDate,
                    'follow_up_done' => $definition['follow_up_done'],
                ]
            );

            if ($contact->wasRecentlyCreated) {
                $this->report['counts']['sponsoring_contacts']++;
            }
        }
    }

    private function seedSponsorAttachments(array $sponsorships): void
    {
        $definitions = [
            [
                'sponsorship_key' => 'Musikhaus Weber-0',
                'original_name' => 'vertrag-musikhaus-weber.pdf',
                'mime_type' => 'application/pdf',
                'file_content' => 'PDF Testinhalt: Sponsoringvertrag Musikhaus Weber',
            ],
            [
                'sponsorship_key' => 'Kulturstiftung am Fluss-0',
                'original_name' => 'foerderantrag-kulturstiftung.pdf',
                'mime_type' => 'application/pdf',
                'file_content' => 'PDF Testinhalt: Förderantrag Kulturstiftung am Fluss',
            ],
            [
                'sponsorship_key' => 'Barbara Singer-0',
                'original_name' => 'dankesschreiben-barbara-singer.txt',
                'mime_type' => 'text/plain',
                'file_content' => 'Vielen Dank für Ihre Unterstützung des Chorprojekts.',
            ],
            [
                'sponsorship_key' => 'Eventagentur Taktvoll-0',
                'original_name' => 'branding-briefing-taktvoll.pdf',
                'mime_type' => 'application/pdf',
                'file_content' => 'PDF Testinhalt: Branding-Briefing Eventagentur Taktvoll',
            ],
            [
                'sponsorship_key' => 'Eventagentur Taktvoll-1',
                'original_name' => 'angebot-sommergala.pdf',
                'mime_type' => 'application/pdf',
                'file_content' => 'PDF Testinhalt: Angebot Sommergala Sponsoring',
            ],
        ];

        foreach ($definitions as $definition) {
            $sponsorship = $sponsorships[$definition['sponsorship_key']] ?? null;
            if (!$sponsorship instanceof Sponsorship) {
                continue;
            }

            $storedFilename = bin2hex(random_bytes(8)) . '_' . $definition['original_name'];
            $attachment = SponsorAttachment::firstOrCreate(
                [
                    'sponsorship_id' => $sponsorship->id,
                    'original_name' => $definition['original_name'],
                ],
                [
                    'filename' => $storedFilename,
                    'mime_type' => $definition['mime_type'],
                    'file_content' => $definition['file_content'],
                ]
            );

            if ($attachment->wasRecentlyCreated) {
                $this->report['counts']['sponsor_attachments']++;
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

    private function seedNewsletters(array $projects, array $activeUsers): void
    {
        $templateDefinitions = [
            [
                'name' => 'Event-Ankündigung',
                'category' => 'event',
                'content_html' => '<h2>Kommender Auftritt</h2>' .
                    '<p>Liebe Sängerinnen und Sänger,</p>' .
                    '<p>wir heißen euch herzlich zu unserem kommenden Konzert willkommen!</p>' .
                    '<p><strong>Datum: [EVENT_DATE]</strong><br>' .
                    '<strong>Uhrzeit: [EVENT_TIME]</strong><br>' .
                    '<strong>Ort: [EVENT_LOCATION]</strong></p>' .
                    '<p>Wir freuen uns auf euer Erscheinen!</p>',
            ],
            [
                'name' => 'Newsletter Standard',
                'category' => 'general',
                'content_html' => '<h2>Newsletter</h2>' .
                    '<p>Liebe Projektmitglieder,</p>' .
                    '<p>hier sind die wichtigsten Neuigkeiten und Infos zum Projekt:</p>' .
                    '<ul><li>Informationen</li><li>Ankündigungen</li><li>Sonstiges</li></ul>' .
                    '<p>Viel Spaß beim Lesen!</p>',
            ],
            [
                'name' => 'Nachbericht',
                'category' => 'report',
                'content_html' => '<h2>Ein großartiger Erfolg!</h2>' .
                    '<p>Unser Konzert war ein voller Erfolg. Vielen Dank an alle Beteiligten!</p>' .
                    '<p>Weitere Bilder finden Sie auf unserer Website.</p>',
            ],
        ];

        $adminUser = $activeUsers[0] ?? null;

        foreach ($templateDefinitions as $templateDef) {
            $template = NewsletterTemplate::updateOrCreate(
                ['name' => $templateDef['name'], 'project_id' => null],
                [
                    'description' => 'Vordefinierte Vorlage',
                    'content_html' => $templateDef['content_html'],
                    'project_id' => null,
                    'category' => $templateDef['category'],
                    'created_by' => $adminUser?->id ?? 1,
                ]
            );

            if ($template->wasRecentlyCreated) {
                $this->report['counts']['newsletter_templates']++;
            }
        }

        // Create sent newsletters for each project
        foreach ($projects as $project) {
            if (count($project->users) === 0) {
                continue;
            }

            // 2-3 gesendete Newsletter pro Projekt
            $sentCount = mt_rand(2, 3);
            for ($i = 0; $i < $sentCount; $i++) {
                $createdUser = $activeUsers[$i % count($activeUsers)] ?? $activeUsers[0];
                $sentDate = (new DateTimeImmutable())->modify('-' . ($sentCount - $i) * 7 . ' days');

                $newsletter = Newsletter::create([
                    'project_id' => $project->id,
                    'event_id' => null,
                    'title' => 'Newsletter ' . $project->name . ' #' . ($i + 1),
                    'content_html' => '<h2>Newsletter ' . $project->name . '</h2>' .
                        '<p>Aktuelle Informationen zum Projekt:</p>' .
                        '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>',
                    'status' => 'sent',
                    'created_by' => $createdUser->id,
                    'locked_by' => null,
                    'locked_at' => null,
                    'sent_at' => $sentDate->format('Y-m-d H:i:s'),
                ]);

                $this->report['counts']['newsletters']++;

                // Add recipients from project members
                $recipients = $project->users()->pluck('user_id')->toArray();
                $newsletter->recipient_count = count($recipients);
                $newsletter->save();

                foreach ($recipients as $userId) {
                    NewsletterRecipient::create([
                        'newsletter_id' => $newsletter->id,
                        'user_id' => $userId,
                        'status' => 'sent',
                    ]);
                    $this->report['counts']['newsletter_recipients']++;

                    // Add to archive
                    $user = User::find($userId);
                    if ($user) {
                        $readAt = mt_rand(1, 100) > 30 ?
                            (new DateTimeImmutable())->format('Y-m-d H:i:s') :
                            null;
                        NewsletterArchive::create([
                            'newsletter_id' => $newsletter->id,
                            'user_id' => $userId,
                            'email' => $user->email,
                            'sent_at' => $sentDate->format('Y-m-d H:i:s'),
                            'read_at' => $readAt,
                        ]);
                        $this->report['counts']['newsletter_archive']++;
                    }
                }
            }

            // 1 Draft pro Projekt
            $draftUser = $activeUsers[(0 + 1) % count($activeUsers)];
            $draft = Newsletter::create([
                'project_id' => $project->id,
                'event_id' => null,
                'title' => 'Entwurf: ' . $project->name . ' - Neuer Newsletter',
                'content_html' => '<h2>Editierbar</h2><p>Dies ist ein Entwurfs-Newsletter, der noch bearbeitet werden kann.</p>',
                'status' => 'draft',
                'created_by' => $draftUser->id,
                'locked_by' => null,
                'locked_at' => null,
            ]);

            $this->report['counts']['newsletters']++;

            $recipients = $project->users()->pluck('user_id')->toArray();
            $draft->recipient_count = count($recipients);
            $draft->save();

            foreach ($recipients as $userId) {
                NewsletterRecipient::create([
                    'newsletter_id' => $draft->id,
                    'user_id' => $userId,
                    'status' => 'pending',
                ]);
                $this->report['counts']['newsletter_recipients']++;
            }
        }
    }
}
