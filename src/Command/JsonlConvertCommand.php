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
    name: 'jsonl:convert',
    description: 'Convert a CSV/JSON/JSONL/JSONLD file, directory, or ZIP into JSONL, optionally dispatching enrichment events.'
)]
final class JsonlConvertCommand
{
    public function __construct(
        private readonly JsonlDirectoryConverter $converter,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly JsonlProfileSummaryRenderer $profileSummaryRenderer,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Input source (CSV, JSON, JSONL, JSONLD, directory, or ZIP)')]
        string $input,

        #[Argument('Output JSONL file')]
        string $output,

        #[Option('Dataset routing key used by listeners (defaults to input basename)')]
        ?string $dataset = null,

        #[Option('Dispatch JsonlRecordEvent per record before writing JSONL')]
        ?bool $dispatch = null,

        #[Option('Slugify a specific field when writing JSONL')]
        ?string $slugify = null,

        #[Option('Primary key field name to ensure uniqueness')]
        ?string $pk = null,

        #[Option('Comma-separated tags applied to all records (e.g. "wcma,profile:dev")')]
        ?string $tags = null,

        #[Option('Path prefix inside a ZIP archive, e.g. "marvel-search-master/records/"')]
        ?string $pathInZip = null,
    ): int {
        if (!file_exists($input)) {
            $io->error(sprintf('Input "%s" does not exist.', $input));
            return Command::FAILURE;
        }

        $isZip = str_ends_with(strtolower($input), '.zip');

        if ($isZip && !$pathInZip) {
            $io->warning('Using ZIP input without --path; all *.json / *.jsonld entries will be considered.');
        }

        // Default: we do dispatch per-record events unless explicitly disabled.
        $dispatch ??= true;

        // Build dataset + tags: dataset + CLI tags
        $dataset ??= pathinfo($input, PATHINFO_FILENAME) ?: null;
        $tagsArray = [];

        if ($dataset) {
            $tagsArray[] = $dataset;
        }
        if ($tags) {
            $tagsArray = array_merge(
                $tagsArray,
                array_filter(array_map('trim', explode(',', $tags)))
            );
        }
        $tagsArray = array_values(array_unique($tagsArray));

        $io->section('Converting source → JSONL');
        $io->listing([
            "Input:     $input" . ($isZip && $pathInZip ? " (ZIP path: $pathInZip)" : ''),
            "Output:    $output",
            "Dataset:   " . ($dataset ?: '(none)'),
            "Tags:      " . (implode(', ', $tagsArray) ?: '(none)'),
            "Dispatch:  " . ($dispatch ? 'yes' : 'no'),
            "Slugify:   " . ($slugify ?: '(none)'),
            "PK:        " . ($pk ?: '(none)'),
        ]);

        // Per-record callback if dispatch is enabled
        $onRecord = null;

        if ($dispatch) {
            if (!$this->eventDispatcher) {
                $io->error('EventDispatcherInterface unavailable — cannot dispatch per-record events.');
                return Command::FAILURE;
            }

            $onRecord = function (array $record, int $index, string $originFile, string $format) use ($tagsArray, $dataset) {
                $event = new JsonlRecordEvent(
                    record: $record,
                    dataset: $dataset,
                    origin: $originFile,
                    format: $format,
                    index: $index,
                    tags: $tagsArray,
                );

                $this->eventDispatcher->dispatch($event);

                // Listeners can reject by setting $event->record = null
                return $event->record;
            };
        }

        // PRE: conversion started
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(
                new JsonlConvertStartedEvent(
                    input: $input,
                    output: $output,
                    tags: $tagsArray,
                )
            );
        }

        try {
            if ($isZip) {
                $provider = new ZipJsonRecordProvider($input, $pathInZip ?: null);

                $count = $this->converter->convertFromProvider(
                    records: $provider->getRecords(),
                    output: $output,
                    slugifyField: $slugify,
                    primaryKeyField: $pk,
                    onRecord: $onRecord,
                    offset: 0,
                    origin: $input,
                    format: 'json',
                );
            } else {
                $count = $this->converter->convert(
                    input: $input,
                    output: $output,
                    slugifyField: $slugify,
                    primaryKeyField: $pk,
                    onRecord: $onRecord,
                );
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // POST: conversion finished
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(
                new JsonlConvertFinishedEvent(
                    input: $input,
                    output: $output,
                    recordCount: $count,
                    tags: $tagsArray,
                )
            );
        }

        $io->success(sprintf('Wrote %d records to %s', $count, $output));

        $this->profileSummaryRenderer->render($io, $output);

        return Command::SUCCESS;
    }
}

