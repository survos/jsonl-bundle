<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Command;

use Survos\JsonlBundle\Sqlite\SidecarDb;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Reclaim the sidecar DB's persisted data cache.
 *
 * Drops the `_rows` cache (+ `v_rows` view) and `VACUUM`s, keeping `meta`, `idx`
 * (offsets + facets), and `field_stats`. This operates on the `.db` cache — it is
 * NOT log compaction (rewriting the `.jsonl`); that is a separate concern.
 */
#[AsCommand('jsonl:vacuum', 'Reclaim the sidecar data cache (drop _rows + VACUUM); keeps offsets/facets/stats.')]
final class JsonlVacuumCommand
{
    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Path to a .jsonl or .jsonl.gz file')]
        string $path,
    ): int {
        $dbPath = $path . '.db';
        if (!is_file($dbPath)) {
            $io->warning(sprintf('No sidecar DB to vacuum: %s', $dbPath));

            return Command::SUCCESS;
        }

        $dir = \dirname($path) ?: '.';
        $lock = (new LockFactory(new FlockStore($dir)))->createLock('jsonl_index_' . sha1($path));
        $lock->acquire(true);

        try {
            $reclaimed = (new SidecarDb($dbPath))->vacuumCache();
        } finally {
            $lock->release();
        }

        $io->success(sprintf(
            'Vacuumed %s — reclaimed %d KB. Offsets, facets, and field_stats retained; re-run jsonl:index/profile to rebuild the cache.',
            $dbPath,
            intdiv($reclaimed, 1024),
        ));

        return Command::SUCCESS;
    }
}
