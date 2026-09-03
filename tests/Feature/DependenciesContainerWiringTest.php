<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AppSettingController;
use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\FinanceController;
use App\Controllers\PasswordResetController;
use App\Controllers\ProfileController;
use App\Controllers\RoleController;
use App\Controllers\SongLibraryController;
use App\Controllers\SponsorshipController;
use App\Services\EntityAttachmentService;
use App\Controllers\TaskController;
use App\Controllers\UserController;
use App\Logging\DatabaseWriteLogger;
use App\Middleware\CsrfMiddleware;
use App\Middleware\MailBadgeRefreshMiddleware;
use App\Persistence\UserPersistence;
use App\Services\AttachmentAccessRegistry;
use App\Services\BackupService;
use App\Services\Mailer;
use App\Services\MailCredentialCryptoService;
use App\Services\RememberLoginService;
use App\Services\SessionInvalidationService;
use DI\Container;
use DI\ContainerBuilder;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Tests\Unit\Bootstrap;

/**
 * Guards against DI container misregistration: Dependencies.php must resolve
 * every ::class key it registers to the same fully-qualified name the rest
 * of the app (e.g. Middleware.php) requests it under. A missing `use` import
 * in Dependencies.php previously made MailBadgeRefreshMiddleware::class
 * resolve to the bare global-namespace string there, so the container had no
 * definition under the real App\Middleware\MailBadgeRefreshMiddleware key and
 * fell back to broken autowiring on every request.
 *
 * It also guards against the more subtle sibling bug: PHP-DI's reflection
 * autowiring skips OPTIONAL typed constructor parameters entirely (it never
 * autowires them from the container, it always uses the PHP default). A class
 * with `?LoggerInterface $logger = null` and no explicit factory here silently
 * gets a NullLogger in production even though every unit test that constructs
 * it manually passes a real logger and stays green. `instanceof` alone would
 * not catch this - the classes below are asserted to actually hold a real
 * Monolog\Logger instance, not just to resolve to the right class.
 */
final class DependenciesContainerWiringTest extends TestCase
{
    /**
     * Builds the real DI container from Settings.php + Dependencies.php,
     * exactly as public/index.php does for a real request. A real DB
     * connection is booted first: some factories (e.g. Twig::class) read
     * AppSetting rows during construction, and without a global Eloquent
     * connection that fails with an unrelated "connection() on null" error
     * that has nothing to do with the logger wiring under test here.
     */
    private function buildContainer(): Container
    {
        Bootstrap::setupTestDatabase();

        $containerBuilder = new ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        return $containerBuilder->build();
    }

    /**
     * Reflection is the only honest way to test this: the point is not that
     * the object resolves, but that its private $logger property was actually
     * populated with the real logger the container built, not a NullLogger
     * fallback silently swallowed at construction time.
     */
    private function loggerPropertyOf(object $object): LoggerInterface
    {
        $reflection = new ReflectionClass($object);
        $property = $reflection->getProperty('logger');

        /** @var LoggerInterface $logger */
        $logger = $property->getValue($object);

        return $logger;
    }

    /**
     * Generic private-property reader for nested wiring checks (e.g. the Mailer
     * instance held inside PasswordResetController).
     */
    private function propertyOf(object $object, string $name): mixed
    {
        $reflection = new ReflectionClass($object);
        $property = $reflection->getProperty($name);

        return $property->getValue($object);
    }

    /**
     * The other pre-existing instance of the same PHP-DI trap: Mailer had
     * `?LoggerInterface $logger = null` and Dependencies.php wired it with plain
     * \DI\autowire(), so mail.send.skipped/.success/.failed never reached the
     * log stream from a container-built Mailer.
     */
    public function testMailerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $mailer = $container->get(Mailer::class);

        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($mailer));
    }

    /**
     * Same trap on MailCredentialCryptoService: mail_credential.decrypt.failed
     * never reached the log stream from a container-built instance. A random
     * valid key is provided for the duration of this test only, since the
     * constructor throws without a configured MAIL_CREDENTIAL_KEY and the
     * point here is the logger wiring, not key management.
     */
    public function testMailCredentialCryptoServiceResolvesWithRealLogger(): void
    {
        $originalKey = $_ENV['MAIL_CREDENTIAL_KEY'] ?? null;
        $_ENV['MAIL_CREDENTIAL_KEY'] = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        try {
            $container = $this->buildContainer();

            $service = $container->get(MailCredentialCryptoService::class);

            $this->assertInstanceOf(MailCredentialCryptoService::class, $service);
            $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($service));
        } finally {
            if ($originalKey === null) {
                unset($_ENV['MAIL_CREDENTIAL_KEY']);
            } else {
                $_ENV['MAIL_CREDENTIAL_KEY'] = $originalKey;
            }
        }
    }

    /**
     * PasswordResetController's own Dependencies.php factory passed a literal
     * `null` for its optional $mailer parameter, so the controller fell back to
     * `new Mailer()` internally - bypassing the container-built Mailer entirely
     * and keeping a NullLogger there even after Mailer::class itself gained a
     * real factory.
     */
    public function testPasswordResetControllerMailerHoldsRealLogger(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(PasswordResetController::class);
        $mailer = $this->propertyOf($controller, 'mailer');

        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($mailer));
    }

    public function testMailBadgeRefreshMiddlewareResolvesFromContainer(): void
    {
        $container = $this->buildContainer();

        $middleware = $container->get(MailBadgeRefreshMiddleware::class);

        $this->assertInstanceOf(MailBadgeRefreshMiddleware::class, $middleware);
    }

    public function testCsrfMiddlewareResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $middleware = $container->get(CsrfMiddleware::class);

        $this->assertInstanceOf(CsrfMiddleware::class, $middleware);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($middleware));
    }

    public function testSongLibraryControllerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(SongLibraryController::class);

        $this->assertInstanceOf(SongLibraryController::class, $controller);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($controller));
    }

    public function testPasswordResetControllerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(PasswordResetController::class);

        $this->assertInstanceOf(PasswordResetController::class, $controller);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($controller));
    }

    public function testRoleControllerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(RoleController::class);

        $this->assertInstanceOf(RoleController::class, $controller);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($controller));
    }

    /**
     * The defect this whole file exists to catch: RememberLoginService had
     * `?LoggerInterface $logger = null` and no Dependencies.php entry, so
     * every production instance silently ran with a NullLogger and the
     * auth.remember_me.used/.rejected events never reached the log stream.
     */
    public function testRememberLoginServiceResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $service = $container->get(RememberLoginService::class);

        $this->assertInstanceOf(RememberLoginService::class, $service);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($service));
    }

    public function testAppSettingControllerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(AppSettingController::class);

        $this->assertInstanceOf(AppSettingController::class, $controller);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($controller));
    }

    public function testAuthControllerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(AuthController::class);

        $this->assertInstanceOf(AuthController::class, $controller);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($controller));
    }

    public function testFinanceControllerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(FinanceController::class);

        $this->assertInstanceOf(FinanceController::class, $controller);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($controller));
    }

    /**
     * BackupService baut den SessionInvalidationService notfalls selbst (Vorgabewert
     * im Konstruktor), damit die bestehenden Aufrufstellen mit neun Argumenten
     * gültig bleiben. Genau das kann eine fehlende Container-Verdrahtung verdecken:
     * Die Sperre liefe dann still mit einem NullLogger statt mit dem echten.
     */
    public function testBackupServiceResolvesWithTheContainerBuiltSessionInvalidation(): void
    {
        $container = $this->buildContainer();

        $service = $container->get(BackupService::class);
        $this->assertInstanceOf(BackupService::class, $service);

        $sessionInvalidation = $this->propertyOf($service, 'sessionInvalidation');
        $this->assertInstanceOf(SessionInvalidationService::class, $sessionInvalidation);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($sessionInvalidation));
    }

    public function testProfileControllerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(ProfileController::class);

        $this->assertInstanceOf(ProfileController::class, $controller);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($controller));
    }

    public function testSponsorshipControllerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(SponsorshipController::class);

        $this->assertInstanceOf(SponsorshipController::class, $controller);

        // Protokolliert wird seit dem gemeinsamen Anhang-Dienst dort, nicht mehr
        // im Controller: eine abgelehnte Datei muss weiterhin im echten Log
        // landen und nicht in einem NullLogger.
        $this->assertInstanceOf(
            Logger::class,
            $this->loggerPropertyOf($container->get(EntityAttachmentService::class))
        );
    }

    public function testTaskControllerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(TaskController::class);

        $this->assertInstanceOf(TaskController::class, $controller);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($controller));
    }

    public function testUserControllerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(UserController::class);

        $this->assertInstanceOf(UserController::class, $controller);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($controller));
    }

    public function testUserPersistenceResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $persistence = $container->get(UserPersistence::class);

        $this->assertInstanceOf(UserPersistence::class, $persistence);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($persistence));
    }

    public function testDatabaseWriteLoggerResolvesWithRealLogger(): void
    {
        $container = $this->buildContainer();

        $writeLogger = $container->get(DatabaseWriteLogger::class);

        $this->assertInstanceOf(DatabaseWriteLogger::class, $writeLogger);
        $this->assertInstanceOf(Logger::class, $this->loggerPropertyOf($writeLogger));
    }

    /**
     * Die zentrale Anhang-Route resolvt ihre beiden Konstruktor-Parameter
     * (AttachmentAccessRegistry, AttachmentResponseFactory) korrekt aus dem
     * Container. Der bisherige Rauchtest der Route (HTTP 302, kein 500) hatte
     * das nicht bewiesen: das 302 stammt aus AuthMiddleware, die vor der
     * eigentlichen Auflösung des Routen-Callables aus dem Container läuft -
     * ein kaputter Eintrag hier hätte dieselbe Antwort ergeben.
     */
    public function testAttachmentControllerResolvesFromContainer(): void
    {
        $container = $this->buildContainer();

        $controller = $container->get(AttachmentController::class);

        $this->assertInstanceOf(AttachmentController::class, $controller);
    }

    /**
     * Die Registry braucht ihr drittes Konstruktor-Argument (das
     * Modul-Array aus den Settings) über eine eigene Fabrik statt reinem
     * Autowiring - derselbe Grund wie oben gilt auch hier.
     */
    public function testAttachmentAccessRegistryResolvesFromContainer(): void
    {
        $container = $this->buildContainer();

        $registry = $container->get(AttachmentAccessRegistry::class);

        $this->assertInstanceOf(AttachmentAccessRegistry::class, $registry);

        // instanceof allein traegt hier nicht: ein Tippfehler in der Fabrik
        // (settings['module'] statt settings['modules']) haette der Registry ein
        // leeres Array gereicht. Sie haette dann in der laufenden Anwendung
        // stillschweigend JEDEN Finanz-, Aufgaben- und Sponsoring-Anhang
        // gesperrt, und keine Zeile der Suite waere rot geworden - die uebrigen
        // Registry-Tests bauen sie mit einem eigenen Modul-Array.
        $modules = $this->propertyOf($registry, 'modules');
        $this->assertIsArray($modules);
        foreach (['finance', 'sponsoring', 'tasks'] as $module) {
            $this->assertArrayHasKey($module, $modules, $module . ' fehlt im Modul-Array der Registry');
        }
    }
}
