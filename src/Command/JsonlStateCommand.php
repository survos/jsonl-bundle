<?php

declare(strict_types=1);

namespace Survos\JsonlBundle\Command;

use Survos\JsonlBundle\Service\JsonlStateRepository;
use Survos\JsonlBundle\Service\JsonlStateService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('jsonl:state', 'Show JSONL sidecar/state (rows, bytes, completed, freshness).')]
final class JsonlStateCommand
{
    public function __construct(
        private readonly JsonlStateRepository $repo = new JsonlStateRepository(),
        private readonly JsonlStateService $service = new JsonlStateService(),
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Path to a .jsonl (or .jsonl.gz) file.')]
        string $path,

        #[Option('Ensure a sidecar exists and captures current file facts.')]
        bool $ensure = false,

        #[Option('Emit machine-readable JSON.', shortcut: 'j')]
        bool $json = false,

        #[Option('Also show the lifecycle log (opened, reopened, completed, closed) with timestamps.')]
        bool $events = false,
    ): int {
        if ($ensure) {
            $state = $this->repo->ensure($path);
        } else {
            $state = $this->repo->load($path);
        }

        $stats = $state->getStats();

        $payload = [
            'jsonl' => $state->getJsonlPath(),
            'sidecar' => $state->getSidecarPath(),
            'rows' => $stats->getRows(),
            'bytes' => $stats->getBytes(),
            'completed' => $stats->isCompleted(),
            'startedAt' => $stats->getStartedAt(),
            'updatedAt' => $stats->getUpdatedAt(),
            'jsonl_mtime' => $stats->getJsonlMtime(),
            'jsonl_size' => $stats->getJsonlSize(),
            'fresh' => $state->isFresh(),
        ];

        $log = $events ? $this->service->events($path) : [];
        if ($events) {
            $payload['events'] = $log;
        }

        if ($json) {
            $io->writeln(\json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('JSONL state');
        $io->definitionList(
            ['JSONL' => $payload['jsonl']],
            ['Sidecar' => $payload['sidecar']],
            ['Rows' => (string) $payload['rows']],
            ['Bytes' => (string) $payload['bytes']],
            ['Completed' => $payload['completed'] ? 'yes' : 'no'],
            ['Fresh' => $payload['fresh'] ? 'yes' : 'no'],
            ['Started at' => $payload['startedAt'] ?? '(unknown)'],
            ['Updated at' => $payload['updatedAt'] ?? '(unknown)'],
            ['JSONL mtime (sidecar)' => null !== $payload['jsonl_mtime'] ? (string) $payload['jsonl_mtime'] : '(missing)'],
            ['JSONL size (sidecar)' => null !== $payload['jsonl_size'] ? (string) $payload['jsonl_size'] : '(missing)'],
        );

        if ($events) {
            $io->section('Lifecycle');
            $io->table(
                ['At', 'Event', 'Detail'],
                [] === $log ? [['(none recorded)', '', '']] : array_map(
                    static fn (array $e): array => [$e['at'], $e['event'], [] === $e['detail'] ? '' : \json_encode($e['detail'], \JSON_UNESCAPED_SLASHES)],
                    $log,
                ),
            );
        }

        return Command::SUCCESS;
    }
}
