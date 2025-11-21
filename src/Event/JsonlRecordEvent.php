<?php declare(strict_types=1);

// File: src/Event/JsonlRecordEvent.php
// jsonl-bundle v0.9
// Lightweight per-record event for in-process tweaks (trim, normalize, add citation, etc.)
// Now uses generic tags instead of a special "dataset" concept.

namespace Survos\JsonlBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * JsonlRecordEvent
 *
 * Emitted once per record by jsonl-bundle commands/services that stream
 * sources (CSV, JSON, JSONL, directories, etc.) into JSONL.
 *
 * Listeners can:
 *  - Inspect and mutate $record in-place
 *  - Filter by $tags (e.g. ["wcma", "source:csv"])
 *  - Use $origin, $format, $index for context if needed
 */
final class JsonlRecordEvent extends Event
{
    const STATUS_OKAY = 'okay';
    const STATUS_DUPLICATE = 'duplicate';
    /**
     * @param array<string,mixed> $record
     * @param string[]            $tags
     */
    public function __construct(
        public ?array $record, // do not insert if null, it's a way for the listener to ignore it
        public string $dataset,
        public ?string $origin = null,         // e.g. filename or URI
        public ?string $format = null,         // e.g. "csv", "json", "jsonl"
        public ?int $index = null,             // 0-based record index if known
        public array $tags = [],               // generic routing / classification tags,
        public ?string $status = null,
    ) {}
}
