<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Command;

use Survos\JsonlBundle\Service\JsonlCountService;
use Survos\JsonlBundle\Service\SidecarService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'jsonl:info',
    description: 'Show sidecar/progress info for JSONL (.jsonl or .jsonl.gz) files.'
)]
final class JsonlInfoCommand
{
    public function __construct(
        private readonly SidecarService $sidecars,
        private readonly JsonlCountService $counter,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('File or directory to inspect')]
        string $path,

        #[Option('Recurse into subdirectories', shortcut: 'r')]
        bool $recursive = false,
    ): int {
        if (is_file($path)) {
            return $this->renderSingle($io, $path);
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

        $rows = [];
        foreach ($finder as $file) {
            $filePath = $file->getPathname();

            $scExists = $this->sidecars->exists($filePath);
            $sc = $scExists ? $this->sidecars->load($filePath) : null;

            $count = $this->counter->rows($filePath);
            $completed = $scExists ? ($sc->completed ? 'yes' : 'no') : '(no sidecar)';
            $updatedAt = $scExists ? ($sc->updatedAt ?? '') : '';
            $startedAt = $scExists ? ($sc->startedAt ?? '') : '';

            $rows[] = [
                (string) $count,
                $completed,
                $updatedAt,
                $startedAt,
                $filePath,
            ];
        }

        if ($rows === []) {
            $io->warning('No JSONL files found.');
            return Command::SUCCESS;
        }

        // Sort by file path for stability
        usort($rows, static fn(array $a, array $b) => strcmp($a[4], $b[4]));

        $io->table(
            ['Rows', 'Complete', 'Updated', 'Started', 'File'],
            $rows
        );

        return Command::SUCCESS;
    }

    private function renderSingle(SymfonyStyle $io, string $filePath): int
    {
        $exists = is_file($filePath);
        if (!$exists) {
            $io->error(sprintf('File not found: %s', $filePath));
            return Command::INVALID;
        }

        $rows = $this->counter->rows($filePath);

        $scExists = $this->sidecars->exists($filePath);
        $sc = $scExists ? $this->sidecars->load($filePath) : null;

        $io->title('JSONL info');
        $io->definitionList(
            ['File' => $filePath],
            ['Rows' => (string) $rows],
            ['Sidecar' => $this->sidecars->sidecarPath($filePath)],
            ['Sidecar exists' => $scExists ? 'yes' : 'no'],
            ['Completed' => $scExists ? ($sc->completed ? 'yes' : 'no') : '(no sidecar)'],
            ['Started' => $scExists ? ($sc->startedAt ?? '') : ''],
            ['Updated' => $scExists ? ($sc->updatedAt ?? '') : ''],
            ['Bytes (sidecar)' => $scExists ? (string) $sc->bytes : ''],
        );

        return Command::SUCCESS;
    }
}
