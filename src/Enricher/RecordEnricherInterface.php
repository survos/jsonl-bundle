<?php declare(strict_types=1);

namespace Survos\JsonlBundle\Enricher;

/**
 * Contract for a domain-specific record enricher.
 *
 * Implementations are tagged in the app and selected by getType()
 * from a generic EnrichRecordHandler.
 */
interface RecordEnricherInterface
{
    /**
     * Logical task type, e.g. "iiif_manifest", "wikidata_lookup", "wcma_iiif_manifest".
     * Must match the EnrichRecordMessage::$taskType the handler receives.
     */
    public function getType(): string;

    /**
     * @param array<string,mixed> $record  Original row (from CSV/JSON/JSONL)
     *
     * @return array<string,mixed>         Enriched row (original + extra fields)
     */
    public function enrich(array $record): array;
}
