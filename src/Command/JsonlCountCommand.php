<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Command;

use Survos\JsonlBundle\Service\JsonlCountService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'jsonl:count',
    description: 'Count rows in JSONL (.jsonl or .jsonl.gz) files, using sidecars when available.'
)]
final class JsonlCountCommand
{
    public function __construct(
        private readonly JsonlCountService $counter,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('File or directory to inspect')]
        string $path,

        #[Option('Recurse into subdirectories', shortcut: 'r')]
        bool $recursive = false,
    ): int {
        $rows = [];
        $total = 0;

        if (is_file($path)) {
            $count = $this->counter->rows($path);
            $rows[] = [(string) $count, $path];

            $io->table(['Rows', 'File'], $rows);

            return Command::SUCCESS;
        }

        if (!is_dir($path)) {
            $io->error(sprintf('Path not found: %s', $path));
            return Command::INVALID;
        }

        $finder = new Finder();
        $finder->files()
            ->in($path)
            ->name('*.jsonl')
            ->name('*.jsonl.gz');

        if (!$recursive) {
            $finder->depth('== 0');
        }

        foreach ($finder as $file) {
            $filePath = $file->getPathname();
            $count = $this->counter->rows($filePath);

            $rows[] = [(string) $count, $filePath];
            $total += $count;
        }

        if ($rows === []) {
            $io->warning('No JSONL files found.');
            return Command::SUCCESS;
        }

        // Sort descending by row count (optional but nice)
        usort($rows, static fn(array $a, array $b) => (int)$b[0] <=> (int)$a[0]);

        // Add total row
        $rows[] = [(string) $total, 'TOTAL'];

        $io->table(['Rows', 'File'], $rows);

        return Command::SUCCESS;
    }
}
