<?php

declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

use Survos\JsonlBundle\Contract\JsonlReaderInterface as ContractJsonlReaderInterface;
use Survos\JsonlBundle\Model\JsonlState;
use Survos\JsonlBundle\Service\JsonlStateService;
use Survos\JsonlBundle\Sqlite\SidecarDb;
use Survos\JsonlBundle\Util\Jsonl;

use function gzseek;

/**
 * Streaming JSONL reader that yields each line decoded as an associative array.
 *
 * Supports both:
 *  - .jsonl
 *  - .jsonl.gz (transparent gzip)
 *
 * startAtLine semantics (as required by JsonlIoTest::*):
 *  - startAtLine is 1-based
 *  - it is a *hint that offsets yielded keys only*
 *  - it does NOT skip any physical lines
 *
 * Example:
 *  - file has 2 valid JSON lines
 *  - startAtLine = 101
 *  => yields keys [101, 102]
 *
 * Default:
 *  - startAtLine = 1
 *  => yields keys [1, 2, 3, ...]
 */
final class JsonlReader implements JsonlReaderInterface, ContractJsonlReaderInterface, \IteratorAggregate
{
    private ?array $tokenIndex = null;
    private ?string $archiveCacheBase = null;

    public function __construct(
        private readonly string $path,
        private readonly int $startAtLine = 1,
        private readonly ?string $sidecarDirectory = null,
    ) {
        if ($this->startAtLine < 1) {
            throw new \InvalidArgumentException('startAtLine must be >= 1');
        }
    }

    public static function open(string $filename, ?string $sidecarDirectory = null): self
    {
        return new self(Jsonl::resolvePath($filename), sidecarDirectory: $sidecarDirectory);
    }

    public static function openArchive(string $archive, string $member, ?string $sidecarDirectory = null): self
    {
        return self::open(ArchiveJsonl::path($archive, $member), $sidecarDirectory);
    }

    public function sidecarPath(): string
    {
        return $this->sidecarBase().'.db';
    }

    private function sidecarBase(): string
    {
        if (!ArchiveJsonl::isPath($this->path)) {
            return $this->path;
        }
        if (null === $this->sidecarDirectory) {
            throw new \LogicException('Archive state and indexes require an explicit sidecar directory.');
        }

        return $this->archiveCacheBase ??= ArchiveJsonl::cacheBase($this->path, $this->sidecarDirectory);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function state(?JsonlStateService $state = null): JsonlState
    {
        $state ??= $this->stateService();

        return $state->load($this->path);
    }

    public function stateService(): JsonlStateService
    {
        return new JsonlStateService([$this->path => $this->sidecarPath()]);
    }

    /**
     * Convenience helper for "give me the first row".
     * Keeps the reader streaming-first; no cursor API required.
     */
    public function first(): ?array
    {
        foreach ($this as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Quick membership check by tokenCode, backed by "<file>.idx.json" if present.
     *
     * Note: this checks the writer's token index, not arbitrary row content.
     */
    public function containsToken(string $tokenCode): bool
    {
        $idx = $this->sidecarBase().'.idx.json';

        if (!\is_file($idx)) {
            return false;
        }

        if (null === $this->tokenIndex) {
            $raw = @\file_get_contents($idx);
            if (false === $raw || '' === \trim($raw)) {
                $this->tokenIndex = [];
            } else {
                $decoded = \json_decode($raw, true);
                $this->tokenIndex = \is_array($decoded) ? $decoded : [];
            }
        }

        return isset($this->tokenIndex[$tokenCode]);
    }

    /**
     * Random-access fetch of a single record by primary key, using the sidecar
     * index (`<file>.db`, built by jsonl:index). Returns null when the pk is not
     * indexed or the line cannot be read/decoded.
     *
     * Plain `.jsonl` seeks directly (O(1)); `.jsonl.gz` uses gzseek on the
     * uncompressed offset (O(n)-ish — gzip is not randomly seekable; ADR §8).
     *
     * @return array<string,mixed>|null
     */
    public function get(string $pk): ?array
    {
        $db = new SidecarDb($this->sidecarPath());
        $offset = $db->lookupOffset($pk);
        if (null === $offset) {
            return null;
        }

        if (ArchiveJsonl::isPath($this->path)) {
            if ($db->hasCache()) {
                $query = $db->connection()->prepare('SELECT _rows.body FROM idx JOIN _rows ON _rows.line_no = idx.line WHERE idx.pk = :pk');
                $query->execute(['pk' => $pk]);
                $body = $query->fetchColumn();
                if (false !== $body) {
                    $decoded = \json_decode($body, true);

                    return \is_array($decoded) ? $decoded : null;
                }
            }
            foreach (ArchiveJsonl::lines($this->path) as $position => $body) {
                if ($position === $offset) {
                    $decoded = \json_decode(\trim($body), true);

                    return \is_array($decoded) ? $decoded : null;
                }
            }

            return null;
        }

        $gzip = Jsonl::isGzipPath($this->path);

        if ($gzip) {
            $handle = \gzopen($this->path, 'rb');
            if (false === $handle) {
                return null;
            }
            try {
                \gzseek($handle, $offset);
                $line = \gzgets($handle);
            } finally {
                \gzclose($handle);
            }
        } else {
            $handle = \fopen($this->path, 'rb');
            if (false === $handle) {
                return null;
            }
            try {
                \fseek($handle, $offset);
                $line = \fgets($handle);
            } finally {
                \fclose($handle);
            }
        }

        if (false === $line) {
            return null;
        }

        $decoded = \json_decode(\trim($line), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Stream JSONL rows as associative arrays.
     *
     * @return \Traversable<int, array<string,mixed>>
     */
    public function getIterator(): \Traversable
    {
        $gzip = Jsonl::isGzipPath($this->path);
        $key = $this->startAtLine;

        if (ArchiveJsonl::isPath($this->path)) {
            foreach (ArchiveJsonl::lines($this->path) as $line) {
                $decoded = \json_decode(\trim($line), true);
                if (\is_array($decoded)) {
                    yield $key++ => $decoded;
                }
            }

            return;
        }

        if ($gzip) {
            $handle = @\gzopen($this->path, 'rb');
            if (false === $handle) {
                throw new \RuntimeException(\sprintf('Unable to open gzipped JSONL file "%s" for reading.', $this->path));
            }

            try {
                while (!\gzeof($handle)) {
                    $line = \gzgets($handle);
                    if (false === $line) {
                        break;
                    }

                    $line = \trim($line);
                    if ('' === $line) {
                        continue;
                    }

                    $decoded = \json_decode($line, true);
                    if (!\is_array($decoded)) {
                        continue;
                    }

                    yield $key++ => $decoded;
                }
            } finally {
                \gzclose($handle);
            }

            return;
        }

        $handle = @\fopen($this->path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Unable to open JSONL file "%s" for reading.', $this->path));
        }

        try {
            while (($line = \fgets($handle)) !== false) {
                $line = \trim($line);
                if ('' === $line) {
                    continue;
                }

                $decoded = \json_decode($line, true);
                if (!\is_array($decoded)) {
                    continue;
                }

                yield $key++ => $decoded;
            }
        } finally {
            \fclose($handle);
        }
    }
}
