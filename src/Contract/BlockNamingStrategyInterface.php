<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract;

/**
 * Generate related paths for a final target (state, block .part files, etc.).
 * You can add more methods later (e.g., manifest paths).
 */
interface BlockNamingStrategyInterface
{
    /** Path for the sidecar state (e.g., "<final>.state"). */
    public function statePath(string $finalPath): string;

    /** Path for a per-block part (e.g., "<final>.block.<N>.part"). */
    public function partPath(string $finalPath, int $blockIndex): string;
}
