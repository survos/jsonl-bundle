<?php

declare(strict_types=1);

// File: src/Service/JsonlDirectoryConverter.php
// jsonl-bundle v0.7+
// Convert CSV/JSON/JSONL files (or a directory of them) into a single JSONL file.
// - Always normalize keys (BOM, whitespace, separators, casing).
// - Optionally slugify a field and inject a primary key.
// - Optionally apply a per-record callback before writing.
// - Can also write from an arbitrary record provider (iterable of arrays).
//
// IMPORTANT: $onRecord may return NULL to reject a row. Rejected rows:
//   - are not written,
//   - do not increment the "written" count,
//   - do not consume auto-generated PKs (sequence only advances on accepted rows).

namespace Survos\JsonlBundle\Service;

use League\Csv\Reader;

final class JsonlDirectoryConverter
{
    public function __construct()
    {
    }

    /**
     * Convert input file or directory to a JSONL file.
     *
     * @param string        $input           Path to CSV/JSON/JSONL file or directory
     * @param string        $output          Destination JSONL file (will be overwritten)
     * @param ?string       $slugifyField    Optional field to slugify (after key normalization)
     * @param ?string       $primaryKeyField Optional primary key field name (after key normalization)
     * @param callable|null $onRecord        Optional callback:
     *                                       function (array|null $record, int $index, string $originFile, string $format): ?array
     *
     * @return int number of records written (after filtering)
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
     * Convert an *iterable* of raw records (e.g. from a ZIP provider) to JSONL.
     *
     * Each item from $records can be:
     *   - a bare array<string,mixed>              → origin/format defaulted
     *   - or ['record' => array, 'origin' => ..., 'format' => ...]
     *
     * @param iterable<array<string,mixed>|array{record:array,origin?:string,format?:string}> $records
     * @param callable|null $onRecord function (array|null $record, int $index, string $origin, string $format): ?array
     */
    public function convertFromProvider(
        iterable $records,
        string $output,
        ?string $slugifyField = null,
        ?string $primaryKeyField = null,
        ?callable $onRecord = null,
        int $offset = 0,
        string $origin = 'provider',
        string $format = 'json',
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

        $index    = $offset; // raw record index (all rows, accepted or rejected)
        $sequence = $offset; // auto PK sequence (only accepted rows)
        $written  = 0;

        foreach ($records as $item) {
            if (!\is_array($item)) {
                $index++;
                continue;
            }

            $originFile = $origin;
            $fmt        = $format;
            $rawRecord  = $item;

            // If provider passes a structured payload, unwrap it.
            if (isset($item['record']) && \is_array($item['record'])) {
                $rawRecord  = $item['record'];
                $originFile = isset($item['origin']) ? (string) $item['origin'] : $origin;
                $fmt        = isset($item['format']) ? (string) $item['format'] : $format;
            }

            // Normalize keys from the provider + normalize scalar values
            $record = [];
            foreach ($rawRecord as $key => $value) {
                $normalizedKey = $this->normalizeKey((string) $key);
                $record[$normalizedKey] = $this->normalizeScalarForJsonl($normalizedKey, $value);
            }

            if ($slugifyField && isset($record[$slugifyField])) {
                $record[$slugifyField] = $this->slugify((string) $record[$slugifyField]);
            }

            if ($primaryKeyField && !isset($record[$primaryKeyField])) {
                $record[$primaryKeyField] = $sequence;
            }

            if ($onRecord) {
                $record = $onRecord($record, $index, $originFile, $fmt);
            }

            // Listener can reject the record by returning null
            if ($record !== null) {
                $this->appendJsonl($output, $record);
                $sequence++;
                $written++;
            }

            $index++;
        }

        return $written;
    }

    /**
     * @param callable|null $onRecord function (array|null $record, int $index, string $originFile, string $format): ?array
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
            'jsonld',
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
        // --- Delimiter sniffing: header with tabs => TSV, else CSV ---
        $sample    = @file_get_contents($path, false, null, 0, 4096) ?: '';
        $firstLine = strtok($sample, "\n") ?: '';
        $delimiter = str_contains($firstLine, "\t") ? "\t" : ',';
        // -------------------------------------------------------------

        $csv = Reader::from($path, 'r');
        $csv->setHeaderOffset(0);
        $csv->setDelimiter($delimiter);
        $csv->setEnclosure('"');
        $csv->setEscape('\\');

        $index    = $offset; // raw row index
        $sequence = $offset; // PK sequence
        $written  = 0;

        foreach ($csv->getRecords() as $row) {
            // Normalize keys + normalize scalar values
            $record = [];
            foreach ($row as $key => $value) {
                $normalizedKey = $this->normalizeKey((string) $key);
                $record[$normalizedKey] = $this->normalizeScalarForJsonl($normalizedKey, $value);
            }

            if ($slugifyField && isset($record[$slugifyField])) {
                $record[$slugifyField] = $this->slugify((string) $record[$slugifyField]);
            }

            if ($primaryKeyField && !isset($record[$primaryKeyField])) {
                $record[$primaryKeyField] = $sequence;
            }

            if ($onRecord) {
                $record = $onRecord($record, $index, $path, 'csv');
            }

            if ($record !== null) {
                $this->appendJsonl($output, $record);
                $sequence++;
                $written++;
            }

            $index++;
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

        $index    = $offset;
        $sequence = $offset;
        $written  = 0;

        foreach ($records as $item) {
            if (!\is_array($item)) {
                $index++;
                continue;
            }

            /** @var array<string,mixed> $item */
            $record = [];

            // Normalize keys + normalize scalar values for each JSON object
            foreach ($item as $key => $value) {
                $normalizedKey = $this->normalizeKey((string) $key);
                $record[$normalizedKey] = $this->normalizeScalarForJsonl($normalizedKey, $value);
            }

            if ($slugifyField && isset($record[$slugifyField])) {
                $record[$slugifyField] = $this->slugify((string) $record[$slugifyField]);
            }

            if ($primaryKeyField && !isset($record[$primaryKeyField])) {
                $record[$primaryKeyField] = $sequence;
            }

            if ($onRecord) {
                $record = $onRecord($record, $index, $path, 'json');
            }

            if ($record !== null) {
                $this->appendJsonl($output, $record);
                $sequence++;
                $written++;
            }

            $index++;
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

        $index    = $offset;
        $sequence = $offset;
        $written  = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    $index++;
                    continue;
                }

                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                if (!\is_array($decoded)) {
                    $index++;
                    continue;
                }

                /** @var array<string,mixed> $decoded */
                $record = [];

                // Normalize keys + normalize scalar values for each JSONL record
                foreach ($decoded as $key => $value) {
                    $normalizedKey = $this->normalizeKey((string) $key);
                    $record[$normalizedKey] = $this->normalizeScalarForJsonl($normalizedKey, $value);
                }

                if ($slugifyField && isset($record[$slugifyField])) {
                    $record[$slugifyField] = $this->slugify((string) $record[$slugifyField]);
                }

                if ($primaryKeyField && !isset($record[$primaryKeyField])) {
                    $record[$primaryKeyField] = $sequence;
                }

                if ($onRecord) {
                    $record = $onRecord($record, $index, $path, 'jsonl');
                }

                if ($record !== null) {
                    $this->appendJsonl($output, $record);
                    $sequence++;
                    $written++;
                }

                $index++;
            }
        } finally {
            fclose($handle);
        }

        return $written;
    }

    /**
     * @param array<string,mixed>|null $record
     */
    private function appendJsonl(string $output, ?array $record = null): void
    {
        if ($record === null) {
            return; // rejected by listener
        }

        $json = json_encode(
            $record,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR
        ) . "\n";

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

    /**
     * Normalize a raw field/key name to a canonical snake_case key.
     *
     * Examples:
     *   "Dimensions.Height"  -> "dimensions_height"
     *   "Source Name"        -> "source_name"
     *   "accessionNumber"    -> "accession_number"
     *   "ULAN"               -> "ulan"
     */
    private function normalizeKey(string $key): string
    {
        // Strip BOM if present
        $key = \preg_replace('/^\xEF\xBB\xBF/u', '', $key) ?? $key;

        // Trim whitespace
        $key = \trim($key);

        // Replace common separators with underscore
        $key = \strtr($key, [
            ' ' => '_',
            '.' => '_',
        ]);

        // CamelCase to snake_case: SomeFieldName -> Some_Field_Name
        $key = \preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;

        // Collapse multiple underscores
        $key = \preg_replace('/__+/', '_', $key) ?? $key;

        // Lowercase
        $key = \strtolower($key);

        return $key;
    }

    /**
     * Normalize a scalar for JSONL + profiling:
     *  - "" / whitespace-only → null
     *  - bool-ish strings     → bool
     *  - integer-ish strings  → int (unless it's clearly an id/code with leading zeros)
     *  - float-ish strings    → float
     *  - everything else      → original value
     */
    private function normalizeScalarForJsonl(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (!\is_string($value)) {
            return $value;
        }

        $v = \trim($value);
        if ($v === '') {
            return null;
        }

        $lowerField = \strtolower($field);
        $l          = \strtolower($v);

        // --- URL / media detection -------------------------------------------
        if (\preg_match('~^https?://~i', $v) === 1) {
            // Force https for common image extensions
            if (\str_starts_with($v, 'http://')
                && \preg_match('~\.(jpe?g|png|gif|webp)(\?|$)~i', $v) === 1
            ) {
                $v = 'https://' . \substr($v, 7);
            }

            // Later, the profiler can look at $v to tag:
            //  - image URLs      (by extension)
            //  - audio URLs      (mp3, wav, ogg, etc.)
            //  - video URLs      (mp4/webm or youtube.com / youtu.be)
            // For now we just return the normalized URL.
            return $v;
        }
        // ---------------------------------------------------------------------

        // --- Year-specific clean-up: treat 0/0000 as "no year" ---------------
        $looksLikeYear = $lowerField === 'year'
            || \str_ends_with($lowerField, '_year')
            || \str_contains($lowerField, 'year');

        if ($looksLikeYear && \in_array($v, ['0', '0000'], true)) {
            return null;
        }
        // ---------------------------------------------------------------------

        // Boolean-ish
        if (\in_array($l, ['true', 'false', 'yes', 'no', 'y', 'n', 'on', 'off'], true)) {
            return \in_array($l, ['true', 'yes', 'y', 'on', '1'], true);
        }

        $looksLikeIdOrCode = \str_contains($lowerField, 'id') || \str_contains($lowerField, 'code');

        // Integer (no decimal point, no exponent)
        if (\preg_match('/^-?\d+$/', $v) === 1) {
            $hasLeadingZero = \strlen($v) > 1 && $v[0] === '0';

            if (!$looksLikeIdOrCode || !$hasLeadingZero) {
                return (int) $v;
            }

            return $v; // code-like, keep as string
        }

        // Float
        if (\is_numeric($v) && \preg_match('/^-?(?:\d+\.\d+|\d+\.|\.\d+|\d+)(?:[eE][+\-]?\d+)?$/', $v) === 1) {
            return (float) $v;
        }

        return $v;
    }


}

