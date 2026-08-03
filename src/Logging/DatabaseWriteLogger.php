<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\QueryExecuted;
use Monolog\Level;
use Psr\Log\LoggerInterface;

/**
 * Protokolliert schreibende SQL-Statements, wenn der Schalter in den
 * Einstellungen aktiv ist.
 *
 * Bindings werden bewusst verworfen: Sie enthalten Passwort-Hashes und
 * verschluesselte Zugangsdaten. Statements auf app_settings werden uebersprungen,
 * weil der Resolver seine Werte von dort liest und die Protokollierung sich sonst
 * selbst aufruft.
 */
final class DatabaseWriteLogger
{
    private const WRITE_STATEMENT = '/^(insert|update|delete|replace|truncate|create|alter|drop)\b/i';

    private bool $handling = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LogLevelResolver $resolver
    ) {
    }

    public function register(Capsule $capsule): void
    {
        $capsule->getConnection()->listen(function (QueryExecuted $query): void {
            $this->handle($query->sql, (float) $query->time);
        });
    }

    public function handle(string $sql, float $timeMs): void
    {
        if ($this->handling) {
            return;
        }

        $statement = ltrim($sql);

        if (preg_match(self::WRITE_STATEMENT, $statement) !== 1) {
            return;
        }

        if (stripos($statement, 'app_settings') !== false) {
            return;
        }

        if (!$this->resolver->isDbWriteLoggingEnabled()) {
            return;
        }

        // db.write is emitted at DEBUG. With the flag on but the configured level
        // stricter than DEBUG (e.g. ERROR), Monolog would drop the record anyway -
        // this check skips building the context array (and the regex/table lookup
        // above already ran, so it stays cheap) for a record nobody will see.
        // resolver->level() re-reads the same cached settings() as
        // isDbWriteLoggingEnabled() just above, so this costs no extra DB access.
        if ($this->resolver->level()->value > Level::Debug->value) {
            return;
        }

        $this->handling = true;

        try {
            $this->logger->debug('Database write executed.', [
                'event' => 'db.write',
                'statement' => $statement,
                'table' => self::tableFromStatement($statement),
                'duration_ms' => $timeMs,
            ]);
        } finally {
            $this->handling = false;
        }
    }

    private static function tableFromStatement(string $statement): ?string
    {
        if (preg_match('/(?:into|update|from|table)\s+[`"]?([a-z0-9_]+)[`"]?/i', $statement, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
