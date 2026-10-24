<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\SponsorLogoUpload;
use PHPUnit\Framework\TestCase;

/**
 * Was als Logo-Datei durchgeht: Grafikformate bis 50 MB - unabhängig von den
 * engeren Grenzen der übrigen Anhänge.
 */
final class SponsorLogoUploadTest extends TestCase
{
    public function testFiftyMegabytesAreTheLimit(): void
    {
        $this->assertTrue(SponsorLogoUpload::validate(50 * 1024 * 1024, 'application/zip')['valid']);

        $tooLarge = SponsorLogoUpload::validate(50 * 1024 * 1024 + 1, 'application/zip');
        $this->assertFalse($tooLarge['valid']);
        $this->assertSame('size_exceeded', $tooLarge['reason']);
        $this->assertStringContainsString('50 MB', (string) $tooLarge['error']);
    }

    public function testAnEmptyFileIsRejected(): void
    {
        $this->assertSame('invalid_size', SponsorLogoUpload::validate(0, 'image/png')['reason']);
    }

    public function testAnUnknownTypeIsRejected(): void
    {
        $result = SponsorLogoUpload::validate(100, 'application/x-msdownload');

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_mime_type', $result['reason']);
    }

    public function testIllustratorAndSvgAreAccepted(): void
    {
        $this->assertTrue(SponsorLogoUpload::validate(100, 'application/illustrator')['valid']);
        $this->assertTrue(SponsorLogoUpload::validate(100, 'image/svg+xml')['valid']);
        $this->assertTrue(SponsorLogoUpload::validate(100, 'IMAGE/SVG+XML; charset=utf-8')['valid']);
    }
}
