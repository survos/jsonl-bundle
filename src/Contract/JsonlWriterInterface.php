<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract;

/**
 * Append one JSON-encoded record per line to a .jsonl or .jsonl.gz file.
 */
interface JsonlWriterInterface
{
    /**
     * Open (or create) a JSONL writer for the given path.
     * The file may be plain text (.jsonl) or gzipped (.jsonl.gz).
     *
     * @param string $filename   Target file path (.jsonl or .jsonl.gz)
     * @param bool   $createDirs If true, ensure parent directory exists (default: true)
     * @param int    $dirPerms   Mode for created directories (default: 0775)
     */
    public static function open(string $filename, bool $createDirs = true, int $dirPerms = 0o775): self;

    /**
     * Write a single row (array/object) as one line.
     * If $tokenCode is provided, duplicate rows are skipped
     * and tracked in a sidecar index (<file>.idx.json).
     */
    public function write(array $row, ?string $tokenCode = null): void;

    /** Flush and close handles; persist any side indexes. */
    public function close(): void;
}
