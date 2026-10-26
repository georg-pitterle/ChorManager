<?php

declare(strict_types=1);

namespace App\Help;

use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;

/**
 * Welche Hilfethemen eine Person sieht und wie sie geordnet sind.
 *
 * Ein Thema hängt an den Menüpunkten, die es beschreibt: Es ist sichtbar, wenn
 * mindestens einer davon in der Seitenleiste der Person steht, und es steht in der
 * Gruppe dieses Menüpunkts. Damit gelten für die Hilfe dieselben Rechte und
 * Feature-Schalter wie für das Menü, ohne eine zweite Rechte-Logik zu pflegen, und
 * die Hilfe ist so geordnet, wie die Person die App kennt.
 *
 * Themen ohne eigenen Menüpunkt (Navigation, Benachrichtigungen, Nextcloud-Anmeldung)
 * stehen unter "Erste Schritte". Zusätzliche Module und Rechte schränken ein Thema
 * weiter ein, wo die Seite zwar sichtbar ist, der beschriebene Teil aber nicht.
 */
final class HelpCatalog
{
    public const START_SECTION = 'start';

    private const START_TITLE = 'Erste Schritte';

    /**
     * Ein Eintrag je Datei help/<thema>/docs/<slug>.md, in der Reihenfolge, in der die
     * Themen innerhalb ihrer Gruppe stehen. Schlüssel:
     *  - menu:    URLs von Menüpunkten; sichtbar, wenn einer davon sichtbar ist
     *  - section: feste Gruppe für Themen ohne Menüpunkt
     *  - modules: Module, die alle aktiv sein müssen
     *  - rights:  Rechte, von denen eines genügt
     */
    private const TOPICS = [
        'navigation'                  => ['section' => self::START_SECTION],
        'notifications'               => ['section' => self::START_SECTION],
        'sso'                         => ['section' => self::START_SECTION, 'modules' => ['oidc']],
        'events'                      => ['menu' => ['/events']],
        'events-registrations'        => ['menu' => ['/registrations']],
        'events-attendance'           => ['menu' => ['/attendance']],
        'evaluations'                 => ['menu' => ['/evaluations']],
        'evaluations-registrations'   => ['menu' => ['/evaluations/registrations']],
        'repertoire-downloads'        => ['menu' => ['/downloads']],
        'repertoire-webdav'           => ['menu' => ['/downloads']],
        'files'                       => ['menu' => ['/files']],
        'files-sharing'               => ['menu' => ['/files']],
        'files-trash-versions'        => ['menu' => ['/files']],
        'files-office'                => ['menu' => ['/files'], 'modules' => ['office']],
        'repertoire'                  => ['menu' => ['/song-library']],
        'repertoire-songs'            => ['menu' => ['/song-library']],
        'repertoire-sheet-archive'    => [
            'menu' => ['/song-library'],
            'modules' => ['sheet_archive'],
            'rights' => ['can_manage_sheet_archive'],
        ],
        'members'                     => ['menu' => ['/users']],
        'voice-group-lists'           => ['menu' => ['/voice-group-lists']],
        'projects'                    => ['menu' => ['/projects']],
        'projects-members'            => ['menu' => ['/projects/members', '/projects']],
        'projects-planning'           => [
            'menu' => ['/projects'],
            'modules' => ['tasks'],
            'rights' => ['can_manage_tasks'],
        ],
        'evaluations-project-members' => ['menu' => ['/evaluations/project-members']],
        'finance'                     => ['menu' => ['/finances']],
        'finance-budget'              => ['menu' => ['/budget']],
        'sponsoring'                  => ['menu' => ['/sponsoring']],
        'sponsoring-sponsors'         => ['menu' => ['/sponsoring']],
        'sponsoring-packages'         => ['menu' => ['/sponsoring']],
        'newsletter'                  => ['menu' => ['/newsletters', '/newsletters/archive']],
        'newsletter-compose'          => ['menu' => ['/newsletters']],
        'newsletter-templates'        => ['menu' => ['/newsletters']],
        'roles'                       => ['menu' => ['/roles']],
        'events-event-types'          => ['menu' => ['/event-types']],
    ];

    /**
     * Fragen, mit denen Mitglieder am häufigsten in die Hilfe kommen. Gezeigt werden
     * die ersten vier, deren Thema die Person sieht.
     */
    private const FREQUENT_QUESTIONS = [
        ['question' => 'Wie sage ich für einen Termin zu oder ab?', 'slug' => 'events-registrations'],
        ['question' => 'Wo finde ich Noten und Übe-Dateien?', 'slug' => 'repertoire-downloads'],
        ['question' => 'Wie bekomme ich die Noten aufs Tablet?', 'slug' => 'repertoire-webdav'],
        ['question' => 'Wo lese ich frühere Newsletter nach?', 'slug' => 'newsletter'],
        ['question' => 'Wie stelle ich E-Mails und Benachrichtigungen ein?', 'slug' => 'notifications'],
        ['question' => 'Wie finde ich mich in der App zurecht?', 'slug' => 'navigation'],
    ];

    private const FREQUENT_QUESTION_LIMIT = 4;

    private string $helpDir;

    /** @var array<string,array<string,mixed>> */
    private array $topics;

    /** @var array<string,string> */
    private array $titles = [];

    /**
     * @param array<string,array<string,mixed>>|null $topics Ersatzkatalog für Tests
     */
    public function __construct(?string $helpDir = null, ?array $topics = null)
    {
        $this->helpDir = $helpDir ?? dirname(__DIR__, 2) . '/help';
        $this->topics = $topics ?? self::TOPICS;
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_keys($this->topics);
    }

    /**
     * Sichtbare Themen nach Gruppen: zuerst "Erste Schritte", dann die Abschnitte der
     * Seitenleiste in ihrer Reihenfolge. Leere Gruppen fallen weg.
     *
     * @return list<array{key:string,title:string,topics:list<array{slug:string,title:string,keywords:list<string>}>}>
     */
    public function groups(NavigationContext $context): array
    {
        $sections = $this->visibleSections($context);
        $groups = [self::START_SECTION => ['key' => self::START_SECTION, 'title' => self::START_TITLE, 'topics' => []]];
        foreach ($sections as $key => $section) {
            $groups[$key] = ['key' => $key, 'title' => $section['title'], 'topics' => []];
        }

        foreach ($this->topics as $slug => $topic) {
            $placement = $this->placement($topic, $context, $sections);
            if ($placement === null) {
                continue;
            }

            $groups[$placement['section']]['topics'][] = [
                'slug' => $slug,
                'title' => $this->title($slug),
                'keywords' => $placement['keywords'],
            ];
        }

        return array_values(array_filter($groups, static fn(array $group): bool => $group['topics'] !== []));
    }

    /**
     * Dieselben Themen als flache Liste, mit dem Gruppentitel an jedem Eintrag.
     *
     * @return list<array{slug:string,title:string,keywords:list<string>,group:string}>
     */
    public function topics(NavigationContext $context): array
    {
        $topics = [];
        foreach ($this->groups($context) as $group) {
            foreach ($group['topics'] as $topic) {
                $topics[] = $topic + ['group' => $group['title']];
            }
        }

        return $topics;
    }

    /**
     * @return list<array{question:string,slug:string}>
     */
    public function frequentQuestions(NavigationContext $context): array
    {
        $visible = array_column($this->topics($context), 'slug');

        $questions = array_values(array_filter(
            self::FREQUENT_QUESTIONS,
            static fn(array $entry): bool => in_array($entry['slug'], $visible, true)
        ));

        return array_slice($questions, 0, self::FREQUENT_QUESTION_LIMIT);
    }

    /**
     * Titel aus der ersten Überschrift der Datei, sonst aus dem Slug abgeleitet.
     */
    public function title(string $slug): string
    {
        if (!isset($this->titles[$slug])) {
            $path = (glob($this->helpDir . '/*/docs/' . $slug . '.md') ?: [])[0] ?? null;
            $this->titles[$slug] = $path !== null
                ? self::titleFromFile($path, $slug)
                : ucwords(str_replace('-', ' ', $slug));
        }

        return $this->titles[$slug];
    }

    public static function titleFromFile(string $path, string $fallbackSlug): string
    {
        $content = (string) file_get_contents($path);

        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return ucwords(str_replace('-', ' ', $fallbackSlug));
    }

    /**
     * Sichtbare Abschnitte der Seitenleiste mit Titel und ihren Menüpunkten (URL => Stichwörter).
     *
     * @return array<string,array{title:string,entries:array<string,list<string>>}>
     */
    private function visibleSections(NavigationContext $context): array
    {
        $sections = [];
        foreach ((new NavigationBuilder())->build($context) as $node) {
            if ($node['type'] !== 'section') {
                continue;
            }

            $entries = [];
            foreach ($node['items'] as $item) {
                $entries[$item['url']] = $item['keywords'];
            }
            $sections[$node['key']] = ['title' => $node['label'], 'entries' => $entries];
        }

        return $sections;
    }

    /**
     * Gruppe und Stichwörter eines Themas, oder null, wenn die Person es nicht sieht.
     *
     * @param array<string,mixed> $topic
     * @param array<string,array{title:string,entries:array<string,list<string>>}> $sections
     * @return array{section:string,keywords:list<string>}|null
     */
    private function placement(array $topic, NavigationContext $context, array $sections): ?array
    {
        foreach ($topic['modules'] ?? [] as $module) {
            if (!$context->module($module)) {
                return null;
            }
        }

        $rights = $topic['rights'] ?? [];
        if ($rights !== [] && !array_filter($rights, static fn(string $right): bool => $context->can($right))) {
            return null;
        }

        $menu = $topic['menu'] ?? [];
        if ($menu === []) {
            return ['section' => (string) ($topic['section'] ?? self::START_SECTION), 'keywords' => []];
        }

        foreach ($menu as $url) {
            foreach ($sections as $key => $section) {
                if (isset($section['entries'][$url])) {
                    return ['section' => $key, 'keywords' => $section['entries'][$url]];
                }
            }
        }

        return null;
    }
}
