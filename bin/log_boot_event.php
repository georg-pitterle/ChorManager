<?php

/**
 * Schreibt ein Boot-Ereignis des Container-Entrypoints in den Log-Stream.
 *
 * Aufruf: php bin/log_boot_event.php <event> [exit-code]
 *
 * Bewusst ein eigenes Skript statt eines echo im Entrypoint: die Zeile muss
 * exakt dieselbe JSON-Form haben wie alle uebrigen Logzeilen der Anwendung,
 * sonst faellt sie beim Parsen in Alloy durch und die Alarmregeln greifen nicht.
 */

declare(strict_types=1);

use App\Logging\BootEventLogger;

require_once __DIR__ . '/bootstrap_cli.php';

$event = $argv[1] ?? '';
$exitCode = isset($argv[2]) && $argv[2] !== '' ? (int) $argv[2] : null;

if ($event === '') {
    fwrite(STDERR, sprintf(
        "Aufruf: php bin/log_boot_event.php <event> [exit-code]\nBekannte Ereignisse: %s\n",
        implode(', ', BootEventLogger::events())
    ));
    exit(2);
}

try {
    BootEventLogger::create()->log($event, $exitCode);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, sprintf(
        "%s\nBekannte Ereignisse: %s\n",
        $exception->getMessage(),
        implode(', ', BootEventLogger::events())
    ));
    exit(2);
}

exit(0);
