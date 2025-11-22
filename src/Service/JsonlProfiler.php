<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

/**
 * Default implementation of JsonlProfilerInterface.
 *
 * - Pure PHP, no dependencies on Doctrine, Meili, etc.
 * - Safe for use in CLI, workers, or tests.
 */
final class JsonlProfiler implements JsonlProfilerInterface
{
    /**
     * @param int $distinctCap Maximum distinct values to track per field.
     *                         50_000 is enough for typical CSV datasets;
     *                         for truly huge sets we can later use a Bloom filter.
     */
    public function __construct(
        private readonly int $distinctCap = 50000,
    ) {
    }

    public function profile(iterable $rows): array
    {
        /** @var array<string, array<string, mixed>> $stats */
        $stats = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            foreach ($row as $field => $value) {
                if (!\array_key_exists($field, $stats)) {
                    $stats[$field] = $this->createEmptyFieldStats();
                }

                $fieldStats = &$stats[$field];
                $fieldStats['total']++;

                if ($value === null) {
                    $fieldStats['nulls']++;
                    unset($fieldStats);
                    continue;
                }

                $type = $this->normalizeType($value);
                if (!\in_array($type, $fieldStats['types'], true)) {
                    $fieldStats['types'][] = $type;
                }

                // Distinct values (non-null only), capped
                if (\count($fieldStats['distinctValues']) < $this->distinctCap) {
                    $fieldStats['distinctValues'][$this->normalizeDistinctKey($value)] = true;
                } else {
                    $fieldStats['distinctCapReached'] = true;
                }

                // String length stats
                if (\is_string($value)) {
                    $len      = \strlen($value);
                    $lengths  = &$fieldStats['stringLengths'];

                    $lengths['min'] = $lengths['min'] === null ? $len : \min($lengths['min'], $len);
                    $lengths['max'] = $lengths['max'] === null ? $len : \max($lengths['max'], $len);
                    $lengths['sum'] += $len;
                    $lengths['count']++;
                    unset($lengths);
                }

                unset($fieldStats);
            }
        }

        // Finalize stats
        foreach ($stats as $field => &$fieldStats) {
            $fieldStats['distinct'] = \count($fieldStats['distinctValues']);
            unset($fieldStats['distinctValues']);

            $lengths = &$fieldStats['stringLengths'];
            if ($lengths['count'] > 0) {
                $lengths['avg'] = $lengths['sum'] / $lengths['count'];
            } else {
                $lengths['min'] = null;
                $lengths['max'] = null;
                $lengths['avg'] = null;
            }
            unset($lengths['sum'], $lengths['count']);

            $fieldStats['booleanLike'] = $this->isBooleanLike($fieldStats);
            $fieldStats['storageHint'] = $this->inferStorageHint($fieldStats);
        }
        unset($fieldStats);

        return $stats;
    }

    private function createEmptyFieldStats(): array
    {
        return [
            'total'              => 0,
            'nulls'              => 0,
            'distinct'           => 0,
            'distinctCapReached' => false,
            'types'              => [],
            'distinctValues'     => [],
            'stringLengths'      => [
                'min'   => null,
                'max'   => null,
                'avg'   => null,
                'sum'   => 0,
                'count' => 0,
            ],
            'booleanLike'        => false,
            'storageHint'        => null,
        ];
    }

    private function normalizeType(mixed $value): string
    {
        return match (true) {
            \is_int($value)    => 'int',
            \is_float($value)  => 'float',
            \is_bool($value)   => 'bool',
            \is_string($value) => 'string',
            \is_array($value)  => 'array',
            default            => 'other',
        };
    }

    private function normalizeDistinctKey(mixed $value): string
    {
        if (\is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return \json_encode($value, \JSON_THROW_ON_ERROR);
    }

    private function isBooleanLike(array $fieldStats): bool
    {
        $types = $fieldStats['types'] ?? [];

        if ($types === ['bool'] || $types === ['bool', 'null'] || $types === ['null', 'bool']) {
            return true;
        }

        if (($fieldStats['distinct'] ?? 0) <= 5 && \in_array('string', $types, true)) {
            $sampleKeys = \array_keys($fieldStats['distinctValues'] ?? []);
            $normalized = \array_map(static fn (string $v) => \strtolower(\trim($v)), $sampleKeys);

            $allowed = ['0', '1', 'true', 'false', 'yes', 'no', 'y', 'n'];
            foreach ($normalized as $v) {
                if (!\in_array($v, $allowed, true)) {
                    return false;
                }
            }

            return !empty($normalized);
        }

        return false;
    }

    private function inferStorageHint(array $fieldStats): ?string
    {
        $types = $fieldStats['types'] ?? [];

        if (empty($types)) {
            return null;
        }

        if ($types === ['bool'] || $types === ['bool', 'null'] || $types === ['null', 'bool']) {
            return 'bool';
        }

        if ($types === ['int'] || $types === ['int', 'null'] || $types === ['null', 'int']) {
            return 'int';
        }

        if ($types === ['float'] || $types === ['float', 'null'] || $types === ['null', 'float']) {
            return 'float';
        }

        if (\in_array('array', $types, true)) {
            return 'json';
        }

        return 'string';
    }
}
