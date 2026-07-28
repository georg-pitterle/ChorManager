<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class NameDisplayCoverageFeatureTest extends TestCase
{
    /**
     * Templates, die Namen bewusst nicht über den Filter ausgeben:
     * E-Mail-Anreden, Initialen-Avatare und Formularfelder.
     *
     * @var array<int, string>
     */
    private const ALLOWED = [
        'templates/emails/invitation.twig',
        'templates/emails/password_reset.twig',
        'templates/emails/registration_reminder.twig',
        'templates/auth/setup.twig',
        'templates/profile/index.twig',
        'templates/users/manage.twig',
    ];

    public function testNoTemplateConcatenatesNamesInline(): void
    {
        $root = realpath(dirname(__DIR__) . '/..');
        $this->assertIsString($root);

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates')
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            $patterns = [
                '/first_name\s*\}\}\s*\{\{\s*[\w.]+\.last_name/',
                '/first_name\|default\([^)]*\)\s*\}\}\s*\{\{\s*[\w.]+\.last_name/',
                '/last_name\s*\}\},\s*\{\{\s*[\w.]+\.first_name/',
                '/first_name[^}]*~\s*["\'][ ,]+["\']\s*~[^}]*last_name/',
                '/last_name[^}]*~\s*["\'][ ,]+["\']\s*~[^}]*first_name/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $content) === 1) {
                    $offenders[] = $relative;
                    break;
                }
            }
        }

        $this->assertSame([], $offenders, 'Inline-Namensverkettung in: ' . implode(', ', $offenders));
    }
}
