<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Tests\IO;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlStateRepository;

final class JsonlWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->dir);
    }

    #[Test]
    public function it_writes_rows_updates_sidecar_and_finishes_with_reportable_state(): void
    {
        $jsonl = $this->dir . '/out/products.jsonl';

        $writer = JsonlWriter::open($jsonl);
        $writer->write(['id' => 1, 'name' => 'a']);
        $writer->write(['id' => 2, 'name' => 'b']);
        $result = $writer->finish(markComplete: true);

        $state = $result->state;

        self::assertSame($jsonl, $state->getJsonlPath());
        self::assertFileExists($jsonl);
        self::assertFileExists($state->getSidecarPath());

        $stats = $state->getStats();

        self::assertSame(2, $stats->getRows());
        self::assertGreaterThan(0, $stats->getBytes());
        self::assertTrue($stats->isCompleted());
        self::assertNotNull($stats->getStartedAt());
        self::assertNotNull($stats->getUpdatedAt());
        self::assertNotNull($stats->getJsonlMtime());
        self::assertNotNull($stats->getJsonlSize());

        self::assertTrue($state->isFresh());
    }

    #[Test]
    public function token_index_skips_duplicates_without_incrementing_sidecar_rows(): void
    {
        $jsonl = $this->dir . '/out/dedupe.jsonl';

        $writer = JsonlWriter::open($jsonl);
        $writer->write(['id' => 1], tokenCode: 'abc');
        $writer->write(['id' => 1], tokenCode: 'abc'); // skipped
        $writer->write(['id' => 2], tokenCode: 'def');
        $result = $writer->finish(markComplete: true);

        $repo  = new JsonlStateRepository();
        $state = $repo->load($jsonl);

        self::assertSame(2, $state->getStats()->getRows());
        self::assertTrue($result->state->getStats()->isCompleted());
        self::assertFileExists($jsonl . '.idx.json');
    }

    private function makeTempDir(): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $dir = $base . DIRECTORY_SEPARATOR . 'jsonl-bundle-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create temp dir "%s".', $dir));
        }
        return $dir;
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            $path = (string) $file;
            if (is_dir($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
