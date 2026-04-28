<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class AppLoggerFactory
{
    /**
     * @param array<string, mixed> $settings
     */
    public static function create(array $settings = []): LoggerInterface
    {
        $channel = self::stringSetting($settings, 'channel', 'chormanager');
        $stream = self::stringSetting($settings, 'stream', 'php://stderr');
        $service = self::stringSetting($settings, 'service', 'chormanager');
        $environment = self::stringSetting($settings, 'environment', 'production');

        $logger = new Logger($channel);
        $handler = new StreamHandler($stream, self::resolveLevel($settings));

        $formatter = new JsonFormatter();
        $formatter->includeStacktraces(true);
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        $logger->pushProcessor(
            static function (LogRecord $record) use ($service, $environment): LogRecord {
                $extra = $record->extra;
                $extra['service'] = $service;
                $extra['env'] = $environment;

                return $record->with(extra: $extra);
            }
        );

        return $logger;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function resolveLevel(array $settings): Level
    {
        $default = 'INFO';
        $levelName = strtoupper(self::stringSetting($settings, 'level', $default));

        try {
            return Level::fromName($levelName);
        } catch (\Throwable) {
            return Level::Info;
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function stringSetting(array $settings, string $key, string $default): string
    {
        $value = $settings[$key] ?? $default;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
