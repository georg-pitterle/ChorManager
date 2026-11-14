<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Das App-Image: Der Webprozess darf den Anwendungscode nicht überschreiben.
 *
 * Bisher gehörte der ganze Baum unter /var/www/html dem Benutzer www-data, unter
 * dem PHP-FPM läuft. Eine Schreiblücke irgendwo in der Anwendung - ein Upload, der
 * seinen Pfad selbst bestimmt, ein Entpacken ohne Pfadprüfung - wäre damit
 * sofort Code-Ausführung gewesen: `src/` oder `public/index.php` überschreiben,
 * nächste Anfrage abwarten. Jetzt gehört der Code root, und www-data besitzt nur
 * `var/`; alles Weitere, was zur Laufzeit beschrieben wird, liegt auf den
 * Volumes, die entrypoint.sh selbst übergibt.
 *
 * Geprüft wird die Datei als Text - ein Image-Build gehört nicht in die Suite.
 */
final class ContainerHardeningDefinitionsTest extends TestCase
{
    private function dockerfile(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
    }

    public function testTheApplicationTreeIsNotHandedToTheWebUser(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '#chown\s+-R\s+www-data:www-data\s+/var/www/html(\s|\\|$)#m',
            $this->dockerfile()
        );
    }

    public function testOnlyTheVarDirectoryBelongsToTheWebUser(): void
    {
        $dockerfile = $this->dockerfile();

        $this->assertStringContainsString('chown -R root:root /var/www/html', $dockerfile);
        $this->assertStringContainsString('chown -R www-data:www-data /var/www/html/var', $dockerfile);
    }

    /**
     * `composer:latest` hätte beim nächsten Build ungefragt eine neue
     * Hauptversion gezogen.
     */
    public function testComposerImageIsPinnedToAMajorVersion(): void
    {
        $this->assertStringNotContainsString('composer:latest', $this->dockerfile());
        $this->assertMatchesRegularExpression('#COPY --from=composer:\d#', $this->dockerfile());
    }

    /**
     * `var/` ist in Git ausgeschlossen, im Build-Kontext aber nicht: Ein lokaler
     * `docker build .` nahm die Datenbank-Sicherungen aus var/backups, die
     * Rate-Limit-Zähler und alles andere aus var/ mit ins Image.
     */
    public function testLocalRuntimeDataStaysOutOfTheBuildContext(): void
    {
        $ignored = array_map(
            'trim',
            explode("\n", (string) file_get_contents(dirname(__DIR__, 2) . '/.dockerignore'))
        );

        $this->assertContains('var', $ignored);
    }
}
