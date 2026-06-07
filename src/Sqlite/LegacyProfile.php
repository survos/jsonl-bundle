<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Sqlite;

/**
 * Transitional adapter: maps the SQL profiler's `field_stats` rows back to the
 * legacy profile shape that existing consumers read (code-bundle `code:entity`,
 * the md & meili apps, folio). It lets those consumers keep working unchanged
 * while being fed by {@see SqlProfiler} instead of the deprecated in-PHP
 * `Service\JsonlProfiler` (PLAN Phase 6).
 *
 * The legacy per-field keys mirror `Model\FieldStats::toArray()` /
 * `Service\JsonlProfiler` output: total, nulls, distinct, types, stringLengths,
 * storageHint, booleanLike, facetCandidate, urlLike/imageLike/jsonLike/
 * naturalLanguageLike, and (when complete) distinctValues + arrayStats.
 */
final class LegacyProfile
{
    /** json_tree type -> legacy type name */
    private const TYPE_MAP = [
        'integer' => 'int',
        'real' => 'float',
        'true' => 'bool',
        'false' => 'bool',
        'text' => 'string',
        'array' => 'array',
        'object' => 'object',
    ];

    /**
     * Full legacy profile array: {input, output, recordCount, tags, fields}.
     *
     * @param list<array<string, mixed>> $fieldStatsRows
     *
     * @return array{input:string,output:null,recordCount:int,tags:array<int,string>,fields:array<string,array<string,mixed>>}
     */
    public static function full(string $input, int $recordCount, array $fieldStatsRows): array
    {
        return [
            'input' => $input,
            'output' => null,
            'recordCount' => $recordCount,
            'tags' => [],
            'fields' => self::mapFields($fieldStatsRows),
        ];
    }

    /**
     * @param list<array<string, mixed>> $fieldStatsRows
     *
     * @return array<string, array<string, mixed>> path => legacy stats
     */
    public static function mapFields(array $fieldStatsRows): array
    {
        $out = [];
        foreach ($fieldStatsRows as $row) {
            $out[(string) $row['path']] = self::mapField($row);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r a decoded field_stats row (SidecarDb::loadFieldStats)
     *
     * @return array<string, mixed>
     */
    public static function mapField(array $r): array
    {
        $jsonTypes = \is_array($r['json_types'] ?? null) ? $r['json_types'] : [];
        $types = [];
        foreach (array_keys($jsonTypes) as $t) {
            if ($t === 'null') {
                continue;
            }
            $mapped = self::TYPE_MAP[(string) $t] ?? null;
            if ($mapped !== null && !\in_array($mapped, $types, true)) {
                $types[] = $mapped;
            }
        }

        $present = (int) ($r['present'] ?? 0);
        $nonNull = (int) ($r['non_null'] ?? 0);
        $distinct = (int) ($r['distinct_n'] ?? 0);
        $lenMin = isset($r['len_min']) ? (int) $r['len_min'] : null;
        $lenMax = isset($r['len_max']) ? (int) $r['len_max'] : null;
        $lenAvg = isset($r['len_avg']) && $r['len_avg'] !== null ? (float) $r['len_avg'] : null;
        $heuristics = \is_array($r['heuristics'] ?? null) ? $r['heuristics'] : [];
        $topValues = \is_array($r['top_values'] ?? null) ? $r['top_values'] : [];
        $isComposite = \in_array('array', $types, true) || \in_array('object', $types, true);

        $field = [
            'total' => $present,
            'nulls' => max(0, $present - $nonNull),
            'distinct' => $distinct,
            'distinctCapReached' => false,
            'types' => $types,
            'stringLengths' => ['min' => $lenMin, 'max' => $lenMax, 'avg' => $lenAvg],
            'booleanLike' => self::booleanLike($types, $distinct, $topValues),
            'facetCandidate' => !$isComposite && $distinct > 0 && $distinct <= 64 && ($lenMax === null || $lenMax <= 128),
            'storageHint' => self::storageHint($types),
            'urlLike' => (bool) ($heuristics['urlLike'] ?? false),
            'imageLike' => (bool) ($heuristics['imageLike'] ?? false),
            'jsonLike' => false,
            'naturalLanguageLike' => (bool) ($heuristics['naturalLanguageLike'] ?? false),
        ];

        // Complete value list only when top-N already holds every distinct value.
        if (!$isComposite && $distinct > 0 && $distinct <= \count($topValues)) {
            $field['distinctValues'] = array_map(static fn (array $t): mixed => $t['value'], $topValues);
        }

        // Array element stats (Phase 4) folded into the legacy arrayStats key.
        if (($r['is_array'] ?? 0) && \is_array($r['elements'] ?? null)) {
            $e = $r['elements'];
            $field['arrayStats'] = [
                'elements' => (int) ($e['count'] ?? 0),
                'elemDistinct' => (int) ($e['distinct'] ?? 0),
                'avgPerRow' => $e['avgPerRow'] ?? 0,
            ];
        }

        return $field;
    }

    /**
     * Matches the legacy Service\JsonlProfiler::inferStorageHint (strict): only a
     * pure single type maps to int/float/bool; anything mixed (e.g. int+float) is
     * 'string'. code:entity derives TEXT-vs-STRING itself from stringLengths.max,
     * so there is no 'text' hint here. Kept faithful for non-breaking parity.
     *
     * @param list<string> $types
     */
    private static function storageHint(array $types): string
    {
        if (\in_array('array', $types, true) || \in_array('object', $types, true)) {
            return 'json';
        }
        if ($types === ['bool']) {
            return 'bool';
        }
        if ($types === ['int']) {
            return 'int';
        }
        if ($types === ['float']) {
            return 'float';
        }

        return 'string';
    }

    /**
     * @param list<string> $types
     * @param list<array{value:mixed,count:int}> $topValues
     */
    private static function booleanLike(array $types, int $distinct, array $topValues): bool
    {
        if ($distinct === 0 || $distinct > 4) {
            return false;
        }
        $allowed = ['0', '1', 'true', 'false', 'yes', 'no', 'y', 'n', 'on', 'off'];
        $values = array_map(static fn (array $t): string => strtolower(trim((string) $t['value'])), $topValues);
        if ($values === []) {
            return $types === ['bool'];
        }
        foreach ($values as $v) {
            if (!\in_array($v, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
