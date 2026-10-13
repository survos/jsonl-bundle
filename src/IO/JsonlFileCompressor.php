<?php

declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

use Survos\JsonlBundle\Service\JsonlStateService;
use Survos\JsonlBundle\Util\Jsonl;

/** Compress a closed JSONL stream without decoding/re-encoding its records. Caller owns the dataset lock. */
final class JsonlFileCompressor
{
    public function compress(string $source, ?int $level = null): string
    {
        $level ??= JsonlWriter::getDefaultCompressionLevel();
        $target = Jsonl::outputPath($source, $level);
        if (Jsonl::isGzipPath($source) || !\is_file($source) || \is_link($source)) {
            throw new \RuntimeException('Expected a regular, uncompressed JSONL file.');
        }
        $mtime = \filemtime($source);
        $size = \filesize($source);
        $tmp = $target.'.'.\bin2hex(\random_bytes(8)).'.tmp';
        $in = \fopen($source, 'rb');
        if (false === $in) {
            throw new \RuntimeException('Cannot read JSONL input.');
        }
        try {
            $out = \gzopen($tmp, 'wb'.$level);
            if (false === $out) {
                throw new \RuntimeException('Cannot create gzip output.');
            }
            $expected = \hash_init('sha256');
            try {
                while (!\feof($in)) {
                    $chunk = \fread($in, 1 << 20);
                    if (false === $chunk) {
                        throw new \RuntimeException('Cannot read JSONL input.');
                    }
                    \hash_update($expected, $chunk);
                    if (\gzwrite($out, $chunk) !== \strlen($chunk)) {
                        throw new \RuntimeException('Incomplete gzip write.');
                    }
                }
            } finally {
                if (!\gzclose($out)) {
                    throw new \RuntimeException('Cannot finish gzip output.');
                }
            }
            $verify = \gzopen($tmp, 'rb');
            if (false === $verify) {
                throw new \RuntimeException('Cannot verify gzip output.');
            }
            $actual = \hash_init('sha256');
            try {
                while (!\gzeof($verify)) {
                    $chunk = \gzread($verify, 1 << 20);
                    if (false === $chunk) {
                        throw new \RuntimeException('Cannot verify gzip output.');
                    }
                    \hash_update($actual, $chunk);
                }
            } finally {
                \gzclose($verify);
            }
            \clearstatcache(true, $source);
            if (!\hash_equals(\hash_final($expected), \hash_final($actual))
                || \filesize($source) !== $size || \filemtime($source) !== $mtime) {
                throw new \RuntimeException('JSONL changed or gzip verification failed; original retained.');
            }
            // Preserve source ordering used to decide whether enriched data is current.
            if (!\touch($tmp, $mtime) || !\rename($tmp, $target)) {
                throw new \RuntimeException('Cannot publish gzip output.');
            }
            $state = new JsonlStateService();
            if ($state->exists($source)) {
                $state->saveSidecar($target, $state->loadSidecar($source));
                $state->touch($target);
            }
            $state->closeAll();
            if (!\unlink($source)) {
                throw new \RuntimeException('Cannot remove verified plain input.');
            }
            // The plain file's sidecar now describes a file that no longer exists.
            if ($state->exists($target) && \is_file($source.'.db')) {
                \unlink($source.'.db');
            }

            return $target;
        } finally {
            \fclose($in);
            if (\is_file($tmp)) {
                \unlink($tmp);
            }
        }
    }
}
