<?php

declare(strict_types=1);

namespace Survos\JsonlBundle\IO;

use Survos\JsonlBundle\Util\Jsonl;

/** Read-only archive payload streaming. BagIt verification belongs to the caller. */
final class ArchiveJsonl
{
    public static function isPath(string $path): bool
    {
        return \str_starts_with($path, 'phar://');
    }

    public static function path(string $archive, string $member): string
    {
        if (!\is_file($archive) || \str_contains($archive, '://')) {
            throw new \InvalidArgumentException('Expected a local archive file.');
        }
        if ('' === $member || \str_starts_with($member, '/') || \str_contains($member, '\\') || \str_contains('/'.$member.'/', '/../') || \str_contains('/'.$member.'/', '/./') || \str_contains($member, "\0")) {
            throw new \InvalidArgumentException('Expected a relative archive member path.');
        }

        return 'phar://'.$archive.'/'.$member;
    }

    /** Caller creates the writable cache directory; never writes into the archive. */
    public static function cacheBase(string $path, string $directory): string
    {
        if (!\is_dir($directory) || !\is_writable($directory)) {
            throw new \InvalidArgumentException('Create a writable sidecar directory first.');
        }
        $archive = \substr($path, 7);
        while (!\is_file($archive)) {
            $slash = \strrpos($archive, '/');
            if (false === $slash || 0 === $slash) {
                throw new \InvalidArgumentException('Archive file not found.');
            }
            $archive = \substr($archive, 0, $slash);
        }
        $digest = \hash_file('sha256', $archive);
        if (false === $digest) {
            throw new \RuntimeException('Cannot checksum archive.');
        }
        $member = \substr($path, 7 + \strlen($archive) + 1);

        return \rtrim($directory, '/').'/'.$digest.'-'.\hash('sha256', $member);
    }

    /** @return \Generator<int, string> Uncompressed byte offset => complete line, including newline. */
    public static function lines(string $path): \Generator
    {
        if (!\is_file($path)) {
            throw new \RuntimeException('Archive member not found.');
        }
        $stream = \fopen($path, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Cannot open archive member.');
        }
        try {
            if (!Jsonl::isGzipPath($path)) {
                while (true) {
                    $offset = \ftell($stream);
                    $line = \fgets($stream);
                    if (false === $line) {
                        break;
                    }
                    yield $offset => $line;
                }

                return;
            }
            // gzopen requires a file descriptor, which phar streams cannot provide.
            // Decode each gzip member explicitly; a zlib stream filter stops at the first.
            $decoder = null;
            $buffer = '';
            $offset = 0;
            $completeMember = false;
            while (!\feof($stream)) {
                $chunk = \fread($stream, 65536);
                if (false === $chunk) {
                    throw new \RuntimeException('Cannot read archive member.');
                }
                while ('' !== $chunk) {
                    $decoder ??= \inflate_init(ZLIB_ENCODING_GZIP);
                    if (false === $decoder) {
                        throw new \RuntimeException('Cannot initialize gzip decoder.');
                    }
                    $before = \inflate_get_read_len($decoder);
                    \set_error_handler(static function (int $severity, string $message): never {
                        throw new \RuntimeException('Invalid gzip archive member: '.$message);
                    });
                    try {
                        $decoded = \inflate_add($decoder, $chunk, ZLIB_SYNC_FLUSH);
                    } finally {
                        \restore_error_handler();
                    }
                    if (false === $decoded) {
                        throw new \RuntimeException('Invalid gzip archive member.');
                    }
                    $consumed = \inflate_get_read_len($decoder) - $before;
                    if ($consumed <= 0) {
                        throw new \RuntimeException('Gzip decoder made no progress.');
                    }
                    $chunk = \substr($chunk, $consumed);
                    $buffer .= $decoded;
                    while (false !== $newline = \strpos($buffer, "\n")) {
                        $line = \substr($buffer, 0, $newline + 1);
                        $buffer = \substr($buffer, $newline + 1);
                        yield $offset => $line;
                        $offset += \strlen($line);
                    }
                    if (ZLIB_STREAM_END === \inflate_get_status($decoder)) {
                        $decoder = null;
                        $completeMember = true;
                    }
                }
            }
            if (null !== $decoder || !$completeMember) {
                throw new \RuntimeException('Truncated gzip archive member.');
            }
            if ('' !== $buffer) {
                yield $offset => $buffer;
            }
        } finally {
            \fclose($stream);
        }
    }
}
