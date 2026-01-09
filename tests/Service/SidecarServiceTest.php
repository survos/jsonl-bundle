<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\JsonlBundle\Service\SidecarService;

final class SidecarServiceTest extends TestCase
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
    public function it_creates_and_updates_sidecar_with_file_facts(): void
    {
        $svc = new SidecarService();

        $jsonl = $this->dir . '/data.jsonl';
        file_put_contents($jsonl, "{}", LOCK_EX);

        self::assertFalse($svc->exists($jsonl));

        $sc = $svc->touch($jsonl, rowsDelta: 0, bytesDelta: 0, captureFileFacts: true);

        self::assertTrue($svc->exists($jsonl));
        self::assertNotNull($sc->startedAt);
        self::assertNotNull($sc->updatedAt);
        self::assertSame(0, $sc->rows);
        self::assertSame(0, $sc->bytes);

        self::assertIsInt($sc->jsonl_mtime);
        self::assertIsInt($sc->jsonl_size);
        self::assertSame(filesize($jsonl), $sc->jsonl_size);

        $sc2 = $svc->touch($jsonl, rowsDelta: 2, bytesDelta: 10, captureFileFacts: true);
        self::assertSame(2, $sc2->rows);
        self::assertSame(10, $sc2->bytes);

        $sc3 = $svc->markComplete($jsonl, captureFileFacts: true);
        self::assertTrue($sc3->completed);
        self::assertNotNull($sc3->updatedAt);
        self::assertIsInt($sc3->jsonl_mtime);
        self::assertIsInt($sc3->jsonl_size);
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
