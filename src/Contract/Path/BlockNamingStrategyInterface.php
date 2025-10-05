<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Path;

/**
 * Determines where the final JSONL file lives and how per-block .part files are named.
 * This enables the policy:
 *   - write each finished block to its own .part file
 *   - when the "next" block is present, append it to the final .jsonl(.gz) immediately and delete the .part
 */
interface BlockNamingStrategyInterface
{
    /** Absolute or project-relative path for the final artifact (e.g., zips/<agg>/<inst>.jsonl.gz). */
    public function finalPath(): string;

    /** Path for a given block's .part file (e.g., final + ".block.<N>.part"). */
    public function partPathFor(int $blockIndex): string;

    /** Path for the sidecar state file associated with the final artifact. */
    public function statePath(): string;
}
