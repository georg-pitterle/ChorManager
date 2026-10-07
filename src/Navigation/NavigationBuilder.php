<?php

declare(strict_types=1);

namespace App\Navigation;

/**
 * Builds the sidebar navigation as a flat list of visible nodes: the start link,
 * the task sections and the help link.
 * Section visibility is derived automatically: a section appears iff at least one
 * of its entries is visible. Active state is precomputed from the context path /
 * nav key. Twig renders the resulting list without any logic.
 */
final class NavigationBuilder
{
    /**
     * Abschnitte, die sich in der Leiste zuklappen lassen. Nur die selten gebrauchten
     * Verwaltungsseiten - alles, was im Chor-Alltag gebraucht wird, bleibt immer sichtbar.
     * Ein neuer Eintrag hier braucht auch eine Zeile in public/js/navigation-state.js,
     * sonst springt sein gemerkter Zustand beim Laden.
     */
    private const FOLDABLE_SECTIONS = ['administration'];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function build(NavigationContext $ctx): array
    {
        $definition = $this->definition();
        $navKeyKnown = $this->isKnownNavKey($definition, $ctx->navKey);
        $nodes = [];

        foreach ($definition['top'] as $entry) {
            if (($entry['visible'])($ctx)) {
                $nodes[] = $this->link($entry, $ctx, $navKeyKnown, 'top');
            }
        }

        foreach ($definition['sections'] as $section) {
            $items = [];
            foreach ($section['entries'] as $entry) {
                if (($entry['visible'])($ctx)) {
                    $items[] = $this->item($entry, $ctx, $navKeyKnown);
                }
            }

            if ($items === []) {
                continue;
            }

            $nodes[] = [
                'type' => 'section',
                'key' => $section['key'],
                'label' => $section['title'],
                'foldable' => in_array($section['key'], self::FOLDABLE_SECTIONS, true),
                'active' => in_array(true, array_column($items, 'active'), true),
                'items' => $items,
            ];
        }

        foreach ($definition['bottom'] as $entry) {
            if (($entry['visible'])($ctx)) {
                $nodes[] = $this->link($entry, $ctx, $navKeyKnown, 'bottom');
            }
        }

        return $nodes;
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function item(array $entry, NavigationContext $ctx, bool $navKeyKnown): array
    {
        return [
            'label' => $entry['label'],
            'url' => $entry['url'],
            'icon' => $entry['icon'],
            'keywords' => $entry['keywords'],
            'active' => $this->matchesActive($entry, $ctx, $navKeyKnown),
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function link(array $entry, NavigationContext $ctx, bool $navKeyKnown, string $position): array
    {
        return ['type' => 'link'] + $this->item($entry, $ctx, $navKeyKnown) + ['position' => $position];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $definition Ergebnis von definition()
     */
    private function isKnownNavKey(array $definition, string $navKey): bool
    {
        if ($navKey === '') {
            return false;
        }

        $entries = array_merge($definition['top'], $definition['bottom']);
        foreach ($definition['sections'] as $section) {
            $entries = array_merge($entries, $section['entries']);
        }

        foreach ($entries as $entry) {
            if (in_array($navKey, $entry['navKeys'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function matchesActive(array $node, NavigationContext $ctx, bool $navKeyKnown): bool
    {
        foreach (($node['excl'] ?? []) as $exclude) {
            if ($exclude !== '' && str_starts_with($ctx->path, $exclude)) {
                return false;
            }
        }

        // Ein bekannter navKey entscheidet allein. Sonst leuchteten in der Leiste zwei
        // Einträge zugleich - etwa "Start" über den Pfad und "Probenmaterial" über den
        // navKey, wenn eine Download-Route unter /dashboard liegt.
        if ($navKeyKnown) {
            return in_array($ctx->navKey, $node['navKeys'] ?? [], true);
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
     * Die einzige deklarative Menüdefinition. Jeder Eintrag trägt Label, Icon, URL,
     * Stichwörter für die Schnellsuche, ein Sichtbarkeitsprädikat und die Angaben für
     * die Aktiv-Markierung. Abschnitte heißen bewusst 'title' statt 'label': mehrere
     * Tests schneiden einzelne Einträge an "'label' =>" aus diesem Quelltext aus.
     *
     * @return array{top: list<array<string,mixed>>, sections: list<array<string,mixed>>, bottom: list<array<string,mixed>>}
     */
    private function definition(): array
    {
        $always = static fn(NavigationContext $c): bool => true;

        return [
            'top' => [
                [
                    'label' => 'Start',
                    'url' => '/dashboard',
                    'icon' => 'bi-house',
                    'keywords' => ['dashboard', 'übersicht', 'startseite'],
                    'prefixes' => ['/', '/dashboard'],
                    'navKeys' => ['dashboard'],
                    'visible' => $always,
                ],
            ],
            'sections' => [
                [
                    'key' => 'events',
                    'title' => 'Termine',
                    'entries' => [
                        [
                            'label' => 'Termine',
                            'url' => '/events',
                            'icon' => 'bi-calendar-event',
                            'keywords' => ['proben', 'konzerte', 'auftritte', 'kalender'],
                            'prefixes' => ['/events'],
                            'navKeys' => ['events'],
                            'visible' => $always,
                        ],
                        [
                            'label' => 'Anmeldungen',
                            'url' => '/registrations',
                            'icon' => 'bi-calendar-check',
                            'keywords' => ['zusage', 'absage', 'teilnahme'],
                            'prefixes' => ['/registrations'],
                            'navKeys' => ['registrations'],
                            'visible' => static fn(NavigationContext $c): bool => $c->module('registration'),
                        ],
                        [
                            'label' => 'Anwesenheit erfassen',
                            'url' => '/attendance',
                            'icon' => 'bi-person-check',
                            'keywords' => ['anwesenheit', 'präsenz', 'abwesend', 'fehlen'],
                            'prefixes' => ['/attendance'],
                            'navKeys' => ['attendance'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_own_voice_group') || $c->can('can_manage_attendance_all'),
                        ],
                        [
                            'label' => 'Anwesenheitsquoten',
                            'url' => '/evaluations',
                            'icon' => 'bi-bar-chart-line-fill',
                            'keywords' => ['statistik', 'auswertung', 'quote'],
                            'prefixes' => ['/evaluations'],
                            'navKeys' => ['evaluations'],
                            'excl' => ['/evaluations/project-members', '/evaluations/registrations'],
                            // Auswertungen sind bewusst für alle angemeldeten Mitglieder offen -
                            // das Menü bildet genau das ab, was die Route zulässt.
                            'visible' => $always,
                        ],
                        [
                            'label' => 'Anmelde-Auswertung',
                            'url' => '/evaluations/registrations',
                            'icon' => 'bi-clipboard-data',
                            'keywords' => ['statistik', 'auswertung', 'anmeldungen'],
                            'prefixes' => ['/evaluations/registrations'],
                            'navKeys' => ['evaluations_registrations'],
                            'visible' => static fn(NavigationContext $c): bool => $c->module('registration'),
                        ],
                    ],
                ],
                [
                    'key' => 'material',
                    'title' => 'Noten & Dateien',
                    'entries' => [
                        [
                            'label' => 'Probenmaterial',
                            'url' => '/downloads',
                            'icon' => 'bi-download',
                            'keywords' => ['noten', 'downloads', 'übedateien', 'mp3', 'pdf'],
                            'prefixes' => ['/downloads'],
                            'navKeys' => ['downloads'],
                            'visible' => $always,
                        ],
                        [
                            'label' => 'Dateien',
                            'url' => '/files',
                            'icon' => 'bi-folder2-open',
                            'keywords' => ['teamordner', 'dokumente', 'freigabe', 'papierkorb'],
                            'prefixes' => ['/files'],
                            'navKeys' => ['files'],
                            'visible' => static fn(NavigationContext $c): bool => $c->module('files'),
                        ],
                        [
                            'label' => 'Repertoire',
                            'url' => '/song-library',
                            'icon' => 'bi-music-note-list',
                            'keywords' => ['lieder', 'stücke', 'songs', 'notenarchiv'],
                            'prefixes' => ['/song-library'],
                            'navKeys' => ['song_library'],
                            'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_song_library'),
                        ],
                    ],
                ],
                [
                    'key' => 'people',
                    'title' => 'Mitglieder & Projekte',
                    'entries' => [
                        [
                            'label' => 'Mitglieder',
                            'url' => '/users',
                            'icon' => 'bi-people-fill',
                            'keywords' => ['mitgliederverwaltung', 'sänger', 'personen', 'adressen', 'kontakte'],
                            'prefixes' => ['/users'],
                            'navKeys' => ['users'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_users') || $c->can('can_manage_own_voice_group'),
                        ],
                        [
                            'label' => 'Projekte',
                            'url' => '/projects',
                            'icon' => 'bi-folder-fill',
                            'keywords' => ['konzertprojekt', 'planung', 'projekt anlegen'],
                            'prefixes' => ['/projects'],
                            'navKeys' => ['projects'],
                            'excl' => ['/projects/members'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_master_data'),
                        ],
                        [
                            'label' => 'Projektbesetzung',
                            'url' => '/projects/members',
                            'icon' => 'bi-person-plus',
                            'keywords' => ['meine projekte', 'stimmgruppe zuteilen', 'projekt'],
                            'prefixes' => ['/projects/members'],
                            'navKeys' => ['project_members'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                ($c->can('can_manage_project_members')
                                    || $c->can('can_assign_own_voice_group_to_project'))
                                && !$c->can('can_manage_master_data'),
                        ],
                        [
                            'label' => 'Projektübersicht',
                            'url' => '/evaluations/project-members',
                            'icon' => 'bi-person-lines-fill',
                            'keywords' => ['projektmitglieder', 'wer singt mit', 'besetzung'],
                            'prefixes' => ['/evaluations/project-members'],
                            'navKeys' => ['evaluations_project_members'],
                            'visible' => $always,
                        ],
                    ],
                ],
                [
                    'key' => 'finance',
                    'title' => 'Finanzen',
                    'entries' => [
                        [
                            'label' => 'Kassa',
                            'url' => '/finances',
                            'icon' => 'bi-bank',
                            'keywords' => ['kassabuch', 'geld', 'buchung', 'einnahmen', 'ausgaben', 'konten'],
                            'prefixes' => ['/finances'],
                            'navKeys' => ['finances'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->module('finance')
                                && ($c->can('can_read_finances') || $c->can('can_manage_finances')),
                        ],
                        [
                            'label' => 'Budget',
                            'url' => '/budget',
                            'icon' => 'bi-calculator',
                            'keywords' => ['planung', 'kosten', 'voranschlag'],
                            'prefixes' => ['/budget'],
                            'navKeys' => ['budget'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->module('budget')
                                && ($c->can('can_read_finances') || $c->can('can_manage_finances')
                                    || $c->can('can_manage_budget')),
                        ],
                        [
                            'label' => 'Sponsoring',
                            'url' => '/sponsoring',
                            'icon' => 'bi-briefcase',
                            'keywords' => ['sponsoren', 'pakete', 'förderer', 'spenden'],
                            'prefixes' => ['/sponsoring'],
                            'navKeys' => ['sponsoring'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->module('sponsoring')
                                && ($c->can('can_manage_sponsoring') || $c->can('can_create_own_sponsorships')),
                        ],
                    ],
                ],
                [
                    'key' => 'communication',
                    'title' => 'Kommunikation',
                    'entries' => [
                        [
                            'label' => 'Newsletter versenden',
                            'url' => '/newsletters',
                            'icon' => 'bi-send',
                            'keywords' => ['newsletter', 'rundschreiben', 'mail schreiben', 'vorlagen'],
                            'prefixes' => ['/newsletters'],
                            'navKeys' => ['newsletters'],
                            'excl' => ['/newsletters/archive'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->module('newsletter') && $c->can('can_manage_newsletters'),
                        ],
                        [
                            'label' => 'Newsletter-Archiv',
                            'url' => '/newsletters/archive',
                            'icon' => 'bi-envelope',
                            'keywords' => ['meine newsletter', 'rundschreiben', 'infos'],
                            'prefixes' => ['/newsletters/archive'],
                            'navKeys' => ['newsletters_archive'],
                            'visible' => static fn(NavigationContext $c): bool => $c->module('newsletter'),
                        ],
                    ],
                ],
                [
                    'key' => 'administration',
                    'title' => 'Administration',
                    'entries' => [
                        [
                            'label' => 'Rollen & Rechte',
                            'url' => '/roles',
                            'icon' => 'bi-shield-lock-fill',
                            'keywords' => ['rollen', 'berechtigung', 'zugriff'],
                            'prefixes' => ['/roles'],
                            'navKeys' => ['roles'],
                            'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_roles'),
                        ],
                        [
                            'label' => 'Stimmgruppen',
                            'url' => '/voice-groups',
                            'icon' => 'bi-music-note-beamed',
                            'keywords' => ['sopran', 'alt', 'tenor', 'bass', 'stimmen'],
                            'prefixes' => ['/voice-groups'],
                            'navKeys' => ['voice_groups'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_master_data'),
                        ],
                        [
                            'label' => 'Termin-Typen',
                            'url' => '/event-types',
                            'icon' => 'bi-tag',
                            'keywords' => ['probe', 'auftritt', 'kategorie', 'farbe'],
                            'prefixes' => ['/event-types'],
                            'navKeys' => ['event_types'],
                            'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_events'),
                        ],
                        [
                            'label' => 'App-Einstellungen',
                            'url' => '/settings',
                            'icon' => 'bi-sliders',
                            'keywords' => ['module', 'konfiguration', 'logo', 'farbe'],
                            'prefixes' => ['/settings'],
                            'navKeys' => ['settings'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_master_data'),
                        ],
                        [
                            'label' => 'Mailversand',
                            'url' => '/admin/mail-queue',
                            'icon' => 'bi-mailbox',
                            'keywords' => ['warteschlange', 'mail-queue', 'zustellung'],
                            'prefixes' => ['/admin/mail-queue', '/mail-queue'],
                            'navKeys' => ['mail_queue'],
                            'visible' => static fn(NavigationContext $c): bool =>
                                $c->can('can_manage_mail_queue'),
                        ],
                        [
                            'label' => 'Backups',
                            'url' => '/backups',
                            'icon' => 'bi-database-down',
                            'keywords' => ['sicherung', 'wiederherstellen', 'export'],
                            'prefixes' => ['/backups'],
                            'navKeys' => ['backups'],
                            'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_backups'),
                        ],
                        [
                            'label' => 'Speicherplatz',
                            'url' => '/storage',
                            'icon' => 'bi-hdd',
                            'keywords' => ['speicher', 'platz', 'belegung', 'aufräumen'],
                            'prefixes' => ['/storage'],
                            'navKeys' => ['storage'],
                            'visible' => static fn(NavigationContext $c): bool => $c->can('can_manage_storage'),
                        ],
                    ],
                ],
            ],
            'bottom' => [
                [
                    'label' => 'Hilfe',
                    'url' => '/help',
                    'icon' => 'bi-question-circle',
                    'keywords' => ['anleitung', 'dokumentation', 'support'],
                    'prefixes' => ['/help'],
                    'navKeys' => ['help'],
                    'visible' => $always,
                ],
            ],
        ];
    }
}
