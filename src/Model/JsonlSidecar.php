<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Model;

/**
 * Serialized to <file>.sidecar.json via SidecarService.
 *
 * Public mutable properties are intentional: SidecarService updates in-place while streaming.
 */
final class JsonlSidecar
{
    public function __construct(
        public ?string $startedAt = null,
        public ?string $updatedAt = null,
        public int $rows = 0,
        public int $bytes = 0,
        public bool $completed = false,
        public ?int $jsonl_mtime = null,
        public ?int $jsonl_size = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'startedAt' => $this->startedAt,
            'updatedAt' => $this->updatedAt,
            'rows' => $this->rows,
            'bytes' => $this->bytes,
            'completed' => $this->completed,
            // Deterministic freshness fields (optional but recommended)
            'jsonl_mtime' => $this->jsonl_mtime,
            'jsonl_size' => $this->jsonl_size,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $startedAt = \is_string($data['startedAt'] ?? null) ? $data['startedAt'] : (\is_string($data['started_at'] ?? null) ? $data['started_at'] : null);
        $updatedAt = \is_string($data['updatedAt'] ?? null) ? $data['updatedAt'] : (\is_string($data['updated_at'] ?? null) ? $data['updated_at'] : null);

        $rows = self::asInt($data['rows'] ?? 0);
        $bytes = self::asInt($data['bytes'] ?? 0);

        $completed = \is_bool($data['completed'] ?? null) ? $data['completed'] : false;

        $mtime = self::asNullableInt($data['jsonl_mtime'] ?? null);
        $size  = self::asNullableInt($data['jsonl_size'] ?? null);

        return new self(
            startedAt: $startedAt,
            updatedAt: $updatedAt,
            rows: $rows,
            bytes: $bytes,
            completed: $completed,
            jsonl_mtime: $mtime,
            jsonl_size: $size,
        );
    }

    private static function asInt(mixed $v): int
    {
        if (\is_int($v)) {
            return $v;
        }
        if (\is_string($v) && $v !== '' && \ctype_digit($v)) {
            return (int) $v;
        }
        return 0;
    }

    private static function asNullableInt(mixed $v): ?int
    {
        if (\is_int($v)) {
            return $v;
        }
        if (\is_string($v) && $v !== '' && \ctype_digit($v)) {
            return (int) $v;
        }
        return null;
    }
}
