<?php

declare(strict_types=1);

// File: src/Service/JsonlDirectoryConverter.php
// jsonl-bundle v0.7
// Convert CSV/JSON/JSONL files (or a directory of them) into a single JSONL file.
// Optionally apply a per-record callback before writing (e.g. dispatch JsonlRecordEvent).

namespace Survos\JsonlBundle\Service;

use League\Csv\Reader;

final class JsonlDirectoryConverter
{
    public function __construct(
    ) {}

    /**
     * Convert input file or directory to a JSONL file.
     *
     * @param string   $input         Path to CSV/JSON/JSONL file or directory
     * @param string   $output        Destination JSONL file (will be overwritten)
     * @param ?string  $slugifyField  Optional field to slugify
     * @param ?string  $primaryKeyField Optional primary key field name
     * @param callable|null $onRecord Optional callback:
     *                                function (array $record, int $index, string $originFile, string $format): array
     *
     * @return int number of records written
     */
    public function convert(
        string $input,
        string $output,
        ?string $slugifyField = null,
        ?string $primaryKeyField = null,
        ?callable $onRecord = null,
    ): int {
        // Ensure directory exists
        $dir = \dirname($output);
        if (!\is_dir($dir)) {
            if (!@mkdir($dir, 0o775, true) && !\is_dir($dir)) {
                throw new \RuntimeException(sprintf('Unable to create directory "%s".', $dir));
            }
        }

        // Truncate output
        if (@file_put_contents($output, '') === false) {
            throw new \RuntimeException(sprintf('Unable to truncate output file "%s".', $output));
        }

        $count = 0;

        if (\is_dir($input)) {
            // Process all known files in directory
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($input, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $count += $this->convertFile(
                    path: $file->getPathname(),
                    output: $output,
                    slugifyField: $slugifyField,
                    primaryKeyField: $primaryKeyField,
                    onRecord: $onRecord,
                    offset: $count,
                );
            }

            return $count;
        }

        // Single file
        return $this->convertFile(
            path: $input,
            output: $output,
            slugifyField: $slugifyField,
            primaryKeyField: $primaryKeyField,
            onRecord: $onRecord,
            offset: 0,
        );
    }

    /**
     * @param callable|null $onRecord function (array $record, int $index, string $originFile, string $format): array
     */
    private function convertFile(
        string $path,
        string $output,
        ?string $slugifyField,
        ?string $primaryKeyField,
        ?callable $onRecord,
        int $offset = 0,
    ): int {
        $ext = strtolower((string) pathinfo($path, \PATHINFO_EXTENSION));

        return match ($ext) {
            'csv'   => $this->convertCsv($path, $output, $slugifyField, $primaryKeyField, $onRecord, $offset),
            'json'  => $this->convertJson($path, $output, $slugifyField, $primaryKeyField, $onRecord, $offset),
            'jsonl' => $this->convertJsonl($path, $output, $slugifyField, $primaryKeyField, $onRecord, $offset),
            default => 0, // ignore unknown files for now
        };
    }

    /**
     * @param callable|null $onRecord
     */
    private function convertCsv(
        string $path,
        string $output,
        ?string $slugifyField,
        ?string $primaryKeyField,
        ?callable $onRecord,
        int $offset = 0,
    ): int {
        $csv = Reader::from($path, 'r');
        $csv->setHeaderOffset(0);
        $csv->setDelimiter(',');
        $csv->setEnclosure('"');
        $csv->setEscape('\\');

        $index = $offset;
        $written = 0;

        foreach ($csv->getRecords() as $row) {
            // Normalize keys: strip BOM + trim
            $record = [];
            foreach ($row as $key => $value) {
                $normalizedKey = trim(preg_replace('/^\xEF\xBB\xBF/u', '', (string) $key));
                $record[$normalizedKey] = $value;
            }

            if ($slugifyField && isset($record[$slugifyField])) {
                $record[$slugifyField] = $this->slugify((string) $record[$slugifyField]);
            }

            if ($primaryKeyField && !isset($record[$primaryKeyField])) {
                $record[$primaryKeyField] = $index;
            }

            if ($onRecord) {
                $record = $onRecord($record, $index, $path, 'csv');
            }

            $this->appendJsonl($output, $record);
            $index++;
            $written++;
        }

        return $written;
    }

    /**
     * @param callable|null $onRecord
     */
    private function convertJson(
        string $path,
        string $output,
        ?string $slugifyField,
        ?string $primaryKeyField,
        ?callable $onRecord,
        int $offset = 0,
    ): int {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read JSON from "%s".', $path));
        }

        $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        $records = [];

        if (\is_array($decoded) && \array_is_list($decoded)) {
            $records = $decoded;
        } elseif (\is_array($decoded)) {
            $records = [$decoded];
        }

        $index = $offset;
        $written = 0;

        foreach ($records as $item) {
            if (!\is_array($item)) {
                continue;
            }

            /** @var array<string,mixed> $record */
            $record = $item;

            if ($slugifyField && isset($record[$slugifyField])) {
                $record[$slugifyField] = $this->slugify((string) $record[$slugifyField]);
            }

            if ($primaryKeyField && !isset($record[$primaryKeyField])) {
                $record[$primaryKeyField] = $index;
            }

            if ($onRecord) {
                $record = $onRecord($record, $index, $path, 'json');
            }

            $this->appendJsonl($output, $record);
            $index++;
            $written++;
        }

        return $written;
    }

    /**
     * @param callable|null $onRecord
     */
    private function convertJsonl(
        string $path,
        string $output,
        ?string $slugifyField,
        ?string $primaryKeyField,
        ?callable $onRecord,
        int $offset = 0,
    ): int {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            throw new \RuntimeException(sprintf('Unable to open JSONL file "%s".', $path));
        }

        $index = $offset;
        $written = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                if (!\is_array($decoded)) {
                    continue;
                }

                /** @var array<string,mixed> $record */
                $record = $decoded;

                if ($slugifyField && isset($record[$slugifyField])) {
                    $record[$slugifyField] = $this->slugify((string) $record[$slugifyField]);
                }

                if ($primaryKeyField && !isset($record[$primaryKeyField])) {
                    $record[$primaryKeyField] = $index;
                }

                if ($onRecord) {
                    $record = $onRecord($record, $index, $path, 'jsonl');
                }

                $this->appendJsonl($output, $record);
                $index++;
                $written++;
            }
        } finally {
            fclose($handle);
        }

        return $written;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function appendJsonl(string $output, array $record): void
    {
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        if (@file_put_contents($output, $json, \FILE_APPEND | \LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Failed to append record to "%s".', $output));
        }
    }

    private function slugify(string $value): string
    {
        $value = \trim(\mb_strtolower($value));
        $value = \preg_replace('/[^a-z0-9]+/u', '-', $value) ?? $value;
        $value = \trim($value, '-');

        return $value;
    }
}
