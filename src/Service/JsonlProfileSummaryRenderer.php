<?php declare(strict_types=1);

// File: src/Service/JsonlProfileSummaryRenderer.php
// jsonl-bundle v0.13
// Reads a *.profile.json artifact, maps it into models, and prints a nice summary table.

namespace Survos\JsonlBundle\Service;

use Survos\JsonlBundle\Model\JsonlProfile;
use Symfony\Component\Console\Style\SymfonyStyle;

final class JsonlProfileSummaryRenderer
{
    public function render(SymfonyStyle $io, string $jsonlOutputPath): void
    {
        $profilePath = $jsonlOutputPath . '.profile.json';

        if (!is_file($profilePath)) {
            $io->note('No profiling artifact found (no listener active, or profiling disabled).');
            return;
        }

        try {
            $raw = file_get_contents($profilePath);
            if ($raw === false) {
                throw new \RuntimeException('file_get_contents() returned false');
            }

            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $profile = JsonlProfile::fromArray($data);
        } catch (\Throwable $e) {
            $io->warning(sprintf(
                'Could not read profiling artifact (%s): %s',
                $profilePath,
                $e->getMessage()
            ));
            return;
        }

        $io->section('Profile Summary');
        if ($profile->recordCount > 0) {
            $io->text(sprintf('Records processed: <info>%d</info>', $profile->recordCount));
        }

        $rows = [];

        foreach ($profile->fields as $fieldName => $fieldStats) {
            $rows[] = [
                $fieldName,
                $fieldStats->getTypesString(),
                $fieldStats->getDistinctLabel(),
                $fieldStats->getFacetFlag(),
                $fieldStats->getUniqueFlag(),
                $fieldStats->getBooleanFlag(),
                $fieldStats->getRangeLabel(),
                $fieldStats->getTopOrFirstValueLabel(),
            ];
        }

        $io->table(
            ['Field', 'Types', 'Distinct', 'Facet', 'Unique', 'Bool?', 'Range', 'Top/First Value'],
            $rows
        );

        $io->text(sprintf('Full profile written to <comment>%s</comment>', $profilePath));
    }
}
