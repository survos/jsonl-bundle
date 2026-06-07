<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Command;

use Survos\JsonlBundle\Sqlite\LegacyProfile;
use Survos\JsonlBundle\Sqlite\SidecarDb;
use Survos\JsonlBundle\Sqlite\SqlProfiler;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[AsCommand('jsonl:profile', 'Profile a JSONL file with SQL (field types, cardinality, top values) into the sidecar.')]
final class JsonlProfileCommand
{
    public function __construct(
        private readonly SqlProfiler $profiler,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Path to a .jsonl or .jsonl.gz file')]
        string $path,

        #[Option('Number of top values to keep per field')]
        int $top = 20,

        #[Option('Emit the full field_stats as JSON')]
        bool $json = false,

        #[Option('Also write a legacy <name>.profile.json (for code:entity / legacy consumers)')]
        bool $legacyJson = false,
    ): int {
        if (!is_file($path)) {
            $io->error(sprintf('File not found: %s', $path));

            return Command::INVALID;
        }

        $dir = \dirname($path) ?: '.';
        $lock = (new LockFactory(new FlockStore($dir)))->createLock('jsonl_profile_' . sha1($path));
        $lock->acquire(true);

        try {
            $result = $this->profiler->profile($path, $top);
        } finally {
            $lock->release();
        }

        $stats = (new SidecarDb($path . '.db'))->loadFieldStats();

        if ($legacyJson) {
            $profilePath = preg_replace('/\.jsonl(\.gz)?$/', '', $path) . '.profile.json';
            $profile = LegacyProfile::full($path, $result->rows, $stats);
            file_put_contents(
                $profilePath,
                (string) json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
            $io->note(sprintf('Wrote legacy profile: %s', $profilePath));
        }

        if ($json) {
            $io->writeln((string) json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($stats as $s) {
            $isArray = (bool) $s['is_array'];
            $elements = $s['elements'] ?? null;
            // for array fields, show element distinct + most-common element
            $distinct = $isArray && $elements !== null ? (int) $elements['distinct'] : (int) $s['distinct_n'];
            $top1 = $s['top_values'][0] ?? ($isArray && $elements !== null ? ($elements['top'][0] ?? null) : null);
            $rows[] = [
                $s['path'],
                implode(',', array_keys($s['json_types'])),
                (string) $s['present'],
                (string) $distinct,
                $isArray ? sprintf('×%s', $elements !== null ? $elements['avgPerRow'] : '?') : '',
                $top1 !== null ? sprintf('%s (%d)', $this->short((string) $top1['value']), $top1['count']) : '',
                implode(',', array_keys(array_filter($s['heuristics']))),
            ];
        }

        $io->table(['field', 'types', 'present', 'distinct', 'array', 'top value', 'hints'], $rows);
        $io->success(sprintf(
            'Profiled %s — %d fields, %d rows, %d invalid%s.',
            $path,
            $result->fields,
            $result->rows,
            $result->invalid,
            $result->truncated > 0 ? sprintf(', %d fields truncated', $result->truncated) : '',
        ));

        return Command::SUCCESS;
    }

    private function short(string $s): string
    {
        return mb_strlen($s) > 40 ? mb_substr($s, 0, 39) . '…' : $s;
    }
}
