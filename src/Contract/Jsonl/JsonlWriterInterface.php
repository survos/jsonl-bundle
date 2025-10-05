<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Jsonl;

/**
 * Write exactly one JSON value per line to a target (optionally gzip).
 */
interface JsonlWriterInterface
{
    /** @param mixed $record Typically an array/object to json_encode. */
    public function write(mixed $record): void;

    /** Flush & close the underlying stream. Safe to call multiple times. */
    public function close(): void;
}
