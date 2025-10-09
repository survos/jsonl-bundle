<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\JsonlBundle\Path\SimpleBlockNamingStrategy;
use Survos\JsonlBundle\State\FileStateStore;

#[CoversClass(FileStateStore::class)]
#[CoversClass(SimpleBlockNamingStrategy::class)]
final class FileStateStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/jsonl-bundle-state-' . \bin2hex(\random_bytes(5));
        if (!\mkdir($concurrentDirectory = $this->tmpDir, 0o775, true) && !\is_dir($concurrentDirectory)) {
            throw new \RuntimeException('Cannot create tmp dir: ' . $this->tmpDir);
        }
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    private function rmrf(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            \unlink($path);
            return;
        }
        $it = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $f) {
            if ($f->isDir()) {
                \rmdir($f->getPathname());
            } else {
                \unlink($f->getPathname());
            }
        }
        \rmdir($path);
    }

    #[Test]
    public function readsEmptyWhenMissingAndWritesAtomically(): void
    {
        $final = $this->tmpDir . '/data/items.jsonl.gz';
        if (!\mkdir($concurrentDirectory = \dirname($final), 0o775, true) && !\is_dir($concurrentDirectory)) {
            throw new \RuntimeException('Cannot create dir: ' . \dirname($final));
        }

        $state = new FileStateStore(new SimpleBlockNamingStrategy());
        $initial = $state->read($final);
        self::assertSame([], $initial, 'Missing state returns empty array');

        $data = [
            'last_block_written' => 12,
            'total_lines' => 3456,
            'updated_at' => \date(\DATE_ATOM),
        ];
        $state->write($final, $data);

        $again = $state->read($final);
        self::assertSame($data, $again);
    }
}
