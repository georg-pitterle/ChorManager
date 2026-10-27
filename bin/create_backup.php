<?php

declare(strict_types=1);

use App\Commands\CreateBackupCommand;
use App\Util\RootPrivilegeDrop;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Console\Application;

require __DIR__ . '/../vendor/autoload.php';

// Als root (docker exec ohne -u) gehörte alles, was der Befehl anlegt, root - die
// Web-App konnte den Datei-Pool danach nicht mehr lesen. Deshalb vor allem anderen
// auf den Benutzer von PHP-FPM wechseln.
RootPrivilegeDrop::enforceForCli();

$containerBuilder = new ContainerBuilder();
$settings = require __DIR__ . '/../src/Settings.php';
$settings($containerBuilder);

$dependencies = require __DIR__ . '/../src/Dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();
$container->get(Capsule::class);

$application = new Application('ChorManager Backup');
$application->addCommand($container->get(CreateBackupCommand::class));
$application->setDefaultCommand('backup:create', true);

$application->run();
