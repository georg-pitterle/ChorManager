<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DownloadController;
use App\Util\UploadValidator;
use PHPUnit\Framework\TestCase;

/**
 * Übungsaufnahmen am Lied.
 *
 * Die Download-Seite bringt für MP3 und MIDI einen eigenen Abspieler mit, und
 * DownloadController::streamAttachment() gibt die Formate zum Streamen frei.
 * Hochladen ließ sich aber keines davon: UploadValidator kannte überhaupt kein
 * Audioformat, jede Datei fiel in "kein erlaubter Dateityp". Der Abspieler war
 * damit unerreichbar.
 *
 * Die Grenze liegt eigens bei 30 MB. Die zehn für sonstige Dateien reichen für
 * eine Probeaufnahme nicht - eine Stunde Chorprobe als MP3 liegt darüber.
 */
final class AudioAttachmentUploadFeatureTest extends TestCase
{
    public function testAudioFormatsAreAllowedForUpload(): void
    {
        foreach (['audio/mpeg', 'audio/midi', 'audio/x-midi', 'application/x-midi'] as $mimeType) {
            $this->assertTrue(
                UploadValidator::isAllowedMimeType($mimeType),
                $mimeType . ' muss hochladbar sein, sonst bleibt der Abspieler unerreichbar.'
            );
        }
    }

    public function testAudioIsNeitherImageNorDocument(): void
    {
        $this->assertTrue(UploadValidator::isAudioMimeType('audio/mpeg'));
        $this->assertFalse(UploadValidator::isImageMimeType('audio/mpeg'));
        $this->assertNotContains('audio/mpeg', UploadValidator::getNonImageMimeTypes());
        $this->assertContains('audio/mpeg', UploadValidator::getAllowedMimeTypes());
    }

    public function testAudioUsesItsOwnThirtyMegabyteLimit(): void
    {
        $this->assertSame(30 * 1024 * 1024, UploadValidator::MAX_AUDIO_SIZE);

        $atLimit = UploadValidator::validateFileSize(UploadValidator::MAX_AUDIO_SIZE, 'audio/mpeg');
        $this->assertTrue($atLimit['valid']);

        // Zwölf MB liegen über der Grenze für sonstige Dateien und müssen als
        // Aufnahme trotzdem durchgehen.
        $this->assertTrue(UploadValidator::validateFileSize(12 * 1024 * 1024, 'audio/mpeg')['valid']);
    }

    public function testAudioBeyondTheLimitIsRejectedWithTheAudioLimitInTheMessage(): void
    {
        $validation = UploadValidator::validateFileSize(UploadValidator::MAX_AUDIO_SIZE + 1, 'audio/mpeg');

        $this->assertFalse($validation['valid']);
        $this->assertSame('size_exceeded', $validation['reason']);
        $this->assertStringContainsString('30 MB', $validation['error']);
    }

    public function testAudioIsStillNoValidLogoUpload(): void
    {
        // validateImageSize() bewacht das Vereinslogo - dort bleibt Audio draußen.
        $validation = UploadValidator::validateImageSize(1024, 'audio/mpeg');

        $this->assertFalse($validation['valid']);
        $this->assertSame('invalid_mime_type', $validation['reason']);
    }

    /**
     * Streambare und hochladbare Formate dürfen nicht auseinanderlaufen - genau
     * daran ist die Funktion vorher gescheitert. Der Abspieler liest deshalb
     * dieselbe Liste, die der Upload durchlässt.
     */
    public function testStreamableFormatsAreExactlyTheUploadableAudioFormats(): void
    {
        $streamable = DownloadController::streamableMimeTypes();
        $uploadable = UploadValidator::getAudioMimeTypes();

        sort($streamable);
        sort($uploadable);

        $this->assertSame($uploadable, $streamable);
    }
}
