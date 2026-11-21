<?php

declare(strict_types=1);

namespace App\Services\Google;

use GuzzleHttp\Exception\ConnectException;
use Throwable;

/**
 * Network failures worth retrying (timeouts, connection resets, DNS hiccups). Certificate problems are
 * a local setup error that retrying cannot fix, so they are not transient.
 */
final class TransientNetworkError
{
    public static function matches(Throwable $e): bool
    {
        return $e instanceof ConnectException && ! preg_match('/ssl|certificate/i', $e->getMessage());
    }
}
