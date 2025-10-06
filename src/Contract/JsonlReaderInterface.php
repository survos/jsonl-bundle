<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract;

/**
 * Iterate decoded rows from a .jsonl or .jsonl.gz file.
 * Implementations SHOULD yield 1-based line numbers as keys.
 */
interface JsonlReaderInterface extends \IteratorAggregate
{
    /** Absolute path to the source file. */
    public function path(): string;

    /** @return \Traversable<int,array> */
    public function getIterator(): \Traversable;

    /**
     * Quick membership check by tokenCode, if your rows include such a field.
     * Implementations may short-circuit via an on-disk index if available.
     */
    public function containsToken(string $tokenCode): bool;
}
