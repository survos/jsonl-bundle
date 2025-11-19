<?php

declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use RuntimeException;
use Survos\JsonlBundle\IO\JsonlWriter;

final class JsonToJsonlConverter
{
    /**
     * Convert a JSON file (or STDIN) into a JSONL[.gz] file.
     *
     * @param string      $input  Path to JSON file, or "-" for STDIN.
     * @param string      $output Path to JSONL or JSONL.GZ file.
     * @param string|null $key    Optional key under which records are stored, e.g. "products", "hits".
     *
     * @return int Number of records written.
     */
    public function convertFile(string $input, string $output, ?string $key = null): int
    {
        // If a key is provided, stream that subtree with JsonMachine.
        if ($key !== null && $key !== '') {
            return $this->streamRecordsUnderKey($input, $output, $key);
        }

        // Otherwise, decode the whole payload and normalize.
        return $this->convertWholeDocument($input, $output);
    }

    /**
     * Stream records from a specific key using JsonMachine.
     *
     * Example JSON:
     * {
     *   "products": [ { ... }, { ... } ]
     * }
     *
     * With $key = "products", this will stream each product element.
     */
    private function streamRecordsUnderKey(string $input, string $output, string $key): int
    {
        $pointer = '/' . $key;

        $options = [
            'decoder' => new ExtJsonDecoder(true), // assoc arrays
            'pointer' => $pointer,
        ];

        if ($input === '-') {
            $items = Items::fromStream(STDIN, $options);
        } else {
            $items = Items::fromFile($input, $options);
        }

        $writer = JsonlWriter::open($output);
        $count  = 0;

        foreach ($items as $arrayKey => $record) {
            if (!\is_array($record)) {
                // Be defensive: if the value is scalar or something odd, wrap it.
                $record = [
                    '_key'   => $arrayKey,
                    'value'  => $record,
                    '_path'  => $pointer,
                ];
            }

            // For now, we don't compute a tokenCode; caller can re-run with a
            // dedicated dedup token if they want. Index file will remain small.
            $writer->write($record);
            $count++;
        }

        $writer->close();

        return $count;
    }

    /**
     * Fallback: read the whole JSON document into memory and normalize to records.
     *
     * - If top-level is a list array: each element becomes a record.
     * - If top-level is an associative array: single record (the whole object).
     * - If top-level is scalar: single record {"value": <scalar>}.
     */
    private function convertWholeDocument(string $input, string $output): int
    {
        $json = $this->readInput($input);

        $decoded = \json_decode($json, true);

        if (\json_last_error() !== \JSON_ERROR_NONE) {
            throw new RuntimeException(sprintf(
                'Invalid JSON in "%s": %s',
                $input === '-' ? 'STDIN' : $input,
                \json_last_error_msg()
            ));
        }

        $records = $this->normalizeRecords($decoded);

        $writer = JsonlWriter::open($output);
        $count  = 0;

        foreach ($records as $record) {
            if (!\is_array($record)) {
                $record = ['value' => $record];
            }

            $writer->write($record);
            $count++;
        }

        $writer->close();

        return $count;
    }

    private function readInput(string $input): string
    {
        if ($input === '-') {
            $contents = \stream_get_contents(STDIN);
            if ($contents === false) {
                throw new RuntimeException('Failed to read from STDIN.');
            }

            return $contents;
        }

        $contents = @\file_get_contents($input);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Failed to read input file "%s".', $input));
        }

        return $contents;
    }

    /**
     * @param mixed $decoded
     *
     * @return array<int,mixed> List of records.
     */
    private function normalizeRecords(mixed $decoded): array
    {
        // If it's an array already:
        if (\is_array($decoded)) {
            // List-style array (0..n): treat each element as a record.
            if (\array_is_list($decoded)) {
                return $decoded;
            }

            // Associative array (object-like): treat whole thing as one record.
            return [$decoded];
        }

        // Anything else (scalar, null) → single record with synthetic wrapper.
        return [['value' => $decoded]];
    }
}

