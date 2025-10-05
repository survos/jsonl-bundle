<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\State;

interface StateStoreInterface
{
    /**
     * Load persisted state or return an empty array when none exists.
     * Recommended keys (by convention):
     *   - last_block_written: int (>= -1)
     *   - total_lines: int
     *   - updated_at: string (ISO8601)
     */
    public function load(): array;

    /**
     * Persist state atomically (e.g., write to tmp then rename).
     *
     * @param array $state Arbitrary associative data; stick to the recommended keys.
     */
    public function save(array $state): void;

    /** Optional: clear the state (e.g., delete the sidecar file). */
    public function clear(): void;
}
