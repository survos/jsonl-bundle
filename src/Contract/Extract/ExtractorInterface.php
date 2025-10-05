<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Extract;

/** Extract the items array from a decoded JSON response. */
interface ExtractorInterface
{
    /** @return array<int,mixed> items suitable for writing one-per-line */
    public function items(array $decoded): array;
}
