<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Command;

use Survos\JsonlBundle\Sqlite\JsonlIndexer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[AsCommand('jsonl:index', 'Build the SQLite sidecar index (offsets, facets, authoritative row count) for a JSONL file.')]
final class JsonlIndexCommand
{
    public function __construct(
        private readonly JsonlIndexer $indexer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Path to a .jsonl or .jsonl.gz file')]
        string $path,

        #[Option('Primary-key field(s), comma-separated (composite keys joined with ":")')]
        string $pk = 'id',

        #[Option('Fields to inline as covering facets in idx.attrs, comma-separated')]
        string $facet = '',
    ): int {
        if (!is_file($path)) {
            $io->error(sprintf('File not found: %s', $path));

            return Command::INVALID;
        }

        $pkFields = $this->splitList($pk) ?: ['id'];
        $facetFields = $this->splitList($facet);

        $dir = \dirname($path) ?: '.';
        $lock = (new LockFactory(new FlockStore($dir)))->createLock('jsonl_index_' . sha1($path));
        $lock->acquire(true);

        try {
            $result = $this->indexer->index($path, $pkFields, $facetFields);
        } finally {
            $lock->release();
        }

        $io->success(sprintf(
            'Indexed %s — %d rows, %d keys, %d invalid skipped (%s).',
            $path,
            $result->rows,
            $result->keys,
            $result->invalid,
            $result->mode,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function splitList(string $csv): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $csv)),
            static fn (string $s): bool => $s !== '',
        ));
    }
}
