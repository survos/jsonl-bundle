<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

use RuntimeException;
use Traversable;
use Survos\JsonlBundle\Contract\JsonlReaderInterface as ContractJsonlReaderInterface;
use Survos\JsonlBundle\Service\JsonlStateRepository;
use Survos\JsonlBundle\Model\JsonlState;
use Survos\JsonlBundle\Util\Jsonl;

/**
 * Streaming JSONL reader that yields each line decoded as an associative array.
 *
 * Supports both:
 *  - .jsonl
 *  - .jsonl.gz (transparent gzip)
 */
final class JsonlReader implements JsonlReaderInterface, ContractJsonlReaderInterface, \IteratorAggregate
{
    private ?array $tokenIndex = null;

    public function __construct(
        private readonly string $path,
    ) {}

    public static function open(string $filename): self
    {
        return new self($filename);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function state(?JsonlStateRepository $repo = null): JsonlState
    {
        $repo ??= new JsonlStateRepository();
        return $repo->load($this->path);
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
        $idx = $this->path . '.idx.json';

        if (!is_file($idx)) {
            return false;
        }

        if ($this->tokenIndex === null) {
            $raw = @file_get_contents($idx);
            if ($raw === false || trim($raw) === '') {
                $this->tokenIndex = [];
            } else {
                $decoded = json_decode($raw, true);
                $this->tokenIndex = is_array($decoded) ? $decoded : [];
            }
        }

        return isset($this->tokenIndex[$tokenCode]);
    }

    /**
     * Stream JSONL rows as associative arrays.
     *
     * @return Traversable<int, array<string,mixed>>
     */
    public function getIterator(): Traversable
    {
        $gzip = Jsonl::isGzipPath($this->path);

        if ($gzip) {
            $handle = @gzopen($this->path, 'rb');
            if ($handle === false) {
                throw new RuntimeException(sprintf('Unable to open gzipped JSONL file "%s" for reading.', $this->path));
            }

            try {
                $index = 1;

                while (!gzeof($handle)) {
                    $line = gzgets($handle);
                    if ($line === false) {
                        break;
                    }

                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $decoded = json_decode($line, true);
                    if (!is_array($decoded)) {
                        continue;
                    }

                    yield $index++ => $decoded;
                }
            } finally {
                gzclose($handle);
            }

            return;
        }

        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open JSONL file "%s" for reading.', $this->path));
        }

        try {
            $index = 1;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    continue;
                }

                yield $index++ => $decoded;
            }
        } finally {
            fclose($handle);
        }
    }
}
