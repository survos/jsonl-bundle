<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Path;

use Survos\JsonlBundle\Contract\BlockNamingStrategyInterface;

final class SimpleBlockNamingStrategy implements BlockNamingStrategyInterface
{
    public function statePath(string $finalPath): string
    {
        return $finalPath . '.state';
    }

    public function partPath(string $finalPath, int $blockIndex): string
    {
        return sprintf('%s.block.%d.part', $finalPath, $blockIndex);
    }
}
