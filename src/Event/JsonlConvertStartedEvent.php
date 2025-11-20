<?php declare(strict_types=1);

// File: src/Event/JsonlConvertStartedEvent.php
// jsonl-bundle v0.10
// Dispatched once before a source is converted to JSONL.

namespace Survos\JsonlBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * JsonlConvertStartedEvent
 *
 * Emitted once before jsonl:convert begins processing records.
 * Useful for services that need to set up resources, open files, etc.
 */
final class JsonlConvertStartedEvent extends Event
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public string $input,
        public string $output,
        public array $tags = [],
    ) {}
}
