<?php
declare(strict_types=1);

// File: src/IO/ZipJsonRecordProvider.php
// Stream JSON records directly from a ZIP archive, including origin/format metadata.

namespace Survos\JsonlBundle\IO;

final class ZipJsonRecordProvider
{
    public function __construct(
        private readonly string $zipPath,
        private readonly ?string $pathPrefix = null,
    ) {
    }

    /**
     * @return \Generator<int, array{record:array<string,mixed>,origin:string,format:string}>
     */
    public function getRecords(): \Generator
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->zipPath) !== true) {
            throw new \RuntimeException(sprintf('Unable to open ZIP archive "%s".', $this->zipPath));
        }

        $prefix = $this->pathPrefix
            ? rtrim($this->pathPrefix, '/') . '/'
            : '';

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }

                // Restrict to prefix, if any
                if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                    continue;
                }

                // Only JSON files
                if (!str_ends_with(strtolower($name), '.json')) {
                    continue;
                }

                $contents = $zip->getFromIndex($i);
                if ($contents === false || $contents === '') {
                    continue;
                }

                $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
                if (!\is_array($decoded)) {
                    continue;
                }

                $origin = $this->zipPath . '#' . $name;
                $format = 'json';

                // JSON can be a single object or a list of objects
                if (\array_is_list($decoded)) {
                    foreach ($decoded as $row) {
                        if (\is_array($row)) {
                            yield [
                                'record' => $row,
                                'origin' => $origin,
                                'format' => $format,
                            ];
                        }
                    }
                } else {
                    yield [
                        'record' => $decoded,
                        'origin' => $origin,
                        'format' => $format,
                    ];
                }
            }
        } finally {
            $zip->close();
        }
    }
}

