<?php

declare(strict_types=1);

namespace App\Util;

use RuntimeException;

/**
 * Thrown when an outbound connection target resolves to a non-public address
 * (loopback, private, link-local, or otherwise reserved) and is therefore
 * refused to prevent server-side request forgery (SSRF).
 */
final class BlockedHostException extends RuntimeException
{
}
