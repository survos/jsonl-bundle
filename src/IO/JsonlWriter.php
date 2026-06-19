<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

use Survos\JsonlBundle\Contract\JsonlWriterInterface;
use Survos\JsonlBundle\Model\JsonlWriterResult;
use Survos\JsonlBundle\Service\JsonlStateService;

use Survos\JsonlBundle\Util\Jsonl;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Write JSON-encoded rows to JSONL (.jsonl or .jsonl.gz).
 *
 * Features:
 *  - Transparent gzip when filename ends with .gz/.gzip
 *  - Symfony Lock component to prevent concurrent writers corrupting output
 *  - Optional token de-dup index "<file>.idx.json" keyed by $tokenCode
 *  - Progress sidecar "<file>.sidecar.json" (rows, bytes, timestamps, completed, jsonl_mtime, jsonl_size)
 *
 * SidecarService is an internal persistence mechanism. Applications should use JsonlStateRepository.
 */
final class JsonlWriter implements JsonlWriterInterface
{
    /** @var resource|null */
    private $fh = null;

    private bool $gzip;
    private string $filename;
    private string $indexFile;

    /** @var array<string,bool> */
    private array $index = [];

    private readonly JsonlStateService $stateService;

    private ?LockInterface $lock = null;

    private ?string $lockFile = null;

    /** @var 'a'|'w' */
    private string $mode = 'w';

    /**
     * Persist the SQLite sidecar state every N rows instead of once per row. Per-row persistence
     * (a `saveMeta()` write transaction + two stat() syscalls in {@see JsonlStateService::touch()})
     * was the dominant cost of bulk writes — it capped large ingests/extracts at ~10 rows/sec
     * regardless of payload. Batching cuts that by ~1000×; the persisted sidecar still ends exact
     * because {@see finish()}/{@see markComplete()} flush the remainder. An interrupted run is
     * `completed=false` and re-run anyway, so an interim undercount between flushes never matters.
     */
    private const int STATE_FLUSH_ROWS = 1000;

    /** Row/byte deltas written since the last sidecar persist; flushed in batches, see above. */
    private int $unsyncedRows = 0;
    private int $unsyncedBytes = 0;

    public function __construct(string $filename)
    {
        $this->filename  = $filename;
        $this->gzip      = Jsonl::isGzipPath($filename);
        $this->indexFile = $filename . '.idx.json';
        $this->stateService = new JsonlStateService();
    }

    /**
     * Open (or create) a JSONL writer for the given path.
     *
     * $mode:
     *  - 'w' = reset/truncate (default)
     *  - 'a' = append
     *
     * Options:
     *  - ensure_dir (bool) default true
     *  - dir_perms (int) default 0775
     *  - reset_sidecars (bool) default true (only relevant for mode 'w')
     *  - lock (bool) default true
     *
     */
    public static function open(
        string $filename,
        string $mode = 'w',
        ?JsonlWriterOptions $options = null,
    ): self
    {
        $options ??= JsonlWriterOptions::defaults();

        $mode = \strtolower($mode);
        if (!\in_array($mode, ['a', 'w'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid JsonlWriter mode "%s". Expected "a" or "w".', $mode));
        }

        if ($options->ensureDir) {
            $dir = \dirname($filename);
            if (!\is_dir($dir)) {
                if (!\mkdir($dir, $options->dirPerms, true) && !\is_dir($dir)) {
                    throw new \RuntimeException(sprintf('Unable to create directory "%s".', $dir));
                }
            }
        }

        $writer = new self($filename);
        $writer->mode = $mode;

        if ($options->useLock) {
            $writer->acquireLock();
        }

        if ($mode === 'w') {
            if ($options->resetSidecars) {
                $writer->resetSidecars();
            }
            $writer->index = [];
        } else {
            $writer->loadIndex();
        }

        $writer->openHandle();
        $writer->stateService->touch($filename, rowsDelta: 0, bytesDelta: 0, captureFileFacts: true);

        return $writer;
    }


    /**
     * Write a row. Accepts an array or a serializable DTO — pass a value object straight in and
     * the writer serialises it (JsonSerializable::jsonSerialize(), else public props with null
     * fields dropped for a sparse stream). This kills the normalize-to-array boilerplate that
     * every producer was repeating. If $tokenCode is provided and already present in the index,
     * the row is skipped.
     */
    public function write(array|object $row, ?string $tokenCode = null): void
    {
        if (\is_object($row)) {
            $row = $row instanceof \JsonSerializable
                ? (array) $row->jsonSerialize()
                : \array_filter(\get_object_vars($row), static fn (mixed $v): bool => $v !== null);
        }

        if ($tokenCode !== null) {
            if (isset($this->index[$tokenCode])) {
                return;
            }
            $this->index[$tokenCode] = true;
        }

        $json = \json_encode($row, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('JSON encode failed.');
        }

        $line = $json . "\n";

        $bytes = $this->gzip
            ? \gzwrite($this->fh, $line)
            : \fwrite($this->fh, $line);

        if ($bytes === false) {
            throw new \RuntimeException(sprintf('Failed writing to "%s".', $this->filename));
        }

        // Accumulate sidecar counters in memory; persist in batches (see self::STATE_FLUSH_ROWS).
        $this->unsyncedRows++;
        $this->unsyncedBytes += (int) $bytes;
        if ($this->unsyncedRows >= self::STATE_FLUSH_ROWS) {
            $this->flushState();
        }
    }

    /** Persist accumulated row/byte deltas to the sidecar in one write; no-op when nothing is pending. */
    private function flushState(): void
    {
        if ($this->unsyncedRows === 0 && $this->unsyncedBytes === 0) {
            return;
        }

        // captureFileFacts:false here — the mtime/size snapshot only needs to be exact at
        // markComplete() (which captures it); skipping the stat() keeps batch flushes cheap.
        $this->stateService->touch(
            $this->filename,
            rowsDelta: $this->unsyncedRows,
            bytesDelta: $this->unsyncedBytes,
            captureFileFacts: false,
        );
        $this->unsyncedRows = 0;
        $this->unsyncedBytes = 0;
    }

    public function state(): \Survos\JsonlBundle\Model\JsonlState
    {
        $this->flushState(); // ensure a deliberate read reflects every row written so far
        return $this->stateService->load($this->filename);
    }

    public function stateService(): JsonlStateService
    {
        return $this->stateService;
    }

    /**  array<string,mixed> $context */
    public function putContext(array $context): void
    {
        $this->stateService->putContext($this->filename, $context);
    }

    public function markComplete(): void
    {
        $this->flushState(); // land the tail of un-persisted rows before the completed snapshot
        $this->stateService->markComplete($this->filename, captureFileFacts: true);
    }

    public function finish(bool $markComplete = true): JsonlWriterResult
    {
        if ($markComplete) {
            $this->markComplete();
        } else {
            $this->flushState(); // no completed snapshot, but still persist the final row/byte counts
        }

        $this->close();

        // Return an application-facing snapshot; no sidecar service exposure.
        $state = $this->stateService->load($this->filename);

        return new JsonlWriterResult($state);
    }

    public function close(): void
    {
        $this->flushState(); // persist any tail deltas for callers that close() without finish()

        // Persist token index only if it was used.
        $this->saveIndex();

        if (\is_resource($this->fh)) {
            if ($this->gzip) {
                \gzclose($this->fh);
            } else {
                \fclose($this->fh);
            }
            $this->fh = null;
        }

        $this->releaseLock();
    }

    public function __destruct()
    {
        if (\is_resource($this->fh) || $this->lock !== null) {
            try {
                $this->close();
            } catch (\Throwable $e) {
                // Avoid throwing in destructors; log instead.
                \error_log('JsonlWriter destructor error: ' . $e->getMessage());
            }
        }
    }

    private function openHandle(): void
    {
        // fopen/gzopen modes:
        //  - 'a' (append) or 'w' (truncate)
        $plainMode = ($this->mode === 'a') ? 'ab' : 'wb';
        $gzipMode  = ($this->mode === 'a') ? 'ab9' : 'wb9';

        $this->fh = $this->gzip
            ? @\gzopen($this->filename, $gzipMode)
            : @\fopen($this->filename, $plainMode);

        if ($this->fh === false || $this->fh === null) {
            throw new \RuntimeException(sprintf('Unable to open "%s" for writing (mode=%s).', $this->filename, $this->mode));
        }
    }

    private function acquireLock(): void
    {
        // Use a file-based store in the same directory as the target file.
        $dir = \dirname($this->filename);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0o775, true);
        }

        $store   = new FlockStore($dir);
        $factory = new LockFactory($store);

        // Stable, filesystem-safe name derived from the filename.
        $name = "jsonl_" . \sha1($this->filename);
        $this->lockFile = \sprintf("%s/sf.%s.%s.lock",
            $dir,
            \substr(\preg_replace("/[^a-z0-9\._-]+/i", "-", $name), 0, 50),
            \strtr(\substr(\base64_encode(\hash("sha256", $name, true)), 0, 7), "/", "_")
        );

        $this->lock = $factory->createLock($name);

        // Blocking lock; if you want a timeout, change to acquire(true) + retry logic.
        if (!$this->lock->acquire(true)) {
            throw new \RuntimeException(\sprintf("Unable to acquire lock for \"%s\".", $this->filename));
        }
    }

    private function releaseLock(): void
    {
        if ($this->lock !== null) {
            try {
                $this->lock->release();
            } finally {
                $this->lock = null;

                if ($this->lockFile !== null && \is_file($this->lockFile)) {
                    @\unlink($this->lockFile);
                }

                $this->lockFile = null;
            }
        }
    }

    private function resetSidecars(): void
    {
        // Known sidecars/indexes we may have created historically.
        $candidates = [
            $this->indexFile,                 // <file>.idx.json
            $this->filename . '.sidecar.json',// <file>.sidecar.json
            $this->filename . '.index.json',  // legacy naming you referenced
        ];

        foreach ($candidates as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }

        // Clear the SQLite sidecar state too, so a fresh ('w') write does not inherit
        // stale rows/bytes/completed/context from a previous run.
        $this->stateService->reset($this->filename);
    }

    private function loadIndex(): void
    {
        if (!\is_file($this->indexFile)) {
            $this->index = [];
            return;
        }

        $raw = @\file_get_contents($this->indexFile);
        if ($raw === false || \trim($raw) === '') {
            $this->index = [];
            return;
        }

        $decoded = \json_decode($raw, true);
        $this->index = \is_array($decoded) ? $decoded : [];
    }

    private function saveIndex(): void
    {
        if ($this->index === []) {
            return;
        }

        $json = \json_encode($this->index, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode index JSON');
        }

        $dir = \dirname($this->indexFile);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0o775, true);
        }

        $tmp = $this->indexFile . '.tmp';
        if (@\file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException(sprintf('Failed writing index tmp file "%s".', $tmp));
        }

        if (!@\rename($tmp, $this->indexFile)) {
            @\unlink($tmp);
            throw new \RuntimeException(sprintf('Failed atomically replacing index file "%s".', $this->indexFile));
        }
    }
}
