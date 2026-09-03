<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DevSeedAttachmentFixtures;
use App\Util\AttachmentPreview;
use PHPUnit\Framework\TestCase;

/**
 * Die Seed-Anhänge trugen bisher PDF-MIME und enthielten reinen Text. Im
 * Vorschau-Modal blieb der Rahmen damit leer - genau der Fall, den man beim
 * Entwickeln sehen will. Diese Fixtures liefern Inhalte, die zu ihrem Typ
 * passen, und je einen darstellbaren und einen nicht darstellbaren Vertreter.
 */
final class DevSeedAttachmentFixturesTest extends TestCase
{
    public function testPdfCarriesTheMagicNumberAndTheCaption(): void
    {
        $fixture = DevSeedAttachmentFixtures::pdf('Testbeleg');

        $this->assertSame('application/pdf', $fixture['mime_type']);
        $this->assertSame('pdf', $fixture['extension']);
        $this->assertStringStartsWith('%PDF-1.', $fixture['content']);
        $this->assertStringContainsString('%%EOF', $fixture['content']);
        $this->assertStringContainsString('Testbeleg', $fixture['content']);
    }

    /**
     * Klammern und Rückstriche beenden in PDF eine Textzeichenkette. Ein
     * Betreff mit Klammer würde die Datei sonst unlesbar machen.
     */
    public function testPdfEscapesParenthesesInTheCaption(): void
    {
        $fixture = DevSeedAttachmentFixtures::pdf('Beleg (Kopie)');

        $this->assertStringContainsString('Beleg \\(Kopie\\)', $fixture['content']);
    }

    public function testPngCarriesTheSignature(): void
    {
        $fixture = DevSeedAttachmentFixtures::png();

        $this->assertSame('image/png', $fixture['mime_type']);
        $this->assertSame('png', $fixture['extension']);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($fixture['content'], 0, 8));
        $this->assertGreaterThan(20, strlen($fixture['content']));
    }

    public function testTextCarriesItsBody(): void
    {
        $fixture = DevSeedAttachmentFixtures::text('Zeile mit Umlauten: ärgerlich, größer, über.');

        $this->assertSame('text/plain', $fixture['mime_type']);
        $this->assertSame('txt', $fixture['extension']);
        $this->assertStringContainsString('größer', $fixture['content']);
    }

    public function testWordDocumentIsNotPreviewable(): void
    {
        $fixture = DevSeedAttachmentFixtures::wordDocument('Konzept');

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $fixture['mime_type']
        );
        $this->assertSame('docx', $fixture['extension']);
        $this->assertFalse(AttachmentPreview::isModalPreviewable($fixture['mime_type']));
        $this->assertNotSame('', $fixture['content']);
    }

    public function testTheThreePreviewableFixturesReallyAre(): void
    {
        $previewable = [
            DevSeedAttachmentFixtures::pdf('x'),
            DevSeedAttachmentFixtures::png(),
            DevSeedAttachmentFixtures::text('x'),
        ];

        foreach ($previewable as $fixture) {
            $this->assertTrue(AttachmentPreview::isModalPreviewable($fixture['mime_type']), $fixture['mime_type']);
        }
    }

    /**
     * Die Reihum-Auswahl ist das, was den Bereichen ihre Mischung gibt. Über
     * vier aufeinanderfolgende Plätze muss jeder der vier Typen genau einmal
     * vorkommen - sonst bekäme ein Bereich womöglich nur darstellbare Anhänge
     * und der Fall "kein Vorschau-Knopf" wäre in Dev nicht zu sehen.
     */
    public function testRotationYieldsEachTypeOncePerFourSlots(): void
    {
        $types = [];
        for ($slot = 1; $slot <= 4; $slot++) {
            $types[] = DevSeedAttachmentFixtures::forSlot($slot, 'Probe')['mime_type'];
        }

        sort($types);

        $this->assertSame([
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/png',
            'text/plain',
        ], $types);
    }

    public function testRotationRepeatsAfterFourSlots(): void
    {
        $this->assertSame(
            DevSeedAttachmentFixtures::forSlot(1, 'Probe')['mime_type'],
            DevSeedAttachmentFixtures::forSlot(5, 'Probe')['mime_type']
        );
    }
}
