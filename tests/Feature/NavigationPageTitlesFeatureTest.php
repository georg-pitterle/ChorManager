<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Menü und Seite müssen denselben Namen tragen: wer in der Leiste "Mitglieder" anklickt,
 * soll nicht auf einer Seite namens "Mitgliederverwaltung" landen. Geprüft werden
 * <title> und Hauptüberschrift jeder Seite, deren Menüname sich geändert hat.
 */
class NavigationPageTitlesFeatureTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function renamedPages(): array
    {
        return [
            'Mitglieder' => ['users/manage.twig', 'Mitglieder', 'Mitglieder'],
            'Probenmaterial' => ['songs/downloads.twig', 'Probenmaterial', 'Probenmaterial'],
            'Projektbesetzung' => ['projects/member_projects.twig', 'Projektbesetzung', 'Projektbesetzung'],
            'Projektübersicht' => ['evaluations/project_members.twig', 'Projektübersicht', 'Projektübersicht'],
            'Newsletter-Archiv' => ['newsletters/archive.twig', 'Newsletter-Archiv', 'Newsletter-Archiv'],
            'Newsletter versenden' => ['newsletters/index.twig', 'Newsletter versenden', 'Newsletter versenden'],
            'Anwesenheit erfassen' => ['attendance/show.twig', 'Anwesenheit erfassen', 'Anwesenheit erfassen'],
            'Rollen & Rechte' => ['roles/index.twig', 'Rollen &amp; Rechte', 'Rollen &amp; Rechte'],
            'Backups' => ['backups/index.twig', 'Backups', 'Backups'],
        ];
    }

    #[DataProvider('renamedPages')]
    public function testPageTitleAndHeadingMatchTheMenuName(string $template, string $title, string $heading): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/' . $template);
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression(
            '/\{% block title %\}\s*' . preg_quote($title, '/') . ' - /',
            $source,
            "Seitentitel in {$template}"
        );
        $this->assertMatchesRegularExpression(
            '/<h1 class="h2 mb-1">' . preg_quote($heading, '/') . '<\/h1>/',
            $source,
            "Überschrift in {$template}"
        );
    }

    public function testStartPageUsesTheNewName(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/dashboard/index.twig');
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression('/\{% block title %\}\s*Start - /', $source);
        $this->assertStringNotContainsString('>Dashboard</p>', $source);
        $this->assertStringContainsString('>Anwesenheit erfassen</a>', $source);
        $this->assertStringNotContainsString('Mitgliederverwaltung', $source);
    }

    /**
     * Die Überzeile über dem Seitentitel nennt den Abschnitt der Seitenleiste, in dem die Seite
     * liegt. Die Gruppen "Bereiche", "Verwaltung" und "Auswertung" gibt es seit dem Umbau auf
     * die Seitenleiste nicht mehr - eine Seite, die sie noch nennt, schickt ins Leere.
     */
    public function testPageEyebrowsNameNoRemovedMenuGroup(): void
    {
        $templates = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates', \FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];
        foreach ($templates as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/class="text-uppercase text-muted small mb-1">(Bereiche|Verwaltung|Auswertung)</', $source, $m)) {
                $offenders[] = basename(dirname($file->getPathname())) . '/' . $file->getFilename() . ': ' . $m[1];
            }
        }

        $this->assertSame([], $offenders, 'Überzeilen mit entfernten Menügruppen.');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function pageSections(): array
    {
        return [
            'Kassa' => ['finances/index.twig', 'Finanzen'],
            'Sponsoring' => ['sponsoring/dashboard.twig', 'Finanzen'],
            'Newsletter versenden' => ['newsletters/index.twig', 'Kommunikation'],
            'Probenmaterial' => ['songs/downloads.twig', 'Noten &amp; Dateien'],
            'Dateien' => ['files/index.twig', 'Noten &amp; Dateien'],
            'Mitglieder' => ['users/manage.twig', 'Mitglieder &amp; Projekte'],
            'Projektübersicht' => ['evaluations/project_members.twig', 'Mitglieder &amp; Projekte'],
            'Anwesenheit erfassen' => ['attendance/show.twig', 'Termine'],
            'Anwesenheitsquoten' => ['evaluations/index.twig', 'Termine'],
            'Backups' => ['backups/index.twig', 'Administration'],
        ];
    }

    #[DataProvider('pageSections')]
    public function testPageEyebrowNamesItsSidebarSection(string $template, string $section): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/' . $template);
        $this->assertIsString($source);

        $this->assertStringContainsString(
            'class="text-uppercase text-muted small mb-1">' . $section . '<',
            $source,
            "Überzeile in {$template}"
        );
    }
}
