<?php declare(strict_types=1);

// File: src/Message/EnhanceRecordMessage.php
// JsonlBundle Enhancement Pipeline v0.4
// This iteration: async transport message; worker will dispatch EnhanceRecordEvent and write JSONL.

namespace Survos\JsonlBundle\Message;

/**
 * Thin async transport message for record enhancement.
 *
 * The "real" logic lives in EnhanceRecordEvent + its listeners.
 * The worker will:
 *  - dispatch the event
 *  - append the enhanced record to outputFile as JSONL
 */
final readonly class EnhanceRecordMessage
{
    /**
     * @param array<string,mixed> $record
     */
    public function __construct(
        public string $inputFile,
        public string $outputFile,
        public string $dataset,
        public array $record,
        public ?string $originFormat = null,
    ) {}
}
