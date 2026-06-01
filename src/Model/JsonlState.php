<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Model;

use Survos\JsonlBundle\Contract\JsonlStateInterface;
use Survos\JsonlBundle\Contract\JsonlStatsInterface;

final readonly class JsonlState implements JsonlStateInterface
{
    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        private string $jsonlPath,
        private string $sidecarPath,
        private JsonlStatsInterface $stats,
        private bool $sidecarExists,
        private array $context = [],
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

    /** @return array<string,mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function context(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
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

        if ($sMtime === null || $sSize === null) {
            return false;
        }

        return $sMtime === $mtime && $sSize === $size;
    }
}
