<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Fetch;

/** Immutable options for concurrent fetching. */
final class FetchOptions
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $concurrency = 8,
        public readonly int $retries = 3,
        public readonly float $timeout = 60.0,
        public readonly array $headers = [],
    ) {}
}
