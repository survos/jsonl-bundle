<?php declare(strict_types=1);

// File: src/Event/JsonlConvertFinishedEvent.php
// jsonl-bundle v0.10
// Dispatched once after a source has been converted to JSONL.

namespace Survos\JsonlBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * JsonlConvertFinishedEvent
 *
 * Emitted once after jsonl:convert has processed all records.
 * Useful for services that summarize the run, close resources, etc.
 */
final class JsonlConvertFinishedEvent extends Event
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public string $input,
        public string $output,
        public int $recordCount,
        public array $tags = [],
    ) {}
}
