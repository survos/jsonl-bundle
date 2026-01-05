<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

final class JsonlSidecarNamer
{
    /**
     * Default sidecar naming: "<file>.state.json" next to the JSONL file.
     * Example: "/path/obj.jsonl" -> "/path/obj.jsonl.state.json"
     */
    public function sidecarPathFor(string $jsonlPath): string
    {
        return $jsonlPath . '.state.json';
    }
}
