<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class PwaFeatureTest extends TestCase
{
    public function testMainLayoutsExposePwaMetadata(): void
    {
        $base = dirname(__DIR__) . '/..';
        $layouts = [
            $base . '/templates/layout.twig',
            $base . '/templates/layout_modal.twig',
        ];

        foreach ($layouts as $path) {
            $content = file_get_contents($path);

            $this->assertIsString($content);
            $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $content);
            $this->assertStringContainsString('<meta name="theme-color" content="#1f3a5f">', $content);
            $this->assertStringContainsString('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', $content);
        }
    }

    public function testCommonJsRegistersServiceWorker(): void
    {
        $path = dirname(__DIR__) . '/../public/js/common.js';
        $content = file_get_contents($path);

        $this->assertIsString($content);
        $this->assertStringContainsString("'serviceWorker' in navigator", $content);
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js')", $content);
    }

    public function testManifestAndServiceWorkerFilesExistWithInstallabilityMarkers(): void
    {
        $base = dirname(__DIR__) . '/..';
        $manifestPath = $base . '/public/manifest.webmanifest';
        $serviceWorkerPath = $base . '/public/sw.js';

        $this->assertFileExists($manifestPath);
        $this->assertFileExists($serviceWorkerPath);
        $this->assertFileExists($base . '/public/icons/icon-192.png');
        $this->assertFileExists($base . '/public/icons/icon-512.png');
        $this->assertFileExists($base . '/public/icons/maskable-icon-512.png');
        $this->assertFileExists($base . '/public/icons/apple-touch-icon.png');

        $manifestContent = file_get_contents($manifestPath);
        $serviceWorkerContent = file_get_contents($serviceWorkerPath);

        $this->assertIsString($manifestContent);
        $this->assertIsString($serviceWorkerContent);
        $this->assertStringContainsString('"display": "standalone"', $manifestContent);
        $this->assertStringContainsString('"src": "/icons/icon-192.png"', $manifestContent);
        $this->assertStringContainsString('"src": "/icons/maskable-icon-512.png"', $manifestContent);
        $this->assertStringContainsString("self.addEventListener('install'", $serviceWorkerContent);
        $this->assertStringNotContainsString('caches.open(', $serviceWorkerContent);
    }
}
