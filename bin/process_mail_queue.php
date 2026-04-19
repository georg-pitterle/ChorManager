<?php

declare(strict_types=1);

use App\Commands\ProcessMailQueueCommand;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Console\Application;

require __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$settings = require __DIR__ . '/../src/Settings.php';
$settings($containerBuilder);

$dependencies = require __DIR__ . '/../src/Dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();
$container->get(Capsule::class);

$application = new Application('ChorManager Mail Queue');
$application->addCommand($container->get(ProcessMailQueueCommand::class));
$application->setDefaultCommand('mail:process-queue', true);

$application->run();
