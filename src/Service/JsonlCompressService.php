<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

use Survos\JsonlBundle\IO\JsonlFileCompressor;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

/**
 * Gzip plain .jsonl files in place. Bytes are copied, not decoded, so rows come out exactly as
 * they went in; {@see JsonlFileCompressor} verifies the result and carries the sidecar over
 * before it removes the plain file. Readers resolve "<name>.jsonl" to "<name>.jsonl.gz".
 */
final class JsonlCompressService
{
    #[AsCommand('jsonl:compress', 'Gzip .jsonl files in place (byte copy, verified, sidecar kept)')]
    public function compress(
        SymfonyStyle $io,
        #[Argument('A .jsonl file, or a directory of them')]
        string $path,
        #[Option('gzip level 0-9 (default: survos_jsonl.compression_level)')]
        ?int $level = null,
        #[Option('Recurse into subdirectories')]
        bool $recursive = false,
        #[Option('List what would be compressed without writing')]
        bool $dryRun = false,
    ): int {
        $level ??= JsonlWriter::getDefaultCompressionLevel();
        $files = is_dir($path) ? $this->collect($path, $recursive) : [$path];
        if ($files === []) {
            $io->success('No plain .jsonl files found.');
            return Command::SUCCESS;
        }

        $compressor = new JsonlFileCompressor();
        $before = $after = 0;
        $failed = [];
        foreach ($files as $file) {
            $size = filesize($file) ?: 0;
            if ($dryRun) {
                $io->writeln(sprintf('  would compress %s (%s bytes)', $file, number_format($size)));
                continue;
            }
            try {
                $target = $compressor->compress($file, $level);
            } catch (\RuntimeException $e) {
                $failed[] = [$file, $e->getMessage()];
                continue;
            }
            $before += $size;
            $after += filesize($target) ?: 0;
            $io->writeln(sprintf('  %s  %s → %s', $target, number_format($size), number_format(filesize($target) ?: 0)), SymfonyStyle::VERBOSITY_VERBOSE);
        }

        if ($dryRun) {
            $io->note(sprintf('%d file(s) would be compressed at level %d.', count($files), $level));
            return Command::SUCCESS;
        }
        if ($failed !== []) {
            $io->table(['File', 'Error (original kept)'], $failed);
        }
        $done = count($files) - count($failed);
        $io->success(sprintf('%d file(s) compressed at level %d: %s → %s bytes.', $done, $level, number_format($before), number_format($after)));

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return list<string> */
    private function collect(string $dir, bool $recursive): array
    {
        $finder = (new Finder())->files()->in($dir)->name('*.jsonl')->notName('*.jsonl.gz')->sortByName();
        if (!$recursive) {
            $finder->depth(0);
        }
        $files = [];
        foreach ($finder as $file) {
            // The compressor refuses links; a work/_raw portal is compressed at its vault target.
            if (!$file->isLink()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
