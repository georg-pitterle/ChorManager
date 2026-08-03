<?php

declare(strict_types=1);

namespace Tests\Feature;

use DI\Container;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Guards the order of setEventDispatcher() vs. bootEloquent() in the
 * Capsule::class factory in Dependencies.php.
 *
 * Capsule::bootEloquent() reads getEventDispatcher() and, if a dispatcher is
 * already set at that point, forwards it to Eloquent Model::setEventDispatcher(),
 * which arms the whole Eloquent model lifecycle (creating/created/saving/saved/
 * deleting/deleted/retrieved, observers, $dispatchesEvents) project-wide. This
 * feature (DatabaseWriteLogger) only needs Connection-level QueryExecuted
 * events, not Eloquent model events, so the dispatcher must be set AFTER
 * bootEloquent(): that keeps Connection::listen() working while leaving
 * Model::getEventDispatcher() null.
 */
final class CapsuleEventDispatcherOrderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Static state on Eloquent\Model, shared process-wide across
        // PHPUnit tests - start every run from a clean slate so a prior
        // test cannot mask a regression here (and so this test cannot
        // leak a false-positive into a test that runs after it).
        Model::unsetEventDispatcher();
    }

    protected function tearDown(): void
    {
        Model::unsetEventDispatcher();

        parent::tearDown();
    }

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

    public function testEloquentModelEventsStayOff(): void
    {
        $container = $this->buildContainer();
        $container->get(Capsule::class);

        $this->assertNull(
            Model::getEventDispatcher(),
            'Eloquent model lifecycle events must stay off - only query events are needed here.'
        );
    }

    public function testQueryEventsStillFireForDatabaseWriteLogger(): void
    {
        $container = $this->buildContainer();
        /** @var Capsule $capsule */
        $capsule = $container->get(Capsule::class);

        $fired = false;
        $capsule->getConnection()->listen(function (QueryExecuted $event) use (&$fired): void {
            $fired = true;
        });

        $capsule->getConnection()->select('SELECT 1');

        $this->assertTrue(
            $fired,
            'Connection::listen() must still fire QueryExecuted - DatabaseWriteLogger depends on it.'
        );
    }
}
