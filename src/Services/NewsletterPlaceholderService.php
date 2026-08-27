<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Newsletter;
use App\Models\User;
use App\Newsletter\PlaceholderDefinition;
use App\Newsletter\RenderContext;

/**
 * Registry und Auflösung der Newsletter-Platzhalter. Einzige Quelle für Rendering,
 * Auswahlliste im Editor, Validierung unbekannter Token und Hilfetext.
 */
class NewsletterPlaceholderService
{
    private const PATTERN = '/\{\{\s*([a-z_]+)\s*\}\}/u';

    /** @var array<string, PlaceholderDefinition>|null */
    private ?array $definitions = null;

    public function __construct(private readonly NameFormatterService $nameFormatter)
    {
    }

    /**
     * Baut den empfänger-unabhängigen Rendering-Kontext für einen Newsletter.
     * Fabrikmethode, damit Aufrufer nicht selbst den NameFormatter durchreichen müssen.
     */
    public function contextFor(Newsletter $newsletter, string $baseUrl): RenderContext
    {
        return RenderContext::fromNewsletter($newsletter, $baseUrl, $this->nameFormatter);
    }

    /**
     * Gibt das Regex-Muster für Umbruchzeichen zurück. Entfernt Wagenrücklauf, Zeilenvorschub,
     * vertikalen Tabulator, Seitenvorschub, NEL und Unicode-Zeilentrenner (U+2028, U+2029),
     * um zu verhindern, dass ein manipulierter Name zusätzliche Mail-Header einschleusen kann.
     * Mehrere aufeinanderfolgende Umbruchzeichen werden zu einem Leerzeichen zusammengefasst.
     */
    private static function getLinebreakPattern(): string
    {
        return '/[\r\n\x0B\x0C\x85' . "\u{2028}\u{2029}" . ']+/u';
    }

    /**
     * @return array<string, PlaceholderDefinition>
     */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $nameFormatter = $this->nameFormatter;

        $definitions = [
            new PlaceholderDefinition(
                key: 'anrede',
                label: 'Anrede',
                description: 'Begrüßung mit Vornamen, ohne Vornamen nur "Hallo".',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'Hallo Georg',
                resolver: static function (RenderContext $context, ?User $recipient): string {
                    $firstName = trim((string) ($recipient->first_name ?? ''));

                    return $firstName === '' ? 'Hallo' : 'Hallo ' . $firstName;
                }
            ),
            new PlaceholderDefinition(
                key: 'vorname',
                label: 'Vorname',
                description: 'Vorname der empfangenden Person.',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'Georg',
                resolver: static fn (RenderContext $context, ?User $recipient): string
                    => trim((string) ($recipient->first_name ?? ''))
            ),
            new PlaceholderDefinition(
                key: 'nachname',
                label: 'Nachname',
                description: 'Nachname der empfangenden Person.',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'Pitterle',
                resolver: static fn (RenderContext $context, ?User $recipient): string
                    => trim((string) ($recipient->last_name ?? ''))
            ),
            new PlaceholderDefinition(
                key: 'name',
                label: 'Vollständiger Name',
                description: 'Name in der global eingestellten Reihenfolge, ersatzweise die E-Mail-Adresse.',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'Georg Pitterle',
                resolver: static function (RenderContext $context, ?User $recipient) use ($nameFormatter): string {
                    if ($recipient === null) {
                        return '';
                    }

                    $name = trim($nameFormatter->formatPerson($recipient));

                    return $name === '' ? trim((string) $recipient->email) : $name;
                }
            ),
            new PlaceholderDefinition(
                key: 'email',
                label: 'E-Mail-Adresse',
                description: 'E-Mail-Adresse der empfangenden Person.',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'georg@example.at',
                resolver: static fn (RenderContext $context, ?User $recipient): string
                    => trim((string) ($recipient->email ?? ''))
            ),
            new PlaceholderDefinition(
                key: 'stimmgruppe',
                label: 'Stimmgruppe',
                description: 'Stimmgruppen samt Untergruppe, ohne Zuordnung "ohne Stimmgruppe".',
                scope: PlaceholderDefinition::SCOPE_RECIPIENT,
                example: 'Sopran (Sopran 1)',
                resolver: static function (RenderContext $context, ?User $recipient): string {
                    if ($recipient === null) {
                        return 'ohne Stimmgruppe';
                    }

                    $subVoiceNames = $recipient->subVoices->keyBy('id');
                    $parts = [];

                    foreach ($recipient->voiceGroups as $group) {
                        $subVoiceId = (int) ($group->pivot->sub_voice_id ?? 0);
                        $subVoiceName = $subVoiceId > 0
                            ? trim((string) ($subVoiceNames[$subVoiceId]->name ?? ''))
                            : '';
                        $groupName = trim((string) $group->name);

                        $parts[] = $subVoiceName === ''
                            ? $groupName
                            : $groupName . ' (' . $subVoiceName . ')';
                    }

                    return $parts === [] ? 'ohne Stimmgruppe' : implode(', ', $parts);
                }
            ),
            new PlaceholderDefinition(
                key: 'titel',
                label: 'Newsletter-Titel',
                description: 'Betreff dieses Newsletters.',
                scope: PlaceholderDefinition::SCOPE_NEWSLETTER,
                example: 'Probenplan Mai',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->title
            ),
            new PlaceholderDefinition(
                key: 'projekt',
                label: 'Projekt',
                description: 'Name des verknüpften Projekts, leer bei projektlosen Newslettern.',
                scope: PlaceholderDefinition::SCOPE_NEWSLETTER,
                example: 'Frühjahrskonzert',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->projectName
            ),
            new PlaceholderDefinition(
                key: 'datum',
                label: 'Datum',
                description: 'Versanddatum, in der Vorschau das aktuelle Datum.',
                scope: PlaceholderDefinition::SCOPE_NEWSLETTER,
                example: '18.08.2026',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->date
            ),
            new PlaceholderDefinition(
                key: 'absender',
                label: 'Absender',
                description: 'Person, die den Newsletter angelegt hat.',
                scope: PlaceholderDefinition::SCOPE_NEWSLETTER,
                example: 'Anna Berger',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->senderName
            ),
            new PlaceholderDefinition(
                key: 'app_name',
                label: 'Anwendungsname',
                description: 'Name der Anwendung aus den Einstellungen.',
                scope: PlaceholderDefinition::SCOPE_GLOBAL,
                example: 'Chor-Manager',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->appName
            ),
            new PlaceholderDefinition(
                key: 'login_url',
                label: 'Adresse der Anwendung',
                description: 'Basisadresse für den Login.',
                scope: PlaceholderDefinition::SCOPE_GLOBAL,
                example: 'https://chor.example',
                resolver: static fn (RenderContext $context, ?User $recipient): string => $context->baseUrl
            ),
            new PlaceholderDefinition(
                key: 'archiv_link',
                label: 'Link zur Browser-Ansicht',
                description: 'Verweis auf diesen Newsletter im persönlichen Archiv.',
                scope: PlaceholderDefinition::SCOPE_GLOBAL,
                example: 'Im Browser ansehen',
                resolver: static function (RenderContext $context, ?User $recipient): string {
                    if ($context->newsletterId === null) {
                        return '';
                    }

                    // Dieser Platzhalter liefert fertiges Markup und läuft deshalb am
                    // Escaping in renderHtml() vorbei. Die Basisadresse stammt ohne
                    // gesetztes APP_URL aus dem Host-Kopf der Anfrage; ein
                    // Anführungszeichen darin bräche sonst aus dem href-Attribut aus.
                    $url = htmlspecialchars(
                        $context->baseUrl . '/newsletters/' . $context->newsletterId . '/preview',
                        ENT_QUOTES,
                        'UTF-8'
                    );

                    return '<a href="' . $url . '">Im Browser ansehen</a>';
                },
                isRawHtml: true
            ),
        ];

        $indexed = [];
        foreach ($definitions as $definition) {
            $indexed[$definition->key] = $definition;
        }

        $this->definitions = $indexed;

        return $indexed;
    }

    /**
     * Ersetzt Platzhalter im HTML-Body. Aufgelöste Werte werden escaped, weil die
     * Ersetzung nach dem Sanitizing passiert und ein Name sonst Markup einschleusen könnte.
     */
    public function renderHtml(string $html, RenderContext $context, ?User $recipient): string
    {
        return $this->replace($html, $context, $recipient, static function (string $value, bool $isRawHtml): string {
            return $isRawHtml ? $value : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        });
    }

    /**
     * Ersetzt Platzhalter in der Betreffzeile. Kein HTML-Escaping, weil "&" im Betreff
     * als "&" stehen bleiben soll. Zeilenumbrüche werden entfernt, sonst erlaubt ein
     * manipulierter Name das Einschleusen zusätzlicher Mail-Header.
     */
    public function renderSubject(string $subject, RenderContext $context, ?User $recipient): string
    {
        $pattern = self::getLinebreakPattern();
        $rendered = $this->replace($subject, $context, $recipient, static function (
            string $value,
            bool $isRawHtml
        ) use ($pattern): string {
            $plain = $isRawHtml ? strip_tags($value) : $value;

            return trim((string) preg_replace($pattern, ' ', $plain));
        });

        return trim((string) preg_replace($pattern, ' ', $rendered));
    }

    /**
     * @param callable(string, bool): string $escape
     */
    private function replace(string $text, RenderContext $context, ?User $recipient, callable $escape): string
    {
        $definitions = $this->definitions();

        $replaced = preg_replace_callback(
            self::PATTERN,
            static function (array $matches) use ($definitions, $context, $recipient, $escape): string {
                $definition = $definitions[$matches[1]] ?? null;
                if ($definition === null) {
                    return $matches[0];
                }

                return $escape($definition->resolve($context, $recipient), $definition->isRawHtml);
            },
            $text
        );

        return $replaced ?? $text;
    }

    /**
     * Token, die im Text stehen, aber nicht in der Registry. Jeder Key nur einmal.
     *
     * @return array<int, string>
     */
    public function findUnknownTokens(string $text): array
    {
        if (preg_match_all(self::PATTERN, $text, $matches) === 0) {
            return [];
        }

        $known = $this->definitions();
        $unknown = [];

        foreach ($matches[1] as $key) {
            if (!isset($known[$key]) && !in_array($key, $unknown, true)) {
                $unknown[] = $key;
            }
        }

        return $unknown;
    }
}
