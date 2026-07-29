<?php

declare(strict_types=1);

namespace App\Util;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use LogicException;
use Traversable;

/**
 * Read-only live view onto $_SESSION for use as a Twig global.
 *
 * Passing the raw $_SESSION array to Twig::addGlobal() hands Twig a by-value
 * copy taken at Twig construction time. Any middleware that resolves Twig
 * before the request is authenticated (global middleware runs before the
 * route-level AuthMiddleware) froze that copy in its unauthenticated state, so
 * templates saw no user - layout.twig then dropped the entire navbar while the
 * page content rendered normally. This view resolves every access against the
 * current superglobal instead.
 *
 * @implements ArrayAccess<string,mixed>
 * @implements IteratorAggregate<string,mixed>
 */
final class SessionView implements ArrayAccess, IteratorAggregate, Countable
{
    public function offsetExists(mixed $offset): bool
    {
        return isset($_SESSION[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $_SESSION[$offset] ?? null;
    }

    /**
     * Templates must never mutate the session; writes go through the services
     * that own the session state (least privilege for the view layer).
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('The session view is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('The session view is read-only.');
    }

    public function count(): int
    {
        return count($_SESSION ?? []);
    }

    /**
     * @return Traversable<string,mixed>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($_SESSION ?? []);
    }
}
