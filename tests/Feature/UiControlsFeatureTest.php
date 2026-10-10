<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Bedienelemente folgen je einer Form: Abbrechen als graue Kontur, Knöpfe im Seitenkopf in
 * normaler Größe, Primärknöpfe ohne Icon, Zeilenaktionen grau oder rot, Dialoge scrollbar,
 * Badges über text-bg-*, Bestätigungen ohne Inline-Skript.
 */
class UiControlsFeatureTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $templates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_starts_with($relative, 'templates/emails/')) {
                continue;
            }

            $templates[$relative] = (string) file_get_contents($file->getPathname());
        }

        return $templates;
    }

    /**
     * @param array<string> $offenders
     */
    private function assertNone(array $offenders, string $message): void
    {
        $this->assertSame([], $offenders, $message);
    }

    public function testCancelIsAlwaysAnOutlineButton(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $content) {
            if (preg_match('/class="btn btn-secondary\b[^"]*"[^>]*>\s*(<i[^>]*><\/i>\s*)?Abbrechen/', $content) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertNone($offenders, 'Abbrechen als gefüllter grauer Knopf.');

        // Kontur-Knöpfe stehen auf hellem Grund: eine weiße Schrift (Rest einer früher
        // gefüllten Variante) macht sie unlesbar.
        foreach (self::templates() as $path => $content) {
            $this->assertDoesNotMatchRegularExpression('/class="btn btn-outline-[a-z]+[^"]*\btext-white\b/', $content, $path);
        }

        // Nebenaktionen (Schließen, Zurück, Hinzufügen) tragen dieselbe graue Kontur.
        foreach (self::templates() as $path => $content) {
            $this->assertDoesNotMatchRegularExpression('/class="btn btn-secondary\b/', $content, $path);
        }
    }

    public function testPageHeaderButtonsHaveNormalSize(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $content) {
            preg_match_all('/<div class="page-actions[^"]*"[^>]*>(.*?)<\/section>/s', $content, $blocks);

            foreach ($blocks[1] as $block) {
                if (preg_match('/class="btn\b[^"]*\bbtn-sm\b/', $block) === 1) {
                    $offenders[] = $path;
                }
            }
        }

        $this->assertNone($offenders, 'Kleine Knöpfe im Seitenkopf.');

        // Im Seitenkopf: eine Hauptaktion (primary), Nebenaktionen grau, Löschen/Archivieren rot.
        $coloured = [];
        foreach (self::templates() as $path => $content) {
            preg_match_all('/<div class="page-actions[^"]*"[^>]*>(.*?)<\/section>/s', $content, $blocks);

            foreach ($blocks[1] as $block) {
                if (preg_match('/class="btn [^"]*\bbtn-(outline-primary|info|outline-info|success|outline-success|warning|outline-warning)\b/', $block) === 1) {
                    $coloured[] = $path;
                }
            }
        }

        $this->assertNone($coloured, 'Farbige Nebenaktionen im Seitenkopf.');
    }

    public function testPrimaryButtonsCarryNoIcon(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $content) {
            preg_match_all('/<(a|button)\b[^>]*class="btn btn-primary\b[^"]*"[^>]*>(.*?)<\/\1>/s', $content, $buttons);

            foreach ($buttons[2] as $inner) {
                if (str_contains($inner, '<i ')) {
                    $offenders[] = $path;
                }
            }
        }

        $this->assertNone($offenders, 'Primärknöpfe mit Icon.');
    }

    /**
     * Zeilenaktionen: neutral grau, Löschen und Überschreiben rot. Eine Primäraktion ist nur
     * die Anlegen-Zeile am Tabellenende.
     */
    public function testTableRowActionsAreGreyOrRed(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $content) {
            preg_match_all('/<td[^>]*data-label="Aktionen"[^>]*>(.*?)<\/td>/s', $content, $cells);

            foreach ($cells[1] as $cell) {
                preg_match_all('/class="(btn [^"]*)"/', $cell, $classes);

                foreach ($classes[1] as $class) {
                    if (preg_match('/\bbtn-(outline-secondary|outline-danger|primary)\b/', $class) !== 1) {
                        $offenders[] = $path . ': ' . $class;
                    }
                }
            }
        }

        $this->assertNone($offenders, 'Zeilenaktionen in anderer Farbe als grau oder rot.');
    }

    public function testDialogsScrollAndAreNotCentred(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $content) {
            preg_match_all('/class="(modal-dialog(?: [^"]*)?)"/', $content, $dialogs);

            foreach ($dialogs[1] as $class) {
                if (str_contains($class, 'nav-search__dialog')) {
                    continue;
                }
                if (!str_contains($class, 'modal-dialog-scrollable') || str_contains($class, 'modal-dialog-centered')) {
                    $offenders[] = $path . ': ' . $class;
                }
            }
        }

        $this->assertNone($offenders, 'Dialoge ohne Scrollbereich oder zentriert.');
    }

    public function testDeleteDialogsHaveAPlainHeader(): void
    {
        foreach (self::templates() as $path => $content) {
            $this->assertStringNotContainsString('class="modal-header bg-danger', $content, $path);
        }
    }

    public function testBadgesUseTextBgClasses(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $content) {
            preg_match_all('/class="([^"]*\bbadge\b[^"]*)"/', $content, $badges);

            foreach ($badges[1] as $class) {
                if (preg_match('/(^|\s)bg-(primary|secondary|success|danger|warning|info|light|dark)(-subtle)?(\s|$)/', $class) === 1) {
                    $offenders[] = $path . ': ' . $class;
                }
            }
        }

        $this->assertNone($offenders, 'Badges mit bg-* statt text-bg-*.');
        $this->assertMatchesRegularExpression(
            '/\.text-bg-primary\s*\{[^}]*color:\s*#2b2b2b/',
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/css/style.css')
        );
    }

    public function testNoInlineEventHandlers(): void
    {
        foreach (self::templates() as $path => $content) {
            $this->assertDoesNotMatchRegularExpression('/\son(click|submit|change)=/', $content, $path);
        }
    }

    /**
     * Badge-Farben tragen Bedeutung: grün erledigt/positiv, rot negativ/dringend, gelb Achtung,
     * grau neutral/inaktiv/Art, blau Hinweis/in Arbeit. Festgehalten an den früheren Ausreißern.
     */
    public function testBadgeColoursFollowTheirMeaning(): void
    {
        $templates = self::templates();
        $expected = [
            'templates/projects/tasks.twig' => ['text-bg-warning">Mittel<'],
            'templates/finances/accounts.twig' => ['text-bg-secondary">Bar<', 'text-bg-secondary">Bank<'],
            'templates/backups/index.twig' => ['text-bg-secondary">Manuell<'],
        ];

        foreach ($expected as $path => $needles) {
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $templates[$path], $path);
            }
        }

        foreach ($templates as $path => $content) {
            $this->assertDoesNotMatchRegularExpression('/text-bg-(warning|primary)"[^>]*>\s*storniert/', $content, $path);
            $this->assertStringNotContainsString('badge bg-primary">Mittel', $content, $path);
        }
    }

    public function testEmptyStatesAreNotAlerts(): void
    {
        foreach (self::templates() as $path => $content) {
            $this->assertDoesNotMatchRegularExpression('/<div class="alert alert-info">\s*(Keine|Noch keine)/', $content, $path);
        }
    }
}
