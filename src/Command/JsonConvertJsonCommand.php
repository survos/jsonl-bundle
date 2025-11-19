<?php

declare(strict_types=1);

namespace Survos\JsonlBundle\Command;

use Survos\JsonlBundle\Service\JsonToJsonlConverter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    'json:convert:json',
    'Convert a JSON file or HTTP resource into a JSONL[.gz] file'
)]
final class JsonConvertJsonCommand
{
    public function __construct(
        private readonly JsonToJsonlConverter $converter,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('path to input JSON file (or "-" for STDIN)')]
        string $input,

        #[Argument('destination JSONL (.jsonl or .jsonl.gz) output file')]
        string $output,

        #[Option('JSON key containing the array of records (e.g., "products", "hits", "items")')]
        ?string $key = null,

        #[Option('overwrite the output file even if it exists')]
        bool $force = false,
    ): int {

        if ($input !== '-' && !\file_exists($input)) {
            $io->error(sprintf('Input "%s" does not exist (use "-" for STDIN).', $input));
            return Command::FAILURE;
        }

        if (\file_exists($output) && ! $force) {
            $io->error(sprintf('Output "%s" already exists. Use --force to overwrite.', $output));
            return Command::FAILURE;
        }

        $io->section('Streaming JSON → JSONL conversion');
        $io->listing([
            "Input:  $input",
            "Output: $output",
            "Key:    " . ($key ?: '(auto-detect)'),
        ]);

        try {
            $count = $this->converter->convertFile($input, $output, $key);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Wrote $count records to $output");

        return Command::SUCCESS;
    }
}

