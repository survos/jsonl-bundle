<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

use Survos\JsonlBundle\Contract\JsonlReaderInterface;

/**
 * Stream-decoding JSONL from plain files or .gz.
 *
 * - Yields 1-based line numbers by default.
 * - You can supply a $startAtLine hint to offset the yielded keys, useful when
 *   you already know how many lines are in the target (e.g., for resume logic).
 *
 * Example:
 *   $r = new JsonlReader('data/items.jsonl.gz', startAtLine: 101);
 *   foreach ($r as $lineNo => $row) { ... } // first yielded key will be 101
 */
final class JsonlReader implements JsonlReaderInterface
{
    public function __construct(
        private string $filename,
        private int $startAtLine = 1
    ) {
        if ($this->startAtLine < 1) {
            throw new \InvalidArgumentException('startAtLine must be >= 1');
        }
    }

    public function path(): string
    {
        return $this->filename;
    }

    /** @return \Traversable<int,array> */
    public function getIterator(): \Traversable
    {
        $gzip = \str_ends_with($this->filename, '.gz');

        if ($gzip) {
            if (!\function_exists('gzopen')) {
                throw new \RuntimeException('zlib not available: cannot read gzip file ' . $this->filename);
            }
            $fh = \gzopen($this->filename, 'rb');
            if (!$fh) {
                throw new \RuntimeException('Cannot open gzip file: ' . $this->filename);
            }
            try {
                $lineNo = $this->startAtLine - 1;
                while (!\gzeof($fh)) {
                    $line = \gzgets($fh);
                    if ($line === false) {
                        break; // EOF or read error
                    }
                    $line = \trim($line);
                    if ($line === '') {
                        continue; // skip blank lines safely
                    }
                    $lineNo++;
                    /** @var array $decoded */
                    $decoded = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
                    yield $lineNo => $decoded;
                }
            } finally {
                \gzclose($fh);
            }
            return;
        }

        $fh = \fopen($this->filename, 'rb');
        if (!$fh) {
            throw new \RuntimeException('Cannot open file: ' . $this->filename);
        }
        try {
            $lineNo = $this->startAtLine - 1;
            while (!\feof($fh)) {
                $line = \fgets($fh);
                if ($line === false) {
                    break; // EOF or read error
                }
                $line = \trim($line);
                if ($line === '') {
                    continue;
                }
                $lineNo++;
                /** @var array $decoded */
                $decoded = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
                yield $lineNo => $decoded;
            }
        } finally {
            \fclose($fh);
        }
    }

    public function containsToken(string $tokenCode): bool
    {
        $idxFile = $this->filename . '.idx.json';
        if (\is_file($idxFile)) {
            $idx = \json_decode((string)\file_get_contents($idxFile), true) ?? [];
            return isset($idx[$tokenCode]);
        }

        foreach ($this as $row) {
            if (($row['_tokenCode'] ?? null) === $tokenCode) {
                return true;
            }
        }
        return false;
    }
}
