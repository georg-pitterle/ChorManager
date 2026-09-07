<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\MailInlineImages;
use PHPUnit\Framework\TestCase;

/**
 * Gmail zeigt Bilder aus `data:`-URIs nicht an — es entfernt sie beim Umschreiben des
 * HTML. Das Logo muss deshalb als eingebetteter Anhang mit `cid:`-Verweis verschickt
 * werden, sonst bleibt der Kopf jeder Systemmail bei Gmail-Empfaengern leer.
 */
final class MailInlineImagesTest extends TestCase
{
    private const PIXEL_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testDataUriBecomesEmbeddedImageWithCidReference(): void
    {
        $html = '<img src="data:image/png;base64,' . self::PIXEL_BASE64 . '" alt="Logo">';

        $result = MailInlineImages::extract($html);

        $this->assertCount(1, $result['images']);
        $image = $result['images'][0];

        $this->assertSame('image/png', $image['mime']);
        $this->assertSame(base64_decode(self::PIXEL_BASE64, true), $image['data']);
        $this->assertStringNotContainsString('data:image', $result['html']);
        $this->assertStringContainsString('src="cid:' . $image['cid'] . '"', $result['html']);
    }

    public function testAltAttributeAndSurroundingMarkupSurvive(): void
    {
        $html = '<img src="data:image/png;base64,' . self::PIXEL_BASE64 . '" alt="Chor" width="56">';

        $result = MailInlineImages::extract($html);

        $this->assertStringContainsString('alt="Chor"', $result['html']);
        $this->assertStringContainsString('width="56"', $result['html']);
    }

    public function testSameImageIsEmbeddedOnlyOnce(): void
    {
        $dataUri = 'data:image/png;base64,' . self::PIXEL_BASE64;
        $html = '<img src="' . $dataUri . '"><img src="' . $dataUri . '">';

        $result = MailInlineImages::extract($html);

        $this->assertCount(1, $result['images']);
        $this->assertSame(2, substr_count($result['html'], 'cid:' . $result['images'][0]['cid']));
    }

    public function testRemoteAndRelativeSourcesStayUntouched(): void
    {
        $html = '<img src="https://example.test/logo.png"><img src="/icons/icon-512.png">';

        $result = MailInlineImages::extract($html);

        $this->assertSame([], $result['images']);
        $this->assertSame($html, $result['html']);
    }

    public function testNonImageDataUriStaysUntouched(): void
    {
        $html = '<a href="data:text/plain;base64,SGFsbG8=">Datei</a>';

        $result = MailInlineImages::extract($html);

        $this->assertSame([], $result['images']);
        $this->assertSame($html, $result['html']);
    }

    public function testUnencodedDataUriIsRejectedInsteadOfEmbeddingGarbage(): void
    {
        $html = '<img src="data:image/png;base64,nicht base64!">';

        $result = MailInlineImages::extract($html);

        $this->assertSame([], $result['images']);
        $this->assertSame($html, $result['html']);
    }

    public function testCidIsUsableAsMessageIdWithoutQuoting(): void
    {
        $html = '<img src="data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>') . '">';

        $result = MailInlineImages::extract($html);

        $this->assertCount(1, $result['images']);
        $this->assertMatchesRegularExpression('/^[0-9a-z]+@chormanager\.local$/', $result['images'][0]['cid']);
        $this->assertSame('image/svg+xml', $result['images'][0]['mime']);
    }
}
