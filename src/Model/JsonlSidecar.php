<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Model;

/**
 * Lightweight progress metadata for a JSONL artifact.
 *
 * Stored as JSON next to the target file, typically:
 *   <file>.sidecar.json
 */
final class JsonlSidecar
{
    public function __construct(
        public int $version = 1,
        public int $rows = 0,
        public int $bytes = 0,
        public bool $completed = false,
        public ?string $startedAt = null,
        public ?string $updatedAt = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $sc = new self();
        $sc->version = (int)($data['version'] ?? 1);
        $sc->rows = (int)($data['rows'] ?? 0);
        $sc->bytes = (int)($data['bytes'] ?? 0);
        $sc->completed = (bool)($data['completed'] ?? false);
        $sc->startedAt = isset($data['startedAt']) ? (string)$data['startedAt'] : null;
        $sc->updatedAt = isset($data['updatedAt']) ? (string)$data['updatedAt'] : null;

        return $sc;
    }

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'rows' => $this->rows,
            'bytes' => $this->bytes,
            'completed' => $this->completed,
            'startedAt' => $this->startedAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
