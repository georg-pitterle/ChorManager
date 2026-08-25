<?php

declare(strict_types=1);

namespace App\Newsletter;

/**
 * Die einzige Quelle für die Gestaltungsklassen, die im Newsletter-Inhalt erlaubt sind.
 *
 * Drei Stellen greifen darauf zu und dürfen nicht auseinanderlaufen: der Sanitizer lässt
 * genau diese Klassen durch, der Mailrenderer übersetzt sie in Inline-Styles, und der Editor
 * bietet sie im Formate-Menü an. Ein freies style-Attribut bleibt bewusst gesperrt — sonst
 * könnte jede Mail anders aussehen, und der Sanitizer verlöre eine Schranke.
 */
final class ContentClasses
{
    /**
     * Klassenname => Beschriftung im Editor.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'newsletter-lead' => 'Einleitung (hervorgehoben)',
        'newsletter-muted' => 'Nebentext (gedämpft)',
        'newsletter-accent' => 'Zwischenüberschrift in Markenfarbe',
        'newsletter-center' => 'Zentriert',
        'newsletter-callout' => 'Hinweiskasten',
    ];

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }

    /**
     * CSS-Deklarationen je Klasse, wie sie in der Mail als Inline-Style landen.
     *
     * E-Mail-Programme werten einen style-Block nur unzuverlässig aus, Outlook verwirft ihn
     * teilweise ganz. Deshalb wird beim Bauen der Mail jede Klasse in genau diese Deklarationen
     * übersetzt. Die Markenfarbe ist nicht fest eingetragen, sondern wird eingesetzt.
     *
     * @param string $brandColor Markenfarbe der Installation als Hex-Wert.
     * @return array<string, string>
     */
    public static function inlineStyles(string $brandColor): array
    {
        return [
            'newsletter-lead' => 'font-size:18px; line-height:1.6; font-weight:600; color:#1f2937',
            'newsletter-muted' => 'font-size:14px; line-height:1.6; color:#667085',
            'newsletter-accent' => 'color:' . $brandColor,
            'newsletter-center' => 'text-align:center',
            'newsletter-callout' => 'padding:14px 18px; background-color:#f5f7fa; '
                . 'border-left:4px solid ' . $brandColor . '; border-radius:6px',
        ];
    }
}
