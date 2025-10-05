<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Pagination;

/** Result of an initial probe, e.g., total hits or first cursor token. */
final class ProbeResult
{
    public function __construct(
        public readonly int $total,           // -1 if unknown
        public readonly array $meta = [],     // extra fields (e.g., first cursor)
    ) {}
}
