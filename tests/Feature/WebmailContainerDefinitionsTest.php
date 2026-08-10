<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards gegen eine halbe Migration: Die Webmail-Container-Definitionen und das
 * SSO-Plugin müssen vollständig auf Tachyon zeigen. Bleiben SnappyMail-Pfade
 * oder -Klassennamen stehen, startet der Container zwar, aber der SSO-Login
 * bricht zur Laufzeit ab (z. B. weil \SnappyMail\SensitiveString in Tachyon
 * nicht mehr existiert - dafür gibt es keinen Compat-Shim).
 */
class WebmailContainerDefinitionsTest extends TestCase
{
    private const TACHYON_IMAGE = 'ghcr.io/kimusan/tachyon:v3.2.2';

    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function read(string $relativePath): string
    {
        $path = $this->repoRoot() . '/' . $relativePath;
        $this->assertFileExists($path, $relativePath . ' existiert nicht.');

        $content = file_get_contents($path);
        $this->assertIsString($content, $relativePath . ' ist nicht lesbar.');

        return $content;
    }

    /**
     * @return array<int, string>
     */
    public static function pluginCopies(): array
    {
        // Pfade wandern in Task 4 (Dev) bzw. Task 5 (Prod) auf webmail-*.
        return [
            ['.ddev/webmail-plugins/chormanager-sso/index.php'],
            ['dist/webmail/chormanager-sso/index.php'],
        ];
    }

    #[DataProvider('pluginCopies')]
    public function testPluginExtendsTheTachyonBaseClass(string $path): void
    {
        $plugin = $this->read($path);

        $this->assertStringContainsString('extends \\Tachyon\\Plugins\\AbstractPlugin', $plugin);
        $this->assertStringNotContainsString('RainLoop\\Plugins\\AbstractPlugin', $plugin);
    }

    #[DataProvider('pluginCopies')]
    public function testPluginUsesTheTachyonSensitiveString(string $path): void
    {
        $plugin = $this->read($path);

        $this->assertStringContainsString('\\Tachyon\\Util\\SensitiveString', $plugin);
        $this->assertStringNotContainsString('SnappyMail\\SensitiveString', $plugin);
    }

    #[DataProvider('pluginCopies')]
    public function testPluginReadsTheNeutralSsoSecret(string $path): void
    {
        $plugin = $this->read($path);

        $this->assertStringContainsString("getenv('WEBMAIL_SSO_SECRET')", $plugin);
        $this->assertStringNotContainsString('SNAPPYMAIL_SSO_SECRET', $plugin);
    }

    /**
     * Tachyon\Model\Domain::fromArray() liest den Schlüssel als direkten Index
     * ("$oDomain->whiteList = (string) $aDomain['whiteList'];"), ohne Null-Guard.
     * Fehlt er in der von uns geschriebenen Domain-JSON, protokolliert Tachyon bei
     * jedem Domain-Load eine PHP-Warning - beobachtet in Produktion nach der
     * Migration, weil SnappyMail 2.38 den Schlüssel noch nicht brauchte.
     */
    #[DataProvider('pluginCopies')]
    public function testPluginWritesTheWhiteListKeyIntoTheDomainConfig(string $path): void
    {
        $plugin = $this->read($path);

        $this->assertStringContainsString("'whiteList' => ''", $plugin);
    }

    public function testDevComposeUsesThePinnedTachyonImage(): void
    {
        $compose = $this->read('.ddev/docker-compose.webmail.yaml');

        $this->assertStringContainsString('image: ' . self::TACHYON_IMAGE, $compose);
        $this->assertStringNotContainsString('djmaze/snappymail', $compose);
    }

    public function testDevComposeUsesTheTachyonDataPaths(): void
    {
        $compose = $this->read('.ddev/docker-compose.webmail.yaml');

        $this->assertStringContainsString(':/var/lib/tachyon', $compose);
        $this->assertStringContainsString(
            '/var/lib/tachyon/_data_/_default_/plugins/chormanager-sso',
            $compose
        );
        $this->assertStringNotContainsString('/var/lib/snappymail', $compose);
    }

    public function testDevEnablePluginScriptTargetsTachyon(): void
    {
        $script = $this->read('.ddev/webmail-plugins/enable-plugin.sh');

        $this->assertStringContainsString(
            'CONFIG_FILE="/var/lib/tachyon/_data_/_default_/configs/application.ini"',
            $script
        );
        $this->assertStringContainsString('env[WEBMAIL_SSO_SECRET]', $script);
        $this->assertStringNotContainsString('SNAPPYMAIL_SSO_SECRET', $script);
    }

    public function testDevNginxProxiesTheTachyonAssetPrefix(): void
    {
        $nginx = $this->read('.ddev/nginx_full/nginx-site.conf');

        $this->assertStringContainsString('location /tachyon/ {', $nginx);
        $this->assertStringContainsString('proxy_pass http://webmail:8888/tachyon/;', $nginx);
        $this->assertStringContainsString('proxy_pass http://webmail:8888/;', $nginx);
        $this->assertStringNotContainsString('/snappymail/', $nginx);
    }

    public function testProdImageBuildsOnThePinnedTachyonImage(): void
    {
        $dockerfile = $this->read('dist/webmail/Dockerfile');

        $this->assertStringContainsString('FROM ' . self::TACHYON_IMAGE, $dockerfile);
        $this->assertStringNotContainsString('djmaze/snappymail', $dockerfile);
    }

    public function testProdEnablePluginScriptTargetsTachyon(): void
    {
        $script = $this->read('dist/webmail/enable-plugin.sh');

        $this->assertStringContainsString(
            'CONFIG_FILE="/var/lib/tachyon/_data_/_default_/configs/application.ini"',
            $script
        );
        $this->assertStringContainsString(
            'PLUGINS_DIR="/var/lib/tachyon/_data_/_default_/plugins"',
            $script
        );
        $this->assertStringContainsString('env[WEBMAIL_SSO_SECRET]', $script);
    }

    /**
     * Der Volume-Key heißt weiterhin snappymail_data: Compose leitet den
     * physischen Volume-Namen daraus ab, eine Umbenennung würde ein leeres
     * Volume anlegen und Admin-Passwort samt Benutzereinstellungen verlieren.
     * Nur der Mountpfad wandert auf /var/lib/tachyon.
     */
    public function testProdComposeKeepsTheLegacyVolumeKeyButMountsTachyonPath(): void
    {
        $compose = $this->read('dist/docker-compose.prod.yml');

        $this->assertStringContainsString('- snappymail_data:/var/lib/tachyon', $compose);
        $this->assertStringNotContainsString(':/var/lib/snappymail', $compose);
    }

    public function testProdComposeUsesTheNeutralWebmailNames(): void
    {
        $compose = $this->read('dist/docker-compose.prod.yml');

        $this->assertStringContainsString('chormanager-webmail:latest', $compose);
        $this->assertStringContainsString('logs.service: "webmail"', $compose);
        $this->assertStringContainsString('WEBMAIL_SSO_SECRET:', $compose);
        $this->assertStringNotContainsString('SNAPPYMAIL_SSO_SECRET', $compose);
    }

    /**
     * Die beiden Plugin-Kopien müssen byte-identisch bleiben: Ein Sicherheitsfix, der nur in
     * einer der beiden Kopien landet (Dev oder Prod), fällt sonst nicht auf und die jeweils
     * andere Umgebung bleibt verwundbar.
     */
    public function testPluginCopiesStayByteIdentical(): void
    {
        $devPath = '.ddev/webmail-plugins/chormanager-sso/index.php';
        $prodPath = 'dist/webmail/chormanager-sso/index.php';

        $dev = $this->read($devPath);
        $prod = $this->read($prodPath);

        $this->assertSame(
            $dev,
            $prod,
            sprintf('%s und %s sind nicht mehr byte-identisch.', $devPath, $prodPath)
        );
    }
}
