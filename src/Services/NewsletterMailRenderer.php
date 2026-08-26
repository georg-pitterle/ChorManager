<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Newsletter;
use App\Newsletter\ContentClasses;
use App\Util\MailBranding;
use DOMDocument;
use DOMElement;
use Slim\Views\Twig;

/**
 * Baut das vollständige Mail-HTML für einen Newsletter: derselbe Rahmen (Logo, Kopfbereich,
 * Akzentlinie, Fußbereich) wie bei den Systemmails, siehe templates/emails/_layout.twig.
 * Einzige Aufgabe dieser Klasse ist das Rendern - Versand und Testmail rufen sie gleichermaßen
 * auf, damit beide denselben Rahmen erzeugen.
 */
class NewsletterMailRenderer
{
    /**
     * Wird beim ersten Aufruf von renderHtml() gefüllt und danach wiederverwendet.
     *
     * @var array{
     *     app_name: string,
     *     primary_color: string,
     *     primary_strong: string,
     *     primary_tint: string,
     *     primary_edge: string,
     *     logo_src: string
     * }|null
     */
    private ?array $brandingCache = null;

    public function __construct(private readonly Twig $view)
    {
    }

    /**
     * @param Newsletter $newsletter Newsletter-Datensatz, liefert Projekt und ID für Kennzeile
     *                               und Browser-Link.
     * @param string $subject Bereits personalisierter Betreff, wird als Überschrift genutzt.
     * @param string $contentHtml Fertig gerenderter Newsletter-Inhalt: bereits sanitisiert,
     *                            Platzhalter bereits ersetzt. Wird unverändert (nicht erneut
     *                            escaped) in den Inhaltsbereich eingesetzt.
     * @param string $baseUrl Basisadresse für den Link zur Browser-Ansicht.
     * @param bool $includeBrowseLink Blendet den Fußzeilen-Link "Diesen Newsletter im Browser
     *                                ansehen" aus. Gilt für beide Vorschauwege (previewFrame()
     *                                und previewRender()): Wer die Vorschau ansieht, ist bereits
     *                                in der Browser-Ansicht, ein Klick würde die Anwendungsseite
     *                                samt Navigation in den kleinen eingebetteten Rahmen laden.
     *                                Versand und Testmail belassen den Link, dort ist er sinnvoll.
     *                                Der Rahmen selbst bleibt in beiden Fällen derselbe Aufruf
     *                                von emails/newsletter.twig, nur dieser eine Block ändert
     *                                sich - so laufen Vorschau und Mail nicht auseinander.
     */
    public function renderHtml(
        Newsletter $newsletter,
        string $subject,
        string $contentHtml,
        string $baseUrl,
        bool $includeBrowseLink = true
    ): string {
        $branding = $this->resolveBranding();

        $projectName = trim((string) ($newsletter->project->name ?? ''));
        $eyebrowLabel = $projectName === '' ? 'Newsletter' : $projectName;

        $browseUrl = rtrim($baseUrl, '/') . '/newsletters/' . (int) $newsletter->id . '/preview';

        return $this->view->fetch('emails/newsletter.twig', array_merge($branding, [
            'subject' => $subject,
            'content_html' => $this->inlineContentClasses($contentHtml, (string) $branding['primary_strong']),
            'eyebrow_label' => $eyebrowLabel,
            'browse_url' => $browseUrl,
            'include_browse_link' => $includeBrowseLink,
        ]));
    }

    /**
     * Übersetzt die erlaubten Gestaltungsklassen in Inline-Styles.
     *
     * E-Mail-Programme werten Klassen aus einem style-Block nur unzuverlässig aus, Outlook
     * verwirft ihn teilweise ganz. Im Mailinhalt muss die Gestaltung deshalb am Element selbst
     * stehen. Gearbeitet wird auf dem DOM statt mit regulären Ausdrücken: Attributwerte, Text
     * und verschachtelte Elemente bleiben dabei unangetastet.
     *
     * Das class-Attribut bleibt erhalten, damit die Vorschau im Browser dieselben Angriffspunkte
     * behält und ein bereits gesetztes style-Attribut nicht überschrieben wird.
     */
    private function inlineContentClasses(string $contentHtml, string $brandColor): string
    {
        if (trim($contentHtml) === '') {
            return $contentHtml;
        }

        $styles = ContentClasses::inlineStyles($brandColor);

        $document = new DOMDocument();
        $previousErrorState = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="newsletter-content-root">' . $contentHtml . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorState);

        if ($loaded === false) {
            return $contentHtml;
        }

        $root = $document->getElementById('newsletter-content-root');
        if (!$root instanceof DOMElement) {
            return $contentHtml;
        }

        $elements = $document->getElementsByTagName('*');
        foreach ($elements as $element) {
            if (!$element instanceof DOMElement || !$element->hasAttribute('class')) {
                continue;
            }

            $declarations = [];
            foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $className) {
                if (isset($styles[$className])) {
                    $declarations[] = $styles[$className];
                }
            }

            if ($declarations === []) {
                continue;
            }

            $existingStyle = trim($element->getAttribute('style'));
            $combined = $existingStyle === ''
                ? implode('; ', $declarations)
                : rtrim($existingStyle, ';') . '; ' . implode('; ', $declarations);

            $element->setAttribute('style', $combined);
        }

        $rendered = '';
        foreach ($root->childNodes as $child) {
            $rendered .= $document->saveHTML($child);
        }

        return $rendered;
    }

    /**
     * Löst MailBranding::resolve() nur beim ersten Aufruf auf. Der Versand ruft renderHtml() in
     * einer Schleife über alle Empfänger auf; ohne diesen Zwischenspeicher würde jede einzelne
     * Empfänger-Mail dieselben zwei Datenbankabfragen und im Zweifel dieselbe Logo-Kodierung noch
     * einmal auslösen. Der Renderer wird über den Container aufgelöst und lebt nur innerhalb
     * einer Anfrage, daher genügt ein einfaches Instanzfeld als Zwischenspeicher.
     *
     * @return array{
     *     app_name: string,
     *     primary_color: string,
     *     primary_strong: string,
     *     primary_tint: string,
     *     primary_edge: string,
     *     logo_src: string
     * }
     */
    protected function resolveBranding(): array
    {
        return $this->brandingCache ??= $this->fetchBranding();
    }

    /**
     * Löst MailBranding::resolve() tatsächlich auf. Eigener Rumpf statt eines Direktaufrufs in
     * resolveBranding(), damit Tests genau diesen Schritt zählen können, ohne den
     * Zwischenspeicher selbst nachzubauen.
     *
     * @return array{
     *     app_name: string,
     *     primary_color: string,
     *     primary_strong: string,
     *     primary_tint: string,
     *     primary_edge: string,
     *     logo_src: string
     * }
     */
    protected function fetchBranding(): array
    {
        return MailBranding::resolve();
    }
}
