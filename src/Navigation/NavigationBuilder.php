<?php

declare(strict_types=1);

namespace App\Navigation;

/**
 * Builds the top navigation as a plain tree of visible nodes.
 * Group visibility is derived automatically: a group appears iff at least one
 * of its child links is visible. Active state is precomputed from the context
 * path / nav key. Twig renders the resulting tree without any logic.
 */
final class NavigationBuilder
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function build(NavigationContext $ctx): array
    {
        $tree = [];

        foreach ($this->definition() as $node) {
            if ($node['kind'] === 'link') {
                if (!($node['visible'])($ctx)) {
                    continue;
                }
                $tree[] = [
                    'type' => 'link',
                    'label' => $node['label'],
                    'url' => $node['url'],
                    'icon' => $node['icon'],
                    'active' => $this->matchesActive($node, $ctx),
                ];
                continue;
            }

            $items = [];
            $previousSection = null;
            $groupActive = false;

            foreach ($node['children'] as $child) {
                if (!($child['visible'])($ctx)) {
                    continue;
                }

                $active = $this->matchesActive($child, $ctx);
                $groupActive = $groupActive || $active;

                $section = $child['section'] ?? null;
                $dividerBefore = $items !== [] && $section !== null && $section !== $previousSection;
                $previousSection = $section;

                $items[] = [
                    'label' => $child['label'],
                    'url' => $child['url'],
                    'icon' => $child['icon'],
                    'active' => $active,
                    'divider_before' => $dividerBefore,
                ];
            }

            if ($items === []) {
                continue;
            }

            $tree[] = [
                'type' => 'group',
                'label' => $node['label'],
                'icon' => $node['icon'],
                'active' => $groupActive,
                'items' => $items,
            ];
        }

        return $tree;
    }

    private function matchesActive(array $node, NavigationContext $ctx): bool
    {
        foreach (($node['excl'] ?? []) as $exclude) {
            if ($exclude !== '' && str_starts_with($ctx->path, $exclude)) {
                return false;
            }
        }

        $navKeys = $node['navKeys'] ?? [];
        if ($ctx->navKey !== '' && in_array($ctx->navKey, $navKeys, true)) {
            return true;
        }

        foreach (($node['prefixes'] ?? []) as $prefix) {
            if ($prefix === '/') {
                if ($ctx->path === '/') {
                    return true;
                }
                continue;
            }
            if ($prefix !== '' && str_starts_with($ctx->path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The single declarative menu definition. Each item carries its label,
     * icon, url, a visibility predicate, and active-matching metadata.
     *
     * @return array<int,array<string,mixed>>
     */
    private function definition(): array
    {
        $always = static fn(NavigationContext $c): bool => true;

        return [
            [
                'kind' => 'link',
                'label' => 'Dashboard',
                'url' => '/dashboard',
                'icon' => 'bi-house',
                'prefixes' => ['/', '/dashboard'],
                'navKeys' => ['dashboard'],
                'visible' => $always,
            ],
            [
                'kind' => 'group',
                'label' => 'Termine',
                'icon' => 'bi-calendar-event',
                'children' => [
                    [
                        'label' => 'Termine',
                        'url' => '/events',
                        'icon' => 'bi-calendar-event',
                        'prefixes' => ['/events'],
                        'navKeys' => ['events'],
                        'visible' => $always,
                    ],
                    [
                        'label' => 'Anwesenheit',
                        'url' => '/attendance',
                        'icon' => 'bi-person-check',
                        'prefixes' => ['/attendance'],
                        'navKeys' => ['attendance'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_attendance') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Anmeldungen',
                        'url' => '/registrations',
                        'icon' => 'bi-calendar-check',
                        'prefixes' => ['/registrations'],
                        'navKeys' => ['registrations'],
                        'visible' => static fn(NavigationContext $c): bool => $c->module('registration'),
                    ],
                ],
            ],
            [
                'kind' => 'group',
                'label' => 'Bereiche',
                'icon' => 'bi-grid-3x3-gap-fill',
                'children' => [
                    [
                        'label' => 'Mitgliederverwaltung',
                        'url' => '/users',
                        'icon' => 'bi-people-fill',
                        'prefixes' => ['/users'],
                        'navKeys' => ['users'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_users') || $c->can('can_manage_own_voice_group'),
                    ],
                    [
                        'label' => 'Meine Projekte',
                        'url' => '/projects/members',
                        'icon' => 'bi-person-check',
                        'prefixes' => ['/projects/members'],
                        'navKeys' => ['project_members'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_project_members') && !$c->can('can_manage_master_data'),
                    ],
                    [
                        'label' => 'Kassa',
                        'url' => '/finances',
                        'icon' => 'bi-bank',
                        'prefixes' => ['/finances'],
                        'navKeys' => ['finances'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->module('finance')
                            && ($c->can('can_read_finances') || $c->can('can_manage_finances')
                                || $c->can('can_manage_users')),
                    ],
                    [
                        'label' => 'Budget',
                        'url' => '/budget',
                        'icon' => 'bi-calculator',
                        'prefixes' => ['/budget'],
                        'navKeys' => ['budget'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->module('budget')
                            && ($c->can('can_read_finances') || $c->can('can_manage_finances')
                                || $c->can('can_manage_users') || $c->can('can_manage_budget')),
                    ],
                    [
                        'label' => 'Sponsoring',
                        'url' => '/sponsoring',
                        'icon' => 'bi-briefcase',
                        'prefixes' => ['/sponsoring'],
                        'navKeys' => ['sponsoring'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->module('sponsoring') && $c->can('can_manage_sponsoring'),
                    ],
                    [
                        'label' => 'Repertoire',
                        'url' => '/song-library',
                        'icon' => 'bi-music-note-list',
                        'prefixes' => ['/song-library'],
                        'navKeys' => ['song_library'],
                        'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_song_library'),
                    ],
                    [
                        'label' => 'Downloads',
                        'url' => '/downloads',
                        'icon' => 'bi-download',
                        'prefixes' => ['/downloads'],
                        'navKeys' => ['downloads'],
                        'visible' => $always,
                    ],
                    [
                        'label' => 'Meine Newsletter',
                        'url' => '/newsletters/archive',
                        'icon' => 'bi-envelope',
                        'prefixes' => ['/newsletters/archive'],
                        'navKeys' => ['newsletters_archive'],
                        'visible' => static fn(NavigationContext $c): bool => $c->module('newsletter'),
                    ],
                    [
                        'label' => 'Newsletter',
                        'url' => '/newsletters',
                        'icon' => 'bi-envelope-open',
                        'prefixes' => ['/newsletters'],
                        'navKeys' => ['newsletters'],
                        'excl' => ['/newsletters/archive'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->module('newsletter') && $c->can('can_manage_newsletters'),
                    ],
                ],
            ],
            [
                'kind' => 'group',
                'label' => 'Auswertungen',
                'icon' => 'bi-bar-chart-fill',
                'children' => [
                    [
                        'label' => 'Anwesenheitsquoten',
                        'url' => '/evaluations',
                        'icon' => 'bi-bar-chart-line-fill',
                        'prefixes' => ['/evaluations'],
                        'navKeys' => ['evaluations'],
                        'excl' => ['/evaluations/project-members', '/evaluations/registrations'],
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_users') || $c->can('can_manage_own_voice_group'),
                    ],
                    [
                        'label' => 'Projektmitglieder',
                        'url' => '/evaluations/project-members',
                        'icon' => 'bi-people-fill',
                        'prefixes' => ['/evaluations/project-members'],
                        'navKeys' => ['evaluations_project_members'],
                        'visible' => $always,
                    ],
                    [
                        'label' => 'Anmeldungen',
                        'url' => '/evaluations/registrations',
                        'icon' => 'bi-calendar-check',
                        'prefixes' => ['/evaluations/registrations'],
                        'navKeys' => ['evaluations_registrations'],
                        'visible' => static fn(NavigationContext $c): bool => $c->module('registration'),
                    ],
                ],
            ],
            [
                'kind' => 'group',
                'label' => 'Verwaltung',
                'icon' => 'bi-gear-fill',
                'children' => [
                    [
                        'label' => 'Projekte',
                        'url' => '/projects',
                        'icon' => 'bi-folder-fill',
                        'prefixes' => ['/projects'],
                        'navKeys' => ['projects'],
                        'excl' => ['/projects/members'],
                        'section' => 'core',
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_master_data') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Rollen',
                        'url' => '/roles',
                        'icon' => 'bi-shield-lock-fill',
                        'prefixes' => ['/roles'],
                        'navKeys' => ['roles'],
                        'section' => 'core',
                        'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Stimmgruppen',
                        'url' => '/voice-groups',
                        'icon' => 'bi-music-note-beamed',
                        'prefixes' => ['/voice-groups'],
                        'navKeys' => ['voice_groups'],
                        'section' => 'core',
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_master_data') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Termin-Typen',
                        'url' => '/event-types',
                        'icon' => 'bi-tag',
                        'prefixes' => ['/event-types'],
                        'navKeys' => ['event_types'],
                        'section' => 'core',
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_master_data') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'App-Einstellungen',
                        'url' => '/settings',
                        'icon' => 'bi-sliders',
                        'prefixes' => ['/settings'],
                        'navKeys' => ['settings'],
                        'section' => 'settings',
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_master_data') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Mailversand',
                        'url' => '/admin/mail-queue',
                        'icon' => 'bi-envelope',
                        'prefixes' => ['/admin/mail-queue', '/mail-queue'],
                        'navKeys' => ['mail_queue'],
                        'section' => 'mailqueue',
                        'visible' => static fn(NavigationContext $c): bool =>
                            $c->can('can_manage_mail_queue') || $c->can('can_manage_users'),
                    ],
                    [
                        'label' => 'Backup-Verwaltung',
                        'url' => '/backups',
                        'icon' => 'bi-database-down',
                        'prefixes' => ['/backups'],
                        'navKeys' => ['backups'],
                        'section' => 'backups',
                        'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_backups'),
                    ],
                ],
            ],
        ];
    }
}
