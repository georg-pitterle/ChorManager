<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\ExceptionLogContext;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Illuminate\Database\QueryException::formatMessage() appends the SQL bindings
 * to getMessage(). A log call with 'exception' => $e therefore puts bindings -
 * bcrypt hashes, encrypted IMAP credentials - into the log for any QueryException.
 * ExceptionLogContext::build() must replace the exception with a sanitised
 * representation for that one exception type, while leaving the existing
 * 'exception' => $e convention untouched for everything else.
 */
final class ExceptionLogContextTest extends TestCase
{
    private const RECOGNIZABLE_SECRET = '$2y$10$RecognizableSecretBcryptHashValueXXXXXXXXXXXXXXXXXXXXX';

    public function testQueryExceptionIsReplacedBySanitizedFieldsWithoutBindings(): void
    {
        $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $queryException = new QueryException(
            'mysql',
            'insert into `users` (`email`, `password`) values (?, ?)',
            ['admin@example.test', self::RECOGNIZABLE_SECRET],
            $previous
        );

        $context = ExceptionLogContext::build($queryException);

        $this->assertArrayNotHasKey('exception', $context);
        $this->assertSame(QueryException::class, $context['exception_class']);
        $this->assertSame(
            'insert into `users` (`email`, `password`) values (?, ?)',
            $context['sql']
        );

        $encoded = (string) json_encode($context);
        $this->assertStringNotContainsString(self::RECOGNIZABLE_SECRET, $encoded);
        $this->assertStringNotContainsString('admin@example.test', $encoded);
    }

    public function testQueryExceptionMessageItselfWouldHaveLeakedTheSecret(): void
    {
        // Sanity check that this test fixture actually reproduces the real bug:
        // Laravel's own getMessage() must contain the secret, otherwise the test
        // above would not be exercising the vulnerable path at all.
        $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $queryException = new QueryException(
            'mysql',
            'insert into `users` (`email`, `password`) values (?, ?)',
            ['admin@example.test', self::RECOGNIZABLE_SECRET],
            $previous
        );

        $this->assertStringContainsString(self::RECOGNIZABLE_SECRET, $queryException->getMessage());
    }

    public function testWrappedQueryExceptionIsAlsoSanitized(): void
    {
        // Monolog's normalizer walks getPrevious() and emits each message, so a
        // QueryException wrapped in another exception would leak its bindings
        // again through the chain.
        $queryException = new QueryException(
            'mysql',
            'insert into `users` (`email`, `password`) values (?, ?)',
            ['admin@example.test', self::RECOGNIZABLE_SECRET],
            new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry')
        );
        $wrapper = new RuntimeException('Could not create the user.', 0, $queryException);

        $context = ExceptionLogContext::build($wrapper);

        $this->assertArrayNotHasKey('exception', $context);
        $this->assertSame(QueryException::class, $context['exception_class']);

        $encoded = (string) json_encode($context);
        $this->assertStringNotContainsString(self::RECOGNIZABLE_SECRET, $encoded);
    }

    public function testNonQueryExceptionKeepsTheExistingExceptionKeyConvention(): void
    {
        $exception = new RuntimeException('Something else failed.');

        $context = ExceptionLogContext::build($exception);

        $this->assertSame(['exception' => $exception], $context);
    }
}
