<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Sqlite;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Streams raw JSONL lines into an ephemeral `_rows(line_no, offset, body)` staging
 * table — the shared front-end for both indexing (Phase 2) and profiling (Phase 3).
 *
 * Bodies are pushed verbatim (no JSON decoding in PHP); SQLite parses them via its
 * JSON functions downstream. For plain `.jsonl` the offset is a seekable byte
 * position; for `.jsonl.gz` it is the uncompressed-stream position.
 */
final class RowStager
{
    public static function create(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS _rows');
        $pdo->exec('CREATE TABLE _rows (line_no INTEGER PRIMARY KEY, offset INTEGER, body TEXT)');
    }

    /**
     * Stream non-empty lines into `_rows`, numbering from $startLine and (for plain
     * files) seeking to $startOffset first (used for incremental tail-scans).
     */
    public static function stream(PDO $pdo, string $path, bool $gzip, int $startOffset = 0, int $startLine = 0): void
    {
        $stmt = $pdo->prepare('INSERT INTO _rows(line_no, offset, body) VALUES(:l, :o, :b)');
        $line = $startLine;

        $pdo->beginTransaction();
        try {
            if ($gzip) {
                $fh = gzopen($path, 'rb');
                if ($fh === false) {
                    throw new RuntimeException(sprintf('Unable to open %s', $path));
                }
                try {
                    while (!gzeof($fh)) {
                        $offset = gztell($fh);
                        $raw = gzgets($fh);
                        if ($raw === false) {
                            break;
                        }
                        $body = trim($raw);
                        if ($body === '') {
                            continue;
                        }
                        $stmt->execute(['l' => ++$line, 'o' => $offset, 'b' => $body]);
                    }
                } finally {
                    gzclose($fh);
                }
            } else {
                $fh = fopen($path, 'rb');
                if ($fh === false) {
                    throw new RuntimeException(sprintf('Unable to open %s', $path));
                }
                try {
                    if ($startOffset > 0) {
                        fseek($fh, $startOffset);
                    }
                    while (true) {
                        $offset = ftell($fh);
                        $raw = fgets($fh);
                        if ($raw === false) {
                            break;
                        }
                        $body = trim($raw);
                        if ($body === '') {
                            continue;
                        }
                        $stmt->execute(['l' => ++$line, 'o' => $offset, 'b' => $body]);
                    }
                } finally {
                    fclose($fh);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Drop malformed-JSON lines from `_rows`; returns how many were removed.
     */
    public static function dropInvalid(PDO $pdo): int
    {
        return (int) $pdo->exec('DELETE FROM _rows WHERE json_valid(body) = 0');
    }

    public static function drop(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS _rows');
    }
}
