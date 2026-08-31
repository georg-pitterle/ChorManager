<?php

declare(strict_types=1);

namespace App\Services;

use App\Newsletter\ContentClasses;
use HTMLPurifier;
use HTMLPurifier_Config;

class HtmlSanitizer
{
    private HTMLPurifier $taskPurifier;
    private HTMLPurifier $newsletterPurifier;

    public function __construct()
    {
        $this->taskPurifier = new HTMLPurifier($this->buildTaskConfig());
        $this->newsletterPurifier = new HTMLPurifier($this->buildNewsletterConfig());
    }

    public function sanitizeTaskHtml(?string $html): string
    {
        $value = trim((string) $html);
        if ($value === '') {
            return '';
        }

        return trim($this->taskPurifier->purify($value));
    }

    public function sanitizeNewsletterHtml(?string $html): string
    {
        $value = trim((string) $html);
        if ($value === '') {
            return '';
        }

        return trim($this->newsletterPurifier->purify($value));
    }

    private function buildTaskConfig(): HTMLPurifier_Config
    {
        $config = $this->buildBaseConfig();

        $config->set('HTML.Allowed', implode(',', [
            'p',
            'br',
            'strong',
            'b',
            'em',
            'i',
            'u',
            'ul',
            'ol',
            'li',
            'a[href|title|target|rel]',
            'blockquote',
            'h2',
            'h3',
            'h4',
            'table',
            'thead',
            'tbody',
            'tr',
            'th',
            'td',
        ]));

        // Allow http/https for task links (internal navigation)
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
        ]);

        return $config;
    }

    private function buildNewsletterConfig(): HTMLPurifier_Config
    {
        $config = $this->buildBaseConfig();

        $config->set('HTML.Allowed', implode(',', [
            'p[class]',
            'br',
            'hr',
            'strong',
            'b',
            'em',
            'i',
            'u',
            'ul[class]',
            'ol[class]',
            'li[class]',
            'a[href|title|target|rel]',
            'blockquote[class]',
            'h1[class]',
            'h2[class]',
            'h3[class]',
            'h4[class]',
            'table',
            'thead',
            'tbody',
            'tr',
            'th',
            'td',
            'img[src|alt|width|height]',
            'span[class]',
        ]));

        // Gestaltung ist auf eine feste Liste begrenzt: Redakteure wählen im Editor aus,
        // ein freies style-Attribut bleibt gesperrt. Eine nicht aufgeführte Klasse entfernt
        // HTMLPurifier stillschweigend, Element und Text bleiben erhalten.
        $config->set('Attr.AllowedClasses', ContentClasses::names());

        // Externe Ressourcen (Bilder, Tracking-Pixel) bleiben gesperrt. Links
        // dürfen dagegen http/https tragen: Der Editor bietet sie an, und ohne
        // erlaubtes Schema wurde das Ziel beim Speichern kommentarlos entfernt.
        //
        // `data` bleibt für eingebettete Bilder frei - der Upload-Helfer erzeugt
        // sie, und HTMLPurifier lässt darunter ohnehin nur jpeg, gif und png zu.
        // `blob` stand hier ebenfalls, war aber wirkungslos: HTMLPurifier kennt
        // für dieses Schema keine Klasse und verwirft solche Adressen bei der
        // Prüfung. Die Freigabe war damit toter Konfigurationsbestand.
        $config->set('URI.DisableExternalResources', true);
        $config->set('URI.DisableResources', false);
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'data' => true,
            'mailto' => true,
        ]);

        return $config;
    }

    private function buildBaseConfig(): HTMLPurifier_Config
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chormanager_htmlpurifier';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        // Nicht erlaubte Tags werden entfernt, nicht escaped: Escapen ließ die
        // Auszeichnung als sichtbaren Tag-Text stehen (z. B. <span> aus einem
        // Word-Einfügen), weil Aufgaben- und Newsletter-Inhalte roh gerendert
        // werden. Der Inhalt der Tags bleibt erhalten, Script- und Style-Blöcke
        // entfernt HTMLPurifier samt Inhalt.
        $config->set('Core.EscapeInvalidTags', false);
        $config->set('Cache.SerializerPath', $cacheDir);
        $config->set('Attr.EnableID', false);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);
        // Disable external resources and scripts by default
        $config->set('URI.DisableExternalResources', true);
        $config->set('URI.DisableResources', false);

        return $config;
    }
}
