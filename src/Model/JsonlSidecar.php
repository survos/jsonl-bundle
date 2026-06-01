<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Model;

/**
 * Serialized JSONL state payload.
 *
 * The default storage is <file>.sidecar.json. Public mutable properties are
 * intentional: the state service updates this object while streaming.
 */
final class JsonlSidecar
{
    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        public ?string $startedAt = null,
        public ?string $updatedAt = null,
        public int $rows = 0,
        public int $bytes = 0,
        public bool $completed = false,
        public ?int $jsonl_mtime = null,
        public ?int $jsonl_size = null,
        public array $context = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'startedAt' => $this->startedAt,
            'updatedAt' => $this->updatedAt,
            'rows' => $this->rows,
            'bytes' => $this->bytes,
            'completed' => $this->completed,
            'jsonl_mtime' => $this->jsonl_mtime,
            'jsonl_size' => $this->jsonl_size,
            'context' => $this->context,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $startedAt = is_string($data['startedAt'] ?? null) ? $data['startedAt'] : (is_string($data['started_at'] ?? null) ? $data['started_at'] : null);
        $updatedAt = is_string($data['updatedAt'] ?? null) ? $data['updatedAt'] : (is_string($data['updated_at'] ?? null) ? $data['updated_at'] : null);

        return new self(
            startedAt: $startedAt,
            updatedAt: $updatedAt,
            rows: self::asInt($data['rows'] ?? 0),
            bytes: self::asInt($data['bytes'] ?? 0),
            completed: is_bool($data['completed'] ?? null) ? $data['completed'] : false,
            jsonl_mtime: self::asNullableInt($data['jsonl_mtime'] ?? null),
            jsonl_size: self::asNullableInt($data['jsonl_size'] ?? null),
            context: is_array($data['context'] ?? null) ? $data['context'] : [],
        );
    }

    private static function asInt(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '' && ctype_digit($v)) {
            return (int) $v;
        }
        return 0;
    }

    private static function asNullableInt(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '' && ctype_digit($v)) {
            return (int) $v;
        }
        return null;
    }
}
