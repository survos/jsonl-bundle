<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

use Survos\JsonlBundle\Contract\JsonlWriterInterface;

/**
 * Append JSON-encoded rows to JSONL (.jsonl or .jsonl.gz).
 * Keeps a tiny boolean index file "<file>.idx.json" keyed by $tokenCode to skip duplicates.
 */
final class JsonlWriter implements JsonlWriterInterface
{
    /** @var resource|\GdImage|\CurlHandle|\GMP|null */
    private $fh = null;
    private bool $gzip;
    private string $filename;
    private string $indexFile;
    /** @var array<string,bool> */
    private array $index = [];

    private function __construct(string $filename)
    {
        $this->filename  = $filename;
        $this->gzip      = str_ends_with($filename, '.gz');
        $this->indexFile = $filename . '.idx.json';

        if (is_file($this->indexFile)) {
            $decoded = json_decode((string)file_get_contents($this->indexFile), true);
            $this->index = \is_array($decoded) ? $decoded : [];
        }

        if ($this->gzip) {
            if (!\function_exists('gzopen')) {
                throw new \RuntimeException('zlib not available: cannot write gzip file ' . $filename);
            }
            $this->fh = @\gzopen($filename, 'ab9');
        } else {
            $this->fh = @\fopen($filename, 'ab');
        }
        if (!$this->fh) {
            throw new \RuntimeException("Cannot open $filename for appending");
        }
    }

    public static function open(string $filename): self
    {
        return new self($filename);
    }

    public function write(array $row, ?string $tokenCode = null): void
    {
        if ($tokenCode !== null) {
            if (isset($this->index[$tokenCode])) {
                return; // already written
            }
            $this->index[$tokenCode] = true;
        }

        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode row as JSON');
        }
        $line = $json . "\n";

        if ($this->gzip) {
            \gzwrite($this->fh, $line);
        } else {
            \fwrite($this->fh, $line);
        }
    }

    public function close(): void
    {
        try {
            // persist index
            @\file_put_contents($this->indexFile, json_encode($this->index, JSON_PRETTY_PRINT));
        } finally {
            if (\is_resource($this->fh)) {
                if ($this->gzip) {
                    \gzclose($this->fh);
                } else {
                    \fclose($this->fh);
                }
                $this->fh = null;
            }
        }
    }

    public function __destruct()
    {
        if (\is_resource($this->fh)) {
            $this->close();
        }
    }
}
