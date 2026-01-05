<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract;

interface JsonlStatsInterface
{
    public function getRows(): int;

    public function getBytes(): int;

    /**
     * ATOM timestamp when writing started (from sidecar).
     */
    public function getStartedAt(): ?string;

    /**
     * ATOM timestamp when last updated (from sidecar).
     */
    public function getUpdatedAt(): ?string;

    public function isCompleted(): bool;

    /**
     * Captured JSONL file facts stored in sidecar (used for deterministic freshness checks).
     */
    public function getJsonlMtime(): ?int;

    public function getJsonlSize(): ?int;
}
