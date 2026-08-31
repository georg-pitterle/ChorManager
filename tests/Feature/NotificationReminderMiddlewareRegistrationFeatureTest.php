<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\NotificationReminderMiddleware;
use App\Middleware\RegistrationReminderMiddleware;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Die Benachrichtigungs-Erinnerungen hängen an Aufgaben und Sponsoring-Wiedervorlagen,
 * nicht an der Anmeldung zu Terminen. Sie dürfen deshalb nicht am Modul "registration"
 * hängen: Dieses Modul ist standardmäßig aus, und die Middleware wurde damit in der
 * Voreinstellung gar nicht registriert - fällige Aufgaben- und Wiedervorlage-Mails
 * blieben ohne eigenen Cron-Lauf lautlos liegen.
 *
 * Welche Anlässe tatsächlich versendet werden, entscheidet weiterhin
 * `NotificationService::isAvailable()` je Anlass anhand des zugehörigen Moduls.
 */
final class NotificationReminderMiddlewareRegistrationFeatureTest extends TestCase
{
    public function testNotificationRemindersAreRegisteredWithoutTheRegistrationModule(): void
    {
        $registered = $this->registeredMiddlewareNames(false);

        $this->assertContains(NotificationReminderMiddleware::class, $registered);
    }

    public function testNotificationRemindersAreRegisteredWithTheRegistrationModule(): void
    {
        $registered = $this->registeredMiddlewareNames(true);

        $this->assertContains(NotificationReminderMiddleware::class, $registered);
    }

    public function testRegistrationRemindersStayBoundToTheRegistrationModule(): void
    {
        $this->assertNotContains(
            RegistrationReminderMiddleware::class,
            $this->registeredMiddlewareNames(false)
        );
        $this->assertContains(
            RegistrationReminderMiddleware::class,
            $this->registeredMiddlewareNames(true)
        );
    }

    /**
     * Führt die Middleware-Konfiguration gegen eine App aus, die jedes `add()` nur
     * mitschreibt, statt es aufzulösen. So bleibt die Prüfung auf der Registrierung
     * und braucht weder Datenbank noch Twig.
     *
     * @return list<string>
     */
    private function registeredMiddlewareNames(bool $registrationEnabled): array
    {
        $container = new Container();
        $container->set('settings', ['modules' => ['registration' => $registrationEnabled]]);

        $app = new class (new ResponseFactory(), $container) extends App {
            /** @var list<mixed> */
            public array $addedMiddleware = [];

            public function add($middleware): App
            {
                $this->addedMiddleware[] = $middleware;

                return $this;
            }
        };

        $configureMiddleware = require dirname(__DIR__, 2) . '/src/Middleware.php';
        $configureMiddleware($app);

        return array_values(array_filter($app->addedMiddleware, 'is_string'));
    }
}
