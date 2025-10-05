<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Fetch;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Bounded-concurrency HTTP fetcher.
 */
interface ConcurrentFetcherInterface
{
    /**
     * @param iterable<string> $urls
     * @param callable $onSuccess fn(string $url, string $body, ResponseInterface $resp): void
     * @param callable|null $onError fn(string $url, \Throwable $e, ?ResponseInterface $resp): void
     */
    public function fetchMany(
        iterable $urls,
        FetchOptions $options,
        callable $onSuccess,
        ?callable $onError = null
    ): void;
}
