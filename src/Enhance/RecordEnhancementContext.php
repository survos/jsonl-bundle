<?php declare(strict_types=1);

namespace Survos\JsonlBundle\Enhance;

/**
 * Context passed to enhancement listeners.
 *
 * Simple, mutable, PHP 8.4-style DTO:
 *  - public promoted props
 *  - record is mutable by listeners
 */
final class RecordEnhancementContext
{
    /**
     * @param array<string,mixed> $record
     */
    public function __construct(
        public string $dataset,
        public array $record,
        public ?string $originFile = null,
        public ?string $originFormat = null, // 'csv', 'json', 'jsonl', ...
    ) {}
}
