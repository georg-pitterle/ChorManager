<?php

declare(strict_types=1);

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

class Bootstrap
{
    private static ?Capsule $capsule = null;

    /**
     * Set up the test database connection
     */
    public static function setupTestDatabase(): void
    {
        if (self::$capsule !== null) {
            // Re-assert as global: some tests (e.g. PasswordResetFeatureTest)
            // swap the global Eloquent connection to an in-memory SQLite
            // Capsule for an isolated scenario and never restore it, which
            // would otherwise leak into every test that runs afterward.
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
            return;
        }

        $envPath = dirname(__DIR__) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    /**
     * Get the database capsule instance
     */
    public static function getCapsule(): ?Capsule
    {
        return self::$capsule;
    }
}
