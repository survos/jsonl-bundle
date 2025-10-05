<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Pagination;

/** Where to resume; block index for offset mode, or cursor token for cursor mode. */
final class ResumePoint
{
    public function __construct(
        public readonly int $nextBlock = 0,
        public readonly ?string $cursor = null
    ) {}
}
