<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

use Survos\JsonlBundle\Contract\JsonlWriterInterface;

/**
 * Append JSON-encoded rows to JSONL (.jsonl or .jsonl.gz).
 * Maintains a boolean index "<file>.idx.json" keyed by $tokenCode to avoid duplicates.
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

    public function __construct(string $filename)
    {
        $this->filename  = $filename;
        $this->gzip      = str_ends_with($filename, '.gz');
        $this->indexFile = $filename . '.idx.json';
    }

    public static function open(string $filename, bool $createDirs = true, int $dirPerms = 0o775): self
    {
        $self = new self($filename);

        $dir = \dirname($filename);
        if (!\is_dir($dir)) {
            if ($createDirs) {
                if (!\mkdir($dir, $dirPerms, true) && !\is_dir($dir)) {
                    throw new \RuntimeException("Cannot create directory: $dir");
                }
            } else {
                throw new \RuntimeException("Parent directory does not exist: $dir (pass \$createDirs=true to auto-create)");
            }
        }

        // Preload index if present
        if (\is_file($self->indexFile)) {
            $decoded = \json_decode((string)\file_get_contents($self->indexFile), true);
            $self->index = \is_array($decoded) ? $decoded : [];
        }

        // Open file handle
        if ($self->gzip) {
            if (!\function_exists('gzopen')) {
                throw new \RuntimeException('zlib not available: cannot write gzip file ' . $filename);
            }
            $self->fh = \gzopen($filename, 'ab9');
        } else {
            $self->fh = \fopen($filename, 'ab');
        }
        if (!$self->fh) {
            throw new \RuntimeException("Cannot open $filename for appending");
        }

        return $self;
    }

    public function write(array $row, ?string $tokenCode = null): void
    {
        if ($tokenCode !== null) {
            if (isset($this->index[$tokenCode])) {
                return; // already written
            }
            $this->index[$tokenCode] = true;
        }

        $json = \json_encode($row, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode row as JSON');
        }

        $line = $json . "\n";

        $bytes = $this->gzip
            ? \gzwrite($this->fh, $line)
            : \fwrite($this->fh, $line);

        if ($bytes === false) {
            throw new \RuntimeException("Failed writing to {$this->filename}");
        }
    }

    public function close(): void
    {
        // Persist index alongside final
        $json = \json_encode($this->index, \JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode index JSON');
        }

        if (\file_put_contents($this->indexFile, $json) === false) {
            throw new \RuntimeException("Failed writing index file: {$this->indexFile}");
        }

        if (\is_resource($this->fh)) {
            if ($this->gzip) {
                \gzclose($this->fh);
            } else {
                \fclose($this->fh);
            }
            $this->fh = null;
        }
    }

    public function __destruct()
    {
        if (\is_resource($this->fh)) {
            try {
                $this->close();
            } catch (\Throwable $e) {
                // Avoid throwing in destructors; log instead.
                \error_log('JsonlWriter destructor error: ' . $e->getMessage());
            }
        }
    }
}
