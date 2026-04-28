<?php

declare(strict_types=1);

use App\Util\CliBootstrap;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Util\Timezone;

require __DIR__ . '/bootstrap_cli.php';

date_default_timezone_set(Timezone::resolveAppTimezone());

$logger = CliBootstrap::logger();
$container = CliBootstrap::container();

/** @var Capsule $capsule */
$capsule = $container->get(Capsule::class);
$row = $capsule->getConnection()->selectOne(
    'SELECT @@session.time_zone AS tz, NOW() AS now_local, UTC_TIMESTAMP() AS now_utc, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS offset_minutes'
);

$logger->info(
    'Timezone runtime check completed.',
    [
        'event' => 'timezone.runtime_check.completed',
        'app_tz' => date_default_timezone_get(),
        'db_session_tz' => $row->tz ?? 'unknown',
        'db_now' => $row->now_local ?? 'unknown',
        'db_utc' => $row->now_utc ?? 'unknown',
        'db_offset_minutes' => $row->offset_minutes ?? 'unknown',
    ]
);
