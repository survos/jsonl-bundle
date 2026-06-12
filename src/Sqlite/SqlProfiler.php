<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Sqlite;

use PDO;
use RuntimeException;
use Survos\JsonlBundle\Util\Jsonl;

/**
 * SQL-based JSONL profiler (ADR 0001 §3, PLAN Phase 3).
 *
 * Stages the file into `_rows`, then computes per-field stats with SQLite's JSON
 * functions (`json_tree`/`json_type`) into the `field_stats` table — replacing the
 * in-PHP accumulator that OOMed on array/blob fields (doc/profiler.md).
 *
 * Bounded by construction: exact `COUNT(DISTINCT)` (a number, never a value list)
 * and a fixed top-N values per field. Object containers are descended; array
 * interiors are left to Phase 4 (child tables) — array-valued fields are recorded
 * with `is_array = 1`. Heuristics are derived from the bounded top-N sample.
 */
final class SqlProfiler
{
    /** image extensions for the imageLike heuristic */
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp'];

    public function profile(string $jsonlPath, int $topN = 20, int $maxDepth = 6, int $maxFields = 2000, bool $persist = true): ProfileResult
    {
        if (!is_file($jsonlPath)) {
            throw new RuntimeException(sprintf('File not found: %s', $jsonlPath));
        }

        $db = new SidecarDb($jsonlPath . '.db');
        $pdo = $db->connection();

        RowStager::create($pdo);
        RowStager::stream($pdo, $jsonlPath, Jsonl::isGzipPath($jsonlPath), 0, 0);
        $invalid = RowStager::dropInvalid($pdo);
        $rows = (int) $pdo->query('SELECT COUNT(*) FROM _rows')->fetchColumn();

        $fields = $this->computeStats($pdo, $topN, $maxDepth);

        $truncated = 0;
        if (count($fields) > $maxFields) {
            uasort($fields, static fn (array $a, array $b): int => $b['present'] <=> $a['present']);
            $truncated = count($fields) - $maxFields;
            $fields = array_slice($fields, 0, $maxFields, true);
        }

        $pdo->exec('DELETE FROM field_stats');
        $this->writeStats($pdo, $fields);

        // Persist `_rows` as the browseable cache + build a friendly VIEW projecting
        // each scalar field to a column (folio FolioViewBuilder pattern). ADR 0001 §4a.
        if ($persist) {
            $this->buildView($pdo, $fields);
        } else {
            RowStager::drop($pdo);
        }

        return new ProfileResult($rows, count($fields), $invalid, $truncated);
    }

    /**
     * @return array<string, array<string, mixed>> path => stat row
     */
    private function computeStats(PDO $pdo, int $topN, int $maxDepth): array
    {
        // depth = number of '.' in the JSON path; bounds pathological nesting.
        $depth = "(length(fullkey) - length(replace(fullkey, '.', '')))";
        // scalar (non-structural) leaves outside arrays
        $scalarWhere = "fullkey <> '$' AND type NOT IN ('object','array','null') AND fullkey NOT LIKE '%[%' AND $depth <= $maxDepth";
        // everything except object containers and array interiors (for the type histogram)
        $nodeWhere = "fullkey <> '$' AND type <> 'object' AND fullkey NOT LIKE '%[%' AND $depth <= $maxDepth";

        /** @var array<string, array<string, mixed>> $fields */
        $fields = [];
        // A) per-(path,type) counts -> histogram, present, non_null, is_array
        $sql = "SELECT fullkey, type, COUNT(*) c FROM _rows, json_tree(_rows.body)
                WHERE $nodeWhere GROUP BY fullkey, type";
        foreach ($pdo->query($sql) as $r) {
            $path = $this->normalize((string) $r['fullkey']);
            if (!isset($fields[$path])) {
                $fields[$path] = $this->emptyStat($path);
            }
            $f = &$fields[$path];
            $c = (int) $r['c'];
            $type = (string) $r['type'];
            $f['json_types'][$type] = $c;
            $f['present'] += $c;
            if ($type !== 'null') {
                $f['non_null'] += $c;
            }
            if ($type === 'array') {
                $f['is_array'] = 1;
            }
            unset($f);
        }

        // B) scalar aggregates -> distinct, min/max, string lengths
        $sql = "SELECT fullkey,
                    COUNT(DISTINCT atom) distinct_n,
                    MIN(atom) min_v, MAX(atom) max_v,
                    MIN(length(atom)) FILTER (WHERE type='text') len_min,
                    MAX(length(atom)) FILTER (WHERE type='text') len_max,
                    AVG(length(atom)) FILTER (WHERE type='text') len_avg
                FROM _rows, json_tree(_rows.body)
                WHERE $scalarWhere GROUP BY fullkey";
        foreach ($pdo->query($sql) as $r) {
            $path = $this->normalize((string) $r['fullkey']);
            if (!isset($fields[$path])) {
                continue;
            }
            $fields[$path]['distinct_n'] = (int) $r['distinct_n'];
            $fields[$path]['min_v'] = $r['min_v'];
            $fields[$path]['max_v'] = $r['max_v'];
            $fields[$path]['len_min'] = $r['len_min'] !== null ? (int) $r['len_min'] : null;
            $fields[$path]['len_max'] = $r['len_max'] !== null ? (int) $r['len_max'] : null;
            $fields[$path]['len_avg'] = $r['len_avg'] !== null ? round((float) $r['len_avg'], 2) : null;
        }

        // C) bounded top-N values per field (window function)
        $sql = "SELECT path, atom, c FROM (
                    SELECT fullkey path, atom, COUNT(*) c,
                        ROW_NUMBER() OVER (PARTITION BY fullkey ORDER BY COUNT(*) DESC, atom) rn
                    FROM _rows, json_tree(_rows.body)
                    WHERE $scalarWhere GROUP BY fullkey, atom
                ) WHERE rn <= " . $topN . ' ORDER BY path, c DESC';
        foreach ($pdo->query($sql) as $r) {
            $path = $this->normalize((string) $r['path']);
            if (!isset($fields[$path])) {
                continue;
            }
            $fields[$path]['top_values'][] = ['value' => $r['atom'], 'count' => (int) $r['c']];
        }

        // heuristics from the bounded top-N sample (no extra scan)
        foreach ($fields as $path => $f) {
            $fields[$path]['heuristics'] = $this->heuristics($f['top_values']);
        }

        // array fields: explode elements with json_each -> element frequency (Phase 4)
        foreach ($fields as $path => $f) {
            if (($f['is_array'] ?? 0) === 1) {
                $arrayRows = (int) ($f['json_types']['array'] ?? 0);
                $fields[$path]['elements'] = $this->arrayElements($pdo, $path, $topN, $arrayRows);
            }
        }

        return $fields;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     */
    private function writeStats(PDO $pdo, array $fields): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO field_stats
                (path, json_types, present, non_null, distinct_n, min_v, max_v, len_min, len_max, len_avg, top_values, is_array, heuristics, elements)
             VALUES
                (:path, :json_types, :present, :non_null, :distinct_n, :min_v, :max_v, :len_min, :len_max, :len_avg, :top_values, :is_array, :heuristics, :elements)'
        );

        $pdo->beginTransaction();
        try {
            foreach ($fields as $f) {
                $stmt->execute([
                    'path' => $f['path'],
                    'json_types' => json_encode($f['json_types'], JSON_UNESCAPED_SLASHES),
                    'present' => $f['present'],
                    'non_null' => $f['non_null'],
                    'distinct_n' => $f['distinct_n'],
                    'min_v' => $this->scalarToString($f['min_v']),
                    'max_v' => $this->scalarToString($f['max_v']),
                    'len_min' => $f['len_min'],
                    'len_max' => $f['len_max'],
                    'len_avg' => $f['len_avg'],
                    'top_values' => json_encode($f['top_values'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'is_array' => $f['is_array'],
                    'heuristics' => json_encode($f['heuristics'], JSON_UNESCAPED_SLASHES),
                    'elements' => $f['elements'] !== null
                        ? json_encode($f['elements'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : null,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Build (or replace) the `v_rows` VIEW projecting each scalar field to a
     * friendly column over the persisted `_rows` cache.
     *
     * @param array<string, array<string, mixed>> $fields
     */
    private function buildView(PDO $pdo, array $fields): void
    {
        $pdo->exec('DROP VIEW IF EXISTS v_rows');

        $cols = ['line_no'];
        foreach ($fields as $path => $f) {
            if (($f['is_array'] ?? 0) === 1) {
                continue; // arrays aren't projected as scalar columns
            }
            $cols[] = sprintf('json_extract(body, %s) AS %s', $this->lit('$.' . $path), $this->ident($path));
        }

        if (count($cols) <= 1) {
            return;
        }

        $pdo->exec('CREATE VIEW v_rows AS SELECT ' . implode(', ', $cols) . ' FROM _rows');
    }

    private function lit(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }

    private function ident(string $s): string
    {
        return '"' . str_replace('"', '""', $s) . '"';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStat(string $path): array
    {
        return [
            'path' => $path,
            'json_types' => [],
            'present' => 0,
            'non_null' => 0,
            'distinct_n' => 0,
            'min_v' => null,
            'max_v' => null,
            'len_min' => null,
            'len_max' => null,
            'len_avg' => null,
            'top_values' => [],
            'is_array' => 0,
            'heuristics' => [],
            'elements' => null,
        ];
    }

    /**
     * Element-level frequency for an array field: explode with json_each, count
     * total + distinct (scalar) elements and a bounded top-N. Object/array
     * elements are counted but not value-tracked (no blob lists). ADR 0001 §4.
     *
     * @return array{count:int,distinct:int,avgPerRow:float,top:list<array{value:mixed,count:int}>}
     */
    private function arrayElements(PDO $pdo, string $path, int $topN, int $arrayRows): array
    {
        $lit = "'" . str_replace("'", "''", '$.' . $path) . "'";

        $agg = $pdo->query(
            "SELECT COUNT(*) AS total,
                COUNT(DISTINCT CASE WHEN je.type NOT IN ('object','array') THEN je.value END) AS scalar_distinct
             FROM _rows, json_each(_rows.body, $lit) je
             WHERE json_type(_rows.body, $lit) = 'array'"
        )->fetch(PDO::FETCH_ASSOC);

        $total = (int) ($agg['total'] ?? 0);
        $distinct = (int) ($agg['scalar_distinct'] ?? 0);

        $top = [];
        $sql = "SELECT je.value AS v, COUNT(*) AS c
                FROM _rows, json_each(_rows.body, $lit) je
                WHERE json_type(_rows.body, $lit) = 'array'
                  AND je.type NOT IN ('object','array')
                GROUP BY je.value ORDER BY c DESC, v LIMIT " . $topN;
        foreach ($pdo->query($sql) as $r) {
            $top[] = ['value' => $r['v'], 'count' => (int) $r['c']];
        }

        return [
            'count' => $total,
            'distinct' => $distinct,
            'avgPerRow' => $arrayRows > 0 ? round($total / $arrayRows, 2) : 0.0,
            'top' => $top,
        ];
    }

    /**
     * `$.a.b` -> `a.b`, `$.id` -> `id`.
     */
    private function normalize(string $fullkey): string
    {
        $s = str_starts_with($fullkey, '$') ? substr($fullkey, 1) : $fullkey;

        return ltrim($s, '.');
    }

    private function scalarToString(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    /**
     * @param list<array{value:mixed,count:int}> $topValues
     *
     * @return array<string, bool>
     */
    private function heuristics(array $topValues): array
    {
        $strings = [];
        foreach ($topValues as $tv) {
            if (is_string($tv['value']) && $tv['value'] !== '') {
                $strings[] = $tv['value'];
            }
        }
        $n = count($strings);
        if ($n === 0) {
            return [];
        }

        $url = 0;
        $img = 0;
        $nl = 0;
        foreach ($strings as $s) {
            $isUrl = (str_starts_with($s, 'http://') || str_starts_with($s, 'https://'))
                && filter_var($s, FILTER_VALIDATE_URL) !== false;
            if ($isUrl) {
                ++$url;
                $ext = strtolower(pathinfo((string) parse_url($s, PHP_URL_PATH), PATHINFO_EXTENSION));
                if (in_array($ext, self::IMAGE_EXT, true)) {
                    ++$img;
                }
            }
            if (substr_count(trim($s), ' ') >= 2) {
                ++$nl;
            }
        }

        $h = [];
        if ($url / $n >= 0.6) {
            $h['urlLike'] = true;
        }
        if ($img / $n >= 0.5) {
            $h['imageLike'] = true;
        }
        if ($nl / $n >= 0.5) {
            $h['naturalLanguageLike'] = true;
        }

        return $h;
    }
}

/**
 * Outcome of a {@see SqlProfiler::profile()} run.
 */
final readonly class ProfileResult
{
    public function __construct(
        public int $rows,
        public int $fields,
        public int $invalid,
        public int $truncated,
    ) {
    }
}
