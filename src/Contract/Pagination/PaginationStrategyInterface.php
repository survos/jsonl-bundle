<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Pagination;

/**
 * Provides a sequence of "work units" (e.g., offset blocks) from a probe + resume.
 * Implementations: OffsetPagination, PagePagination, CursorPagination.
 */
interface PaginationStrategyInterface
{
    /**
     * @return \Traversable<array> Each element describes a unit (e.g., ['block'=>int, 'query'=>array])
     */
    public function plan(ProbeResult $probe, ResumePoint $resume): \Traversable;
}
