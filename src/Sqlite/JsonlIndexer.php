<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Sqlite;

use InvalidArgumentException;
use RuntimeException;
use Survos\JsonlBundle\Util\Jsonl;

/**
 * Builds the `<file>.db` sidecar index for a JSONL artifact (ADR 0001, PLAN Phase 2):
 *
 *  - streams raw lines, pushing each (offset, body) into ephemeral `_rows`;
 *  - drops malformed JSON lines;
 *  - builds `idx` (pk -> offset) + covering `attrs` via SQLite JSON functions
 *    (no JSON decoding in PHP), last-occurrence-wins for duplicate keys;
 *  - records the authoritative row count + file facts into `meta`;
 *  - drops the `_rows` staging (Phase 2 keeps it ephemeral; Phase 7 persists it).
 *
 * Plain `.jsonl` that has only grown is indexed incrementally (tail-scan from the
 * previously recorded size). `.jsonl.gz` is always a full scan (offsets are not
 * seekable; ADR §8).
 */
final class JsonlIndexer
{
    /**
     * @param list<string> $pkFields    primary-key field(s); composite keys are joined with ':'
     * @param list<string> $facetFields fields inlined into the covering `attrs`
     */
    public function index(string $jsonlPath, array $pkFields = ['id'], array $facetFields = [], bool $persist = true): IndexResult
    {
        if (!is_file($jsonlPath)) {
            throw new RuntimeException(sprintf('File not found: %s', $jsonlPath));
        }
        if ($pkFields === []) {
            $pkFields = ['id'];
        }

        $db = new SidecarDb($jsonlPath . '.db');
        $pdo = $db->connection();

        $gzip = Jsonl::isGzipPath($jsonlPath);
        $prev = $db->loadMeta();
        $currentSize = filesize($jsonlPath);

        // Incremental tail-scan needs the persisted `_rows` cache to append to (so it
        // stays a whole-file materialization) AND the existing `idx` to upsert into.
        $incremental = !$gzip
            && $prev !== null
            && $prev->jsonlSize !== null
            && $currentSize !== false
            && $currentSize >= $prev->jsonlSize
            && $db->hasIdx()
            && $db->hasCache();

        if ($incremental) {
            // append new lines onto the existing cache, continuing line numbering
            $startLine = (int) $pdo->query('SELECT COALESCE(MAX(line_no), 0) FROM _rows')->fetchColumn();
            RowStager::stream($pdo, $jsonlPath, $gzip, (int) $prev->jsonlSize, $startLine);
        } else {
            $startLine = 0;
            RowStager::create($pdo);
            RowStager::stream($pdo, $jsonlPath, $gzip, 0, 0);
            $pdo->exec('DELETE FROM idx');
        }

        $invalid = RowStager::dropInvalid($pdo);

        $pkExpr = $this->pkExpr($pkFields);
        $attrsExpr = $this->attrsExpr($facetFields);
        $lineFilter = $incremental ? ' WHERE line_no > ' . $startLine : '';
        $pdo->exec(sprintf(
            "INSERT INTO idx(pk, offset, line, attrs)
             SELECT COALESCE(%s, '#' || line_no), offset, line_no, %s
             FROM _rows%s ORDER BY line_no
             ON CONFLICT(pk) DO UPDATE SET
                 offset = excluded.offset, line = excluded.line, attrs = excluded.attrs",
            $pkExpr,
            $attrsExpr,
            $lineFilter,
        ));

        foreach ($facetFields as $field) {
            $pdo->exec(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON idx(attrs ->> %s)',
                $this->ident('ix_idx_' . $field),
                $this->lit($this->jsonPath($field)),
            ));
        }

        // `_rows` now materializes the whole file (full stage, or cache + appended tail).
        $rows = (int) $pdo->query('SELECT COUNT(*) FROM _rows')->fetchColumn();
        $keys = (int) $pdo->query('SELECT COUNT(*) FROM idx')->fetchColumn();

        // Persist `_rows` by default as the browseable data cache (ADR 0001 §4a);
        // `jsonl:vacuum` reclaims it. The `.jsonl` stays the source of truth.
        if (!$persist) {
            RowStager::drop($pdo);
        }

        $this->writeMeta($db, $prev ?? new SidecarMeta(), $jsonlPath, $rows);

        return new IndexResult(
            rows: $rows,
            keys: $keys,
            invalid: $invalid,
            mode: $incremental ? 'incremental' : 'full',
        );
    }

    private function writeMeta(SidecarDb $db, SidecarMeta $meta, string $path, int $rows): void
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $meta->startedAt ??= $now;
        $meta->updatedAt = $now;
        $meta->rows = $rows;

        $size = filesize($path);
        $mtime = filemtime($path);
        $meta->jsonlSize = $size !== false ? $size : null;
        $meta->jsonlMtime = $mtime !== false ? $mtime : null;

        $db->saveMeta($meta);
    }

    /**
     * @param list<string> $fields
     */
    private function pkExpr(array $fields): string
    {
        $parts = array_map(
            fn (string $f): string => sprintf('(body ->> %s)', $this->lit($this->jsonPath($f))),
            $fields,
        );

        return count($parts) === 1 ? $parts[0] : implode(" || ':' || ", $parts);
    }

    /**
     * @param list<string> $fields
     */
    private function attrsExpr(array $fields): string
    {
        if ($fields === []) {
            return 'NULL';
        }

        $args = [];
        foreach ($fields as $f) {
            $args[] = $this->lit($f);
            $args[] = sprintf('body ->> %s', $this->lit($this->jsonPath($f)));
        }

        return 'json_object(' . implode(', ', $args) . ')';
    }

    private function jsonPath(string $field): string
    {
        if (!preg_match('/^[A-Za-z0-9_.]+$/', $field)) {
            throw new InvalidArgumentException(sprintf('Invalid field name: %s', $field));
        }

        return '$.' . $field;
    }

    private function lit(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }

    private function ident(string $s): string
    {
        return '"' . str_replace('"', '""', $s) . '"';
    }
}

/**
 * Outcome of a {@see JsonlIndexer::index()} run.
 */
final readonly class IndexResult
{
    public function __construct(
        public int $rows,
        public int $keys,
        public int $invalid,
        public string $mode,
    ) {
    }
}
