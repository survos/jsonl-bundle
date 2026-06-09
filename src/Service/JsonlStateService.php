<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

use Survos\JsonlBundle\Model\JsonlSidecar;
use Survos\JsonlBundle\Model\JsonlState;
use Survos\JsonlBundle\Model\JsonlStats;
use Survos\JsonlBundle\Sqlite\SidecarDb;
use Survos\JsonlBundle\Sqlite\SidecarMeta;
use Survos\JsonlBundle\Util\Jsonl;

/**
 * Public state API for JSONL artifacts.
 *
 * State is persisted in a per-file SQLite sidecar (`<file>.db`, a `meta`
 * key/value table) — see doc/adr-0001-sqlite-sidecar.md. Callers should treat
 * the storage as an implementation detail; the public surface and the
 * JsonlSidecar DTO are unchanged. A pre-existing `<file>.sidecar.json` is read
 * once as a fallback and folded into the DB on the next save.
 */
class JsonlStateService
{
    /** @var array<string, SidecarDb> */
    private array $dbs = [];

    public function sidecarPath(string $jsonlPath): string
    {
        // Canonical state store is now the SQLite sidecar DB (ADR 0001).
        return $jsonlPath . '.db';
    }

    public function exists(string $jsonlPath): bool
    {
        return $this->sidecarDb($jsonlPath)->hasState();
    }

    public function loadSidecar(string $jsonlPath): JsonlSidecar
    {
        $meta = $this->sidecarDb($jsonlPath)->loadMeta();
        if ($meta !== null) {
            return $this->metaToSidecar($meta);
        }

        // State lives in the SQLite sidecar (<file>.db). Obsolete .sidecar.json files
        // are no longer read — purge them with `jsonl:clean` (no migration).
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        return new JsonlSidecar(startedAt: $now, updatedAt: $now);
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

    /**
     * Reset persisted state to a fresh sidecar (rows/bytes 0, not completed, empty
     * context). Used when a JSONL file is truncated/rewritten so stale `completed`
     * or counters from a previous run do not leak into the new one.
     */
    public function reset(string $jsonlPath): JsonlSidecar
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $sc = new JsonlSidecar(startedAt: $now, updatedAt: $now);
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
        $this->sidecarDb($jsonlPath)->saveMeta($this->sidecarToMeta($sidecar));
    }

    private function sidecarDb(string $jsonlPath): SidecarDb
    {
        $path = $this->sidecarPath($jsonlPath);

        return $this->dbs[$path] ??= new SidecarDb($path);
    }

    /*
     * The two mappings below are the public DTO <-> storage DTO seam. The only
     * field differences are the names jsonl_mtime/jsonl_size (public, legacy
     * snake_case) vs jsonlMtime/jsonlSize (storage). A Symfony ObjectMapper
     * (#[Map]) could replace them — deferred until it is adopted bundle-wide
     * (field-bundle ADR 0002), since here it would only cover this trivial copy
     * and not the typed<->TEXT coercion, which lives in SidecarMeta.
     */

    private function metaToSidecar(SidecarMeta $m): JsonlSidecar
    {
        return new JsonlSidecar(
            startedAt: $m->startedAt,
            updatedAt: $m->updatedAt,
            rows: $m->rows,
            bytes: $m->bytes,
            completed: $m->completed,
            jsonl_mtime: $m->jsonlMtime,
            jsonl_size: $m->jsonlSize,
            context: $m->context,
        );
    }

    private function sidecarToMeta(JsonlSidecar $sc): SidecarMeta
    {
        return new SidecarMeta(
            startedAt: $sc->startedAt,
            updatedAt: $sc->updatedAt,
            rows: $sc->rows,
            bytes: $sc->bytes,
            completed: $sc->completed,
            jsonlMtime: $sc->jsonl_mtime,
            jsonlSize: $sc->jsonl_size,
            context: $sc->context,
        );
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
