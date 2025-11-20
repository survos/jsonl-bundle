<?php declare(strict_types=1);

// File: src/Model/FieldStats.php
// jsonl-bundle v0.13
// Smart model for a single field's profiling data.
// Accumulates stats via push() and can serialize to/from array.

namespace Survos\JsonlBundle\Model;

final class FieldStats
{
    public const DISTINCT_CAP = 1024;
    public const DISTRIBUTION_LIMIT = 1024;
    public const DISTRIBUTION_MAX_VALUE_LENGTH = 64;

    public string $name;

    public int $total = 0;
    public int $nulls = 0;

    /** @var array<string,int> */
    private array $distinctValues = [];
    public int $distinctCount = 0;
    public bool $distinctCapReached = false;

    public bool $flagInt = false;
    public bool $flagFloat = false;
    public bool $flagBool = false;
    public bool $flagString = false;
    public bool $flagArray = false;
    public bool $flagObject = false;

    public int $stringCount = 0;
    public ?int $stringMinLength = null;
    public ?int $stringMaxLength = null;
    public int $stringSumLength = 0;

    public mixed $firstValue = null;

    /** Storage hint (int, float, bool, string, text, json) */
    public string $storageHint = '';

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Push one observed value into this field's stats.
     */
    public function push(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->nulls++;
            return;
        }

        $this->total++;

        if ($this->firstValue === null) {
            $this->firstValue = $value;
        }

        // Distinct tracking
        if (!$this->distinctCapReached) {
            $key = $this->normalizeDistinctKey($value);

            if (!array_key_exists($key, $this->distinctValues)) {
                $this->distinctValues[$key] = 1;
                $this->distinctCount++;

                if ($this->distinctCount > self::DISTINCT_CAP) {
                    $this->distinctValues = [];
                    $this->distinctCapReached = true;
                }
            } else {
                $this->distinctValues[$key]++;
            }
        }

        // Type flags and string-length stats
        if (is_int($value)) {
            $this->flagInt = true;
        } elseif (is_float($value)) {
            $this->flagFloat = true;
        } elseif (is_bool($value)) {
            $this->flagBool = true;
        } elseif (is_array($value)) {
            $this->flagArray = true;
        } elseif (is_object($value)) {
            $this->flagObject = true;
        } else {
            $this->flagString = true;
        }

        if (is_string($value)) {
            $len = mb_strlen($value);
            $this->stringCount++;
            $this->stringSumLength += $len;
            $this->stringMinLength = $this->stringMinLength === null ? $len : min($this->stringMinLength, $len);
            $this->stringMaxLength = $this->stringMaxLength === null ? $len : max($this->stringMaxLength, $len);
        }
    }

    /**
     * @return array<string,mixed>  Shape is compatible with existing artifacts.
     */
    public function toArray(): array
    {
        $types = $this->getTypes();

        $unique = null;
        if ($this->total > 0 && !$this->distinctCapReached) {
            $unique = ($this->distinctCount === $this->total);
        }

        $avgLen = null;
        if ($this->stringCount > 0) {
            $avgLen = (float)$this->stringSumLength / $this->stringCount;
        }

        $booleanLike   = $this->computeBooleanLike();
        $facetCandidate = $this->computeFacetCandidate($types);
        $this->storageHint = $this->computeStorageHint($types);

        $distribution = $this->buildDistribution();

        return [
            'total' => $this->total,
            'nulls' => $this->nulls,
            'distinct' => $this->distinctCount,
            'distinctCapReached' => $this->distinctCapReached,
            'unique' => $unique,
            'types' => $types,
            'stringLengths' => [
                'min' => $this->stringMinLength,
                'max' => $this->stringMaxLength,
                'avg' => $avgLen,
            ],
            'booleanLike' => $booleanLike,
            'facetCandidate' => $facetCandidate,
            'storageHint' => $this->storageHint,
            'distribution' => $distribution,
            'distributionCapped' => $this->distinctCapReached,
            'firstValue' => $this->firstValue,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(string $name, array $data): self
    {
        $self = new self($name);

        $self->total = (int)($data['total'] ?? 0);
        $self->nulls = (int)($data['nulls'] ?? 0);
        $self->distinctCount = (int)($data['distinct'] ?? 0);
        $self->distinctCapReached = (bool)($data['distinctCapReached'] ?? false);

        $types = $data['types'] ?? [];
        if (is_array($types)) {
            foreach ($types as $t) {
                $t = (string)$t;
                if ($t === 'int') {
                    $self->flagInt = true;
                } elseif ($t === 'float') {
                    $self->flagFloat = true;
                } elseif ($t === 'bool') {
                    $self->flagBool = true;
                } elseif ($t === 'string') {
                    $self->flagString = true;
                } elseif ($t === 'array') {
                    $self->flagArray = true;
                } elseif ($t === 'object') {
                    $self->flagObject = true;
                }
            }
        }

        $stringLengths = $data['stringLengths'] ?? [];
        if (is_array($stringLengths)) {
            $self->stringMinLength = isset($stringLengths['min']) ? (int)$stringLengths['min'] : null;
            $self->stringMaxLength = isset($stringLengths['max']) ? (int)$stringLengths['max'] : null;
            $avg = $stringLengths['avg'] ?? null;
            if ($avg !== null) {
                $self->stringSumLength = 0; // we don't restore exact sum, just derived avg externally if needed
            }
        }

        $self->firstValue   = $data['firstValue'] ?? null;
        $self->storageHint  = (string)($data['storageHint'] ?? '');

        // We intentionally do not restore distinctValues here; it's not needed for summary.
        return $self;
    }

    // -------------------------------------------------------------------------
    // Summary helpers
    // -------------------------------------------------------------------------

    public function getTypesString(): string
    {
        return implode(', ', $this->getTypes());
    }

    public function getDistinctLabel(): string
    {
        $label = (string)$this->distinctCount;
        if ($this->distinctCapReached) {
            $label .= ' (capped)';
        }

        return $label;
    }

    public function getFacetFlag(): string
    {
        return $this->computeFacetCandidate($this->getTypes()) ? '✔' : '';
    }

    public function getUniqueFlag(): string
    {
        if ($this->total > 0 && !$this->distinctCapReached && $this->distinctCount === $this->total) {
            return '✔';
        }

        return '';
    }

    public function getBooleanFlag(): string
    {
        return $this->computeBooleanLike() ? '✔' : '';
    }

    public function getRangeLabel(): string
    {
        if ($this->stringMinLength === null || $this->stringMaxLength === null) {
            return '';
        }

        if ($this->stringMinLength === $this->stringMaxLength) {
            return sprintf('%d chars', $this->stringMinLength);
        }

        return sprintf('%d–%d chars', $this->stringMinLength, $this->stringMaxLength);
    }

    public function getTopOrFirstValueLabel(): string
    {
        $distribution = $this->buildDistribution();
        if ($distribution && !empty($distribution['values']) && is_array($distribution['values'])) {
            $values = $distribution['values'];
            arsort($values);
            $rawValue = array_key_first($values);
            $rawCount = $values[$rawValue];

            $displayValue = $this->formatValueForDisplay($rawValue, $this->storageHint);

            return sprintf('%s (%d)', $displayValue, $rawCount);
        }

        if ($this->firstValue !== null && $this->firstValue !== '') {
            return $this->formatValueForDisplay($this->firstValue, $this->storageHint);
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function normalizeDistinctKey(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }

        return json_encode($value);
    }

    /**
     * @return string[]
     */
    private function getTypes(): array
    {
        $types = [];
        if ($this->flagInt) {
            $types[] = 'int';
        }
        if ($this->flagFloat) {
            $types[] = 'float';
        }
        if ($this->flagBool) {
            $types[] = 'bool';
        }
        if ($this->flagString) {
            $types[] = 'string';
        }
        if ($this->flagArray) {
            $types[] = 'array';
        }
        if ($this->flagObject) {
            $types[] = 'object';
        }

        return $types;
    }

    private function computeBooleanLike(): bool
    {
        if ($this->distinctCapReached || $this->distinctCount === 0 || $this->distinctCount > 4) {
            return false;
        }

        $normalized = [];
        foreach (array_keys($this->distinctValues) as $val) {
            $v = strtolower(trim((string) $val));
            $normalized[$v] = true;
        }

        $allowed = [
            '0', '1',
            'true', 'false',
            'yes', 'no',
            'y', 'n',
            'on', 'off',
        ];

        foreach (array_keys($normalized) as $v) {
            if (!in_array($v, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string[] $types
     */
    private function computeFacetCandidate(array $types): bool
    {
        if ($this->distinctCapReached || $this->distinctCount === 0) {
            return false;
        }

        $maxFacetDistinct = 64;
        if ($this->distinctCount > $maxFacetDistinct) {
            return false;
        }

        if ($this->stringMaxLength !== null && $this->stringMaxLength > 128) {
            return false;
        }

        if (in_array('array', $types, true) || in_array('object', $types, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param string[] $types
     */
    private function computeStorageHint(array $types): string
    {
        if (in_array('array', $types, true) || in_array('object', $types, true)) {
            return 'json';
        }

        if (in_array('bool', $types, true)) {
            return 'bool';
        }

        if (in_array('int', $types, true) && !in_array('string', $types, true) && !in_array('float', $types, true)) {
            return 'int';
        }

        if (in_array('float', $types, true) && !in_array('string', $types, true)) {
            return 'float';
        }

        if ($this->stringMaxLength !== null && $this->stringMaxLength > 255) {
            return 'text';
        }

        return 'string';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildDistribution(): ?array
    {
        if ($this->distinctCapReached || $this->distinctCount === 0 || $this->distinctCount > self::DISTRIBUTION_LIMIT) {
            return null;
        }

        $valuesOut = [];
        foreach ($this->distinctValues as $val => $count) {
            if (mb_strlen((string) $val) > self::DISTRIBUTION_MAX_VALUE_LENGTH) {
                continue;
            }
            $valuesOut[$val] = $count;
        }

        if ($valuesOut === []) {
            return null;
        }

        return [
            'values' => $valuesOut,
            'totalDistinct' => $this->distinctCount,
        ];
    }

    private function formatValueForDisplay(mixed $value, string $storageHint): string
    {
        if ($storageHint === 'int' || $storageHint === 'float' || $storageHint === 'bool' || $storageHint === 'json') {
            return (string) $value;
        }

        if ($storageHint === '' && is_numeric($value)) {
            return (string) $value;
        }

        return sprintf('"%s"', (string) $value);
    }
}
