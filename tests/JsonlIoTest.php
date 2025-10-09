<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Util\Jsonl;

#[CoversClass(JsonlReader::class)]
#[CoversClass(JsonlWriter::class)]
#[CoversClass(Jsonl::class)]
final class JsonlIoTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/jsonl-bundle-' . \bin2hex(\random_bytes(5));
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
    public function writesAndReadsPlainJsonl(): void
    {
        $file = $this->tmpDir . '/items.jsonl';
        $w = JsonlWriter::open($file); // auto-creates parent dir already exists
        $w->write(['id' => 1, 'name' => 'alpha'], tokenCode: '1');
        $w->write(['id' => 2, 'name' => 'beta'], tokenCode: '2');
        $w->write(['id' => 2, 'name' => 'beta-duplicate'], tokenCode: '2'); // should be skipped
        $w->close();

        self::assertFileExists($file);
        self::assertSame(2, Jsonl::countLines($file));

        $rows = [];
        foreach (new JsonlReader($file) as $line => $row) {
            $rows[$line] = $row;
        }

        self::assertCount(2, $rows);
        self::assertSame(['id' => 1, 'name' => 'alpha'], $rows[1]);
        self::assertSame(['id' => 2, 'name' => 'beta'], $rows[2]);

        $reader = new JsonlReader($file);
        self::assertTrue($reader->containsToken('1'));
        self::assertTrue($reader->containsToken('2'));
        self::assertFalse($reader->containsToken('missing'));
    }

    #[Test]
    public function writesAndReadsGzipJsonl(): void
    {
        if (!\function_exists('gzopen')) {
            $this->markTestSkipped('zlib not available');
        }

        $file = $this->tmpDir . '/items.jsonl.gz';
        $w = JsonlWriter::open($file);
        $w->write(['id' => 10, 'name' => 'gamma'], tokenCode: '10');
        $w->write(['id' => 11, 'name' => 'delta'], tokenCode: '11');
        $w->close();

        self::assertFileExists($file);
        self::assertSame(2, Jsonl::countLines($file));

        $rows = [];
        foreach (new JsonlReader($file) as $line => $row) {
            $rows[$line] = $row;
        }

        self::assertCount(2, $rows);
        self::assertSame(['id' => 10, 'name' => 'gamma'], $rows[1]);
        self::assertSame(['id' => 11, 'name' => 'delta'], $rows[2]);

        $reader = new JsonlReader($file);
        self::assertTrue($reader->containsToken('10'));
        self::assertFalse($reader->containsToken('xyz'));
    }

    #[Test]
    public function startAtLineHintOffsetsKeys(): void
    {
        $file = $this->tmpDir . '/offset.jsonl';
        $w = JsonlWriter::open($file);
        $w->write(['n' => 1]);
        $w->write(['n' => 2]);
        $w->close();

        $keys = [];
        foreach (new JsonlReader($file, startAtLine: 101) as $line => $row) {
            $keys[] = $line;
        }
        self::assertSame([101, 102], $keys);
    }
}
