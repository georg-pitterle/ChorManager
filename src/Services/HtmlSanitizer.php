<?php

declare(strict_types=1);

namespace App\Services;

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
            'p',
            'br',
            'hr',
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
            'h1',
            'h2',
            'h3',
            'h4',
            'table',
            'thead',
            'tbody',
            'tr',
            'th',
            'td',
            'img[src|alt|width|height]',
            'span',
        ]));

        // Strict security: only allow data: and blob: URIs for images, disable external resources
        $config->set('URI.DisableExternalResources', true);
        $config->set('URI.DisableResources', false);
        $config->set('URI.AllowedSchemes', [
            'data' => true,
            'blob' => true,
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
        $config->set('Core.EscapeInvalidTags', true);
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
