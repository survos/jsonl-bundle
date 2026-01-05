<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Model;

use Survos\JsonlBundle\Contract\JsonlStateInterface;
use Survos\JsonlBundle\Contract\JsonlStatsInterface;

final readonly class JsonlState implements JsonlStateInterface
{
    public function __construct(
        private string $jsonlPath,
        private string $sidecarPath,
        private JsonlStatsInterface $stats,
        private bool $sidecarExists,
    ) {
    }

    public function getJsonlPath(): string
    {
        return $this->jsonlPath;
    }

    public function getSidecarPath(): string
    {
        return $this->sidecarPath;
    }

    public function getStats(): JsonlStatsInterface
    {
        return $this->stats;
    }

    public function exists(): bool
    {
        return \is_file($this->jsonlPath);
    }

    public function isFresh(): bool
    {
        if (!$this->sidecarExists || !\is_file($this->jsonlPath)) {
            return false;
        }

        $mtime = @\filemtime($this->jsonlPath);
        $size  = @\filesize($this->jsonlPath);

        if (!\is_int($mtime) || !\is_int($size)) {
            return false;
        }

        $sMtime = $this->stats->getJsonlMtime();
        $sSize  = $this->stats->getJsonlSize();

        // If the sidecar hasn't captured file facts yet, it's not fresh.
        if ($sMtime === null || $sSize === null) {
            return false;
        }

        return $sMtime === $mtime && $sSize === $size;
    }
}
