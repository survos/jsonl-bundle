<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Jsonl;

interface JsonlReaderInterface
{
    /**
     * @return \Traversable<mixed> Yields one decoded JSON value per line.
     */
    public function getIterator(): \Traversable;

    /** Total number of lines if cheaply known (e.g., from a sidecar or pre-scan). */
    public function getLineCountHint(): ?int;

    /** Close any underlying handle. */
    public function close(): void;
}
