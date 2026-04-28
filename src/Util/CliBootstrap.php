<?php

declare(strict_types=1);

namespace App\Util;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class CliBootstrap
{
    private static ?ContainerInterface $container = null;

    public static function container(): ContainerInterface
    {
        if (self::$container instanceof ContainerInterface) {
            return self::$container;
        }

        $rootPath = dirname(__DIR__, 2);
        $envPath = $rootPath . '/.env';
        if (is_file($envPath)) {
            Dotenv::createImmutable($rootPath)->safeLoad();
        }

        $containerBuilder = new ContainerBuilder();
        $settings = require $rootPath . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require $rootPath . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        self::$container = $containerBuilder->build();

        return self::$container;
    }

    public static function logger(): LoggerInterface
    {
        return self::container()->get(LoggerInterface::class);
    }
}
