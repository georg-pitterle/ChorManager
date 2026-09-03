<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\AttachmentPreview;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\Environment;

/**
 * Der Baustein und das Modal existieren genau einmal. Der Test hält fest, was
 * sonst still auseinanderläuft: dass das Modal global eingebunden ist und
 * nicht in sechs Templates einzeln, und dass der Baustein die Datenattribute
 * trägt, aus denen das Skript liest.
 *
 * Der Baustein wird echt gerendert (nicht per Textsuche im Quelltext), damit ein
 * kaputtes Include oder eine falsche Bedingung auffällt, statt dass der Test
 * grün bleibt, obwohl nie ein Knopf erscheint. Nur für Datei-Inhalt, der sich
 * serverseitig nicht rendern lässt - das JavaScript und die Layout-Einbindung -
 * bleibt eine Textprüfung richtig.
 */
final class AttachmentPreviewPartialFeatureTest extends TestCase
{
    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);
        $this->assertIsString($content, $relativePath . ' fehlt');

        return $content;
    }

    /**
     * Die Twig-Umgebung kommt aus dem echten Container, nicht aus einem
     * Nachbau. Eine hier registrierte Kopie der Funktion `attachment_previewable`
     * würde sich selbst prüfen: Stellte jemand die Registrierung in
     * Dependencies.php auf `isInlineServable()` um, bliebe der Test grün,
     * während in der Anwendung jeder MIDI-Anhang einen Vorschau-Knopf mit
     * leerem Fenster bekäme.
     *
     * Der Aufbau folgt DependenciesContainerWiringTest: erst die
     * Datenbankverbindung, weil die Twig-Fabrik beim Bauen Einstellungen liest.
     */
    private function twig(): Environment
    {
        Bootstrap::setupTestDatabase();

        $containerBuilder = new ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        return $containerBuilder->build()->get(Twig::class)->getEnvironment();
    }

    private function renderActions(array $context): string
    {
        return $this->twig()->render('partials/attachment_actions.twig', array_merge([
            'attachment_id' => 42,
            'attachment_name' => 'Programmheft.pdf',
            'attachment_mime' => 'application/pdf',
            'attachment_size' => 12345,
        ], $context));
    }

    public function testActionsPartialRendersBothButtonsForPreviewableMime(): void
    {
        $html = $this->renderActions([
            'attachment_id' => 7,
            'attachment_name' => 'Programmheft.pdf',
            'attachment_mime' => 'application/pdf',
            'attachment_size' => 98765,
        ]);

        // Der Haken, auf den public/js/attachment-preview.js delegiert. Ohne
        // ihn bliebe der Knopf sichtbar, aber tot.
        $this->assertStringContainsString('data-attachment-preview', $html);
        $this->assertStringContainsString('data-attachment-id="7"', $html);
        $this->assertStringContainsString('data-attachment-name="Programmheft.pdf"', $html);
        $this->assertStringContainsString('data-attachment-mime="application/pdf"', $html);
        $this->assertStringContainsString('data-attachment-size="98765"', $html);
        $this->assertStringContainsString('href="/attachments/7/download"', $html);
        $this->assertStringContainsString('bi-eye', $html);
        $this->assertStringContainsString('bi-download', $html);
    }

    public function testActionsPartialOmitsPreviewButtonForNonPreviewableMime(): void
    {
        $html = $this->renderActions([
            'attachment_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);

        $this->assertStringNotContainsString('bi-eye', $html);
        $this->assertStringNotContainsString('data-attachment-id', $html);
        $this->assertStringContainsString('bi-download', $html);
    }

    public function testActionsPartialOmitsPreviewButtonForMidi(): void
    {
        // MIDI wird zwar inline ausgeliefert (Task 1), aber das gemeinsame Modal
        // kann es nicht darstellen - genau der Fall, der isInlineServable() und
        // isModalPreviewable() unterscheidet.
        $html = $this->renderActions(['attachment_mime' => 'audio/midi']);

        $this->assertStringNotContainsString('bi-eye', $html);
        $this->assertStringNotContainsString('data-attachment-id', $html);
        $this->assertStringContainsString('bi-download', $html);
    }

    public function testActionsPartialEscapesAttachmentNameWithHtmlMetacharacters(): void
    {
        $maliciousName = '"><script>alert(1)</script>.pdf';

        $html = $this->renderActions(['attachment_name' => $maliciousName]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString(
            'data-attachment-name="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;.pdf"',
            $html
        );
    }

    /**
     * Die im Container registrierte Funktion muss auf `isModalPreviewable()`
     * abbilden, nicht auf die weitere Liste `isInlineServable()`. MIDI ist der
     * Fall, der beide unterscheidet.
     */
    public function testAttachmentPreviewableFunctionMapsToModalPreviewable(): void
    {
        $twig = $this->twig();
        $template = $twig->createTemplate('{{ attachment_previewable(mime) ? "yes" : "no" }}');

        $probes = ['application/pdf', 'audio/midi', 'audio/x-midi', 'text/plain', 'application/zip'];

        foreach ($probes as $mime) {
            $expected = AttachmentPreview::isModalPreviewable($mime) ? 'yes' : 'no';
            $this->assertSame($expected, $template->render(['mime' => $mime]), $mime);
        }

        // Zusätzlich ausdrücklich: MIDI ist inline auslieferbar, aber nicht
        // darstellbar. Wäre die weitere Liste registriert, stünde hier "yes".
        $this->assertTrue(AttachmentPreview::isInlineServable('audio/midi'));
        $this->assertSame('no', $template->render(['mime' => 'audio/midi']));
    }

    public function testModalIsIncludedOnceGloballyInLayout(): void
    {
        $layout = $this->read('templates/layout.twig');

        $this->assertStringContainsString('partials/attachment_preview_modal.twig', $layout);
        $this->assertStringContainsString('/js/attachment-preview.js', $layout);
        $this->assertSame(1, substr_count($layout, 'partials/attachment_preview_modal.twig'));
    }

    public function testModalMarkupHasStableHooks(): void
    {
        $modal = $this->read('templates/partials/attachment_preview_modal.twig');

        $this->assertStringContainsString('id="attachmentPreviewModal"', $modal);
        $this->assertStringContainsString('id="attachmentPreviewBody"', $modal);
        $this->assertStringContainsString('id="attachmentPreviewTitle"', $modal);
        $this->assertStringContainsString('id="attachmentPreviewMeta"', $modal);
        $this->assertStringContainsString('id="attachmentPreviewDownload"', $modal);
    }

    public function testModalMarkupHasNoInlineJsOrCss(): void
    {
        $modal = $this->read('templates/partials/attachment_preview_modal.twig');

        $this->assertStringNotContainsString('onclick', $modal);
        $this->assertStringNotContainsString('<script', $modal);
        $this->assertStringNotContainsString('style="', $modal);
    }

    public function testScriptExistsAndReadsDataAttachmentAttributes(): void
    {
        $script = $this->read('public/js/attachment-preview.js');

        $this->assertStringContainsString('attachmentPreviewModal', $script);
        $this->assertStringContainsString('data-attachment-id', $script);
    }
}
