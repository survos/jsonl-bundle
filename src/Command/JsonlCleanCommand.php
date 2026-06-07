<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

/**
 * Purge obsolete text sidecars (`<file>.sidecar.json`, and stray `.sidecar.json.tmp`)
 * superseded by the SQLite sidecar `<file>.db`. State is no longer migrated from
 * them — they are simply removed.
 *
 * Note: the writer's token de-dup index `<file>.idx.json` is left alone (still used
 * during writing); consolidating it into the `.db` is separate future work.
 */
#[AsCommand('jsonl:clean', 'Purge obsolete .sidecar.json text sidecars (superseded by <file>.db).')]
final class JsonlCleanCommand
{
    public function __invoke(
        SymfonyStyle $io,

        #[Argument('A .sidecar.json/.jsonl file or a directory to scan')]
        string $path,

        #[Option('Recurse into subdirectories')]
        bool $recursive = false,

        #[Option('List what would be deleted without deleting')]
        bool $dryRun = false,
    ): int {
        $targets = $this->collect($path, $recursive);

        if ($targets === []) {
            $io->success('No obsolete .sidecar.json files found.');

            return Command::SUCCESS;
        }

        $bytes = 0;
        $deleted = 0;
        foreach ($targets as $file) {
            $size = filesize($file) ?: 0;
            if ($dryRun) {
                $io->writeln(sprintf('  would delete %s (%d bytes)', $file, $size));
                continue;
            }
            if (unlink($file)) {
                $bytes += $size;
                ++$deleted;
            }
        }

        if ($dryRun) {
            $io->note(sprintf('%d file(s) would be deleted.', count($targets)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Purged %d obsolete sidecar(s), %d KB.', $deleted, intdiv($bytes, 1024)));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function collect(string $path, bool $recursive): array
    {
        if (is_file($path)) {
            // accept either the .jsonl or the .sidecar.json itself
            $base = str_ends_with($path, '.sidecar.json') ? substr($path, 0, -\strlen('.sidecar.json')) : $path;

            return array_values(array_filter([
                $base . '.sidecar.json',
                $base . '.sidecar.json.tmp',
            ], 'is_file'));
        }

        if (!is_dir($path)) {
            return [];
        }

        $finder = (new Finder())->files()->in($path)->name('*.sidecar.json')->name('*.sidecar.json.tmp');
        if (!$recursive) {
            $finder->depth('== 0');
        }

        $out = [];
        foreach ($finder as $file) {
            $out[] = $file->getPathname();
        }

        return $out;
    }
}
