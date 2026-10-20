<?php

declare(strict_types=1);

namespace Survos\JsonlBundle\Tests\IO;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\JsonlBundle\IO\ArchiveJsonl;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\Sqlite\JsonlIndexer;
use Survos\JsonlBundle\Util\Jsonl;
use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function gzencode;
use function hash_file;
use function iterator_to_array;
use function mkdir;
use function random_bytes;
use function substr;
use function sys_get_temp_dir;

#[CoversClass(ArchiveJsonl::class)]
#[CoversClass(JsonlReader::class)]
final class ArchiveJsonlReaderTest extends TestCase
{
    private string $root;
    private string $archive;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/jsonl-archive-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        mkdir($this->root.'/cache');
        $this->archive = $this->root.'/raw.tar';
        $tar = new \PharData($this->archive);
        $tar->addFromString('data/plain.jsonl', "{\"id\":\"one\"}\n{\"id\":\"two\"}\n");
        // Members can split a JSONL line; the decoder must preserve the stream boundary.
        $tar->addFromString('data/raw.jsonl.gz', gzencode("{\"id\":\"one\"}\n{\"id\":").gzencode("\"two\"}\n"));
        $tar->addFromString('data/invalid.jsonl.gz', 'not gzip');
        $tar->addFromString('data/truncated.jsonl.gz', substr(gzencode("{\"id\":1}\n"), 0, -4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    #[Test]
    public function testPlainAndConcatenatedGzipMembersStreamWithoutExtraction(): void
    {
        $before = hash_file('sha256', $this->archive);
        foreach (['data/plain.jsonl', 'data/raw.jsonl.gz'] as $member) {
            $reader = JsonlReader::openArchive($this->archive, $member);
            self::assertSame([1 => ['id' => 'one'], 2 => ['id' => 'two']], iterator_to_array($reader));
            self::assertSame(2, Jsonl::countLines($reader->path()));
        }
        self::assertSame($before, hash_file('sha256', $this->archive));
        self::assertDirectoryDoesNotExist($this->root.'/data');
    }

    #[Test]
    public function testExternalIndexAndStateKeepArchiveUnchanged(): void
    {
        $before = hash_file('sha256', $this->archive);
        $reader = JsonlReader::openArchive($this->archive, 'data/raw.jsonl.gz', $this->root.'/cache');
        $result = (new JsonlIndexer())->index($reader->path(), sidecarPath: $reader->sidecarPath());
        self::assertSame(2, $result->rows);
        self::assertFileExists($reader->sidecarPath());
        self::assertSame(['id' => 'two'], $reader->get('two'));
        self::assertSame(2, $reader->stateService()->rows($reader->path()));
        $reader->stateService()->markComplete($reader->path());
        self::assertTrue($reader->state()->getStats()->isCompleted());
        // The offset-only index still works after the optional cached bodies are dropped.
        (new JsonlIndexer())->index($reader->path(), persist: false, sidecarPath: $reader->sidecarPath());
        self::assertSame(['id' => 'two'], $reader->get('two'));
        self::assertSame($before, hash_file('sha256', $this->archive));
        $other = JsonlReader::openArchive($this->archive, 'data/plain.jsonl', $this->root.'/cache');
        self::assertNotSame($reader->sidecarPath(), $other->sidecarPath());
    }

    #[Test]
    public function testReplacedArchiveGetsDifferentSidecar(): void
    {
        $first = JsonlReader::openArchive($this->archive, 'data/plain.jsonl', $this->root.'/cache')->sidecarPath();
        $tar = new \PharData($this->archive);
        $tar->addFromString('data/extra.jsonl', "{\"id\":3}\n");
        unset($tar);
        $second = JsonlReader::openArchive($this->archive, 'data/plain.jsonl', $this->root.'/cache')->sidecarPath();
        self::assertNotSame($first, $second);
    }

    #[Test]
    public function testArchiveStateRequiresExplicitCacheDirectory(): void
    {
        $this->expectException(\LogicException::class);
        JsonlReader::openArchive($this->archive, 'data/raw.jsonl.gz')->state();
    }

    #[Test]
    public function testTruncatedGzipIsNotSilentlyAccepted(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Truncated gzip');
        iterator_to_array(JsonlReader::openArchive($this->archive, 'data/truncated.jsonl.gz'));
    }

    #[Test]
    public function testInvalidGzipThrowsWithoutLeakingNativeWarnings(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid gzip');
        iterator_to_array(JsonlReader::openArchive($this->archive, 'data/invalid.jsonl.gz'));
    }

    #[Test]
    public function testMissingMemberFailsClearly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Archive member not found');
        iterator_to_array(JsonlReader::openArchive($this->archive, 'missing.jsonl.gz'));
    }

    #[Test]
    public function testTraversalIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JsonlReader::openArchive($this->archive, '../raw.jsonl');
    }
}
