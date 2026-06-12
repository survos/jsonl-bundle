<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Sqlite;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Per-file SQLite sidecar store (`<file>.db`).
 *
 * Phase 1 scope (see doc/adr-0001-sqlite-sidecar.md): a typed `meta` key/value
 * table that replaces `<file>.sidecar.json` as the JSONL state store. Later
 * phases add `idx`, `field_stats`, and the data cache to the same database.
 *
 * The `.jsonl[.gz]` log remains the source of truth; this DB is a derived,
 * rebuildable cache and is never authoritative.
 */
final class SidecarDb
{
    public const int SCHEMA_VERSION = 4;

    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $dbPath,
    ) {
    }

    public function path(): string
    {
        return $this->dbPath;
    }

    /**
     * Load the persisted meta, or null if no sidecar DB / no state exists yet.
     */
    public function loadMeta(): ?SidecarMeta
    {
        $rows = $this->readRows();

        return ($rows === null || $rows === []) ? null : SidecarMeta::fromRow($rows);
    }

    /**
     * Whether this file has persisted state (the DB exists and holds meta rows).
     */
    public function hasState(): bool
    {
        $rows = $this->readRows();

        return $rows !== null && $rows !== [];
    }

    /**
     * Upsert the meta in a single transaction.
     */
    public function saveMeta(SidecarMeta $meta): void
    {
        $pdo = $this->pdo(true);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO meta(key, value) VALUES(:k, :v)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value'
            );
            foreach ($meta->toRow() as $key => $value) {
                $stmt->execute(['k' => $key, 'v' => $value]);
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
     * @return array<string, string>|null  null when no DB file exists yet
     */
    private function readRows(): ?array
    {
        if (!is_file($this->dbPath)) {
            return null;
        }

        $stmt = $this->pdo(false)->query('SELECT key, value FROM meta');

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $map[(string) $row[0]] = (string) $row[1];
        }

        return $map;
    }

    private function pdo(bool $create): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if (!is_file($this->dbPath) && !$create) {
            throw new RuntimeException(sprintf('Sidecar DB does not exist: %s', $this->dbPath));
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $dir));
        }

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $this->migrate($pdo);

        return $this->pdo = $pdo;
    }

    private function migrate(PDO $pdo): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

        if ($version === self::SCHEMA_VERSION) {
            return;
        }

        if ($version > self::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'Sidecar DB schema version %d is newer than supported %d at %s',
                $version,
                self::SCHEMA_VERSION,
                $this->dbPath,
            ));
        }

        // Stepwise, idempotent upgrades from $version up to SCHEMA_VERSION.
        if ($version < 1) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)');
        }
        if ($version < 2) {
            // pk -> byte offset, plus a covering `attrs` JSON of low-cardinality
            // facet fields (see doc/adr-0001-sqlite-sidecar.md §3a).
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS idx (
                    pk     TEXT PRIMARY KEY,
                    offset INTEGER,
                    line   INTEGER,
                    attrs  TEXT
                )'
            );
        }
        if ($version < 3) {
            // one row per observed field path; bounded by construction
            // (top-N values + exact counts, never value-list blobs). ADR 0001 §3.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS field_stats (
                    path        TEXT PRIMARY KEY,
                    json_types  TEXT,
                    present     INTEGER,
                    non_null    INTEGER,
                    distinct_n  INTEGER,
                    min_v       TEXT,
                    max_v       TEXT,
                    len_min     INTEGER,
                    len_max     INTEGER,
                    len_avg     REAL,
                    top_values  TEXT,
                    is_array    INTEGER DEFAULT 0,
                    heuristics  TEXT,
                    elements    TEXT
                )'
            );
        }

        if ($version === 3) {
            // array-element stats (count, distinct, avgPerRow, top-N) for is_array
            // fields - folded into the parent field row (ADR 0001 §4).
            $pdo->exec('ALTER TABLE field_stats ADD COLUMN elements TEXT');
        }

        $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    /**
     * Read-write connection to the sidecar DB (creates + migrates the file).
     * Used by the indexer to build `idx` / staging in the same database.
     */
    public function connection(): PDO
    {
        return $this->pdo(true);
    }

    /**
     * Whether the `idx` table holds any rows.
     */
    public function hasIdx(): bool
    {
        if (!is_file($this->dbPath)) {
            return false;
        }

        return (bool) $this->pdo(false)->query('SELECT EXISTS(SELECT 1 FROM idx)')->fetchColumn();
    }

    /**
     * Number of distinct primary keys in the index.
     */
    public function keyCount(): int
    {
        if (!is_file($this->dbPath)) {
            return 0;
        }

        return (int) $this->pdo(false)->query('SELECT COUNT(*) FROM idx')->fetchColumn();
    }

    /**
     * Field names inlined into the covering `attrs` (derived from a sample row).
     *
     * @return list<string>
     */
    public function facetFields(): array
    {
        if (!is_file($this->dbPath)) {
            return [];
        }

        $attrs = $this->pdo(false)->query('SELECT attrs FROM idx WHERE attrs IS NOT NULL LIMIT 1')->fetchColumn();
        if (!is_string($attrs)) {
            return [];
        }

        $decoded = json_decode($attrs, true);

        return is_array($decoded) ? array_map(static fn ($k): string => (string) $k, array_keys($decoded)) : [];
    }

    /**
     * Flush the WAL into the main DB file and truncate it. Call before copying or
     * moving a `<file>.db` so the copy is self-contained (WAL mode otherwise keeps
     * recent writes in the sibling `-wal` file).
     */
    public function checkpoint(): void
    {
        if (!is_file($this->dbPath)) {
            return;
        }
        $this->pdo(false)->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    }

    /**
     * Whether the persisted `_rows` data cache exists and holds rows.
     */
    public function hasCache(): bool
    {
        if (!is_file($this->dbPath)) {
            return false;
        }
        $exists = $this->pdo(false)
            ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='_rows'")
            ->fetchColumn();
        if ($exists === false) {
            return false;
        }

        return (bool) $this->pdo(false)->query('SELECT EXISTS(SELECT 1 FROM _rows)')->fetchColumn();
    }

    /**
     * Drop the persisted data cache (`_rows`, any `_rows_*` children, the `v_rows`
     * view) and `VACUUM`. Keeps `meta`/`idx`/`field_stats`. Returns bytes reclaimed.
     */
    public function vacuumCache(): int
    {
        if (!is_file($this->dbPath)) {
            return 0;
        }

        $before = filesize($this->dbPath) ?: 0;
        $pdo = $this->pdo(true);

        $pdo->exec('DROP VIEW IF EXISTS v_rows');
        $pdo->exec('DROP TABLE IF EXISTS _rows');
        foreach (
            $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '\_rows\_%' ESCAPE '\'")
                ->fetchAll(PDO::FETCH_COLUMN) as $child
        ) {
            $pdo->exec('DROP TABLE IF EXISTS "' . str_replace('"', '""', (string) $child) . '"');
        }
        $pdo->exec('VACUUM');
        // In WAL mode the compaction only reaches the main file after a truncating
        // checkpoint (verified: VACUUM alone leaves the file size unchanged).
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

        clearstatcache(true, $this->dbPath);

        return max(0, $before - (filesize($this->dbPath) ?: 0));
    }

    /**
     * Load the profiler's per-field stats (json columns decoded), present-desc.
     *
     * @return list<array<string, mixed>>
     */
    public function loadFieldStats(): array
    {
        if (!is_file($this->dbPath)) {
            return [];
        }

        $rows = [];
        foreach ($this->pdo(false)->query('SELECT * FROM field_stats ORDER BY present DESC, path') as $row) {
            $row['json_types'] = json_decode((string) ($row['json_types'] ?? '{}'), true) ?: [];
            $row['top_values'] = json_decode((string) ($row['top_values'] ?? '[]'), true) ?: [];
            $row['heuristics'] = json_decode((string) ($row['heuristics'] ?? '{}'), true) ?: [];
            $row['elements'] = array_key_exists('elements', $row) && $row['elements'] !== null
                ? json_decode((string) $row['elements'], true)
                : null;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Byte offset for a primary key, or null if unknown.
     *
     * For plain `.jsonl` the offset is usable with fseek(); for `.jsonl.gz` it is
     * the uncompressed-stream position (usable with gzseek(), O(n)-ish).
     */
    public function lookupOffset(string $pk): ?int
    {
        if (!is_file($this->dbPath)) {
            return null;
        }

        $stmt = $this->pdo(false)->prepare('SELECT offset FROM idx WHERE pk = :pk');
        $stmt->execute(['pk' => $pk]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    /**
     * Facet counts for an indexed field, computed from `idx.attrs` with no data scan.
     *
     * @return array<string, int> value => count, descending
     */
    public function facetCounts(string $field): array
    {
        if (!is_file($this->dbPath)) {
            return [];
        }
        if (!preg_match('/^[A-Za-z0-9_.]+$/', $field)) {
            throw new InvalidArgumentException(sprintf('Invalid field name: %s', $field));
        }

        $sql = sprintf(
            "SELECT attrs ->> '$.%s' AS v, COUNT(*) AS c
             FROM idx WHERE attrs IS NOT NULL
             GROUP BY v ORDER BY c DESC, v",
            $field,
        );

        $out = [];
        foreach ($this->pdo(false)->query($sql) as $row) {
            $out[(string) $row['v']] = (int) $row['c'];
        }

        return $out;
    }
}

/**
 * Typed view of the sidecar `meta` table — the storage-facing counterpart of
 * the public {@see \Survos\JsonlBundle\Model\JsonlSidecar} DTO.
 *
 * Co-located with SidecarDb (internal to the Sqlite layer). It owns the
 * typed <-> TEXT coercion for the key/value cells, and normalises keys to
 * camelCase per platform convention (e.g. jsonlMtime, not jsonl_mtime).
 */
final class SidecarMeta
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public ?string $startedAt = null,
        public ?string $updatedAt = null,
        public int $rows = 0,
        public int $bytes = 0,
        public bool $completed = false,
        public ?int $jsonlMtime = null,
        public ?int $jsonlSize = null,
        public array $context = [],
        public int $schemaVersion = SidecarDb::SCHEMA_VERSION,
    ) {
    }

    /**
     * @param array<string, string> $row
     */
    public static function fromRow(array $row): self
    {
        $str = static fn (string $k): ?string => isset($row[$k]) && $row[$k] !== '' ? $row[$k] : null;
        $int = static fn (string $k): ?int => isset($row[$k]) && $row[$k] !== '' ? (int) $row[$k] : null;

        $context = [];
        if (isset($row['context']) && $row['context'] !== '') {
            $decoded = json_decode($row['context'], true);
            if (is_array($decoded)) {
                $context = $decoded;
            }
        }

        return new self(
            startedAt: $str('startedAt'),
            updatedAt: $str('updatedAt'),
            rows: $int('rows') ?? 0,
            bytes: $int('bytes') ?? 0,
            completed: ($row['completed'] ?? '0') === '1',
            jsonlMtime: $int('jsonlMtime'),
            jsonlSize: $int('jsonlSize'),
            context: $context,
            schemaVersion: $int('schemaVersion') ?? SidecarDb::SCHEMA_VERSION,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toRow(): array
    {
        $context = json_encode($this->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($context === false) {
            $context = '[]';
        }

        return [
            'startedAt' => $this->startedAt ?? '',
            'updatedAt' => $this->updatedAt ?? '',
            'rows' => (string) $this->rows,
            'bytes' => (string) $this->bytes,
            'completed' => $this->completed ? '1' : '0',
            'jsonlMtime' => $this->jsonlMtime !== null ? (string) $this->jsonlMtime : '',
            'jsonlSize' => $this->jsonlSize !== null ? (string) $this->jsonlSize : '',
            'context' => $context,
            'schemaVersion' => (string) $this->schemaVersion,
        ];
    }
}
