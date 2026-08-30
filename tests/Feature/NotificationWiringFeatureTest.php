<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AppSettingController;
use App\Controllers\EventController;
use App\Controllers\ProfileController;
use App\Controllers\ProjectController;
use App\Controllers\TaskController;
use App\Services\NotificationService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Der Benachrichtigungsdienst steht in fuenf Controllern als letzter,
 * **optionaler** Parameter - er musste ans Ende, weil zahlreiche Tests diese
 * Controller mit festen Positionsargumenten bauen und ein Parameter in der
 * Mitte sie stumm verschoben hätte.
 *
 * Optional heißt aber: PHP-DI füllt ihn nicht von selbst. Fiele die
 * ausdrückliche Registrierung in `Dependencies.php` weg, liefe der Betrieb
 * weiter - nur ohne eine einzige Benachrichtigung, ohne Fehler und ohne dass
 * ein anderer Test anschlüge. Genau diese Lücke schließt dieser Test.
 *
 * @return array<string, array{0: class-string}>
 */
final class NotificationWiringFeatureTest extends TestCase
{
    private const CRYPTO_ENV_KEY = 'MAIL_CREDENTIAL_KEY';

    private ?string $originalCryptoKey = null;
    private bool $hadCryptoKey = false;

    /**
     * Der ProfileController zieht ueber den Container den Krypto-Dienst mit, und
     * der besteht ohne Schluessel nicht. Hier geht es nicht um Verschluesselung,
     * also reicht ein Wegwerf-Schluessel fuer die Dauer des Tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->hadCryptoKey = array_key_exists(self::CRYPTO_ENV_KEY, $_ENV);
        $this->originalCryptoKey = $_ENV[self::CRYPTO_ENV_KEY] ?? null;

        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV[self::CRYPTO_ENV_KEY] = $key;
        $_SERVER[self::CRYPTO_ENV_KEY] = $key;
        putenv(self::CRYPTO_ENV_KEY . '=' . $key);
    }

    protected function tearDown(): void
    {
        if ($this->hadCryptoKey && $this->originalCryptoKey !== null) {
            $_ENV[self::CRYPTO_ENV_KEY] = $this->originalCryptoKey;
            $_SERVER[self::CRYPTO_ENV_KEY] = $this->originalCryptoKey;
            putenv(self::CRYPTO_ENV_KEY . '=' . $this->originalCryptoKey);
        } else {
            unset($_ENV[self::CRYPTO_ENV_KEY], $_SERVER[self::CRYPTO_ENV_KEY]);
            putenv(self::CRYPTO_ENV_KEY);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function controllers(): array
    {
        return [
            'TaskController' => [TaskController::class],
            'EventController' => [EventController::class],
            'ProjectController' => [ProjectController::class],
            'ProfileController' => [ProfileController::class],
            'AppSettingController' => [AppSettingController::class],
        ];
    }

    /**
     * @param class-string $controllerClass
     */
    #[DataProvider('controllers')]
    public function testTheContainerHandsTheControllerItsNotificationService(string $controllerClass): void
    {
        $controller = $this->buildContainerController($controllerClass);

        $property = new ReflectionProperty($controllerClass, 'notificationService');
        $wired = $property->getValue($controller);

        $this->assertInstanceOf(
            NotificationService::class,
            $wired,
            $controllerClass . ' bekommt keinen Benachrichtigungsdienst - '
                . 'die Registrierung in Dependencies.php fehlt.'
        );
    }

    private function buildContainerController(string $controllerClass): object
    {
        $containerBuilder = new ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        $container = $containerBuilder->build();
        $container->get(Capsule::class);

        return $container->get($controllerClass);
    }
}
