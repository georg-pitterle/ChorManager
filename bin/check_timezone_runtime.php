<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Util\Timezone;

date_default_timezone_set(Timezone::resolveAppTimezone());

$containerBuilder = new ContainerBuilder();
$settings = require __DIR__ . '/../src/Settings.php';
$settings($containerBuilder);
$dependencies = require __DIR__ . '/../src/Dependencies.php';
$dependencies($containerBuilder);
$container = $containerBuilder->build();

/** @var Capsule $capsule */
$capsule = $container->get(Capsule::class);
$row = $capsule->getConnection()->selectOne(
    'SELECT @@session.time_zone AS tz, NOW() AS now_local, UTC_TIMESTAMP() AS now_utc, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS offset_minutes'
);

echo 'app_tz=' . date_default_timezone_get() . PHP_EOL;
echo 'db_session_tz=' . ($row->tz ?? 'unknown') . PHP_EOL;
echo 'db_now=' . ($row->now_local ?? 'unknown') . PHP_EOL;
echo 'db_utc=' . ($row->now_utc ?? 'unknown') . PHP_EOL;
echo 'db_offset_minutes=' . ($row->offset_minutes ?? 'unknown') . PHP_EOL;
