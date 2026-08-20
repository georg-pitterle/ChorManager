<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Dokumentiert die eigentliche Ursache des Modal-Anlegen-Fehlers: Der einzige verlinkte Weg zum
 * Anlegen eines Newsletters ist der Modal-Dialog in templates/newsletters/index.twig. Dort findet
 * nie ein echter Seitenwechsel statt - public/js/newsletters.js lädt den Editor per Anfrage in
 * denselben Dialog nach. Das nachgeladene Editor-Template erweitert layout_modal.twig, und dieses
 * Layout bindet den Session-Meldungsbereich (partials/flash.twig) gar nicht ein. Eine Warnung, die
 * ein Endpunkt über $_SESSION['warning'] meldet, kann im Modal-Weg deshalb strukturell nie
 * erscheinen - unabhängig davon, ob ein Controller sie dort ablegt. Legt künftig jemand wieder eine
 * Meldung über die Sitzung in den Modal-Weg, bleibt dieser Test nur so lange rot, wie
 * layout_modal.twig den Meldungsbereich weiterhin nicht einbindet - und genau das ist heute der
 * Fall und der Grund, warum der Anlegen-Endpunkt die Warnung stattdessen über das JSON-Feld
 * "warnings" liefert (siehe NewsletterUnknownPlaceholderFeatureTest::testStoreReturnsWarningInJsonWithoutTouchingSession).
 */
final class NewsletterModalWarningDeliveryFeatureTest extends TestCase
{
    public function testModalLayoutDoesNotRenderSessionFlashMessages(): void
    {
        $layoutModalPath = dirname(__DIR__) . '/../templates/layout_modal.twig';
        $layoutModalContent = file_get_contents($layoutModalPath);

        $this->assertIsString($layoutModalContent);
        $this->assertStringNotContainsString(
            'partials/flash.twig',
            $layoutModalContent,
            'layout_modal.twig bindet jetzt den Session-Meldungsbereich ein. Damit dürfte der '
            . 'Anlegen-Endpunkt eine Warnung wieder über $_SESSION[\'warning\'] melden - vorher muss '
            . 'aber sichergestellt sein, dass jede Editor-Nachladung im Modal-Weg (newsletters.js) die '
            . 'Meldung nicht sofort wieder überschreibt.'
        );
    }

    /**
     * Newsletter-Bearbeiten und -Anlegen sind die einzigen Templates, die layout_modal.twig
     * tatsächlich im Modal-Weg verwenden; sie bestätigen, dass die obige Prüfung den Weg trifft,
     * über den der Anlegen-Dialog wirklich läuft.
     */
    public function testNewsletterCreateAndEditTemplatesSwitchToModalLayoutWhenModal(): void
    {
        $base = dirname(__DIR__) . '/..';
        $templates = [
            $base . '/templates/newsletters/create.twig',
            $base . '/templates/newsletters/edit.twig',
        ];

        foreach ($templates as $path) {
            $content = file_get_contents($path);
            $this->assertIsString($content);
            $this->assertStringContainsString(
                "is_modal|default(false) ? 'layout_modal.twig' : 'layout.twig'",
                $content,
                basename($path) . ' soll im Modal-Weg layout_modal.twig erweitern'
            );
        }
    }
}
