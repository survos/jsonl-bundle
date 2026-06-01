<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

use Survos\JsonlBundle\Model\JsonlSidecar;
use Survos\JsonlBundle\Model\JsonlState;
use Survos\JsonlBundle\Model\JsonlStats;
use Survos\JsonlBundle\Util\Jsonl;

/**
 * Public state API for JSONL artifacts.
 *
 * The default storage is a sidecar JSON file next to the artifact, but callers
 * should treat that as an implementation detail. Future stores can persist the
 * same state in dataset-bundle, Redis, or elsewhere.
 */
class JsonlStateService
{
    public function sidecarPath(string $jsonlPath): string
    {
        return $jsonlPath . '.sidecar.json';
    }

    public function exists(string $jsonlPath): bool
    {
        return is_file($this->sidecarPath($jsonlPath));
    }

    public function loadSidecar(string $jsonlPath): JsonlSidecar
    {
        $path = $this->sidecarPath($jsonlPath);

        if (!is_file($path)) {
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            return new JsonlSidecar(startedAt: $now, updatedAt: $now);
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            return new JsonlSidecar(startedAt: $now, updatedAt: $now);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            return new JsonlSidecar(startedAt: $now, updatedAt: $now);
        }

        return JsonlSidecar::fromArray($data);
    }

    public function load(string $jsonlPath): JsonlState
    {
        $sidecarExists = $this->exists($jsonlPath);
        $sc = $this->loadSidecar($jsonlPath);

        $stats = new JsonlStats(
            rows: $sc->rows,
            bytes: $sc->bytes,
            startedAt: $sc->startedAt,
            updatedAt: $sc->updatedAt,
            completed: $sc->completed,
            jsonlMtime: $sc->jsonl_mtime,
            jsonlSize: $sc->jsonl_size,
        );

        return new JsonlState(
            jsonlPath: $jsonlPath,
            sidecarPath: $this->sidecarPath($jsonlPath),
            stats: $stats,
            sidecarExists: $sidecarExists,
            context: $sc->context,
        );
    }

    public function ensure(string $jsonlPath): JsonlState
    {
        $this->touch($jsonlPath, rowsDelta: 0, bytesDelta: 0, captureFileFacts: true);
        return $this->load($jsonlPath);
    }

    /** @param array<string,mixed> $context */
    public function touch(
        string $jsonlPath,
        int $rowsDelta = 0,
        int $bytesDelta = 0,
        bool $captureFileFacts = true,
        array $context = [],
    ): JsonlSidecar {
        $sc = $this->loadSidecar($jsonlPath);

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $sc->startedAt ??= $now;
        $sc->rows += $rowsDelta;
        $sc->bytes += $bytesDelta;
        $sc->updatedAt = $now;
        if ($context !== []) {
            $sc->context = $this->mergeContext($sc->context, $context);
        }

        $this->captureFileFacts($jsonlPath, $sc, $captureFileFacts);
        $this->saveSidecar($jsonlPath, $sc);

        return $sc;
    }

    /** @param array<string,mixed> $context */
    public function putContext(string $jsonlPath, array $context, bool $captureFileFacts = true): JsonlSidecar
    {
        return $this->touch($jsonlPath, rowsDelta: 0, bytesDelta: 0, captureFileFacts: $captureFileFacts, context: $context);
    }

    public function markComplete(string $jsonlPath, bool $captureFileFacts = true): JsonlSidecar
    {
        $sc = $this->loadSidecar($jsonlPath);
        $sc->completed = true;
        $sc->updatedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $sc->startedAt ??= $sc->updatedAt;

        $this->captureFileFacts($jsonlPath, $sc, $captureFileFacts);
        $this->saveSidecar($jsonlPath, $sc);

        return $sc;
    }

    public function rows(string $jsonlPath): int
    {
        if ($this->exists($jsonlPath)) {
            return max(0, $this->loadSidecar($jsonlPath)->rows);
        }

        return $this->countNewlines($jsonlPath);
    }

    public function countNewlines(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }

        if (Jsonl::isGzipPath($path)) {
            $fh = @gzopen($path, 'rb');
            if ($fh === false) {
                return 0;
            }
            try {
                $lines = 0;
                while (!gzeof($fh)) {
                    $chunk = gzread($fh, 1024 * 1024);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $lines += substr_count($chunk, "\n");
                }
                return $lines;
            } finally {
                gzclose($fh);
            }
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return 0;
        }
        try {
            $lines = 0;
            while (!feof($fh)) {
                $chunk = fread($fh, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $lines += substr_count($chunk, "\n");
            }
            return $lines;
        } finally {
            fclose($fh);
        }
    }

    public function saveSidecar(string $jsonlPath, JsonlSidecar $sidecar): void
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

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException(sprintf('Failed writing sidecar tmp file "%s".', $tmp));
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Failed atomically replacing sidecar file "%s".', $path));
        }
    }

    private function captureFileFacts(string $jsonlPath, JsonlSidecar $sc, bool $capture): void
    {
        if (!$capture || !is_file($jsonlPath)) {
            return;
        }

        $mtime = @filemtime($jsonlPath);
        $size = @filesize($jsonlPath);
        $sc->jsonl_mtime = is_int($mtime) ? $mtime : $sc->jsonl_mtime;
        $sc->jsonl_size = is_int($size) ? $size : $sc->jsonl_size;
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function mergeContext(array $base, array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }
}
