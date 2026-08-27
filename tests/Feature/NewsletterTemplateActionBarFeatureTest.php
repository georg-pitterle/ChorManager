<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * "Speichern" muss im Vorlagen-Formular ohne Scrollen erreichbar bleiben.
 *
 * Das Formular wird im Bearbeiten-Dialog in den scrollenden Modalbereich geladen.
 * Seine Knöpfe stehen - anders als beim Erstellen-Dialog, der eine
 * modal-footer-Leiste außerhalb des Scrollbereichs hat - als letztes Element im
 * Inhalt selbst. Der Inhalt ist rund 1400 px hoch, am Telefon sind vom Dialog je
 * nach Gerät nur 220 bis 650 px sichtbar; ohne die feststehende Leiste lagen die
 * Knöpfe weit unterhalb des Sichtfensters und die Vorlage ließ sich nicht speichern.
 */
final class NewsletterTemplateActionBarFeatureTest extends TestCase
{
    private function templateSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/newsletters/templates_edit.twig'
        );
    }

    private function styleSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/css/style.css');
    }

    public function testActionButtonsSitInAStickyActionBar(): void
    {
        $template = $this->templateSource();

        $this->assertMatchesRegularExpression(
            '/<div class="[^"]*\bform-action-bar\b[^"]*">\s*'
            . '<a href="\/newsletters\/templates"[^>]*>Abbrechen<\/a>\s*'
            . '<button type="submit"[^>]*>Speichern<\/button>/s',
            $template,
            'Abbrechen und Speichern gehören in die feststehende Aktionsleiste.'
        );
    }

    public function testStickyActionBarIsDefinedInTheStylesheet(): void
    {
        $style = $this->styleSource();

        $this->assertMatchesRegularExpression(
            '/\.form-action-bar\s*\{[^}]*position:\s*sticky[^}]*\}/s',
            $style,
            'Ohne position: sticky scrollt die Leiste wieder mit dem Inhalt weg.'
        );

        // bottom ist der Anker: sticky ohne Randangabe verhält sich wie static.
        $this->assertMatchesRegularExpression(
            '/\.form-action-bar\s*\{[^}]*bottom:\s*0[^}]*\}/s',
            $style
        );

        // Ohne deckenden Hintergrund scrollt der Formularinhalt sichtbar durch die Leiste.
        $this->assertMatchesRegularExpression(
            '/\.form-action-bar\s*\{[^}]*background-color:[^};]+[^}]*\}/s',
            $style
        );
    }

    /**
     * Die Leiste darf nicht per inline-style gebaut werden - das verbietet die
     * Vorlagen-Hygiene des Projekts und würde die Regel am Stylesheet vorbeiziehen.
     */
    public function testActionBarUsesNoInlineStyles(): void
    {
        $this->assertStringNotContainsString('style="', $this->templateSource());
    }

    private function indexSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/newsletters/templates_index.twig'
        );
    }

    /**
     * Das Formular im Erstellen-Dialog muss die Flex-Hülle sein.
     *
     * Bootstrap verteilt Kopf, Körper und Fußleiste über .modal-content
     * (display: flex, flex-direction: column). Im Erstellen-Dialog umschließt ein
     * <form> die drei - damit ist das Formular das einzige Flex-Kind, und der Körper
     * wird nie in der Höhe beschnitten: Gemessen wuchs er auf 1261 px in einem 711 px
     * hohen Dialog und schob die Fußleiste auf y=1333, weit aus dem 727 px hohen
     * Fenster. Der Knopf "Vorlage erstellen" war am Telefon damit unerreichbar, und
     * trotz modal-dialog-scrollable scrollte nichts.
     */
    public function testCreateDialogFormPassesTheHeightConstraintToTheModalBody(): void
    {
        $index = $this->indexSource();

        $this->assertMatchesRegularExpression(
            '/<div class="modal-content">\s*(?:\{#.*?#\}\s*)?<form[^>]*\bclass="[^"]*\bmodal-form-shell\b/s',
            $index,
            'Das Formular zwischen .modal-content und .modal-body braucht die Flex-Hülle.'
        );
    }

    public function testModalFormShellIsDefinedInTheStylesheet(): void
    {
        $style = $this->styleSource();

        $this->assertMatchesRegularExpression(
            '/\.modal-form-shell\s*\{[^}]*display:\s*flex[^}]*\}/s',
            $style
        );
        $this->assertMatchesRegularExpression(
            '/\.modal-form-shell\s*\{[^}]*flex-direction:\s*column[^}]*\}/s',
            $style
        );
        // Ohne min-height: 0 darf ein Flex-Kind nicht unter seine Inhaltshöhe
        // schrumpfen - der Körper bekäme die Beschränkung nie zu sehen.
        $this->assertMatchesRegularExpression(
            '/\.modal-form-shell\s*\{[^}]*min-height:\s*0[^}]*\}/s',
            $style
        );
    }
}
