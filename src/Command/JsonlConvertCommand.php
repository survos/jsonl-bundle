<?php declare(strict_types=1);

// File: src/Command/JsonlConvertCommand.php
// jsonl-bundle v0.11
// Unified converter: CSV/JSON/JSONL/dir/zip → JSONL
// Optional per-record enhancement via JsonlRecordEvent,
// plus start/finish events for profiling/summarization.

namespace Survos\JsonlBundle\Command;

use Survos\JsonlBundle\Event\JsonlConvertFinishedEvent;
use Survos\JsonlBundle\Event\JsonlConvertStartedEvent;
use Survos\JsonlBundle\Event\JsonlRecordEvent;
use Survos\JsonlBundle\IO\ZipJsonRecordProvider;
use Survos\JsonlBundle\Service\JsonlDirectoryConverter;
use Survos\JsonlBundle\Service\JsonlProfileSummaryRenderer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand(
    'jsonl:convert',
    'Convert CSV/JSON/JSONL/JSONLD files, directories, or ZIP archives into JSONL format with optional event-driven enrichment'
)]
final class JsonlConvertCommand
{
    public function __construct(
        private readonly JsonlDirectoryConverter $converter,
        private readonly JsonlProfileSummaryRenderer $profileSummaryRenderer,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Input source (CSV, JSON, JSONL, JSONLD, directory, or ZIP)')]
        string $input,
        #[Argument('Output JSONL file path')]
        string $output,
        #[Option('Dataset routing key for listeners (defaults to input basename)')]
        ?string $dataset = null,
        #[Option('Dispatch JsonlRecordEvent per record for enrichment (default: true)')]
        ?bool $dispatch = null,
        #[Option('Field name to slugify when writing records')]
        ?string $slugify = null,
        #[Option('Primary key field name to ensure uniqueness')]
        ?string $pk = null,
        #[Option('Comma-separated tags applied to all records (e.g., "wcma,profile:dev")')]
        ?string $tags = null,
        #[Option('Path prefix inside ZIP archive (e.g., "records/")')]
        ?string $pathInZip = null,
    ): int {
        // Validate input exists
        if (!file_exists($input)) {
            $io->error(sprintf('Input "%s" does not exist.', $input));
            return Command::FAILURE;
        }

        $isZip = str_ends_with(strtolower($input), '.zip');

        if ($isZip && $pathInZip === null) {
            $io->warning('Processing ZIP without --path-in-zip; all *.json/*.jsonld entries will be included.');
        }

        // Enable event dispatching by default
        $dispatch ??= true;

        // Build dataset identifier and tags array
        $dataset ??= pathinfo($input, PATHINFO_FILENAME);
        $tagsArray = $this->buildTagsArray($dataset, $tags);

        // Display conversion configuration
        $this->displayConversionInfo($io, $input, $output, $dataset, $tagsArray, $dispatch, $slugify, $pk, $isZip, $pathInZip);

        // Create per-record callback for event dispatching
        $onRecord = $this->createRecordCallback($dispatch, $io, $tagsArray, $dataset);

        // Dispatch pre-conversion event
        $this->dispatchStartEvent($input, $output, $tagsArray);

        // Execute conversion
        try {
            $count = $this->executeConversion($input, $output, $slugify, $pk, $onRecord, $isZip, $pathInZip);
        } catch (\Throwable $e) {
            $io->error(sprintf('Conversion failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        // Dispatch post-conversion event
        $this->dispatchFinishEvent($input, $output, $count, $tagsArray);

        $io->success(sprintf('Successfully wrote %d records to %s', $count, $output));

        // Display profiling summary
        $this->profileSummaryRenderer->render($io, $output);

        return Command::SUCCESS;
    }

    /**
     * Build tags array from dataset and comma-separated tags string
     */
    private function buildTagsArray(?string $dataset, ?string $tags): array
    {
        $tagsArray = [];

        if ($dataset !== null && $dataset !== '') {
            $tagsArray[] = $dataset;
        }

        if ($tags !== null && $tags !== '') {
            $parsed = array_filter(array_map('trim', explode(',', $tags)));
            $tagsArray = array_merge($tagsArray, $parsed);
        }

        return array_values(array_unique($tagsArray));
    }

    /**
     * Display conversion configuration information
     */
    private function displayConversionInfo(
        SymfonyStyle $io,
        string $input,
        string $output,
        ?string $dataset,
        array $tagsArray,
        bool $dispatch,
        ?string $slugify,
        ?string $pk,
        bool $isZip,
        ?string $pathInZip,
    ): void {
        $io->section('Converting source → JSONL');
        $io->listing([
            sprintf('Input:     %s%s', $input, $isZip && $pathInZip ? " (ZIP path: $pathInZip)" : ''),
            sprintf('Output:    %s', $output),
            sprintf('Dataset:   %s', $dataset ?: '(none)'),
            sprintf('Tags:      %s', $tagsArray !== [] ? implode(', ', $tagsArray) : '(none)'),
            sprintf('Dispatch:  %s', $dispatch ? 'yes' : 'no'),
            sprintf('Slugify:   %s', $slugify ?: '(none)'),
            sprintf('PK:        %s', $pk ?: '(none)'),
        ]);
    }

    /**
     * Create per-record callback for event dispatching
     */
    private function createRecordCallback(bool $dispatch, SymfonyStyle $io, array $tagsArray, ?string $dataset): ?callable
    {
        if (!$dispatch) {
            return null;
        }

        if ($this->eventDispatcher === null) {
            $io->error('EventDispatcher unavailable; cannot dispatch per-record events.');
            throw new \RuntimeException('EventDispatcher required for record dispatching');
        }

        return function (array $record, int $index, string $originFile, string $format) use ($tagsArray, $dataset): ?array {
            $event = new JsonlRecordEvent(
                record: $record,
                dataset: $dataset,
                origin: $originFile,
                format: $format,
                index: $index,
                tags: $tagsArray,
            );

            $this->eventDispatcher->dispatch($event);

            // Listeners can reject records by setting $event->record = null
            return $event->record;
        };
    }

    /**
     * Execute the conversion based on input type
     */
    private function executeConversion(
        string $input,
        string $output,
        ?string $slugify,
        ?string $pk,
        ?callable $onRecord,
        bool $isZip,
        ?string $pathInZip,
    ): int {
        if ($isZip) {
            $provider = new ZipJsonRecordProvider($input, $pathInZip);

            return $this->converter->convertFromProvider(
                records: $provider->getRecords(),
                output: $output,
                slugifyField: $slugify,
                primaryKeyField: $pk,
                onRecord: $onRecord,
                offset: 0,
                origin: $input,
                format: 'json',
            );
        }

        return $this->converter->convert(
            input: $input,
            output: $output,
            slugifyField: $slugify,
            primaryKeyField: $pk,
            onRecord: $onRecord,
        );
    }

    /**
     * Dispatch conversion started event
     */
    private function dispatchStartEvent(string $input, string $output, array $tagsArray): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }

        $this->eventDispatcher->dispatch(
            new JsonlConvertStartedEvent(
                input: $input,
                output: $output,
                tags: $tagsArray,
            )
        );
    }

    /**
     * Dispatch conversion finished event
     */
    private function dispatchFinishEvent(string $input, string $output, int $recordCount, array $tagsArray): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }

        $this->eventDispatcher->dispatch(
            new JsonlConvertFinishedEvent(
                input: $input,
                output: $output,
                recordCount: $recordCount,
                tags: $tagsArray,
            )
        );
    }
}

