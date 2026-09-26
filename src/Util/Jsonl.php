<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Util;

use function is_file;
use function glob;
use function array_unique;
use function array_values;
use function preg_replace;

/** Small helpers for JSONL files (plain or gzip). */
final class Jsonl
{
    /** A new plain write wins over an older compressed copy during a rebuild. */
    public static function resolvePath(string $path): string
    {
        return is_file($path) || self::isGzipPath($path) ? $path : (is_file($path.'.gz') ? $path.'.gz' : $path);
    }

    /** @return list<string> One stream per stem, including gzip-only streams. */
    public static function files(string $directory): array
    {
        $paths = [];
        foreach (glob($directory.'/*.jsonl{,.gz}', GLOB_BRACE) ?: [] as $path) {
            $paths[] = self::resolvePath(preg_replace('/\.gz$/', '', $path));
        }
        return array_values(array_unique($paths));
    }

    /**
     * Read a compression setting as config and env vars deliver it: false/"false"/"off"/"" (or
     * null) mean plain JSONL, an integer or numeric string 0-9 is a gzip level. Use a plain
     * %env(NAME)%: the json: processor only accepts arrays, so it rejects "1" and "false".
     */
    public static function compression(int|string|bool|null $setting): int|false
    {
        if ($setting === null || $setting === false || \in_array(\strtolower(\trim((string) $setting)), ['', 'false', 'off', 'no', 'none'], true)) {
            return false;
        }
        if ($setting === true) {
            return 1;
        }
        if (\is_int($setting) || \ctype_digit(\trim((string) $setting))) {
            $level = (int) $setting;
            if ($level >= 0 && $level <= 9) {
                return $level;
            }
        }
        throw new \InvalidArgumentException(\sprintf('Compression must be false or a level 0-9, got "%s".', (string) $setting));
    }

    /** Compression policy chooses the suffix; level zero still means gzip. */
    public static function outputPath(string $path, int|false $compression = 1): string
    {
        if ($compression !== false && ($compression < 0 || $compression > 9)) {
            throw new \InvalidArgumentException('Compression must be false or a level between 0 and 9.');
        }
        $plain = preg_replace('/\.gz$/', '', $path);
        return $compression === false ? $plain : $plain.'.gz';
    }

    public static function countLines(string $path): int
    {
        $path = self::resolvePath($path);
        if (!\is_file($path)) {
            return 0;
        }

        $gzip = self::isGzipPath($path);
        $count = 0;

        if ($gzip) {
            $h = \gzopen($path, 'rb');
            if (!$h) { return 0; }
            try {
                while (!\gzeof($h)) {
                    $chunk = \gzread($h, 1 << 20); // 1 MiB
                    if ($chunk === false) { break; }
                    $count += \substr_count($chunk, "\n");
                }
            } finally {
                \gzclose($h);
            }
            return $count;
        }

        $h = \fopen($path, 'rb');
        if (!$h) { return 0; }
        try {
            while (!\feof($h)) {
                $chunk = \fread($h, 1 << 20);
                if ($chunk === false) { break; }
                $count += \substr_count($chunk, "\n");
            }
        } finally {
            \fclose($h);
        }
        return $count;
    }

    public static function isGzipPath(string $path): bool
    {
        return \str_ends_with($path, '.gz') || \str_ends_with($path, '.gzip');
    }
}
