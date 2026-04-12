<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Util\UploadValidator;
use PHPUnit\Framework\TestCase;

class UploadCompressionFeatureTest extends TestCase
{
    public function testImageOver2MBIsRejected(): void
    {
        $twoMBPlusOne = (2 * 1024 * 1024) + 1;
        $result = UploadValidator::validateImageSize($twoMBPlusOne, 'image/jpeg');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('2 MB', $result['error']);
    }

    public function testImageUnder2MBIsAccepted(): void
    {
        $oneMB = 1 * 1024 * 1024;
        $result = UploadValidator::validateImageSize($oneMB, 'image/jpeg');

        $this->assertTrue($result['valid']);
    }

    public function testNonImageOver10MBIsRejected(): void
    {
        $tenMBPlusOne = (10 * 1024 * 1024) + 1;
        $result = UploadValidator::validateFileSize($tenMBPlusOne, 'application/pdf');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('10 MB', $result['error']);
    }

    public function testNonImageUnder10MBIsAccepted(): void
    {
        $fiveMB = 5 * 1024 * 1024;
        $result = UploadValidator::validateFileSize($fiveMB, 'application/pdf');

        $this->assertTrue($result['valid']);
    }

    public function testGetImageMimeTypesReturnsCommonFormats(): void
    {
        $types = UploadValidator::getImageMimeTypes();

        $this->assertContains('image/jpeg', $types);
        $this->assertContains('image/png', $types);
        $this->assertContains('image/webp', $types);
    }

    public function testGetNonImageMimeTypesReturnsDocumentFormats(): void
    {
        $types = UploadValidator::getNonImageMimeTypes();

        $this->assertContains('application/pdf', $types);
        $this->assertContains('application/msword', $types);
    }

    public function testImageExactlyAtLimitIsAccepted(): void
    {
        $validation = UploadValidator::validateImageSize(
            2097152, // Exactly 2 MB
            'image/jpeg'
        );
        $this->assertTrue($validation['valid']);
    }

    public function testImageJustOver2MBIsRejected(): void
    {
        $validation = UploadValidator::validateImageSize(
            2097153, // 2 MB + 1 byte
            'image/jpeg'
        );
        $this->assertFalse($validation['valid']);
        $this->assertStringContainsString('Bilddatei zu groß', $validation['error']);
        $this->assertStringContainsString('2 MB', $validation['error']);
    }

    public function testNonImageExactlyAtLimitIsAccepted(): void
    {
        $validation = UploadValidator::validateFileSize(
            10485760, // Exactly 10 MB
            'application/pdf'
        );
        $this->assertTrue($validation['valid']);
    }

    public function testNonImageJustOver10MBIsRejected(): void
    {
        $validation = UploadValidator::validateFileSize(
            10485761, // 10 MB + 1 byte
            'application/pdf'
        );
        $this->assertFalse($validation['valid']);
        $this->assertStringContainsString('Datei zu groß', $validation['error']);
    }

    public function testErrorMessageIncludesActualFileSizeForImages(): void
    {
        $validation = UploadValidator::validateImageSize(
            5242880, // 5 MB
            'image/jpeg'
        );
        $this->assertFalse($validation['valid']);
        $this->assertStringContainsString('5', $validation['error']); // Size mentioned
    }

    public function testErrorMessageIncludesActualFileSizeForNonImages(): void
    {
        $validation = UploadValidator::validateFileSize(
            15728640, // 15 MB
            'application/pdf'
        );
        $this->assertFalse($validation['valid']);
        $this->assertStringContainsString('15', $validation['error']); // Size mentioned
    }

    public function testInvalidImageMimeTypeIsRejectedWithMessage(): void
    {
        $validation = UploadValidator::validateImageSize(
            1048576, // 1 MB, valid size
            'text/plain' // Invalid for image validation
        );
        $this->assertFalse($validation['valid']);
        $this->assertStringContainsString('erlaubtes Bildformat', $validation['error']);
    }

    public function testZeroByteImageIsRejected(): void
    {
        $validation = UploadValidator::validateImageSize(
            0,
            'image/jpeg'
        );
        $this->assertFalse($validation['valid']);
    }

    public function testNegativeSizeIsRejected(): void
    {
        $validation = UploadValidator::validateFileSize(
            -1,
            'application/pdf'
        );
        $this->assertFalse($validation['valid']);
    }

    public function testAllImageMimeTypesAreSupported(): void
    {
        $mimeTypes = UploadValidator::getImageMimeTypes();
        $supportedFormats = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        foreach ($supportedFormats as $mimeType) {
            $this->assertContains($mimeType, $mimeTypes, "Missing support for $mimeType");
        }
    }

    public function testAllDocumentMimeTypesAreSupported(): void
    {
        $mimeTypes = UploadValidator::getNonImageMimeTypes();
        $supportedFormats = ['application/pdf', 'text/plain'];

        foreach ($supportedFormats as $mimeType) {
            $this->assertContains($mimeType, $mimeTypes, "Missing support for $mimeType");
        }
    }
}
