<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Util;

/** Small helpers for JSONL files (plain or gzip). */
final class Jsonl
{
    public static function countLines(string $path): int
    {
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
