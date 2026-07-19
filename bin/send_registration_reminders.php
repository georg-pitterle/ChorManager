<?php

declare(strict_types=1);

use App\Commands\SendRegistrationRemindersCommand;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Console\Application;

require __DIR__ . '/../vendor/autoload.php';

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

$containerBuilder = new ContainerBuilder();
$settings = require __DIR__ . '/../src/Settings.php';
$settings($containerBuilder);

$dependencies = require __DIR__ . '/../src/Dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();
$container->get(Capsule::class);

$application = new Application('ChorManager Registration Reminders');
$application->addCommand($container->get(SendRegistrationRemindersCommand::class));
$application->setDefaultCommand('registration:send-reminders', true);

$application->run();
