<?php

declare(strict_types=1);

namespace Survos\JsonlBundle\Command;

use Survos\JsonlBundle\Service\JsonlDirectoryConverter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    'json:convert:dir',
    'Convert JSON files from a directory or .zip archive into a JSONL[.gz] file'
)]
final class JsonConvertDirCommand
{
    public function __construct(
        private readonly JsonlDirectoryConverter $converter,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('directory or .zip archive to read JSON files from')]
        string $input,

        #[Argument('destination JSONL (.jsonl or .jsonl.gz) file')]
        string $output,

        #[Option('limit processed files to a sub-path inside the directory/archive (e.g. "/records")')]
        ?string $path = null,

        #[Option('glob pattern for JSON filenames (default: "*.json")')]
        string $pattern = '*.json',

        #[Option('field whose value will be slugified into a "code" field (e.g. "name" or "title")')]
        ?string $slugify = null,

        #[Option('primary key spec: field name ("id") or pattern like "car-{lineNumber}"')]
        ?string $pk = null,

        #[Option('overwrite the output file if it already exists')]
        bool $force = false,
    ): int {
        if (!\file_exists($input)) {
            $io->error(sprintf('Input "%s" does not exist.', $input));

            return Command::FAILURE;
        }

        if (\file_exists($output) && ! $force) {
            $io->error(sprintf('Output "%s" already exists. Use --force to overwrite.', $output));

            return Command::FAILURE;
        }

        $io->section('Directory/ZIP → JSONL conversion');
        $io->listing([
            "Input:   $input",
            "Output:  $output",
            "Path:    " . ($path ?: '(none)'),
            "Pattern: " . ($pattern ?: '(none)'),
            "Slugify: " . ($slugify ?: '(disabled)'),
            "PK:      " . ($pk ?: '(auto: code or lineNumber)'),
        ]);

        try {
            $count = $this->converter->convert(
                input: $input,
                output: $output,
                path: $path,
                pattern: $pattern,
                slugifyField: $slugify,
                pkSpec: $pk,
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success("Wrote $count JSON object(s) to $output");

        return Command::SUCCESS;
    }
}

