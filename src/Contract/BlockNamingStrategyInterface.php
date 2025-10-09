<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract;

/**
 * Generate related paths for a final target (state, block .part files, etc.).
 */
interface BlockNamingStrategyInterface
{
    public function statePath(string $finalPath): string;
    public function partPath(string $finalPath, int $blockIndex): string;
}
