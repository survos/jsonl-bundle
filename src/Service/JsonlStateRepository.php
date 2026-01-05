<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

use Survos\JsonlBundle\Model\JsonlState;
use Survos\JsonlBundle\Model\JsonlStats;

/**
 * Application-facing JSONL state loader.
 *
 * Uses SidecarService as persistence (sidecar is the canonical progress record),
 * but exposes a stable typed state API for the application.
 */
final class JsonlStateRepository
{
    public function __construct(
        private readonly SidecarService $sidecarService = new SidecarService(),
    ) {
    }

    public function load(string $jsonlPath): JsonlState
    {
        $sidecarPath   = $this->sidecarService->sidecarPath($jsonlPath);
        $sidecarExists = $this->sidecarService->exists($jsonlPath);

        $sc = $this->sidecarService->load($jsonlPath);

        $stats = new JsonlStats(
            rows: (int)($sc->rows ?? 0),
            bytes: (int)($sc->bytes ?? 0),
            startedAt: $sc->startedAt ?? null,
            updatedAt: $sc->updatedAt ?? null,
            completed: (bool)($sc->completed ?? false),
            jsonlMtime: \is_int($sc->jsonl_mtime ?? null) ? $sc->jsonl_mtime : null,
            jsonlSize: \is_int($sc->jsonl_size ?? null) ? $sc->jsonl_size : null,
        );

        return new JsonlState(
            jsonlPath: $jsonlPath,
            sidecarPath: $sidecarPath,
            stats: $stats,
            sidecarExists: $sidecarExists,
        );
    }

    /**
     * Ensure a sidecar exists and captures current file facts, without changing rows/bytes.
     */
    public function ensure(string $jsonlPath): JsonlState
    {
        $this->sidecarService->touch($jsonlPath, rowsDelta: 0, bytesDelta: 0, captureFileFacts: true);
        return $this->load($jsonlPath);
    }
}
