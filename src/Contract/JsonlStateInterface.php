<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract;

interface JsonlStateInterface
{
    public function getJsonlPath(): string;

    public function getSidecarPath(): string;

    public function getStats(): JsonlStatsInterface;

    public function exists(): bool;

    /**
     * Deterministic freshness:
     * - sidecar exists
     * - jsonl exists
     * - sidecar jsonl_mtime/jsonl_size match filesystem
     */
    public function isFresh(): bool;
}
