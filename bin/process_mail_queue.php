<?php

declare(strict_types=1);

use App\Commands\ProcessMailQueueCommand;
use App\Util\CliBootstrap;
use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Console\Application;

// Ueber das gemeinsame CLI-Bootstrap laden: es setzt die App-Zeitzone, damit der Worker faellige
// Eintraege gegen dieselbe Uhrzeit prueft, in der die Oberflaeche sie einreiht.
require __DIR__ . '/bootstrap_cli.php';

// CliBootstrap baut den Container und liest dabei die .env ein. Ohne das lief der Worker mit den
// Standardwerten aller Umgebungsvariablen - unter anderem mit DISABLE_MAIL_SEND=true, wodurch er
// jede Mail als "skipped" markierte, statt sie zuzustellen.
$container = CliBootstrap::container();
$container->get(Capsule::class);

$application = new Application('ChorManager Mail Queue');
$application->addCommand($container->get(ProcessMailQueueCommand::class));
$application->setDefaultCommand('mail:process-queue', true);

$application->run();
