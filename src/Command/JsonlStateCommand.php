<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Command;

use Survos\JsonlBundle\Service\JsonlStateRepository;
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
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Path to a .jsonl (or .jsonl.gz) file.')]
        string $path,

        #[Option('Ensure a sidecar exists and captures current file facts.', shortcut: 'e')]
        bool $ensure = false,

        #[Option('Emit machine-readable JSON.', shortcut: 'j')]
        bool $json = false,
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
            ['JSONL mtime (sidecar)' => $payload['jsonl_mtime'] !== null ? (string) $payload['jsonl_mtime'] : '(missing)'],
            ['JSONL size (sidecar)' => $payload['jsonl_size'] !== null ? (string) $payload['jsonl_size'] : '(missing)'],
        );

        return Command::SUCCESS;
    }
}
