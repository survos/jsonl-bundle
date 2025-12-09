<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

use IteratorAggregate;
use RuntimeException;
use Traversable;

/**
 * Basic JSONL reader that yields each line as an associative array.
 */
final class JsonlReader implements JsonlReaderInterface, IteratorAggregate
{
    public function __construct(
        private readonly string $path,
    ) {}

    public static function open(string $filename): self
    {
        return new self($filename);
    }

    /**
     * Stream JSONL rows as associative arrays.
     *
     * @return Traversable<int, array<string,mixed>>
     */
    public function getIterator(): Traversable
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf(
                'Unable to open JSONL file "%s" for reading.',
                $this->path
            ));
        }

        try {
            $index = 0;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (!is_array($decoded)) {
                    // optionally: throw?
                    continue;
                }

                yield $index++ => $decoded;
            }

        } finally {
            fclose($handle);
        }
    }
}
