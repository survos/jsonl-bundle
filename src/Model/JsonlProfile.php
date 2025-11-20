<?php declare(strict_types=1);

// File: src/Model/JsonlProfile.php
// jsonl-bundle v0.12
// Top-level profile model: input/output/tags + FieldStats.

namespace Survos\JsonlBundle\Model;

final class JsonlProfile
{
    /**
     * @param string[]                    $tags
     * @param array<string,FieldStats>    $fields
     */
    public function __construct(
        public string $input,
        public string $output,
        public int $recordCount,
        public array $tags,
        public array $fields = [],
    ) {}

    public function ensureField(string $name): FieldStats
    {
        if (!isset($this->fields[$name])) {
            $this->fields[$name] = new FieldStats($name);
        }

        return $this->fields[$name];
    }

    /**
     * Serialize to array shape compatible with existing artifacts.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $name => $stats) {
            $fields[$name] = $stats->toArray();
        }

        return [
            'input' => $this->input,
            'output' => $this->output,
            'recordCount' => $this->recordCount,
            'tags' => $this->tags,
            'fields' => $fields,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tags = $data['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }

        $fields = [];
        $fieldData = $data['fields'] ?? [];
        if (is_array($fieldData)) {
            foreach ($fieldData as $name => $stats) {
                if (!is_array($stats)) {
                    continue;
                }
                $fields[$name] = FieldStats::fromArray((string)$name, $stats);
            }
        }

        return new self(
            input: (string)($data['input'] ?? ''),
            output: (string)($data['output'] ?? ''),
            recordCount: (int)($data['recordCount'] ?? 0),
            tags: array_values($tags),
            fields: $fields,
        );
    }
}
