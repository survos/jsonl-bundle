<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Tests\Util;

final class TestPaths
{
    public static function tempDir(string $prefix = 'jsonl-bundle-'): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $dir  = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));

        if (!@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create temp dir "%s".', $dir));
        }

        return $dir;
    }

    public static function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            $path = (string) $file;
            if (is_dir($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
