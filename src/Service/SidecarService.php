<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

use Survos\JsonlBundle\Model\JsonlSidecar;

/**
 * Manages JSONL sidecar progress metadata.
 *
 * Stored as JSON next to the target file:
 *   <file>.sidecar.json
 */
final class SidecarService
{
    public function sidecarPath(string $jsonlPath): string
    {
        return $jsonlPath . '.sidecar.json';
    }

    public function exists(string $jsonlPath): bool
    {
        return is_file($this->sidecarPath($jsonlPath));
    }

    public function load(string $jsonlPath): JsonlSidecar
    {
        $path = $this->sidecarPath($jsonlPath);

        if (!is_file($path)) {
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

            return new JsonlSidecar(
                startedAt: $now,
                updatedAt: $now,
            );
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

            return new JsonlSidecar(
                startedAt: $now,
                updatedAt: $now,
            );
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // If corrupted, start fresh rather than breaking pipelines.
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

            return new JsonlSidecar(
                startedAt: $now,
                updatedAt: $now,
            );
        }

        return JsonlSidecar::fromArray($data);
    }

    public function touch(string $jsonlPath, int $rowsDelta = 0, int $bytesDelta = 0): JsonlSidecar
    {
        $sc = $this->load($jsonlPath);

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        if ($sc->startedAt === null) {
            $sc->startedAt = $now;
        }

        $sc->rows += $rowsDelta;
        $sc->bytes += $bytesDelta;
        $sc->updatedAt = $now;

        $this->save($jsonlPath, $sc);

        return $sc;
    }

    public function markComplete(string $jsonlPath): JsonlSidecar
    {
        $sc = $this->load($jsonlPath);

        $sc->completed = true;
        $sc->updatedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        if ($sc->startedAt === null) {
            $sc->startedAt = $sc->updatedAt;
        }

        $this->save($jsonlPath, $sc);

        return $sc;
    }

    public function save(string $jsonlPath, JsonlSidecar $sidecar): void
    {
        $path = $this->sidecarPath($jsonlPath);

        $json = json_encode($sidecar->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode sidecar JSON.');
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        // Atomic replace
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException(sprintf('Failed writing sidecar tmp file "%s".', $tmp));
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Failed atomically replacing sidecar file "%s".', $path));
        }
    }
}
