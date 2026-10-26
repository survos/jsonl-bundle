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
        $archive = \substr($path, 7);
        while (!\is_file($archive)) {
            $parent = \dirname($archive);
            if ($parent === $archive || '.' === $parent) {
                throw new \RuntimeException('Archive member not found.');
            }
            $archive = $parent;
        }
        $member = \substr($path, 8 + \strlen($archive));
        self::path($archive, $member); // Apply the same member-path checks to caller-supplied URIs.
        $process = null;
        $errors = null;
        $complete = false;
        if (\str_ends_with(\strtolower($archive), '.tar')) {
            // Phar member sizes are uint32_t. Native tar streams large members unchanged;
            // the existing decoder below still handles concatenated gzip and JSONL boundaries.
            $errors = \tmpfile();
            $process = \proc_open(['tar', '-xOf', $archive, '--', $member], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $errors], $pipes);
            if (false === $process) {
                \fclose($errors);
                throw new \RuntimeException('Cannot start tar member reader.');
            }
            \fclose($pipes[0]);
            $stream = $pipes[1];
        } else {
            if (!\is_file($path)) {
                throw new \RuntimeException('Archive member not found.');
            }
            $stream = \fopen($path, 'rb');
            if (false === $stream) {
                throw new \RuntimeException('Cannot open archive member.');
            }
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

                $complete = true;

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
            if (null === $decoder && !$completeMember && null !== $process) {
                throw new \RuntimeException('Archive member not found or empty gzip payload.');
            }
            if (null !== $decoder || !$completeMember) {
                throw new \RuntimeException('Truncated gzip archive member.');
            }
            if ('' !== $buffer) {
                yield $offset => $buffer;
            }
            $complete = true;
        } finally {
            \fclose($stream);
            if (null !== $process) {
                // Early limits close the pipe without draining a multi-gigabyte member.
                if (!$complete) {
                    \proc_terminate($process);
                }
                $code = \proc_close($process);
                \rewind($errors);
                $error = \stream_get_contents($errors);
                \fclose($errors);
                if ($complete && 0 !== $code) {
                    throw new \RuntimeException('Cannot read tar archive member: '.\trim($error));
                }
            }
        }
    }
}
