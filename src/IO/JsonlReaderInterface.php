<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

/**
 * Simple interface for streaming JSONL rows as associative arrays.
 */
interface JsonlReaderInterface extends \IteratorAggregate
{
    /**
     * @return \Traversable<int, array<string, mixed>>
     */
    public function getIterator(): \Traversable;
}
