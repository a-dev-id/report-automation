<?php

declare(strict_types=1);

namespace App\Services\Google;

use Closure;
use Throwable;

/** Runs a call and retries it with exponential backoff (base, 2x, 4x...) while $isRetryable says so. */
final class Retrier
{
    private readonly Closure $sleep;

    /** @param  (Closure(int): void)|null  $sleep  receives milliseconds; replaced in tests */
    public function __construct(
        private readonly int $maxAttempts = 4,
        private readonly int $baseDelayMs = 500,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @param  Closure(Throwable): bool  $isRetryable
     * @return T
     */
    public function run(Closure $call, Closure $isRetryable): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (Throwable $e) {
                if ($attempt >= max(1, $this->maxAttempts) || ! $isRetryable($e)) {
                    throw $e;
                }

                ($this->sleep)($this->baseDelayMs * (2 ** ($attempt - 1)));
            }
        }
    }
}
