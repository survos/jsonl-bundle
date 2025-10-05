<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Fetch;

interface RetryStrategyInterface
{
    /** Decide if a retry should occur for the given attempt (1-based). */
    public function shouldRetry(int $attempt, ?int $statusCode, ?\Throwable $error): bool;

    /** Milliseconds to wait before the next attempt (backoff with jitter, etc.). */
    public function backoffDelayMs(int $attempt, ?int $statusCode, ?\Throwable $error): int;
}
