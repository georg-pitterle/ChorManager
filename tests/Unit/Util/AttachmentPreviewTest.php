<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\AttachmentPreview;
use PHPUnit\Framework\TestCase;

/**
 * Die einzige Stelle, die entscheidet, was inline ausgeliefert und was im
 * Modal gezeigt wird. Zwei Fragen, weil sie nicht dieselbe sind: MIDI wird
 * ausgeliefert (der eingebettete Player auf der Downloads-Seite braucht die
 * Quelle), taugt aber nicht für das globale Modal - die Wiedergabe hängt an
 * drei Bibliotheken, die nur auf jener Seite geladen werden.
 */
final class AttachmentPreviewTest extends TestCase
{
    public function testNormalizeCutsParametersAndLowercases(): void
    {
        $this->assertSame('text/plain', AttachmentPreview::normalize('text/plain; charset=utf-8'));
        $this->assertSame('text/plain', AttachmentPreview::normalize('  TEXT/PLAIN  '));
        $this->assertSame('application/pdf', AttachmentPreview::normalize('Application/PDF'));
        $this->assertSame('', AttachmentPreview::normalize(''));
    }

    public function testInlineServableTypes(): void
    {
        foreach (['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'] as $mime) {
            $this->assertTrue(AttachmentPreview::isInlineServable($mime), $mime);
        }

        foreach (['text/plain', 'audio/mpeg', 'audio/midi', 'audio/x-midi', 'application/x-midi'] as $mime) {
            $this->assertTrue(AttachmentPreview::isInlineServable($mime), $mime);
        }

        $this->assertTrue(AttachmentPreview::isInlineServable('text/plain; charset=utf-8'));
    }

    public function testInlineServableRejectsOfficeDocuments(): void
    {
        $office = [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        foreach ($office as $mime) {
            $this->assertFalse(AttachmentPreview::isInlineServable($mime), $mime);
        }

        $this->assertFalse(AttachmentPreview::isInlineServable('text/html'));
        $this->assertFalse(AttachmentPreview::isInlineServable(''));
    }

    public function testModalPreviewableExcludesMidi(): void
    {
        $this->assertTrue(AttachmentPreview::isModalPreviewable('application/pdf'));
        $this->assertTrue(AttachmentPreview::isModalPreviewable('image/png'));
        $this->assertTrue(AttachmentPreview::isModalPreviewable('text/plain; charset=utf-8'));
        $this->assertTrue(AttachmentPreview::isModalPreviewable('audio/mpeg'));

        foreach (['audio/midi', 'audio/x-midi', 'application/x-midi'] as $mime) {
            $this->assertFalse(AttachmentPreview::isModalPreviewable($mime), $mime);
        }
    }

    public function testModalPreviewableIsSubsetOfInlineServable(): void
    {
        $all = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'text/plain',
            'audio/mpeg',
            'audio/midi',
            'audio/x-midi',
            'application/x-midi',
            'application/msword',
            'text/html',
        ];

        foreach ($all as $mime) {
            if (AttachmentPreview::isModalPreviewable($mime)) {
                $this->assertTrue(AttachmentPreview::isInlineServable($mime), $mime);
            }
        }
    }
}
