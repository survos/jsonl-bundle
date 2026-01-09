<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Path;

/**
 * Extremely small naming strategy used by file-based stores in tests and examples.
 *
 * The intent is to keep filenames predictable and human-inspectable.
 */
final class SimpleBlockNamingStrategy
{
    public function __construct(
        private readonly string $suffix = '.json',
    ) {
    }

    /**
     * Return a full path for a "block" name in a base directory.
     *
     * Example:
     *   baseDir: /tmp/state
     *   name:    foo
     *   => /tmp/state/foo.json
     */
    public function path(string $baseDir, string $name): string
    {
        $baseDir = rtrim($baseDir, '/');
        $name = ltrim($name, '/');

        return $baseDir . '/' . $name . $this->suffix;
    }
}
