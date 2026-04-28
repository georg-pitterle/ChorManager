<?php

declare(strict_types=1);

use App\Services\DevSeedService;
use App\Util\CliBootstrap;
use Illuminate\Database\Capsule\Manager as Capsule;

require __DIR__ . '/bootstrap_cli.php';

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

$logger = CliBootstrap::logger();
$container = CliBootstrap::container();
$container->get(Capsule::class);

/** @var DevSeedService $service */
$service = $container->get(DevSeedService::class);

try {
    $report = $service->run($options['mode'], $options['years'], $options['seed']);
    $logger->info(
        'Development seed completed.',
        [
            'event' => 'dev_seed.completed',
            'mode' => $options['mode'],
            'years' => $options['years'],
            'seed' => $options['seed'],
            'report' => $report,
        ]
    );
    exit(0);
} catch (Throwable $e) {
    $logger->error(
        'Development seed failed.',
        [
            'event' => 'dev_seed.failed',
            'mode' => $options['mode'],
            'years' => $options['years'],
            'seed' => $options['seed'],
            'exception' => $e,
        ]
    );
    exit(1);
}
