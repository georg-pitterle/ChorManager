<?php

declare(strict_types=1);

use App\Services\DevSeedService;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

require __DIR__ . '/../vendor/autoload.php';

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

$options = [
    'mode' => 'append',
    'years' => 3,
    'seed' => 20260321,
];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--mode=')) {
        $options['mode'] = (string) substr($arg, 7);
    }

    if (str_starts_with($arg, '--years=')) {
        $options['years'] = (int) substr($arg, 8);
    }

    if (str_starts_with($arg, '--seed=')) {
        $options['seed'] = (int) substr($arg, 7);
    }
}

$containerBuilder = new ContainerBuilder();
$settings = require __DIR__ . '/../src/Settings.php';
$settings($containerBuilder);

$dependencies = require __DIR__ . '/../src/Dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();
$container->get(Capsule::class);

/** @var DevSeedService $service */
$service = $container->get(DevSeedService::class);

try {
    $report = $service->run($options['mode'], $options['years'], $options['seed']);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
