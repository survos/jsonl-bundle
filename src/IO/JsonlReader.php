<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

use RuntimeException;

/**
 * Basic JSONL reader that yields each line as an associative array.
 */
final class JsonlReader implements JsonlReaderInterface
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * @return \Traversable<int, array<string, mixed>>
     */
    public function getIterator(): \Traversable
    {
        $handle = @\fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open JSONL file "%s" for reading.', $this->path));
        }

        try {
            $index = 0;
            while (($line = \fgets($handle)) !== false) {
                $line = \trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = \json_decode($line, true);
                if (!\is_array($decoded)) {
                    continue;
                }

                yield $index++ => $decoded;
            }
        } finally {
            \fclose($handle);
        }
    }
}
