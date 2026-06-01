<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

use Survos\JsonlBundle\Model\JsonlSidecar;

/**
 * @deprecated Use JsonlStateService. Kept for compatibility with older callers
 * that work directly with raw sidecar payloads.
 */
final class SidecarService
{
    private JsonlStateService $state;

    public function __construct(?JsonlStateService $state = null)
    {
        $this->state = $state ?? new JsonlStateService();
    }

    public function sidecarPath(string $jsonlPath): string
    {
        return $this->state->sidecarPath($jsonlPath);
    }

    public function exists(string $jsonlPath): bool
    {
        return $this->state->exists($jsonlPath);
    }

    public function load(string $jsonlPath): JsonlSidecar
    {
        return $this->state->loadSidecar($jsonlPath);
    }

    /** @param array<string,mixed> $context */
    public function touch(string $jsonlPath, int $rowsDelta = 0, int $bytesDelta = 0, bool $captureFileFacts = true, array $context = []): JsonlSidecar
    {
        return $this->state->touch($jsonlPath, $rowsDelta, $bytesDelta, $captureFileFacts, $context);
    }

    public function markComplete(string $jsonlPath, bool $captureFileFacts = true): JsonlSidecar
    {
        return $this->state->markComplete($jsonlPath, $captureFileFacts);
    }

    public function save(string $jsonlPath, JsonlSidecar $sidecar): void
    {
        $this->state->saveSidecar($jsonlPath, $sidecar);
    }
}
