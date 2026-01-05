<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

use Survos\JsonlBundle\Contract\JsonlWriterInterface;
use Survos\JsonlBundle\Model\JsonlWriterResult;
use Survos\JsonlBundle\Service\JsonlStateRepository;
use Survos\JsonlBundle\Service\SidecarService;
use Survos\JsonlBundle\Util\Jsonl;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Append JSON-encoded rows to JSONL (.jsonl or .jsonl.gz).
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

    private readonly SidecarService $sidecar;

    private ?LockInterface $lock = null;

    public function __construct(string $filename)
    {
        $this->filename  = $filename;
        $this->gzip      = Jsonl::isGzipPath($filename);
        $this->indexFile = $filename . '.idx.json';
        $this->sidecar   = new SidecarService();
    }

    public static function open(string $filename, bool $createDirs = true, int $dirPerms = 0o775): self
    {
        if ($createDirs) {
            $dir = \dirname($filename);
            if (!\is_dir($dir)) {
                if (!@\mkdir($dir, $dirPerms, true) && !\is_dir($dir)) {
                    throw new \RuntimeException(sprintf('Unable to create directory "%s".', $dir));
                }
            }
        }

        $writer = new self($filename);

        $writer->acquireLock();
        $writer->openHandle();
        $writer->loadIndex();

        // Ensure sidecar exists (startedAt/updatedAt) and capture file facts.
        $writer->sidecar->touch($filename, rowsDelta: 0, bytesDelta: 0, captureFileFacts: true);

        return $writer;
    }

    /**
     * Write a row. If $tokenCode is provided and already present in the index, the row is skipped.
     */
    public function write(array $row, ?string $tokenCode = null): void
    {
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

        // Update sidecar counters and capture deterministic file facts.
        $this->sidecar->touch($this->filename, rowsDelta: 1, bytesDelta: (int) $bytes, captureFileFacts: true);
    }

    public function markComplete(): void
    {
        $this->sidecar->markComplete($this->filename, captureFileFacts: true);
    }

    public function finish(bool $markComplete = true): JsonlWriterResult
    {
        if ($markComplete) {
            $this->markComplete();
        }

        $this->close();

        // Return an application-facing snapshot; no sidecar service exposure.
        $repo  = new JsonlStateRepository($this->sidecar);
        $state = $repo->load($this->filename);

        return new JsonlWriterResult($state);
    }

    public function close(): void
    {
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
        $this->fh = $this->gzip
            ? @\gzopen($this->filename, 'ab9')
            : @\fopen($this->filename, 'ab');

        if ($this->fh === false || $this->fh === null) {
            throw new \RuntimeException(sprintf('Unable to open "%s" for writing.', $this->filename));
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
        $name = 'jsonl_' . \sha1($this->filename);

        $this->lock = $factory->createLock($name);

        // Blocking lock; if you want a timeout, change to acquire(true) + retry logic.
        if (!$this->lock->acquire(true)) {
            throw new \RuntimeException(sprintf('Unable to acquire lock for "%s".', $this->filename));
        }
    }

    private function releaseLock(): void
    {
        if ($this->lock !== null) {
            try {
                $this->lock->release();
            } finally {
                $this->lock = null;
            }
        }
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
