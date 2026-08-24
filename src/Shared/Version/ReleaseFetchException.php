<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * A check attempt that produced no usable answer. The message is safe to
 * persist: it is built from a fixed vocabulary, never from raw transport text
 * that could carry a token-bearing URL.
 */
final class ReleaseFetchException extends \RuntimeException
{
}
