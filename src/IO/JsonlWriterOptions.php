<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

/**
 * JsonlWriter open() options.
 *
 * - ensureDir: create parent directory if missing
 * - dirPerms: permissions for created directories (when ensureDir=true)
 * - resetSidecars: when mode='w', delete known sidecar/index files before writing
 * - useLock: acquire a Symfony Lock to prevent concurrent writers
 */
final readonly class JsonlWriterOptions
{
    public function __construct(
        public bool $ensureDir = true,
        public int $dirPerms = 0o775,
        public bool $resetSidecars = true,
        public bool $useLock = true,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public static function noDirs(): self
    {
        return new self(ensureDir: false);
    }

    public static function noLock(): self
    {
        return new self(useLock: false);
    }
}
