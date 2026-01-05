<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Model;

use Survos\JsonlBundle\Contract\JsonlStatsInterface;

final readonly class JsonlStats implements JsonlStatsInterface
{
    public function __construct(
        private int $rows = 0,
        private int $bytes = 0,
        private ?string $startedAt = null,
        private ?string $updatedAt = null,
        private bool $completed = false,
        private ?int $jsonlMtime = null,
        private ?int $jsonlSize = null,
    ) {
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    public function getBytes(): int
    {
        return $this->bytes;
    }

    public function getStartedAt(): ?string
    {
        return $this->startedAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function getJsonlMtime(): ?int
    {
        return $this->jsonlMtime;
    }

    public function getJsonlSize(): ?int
    {
        return $this->jsonlSize;
    }
}
