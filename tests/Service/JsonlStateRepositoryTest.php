<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\JsonlBundle\Service\JsonlStateRepository;
use Survos\JsonlBundle\Service\SidecarService;

final class JsonlStateRepositoryTest extends TestCase
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
    public function it_loads_state_from_sidecar_and_checks_freshness(): void
    {
        $sidecar = new SidecarService();
        $repo    = new JsonlStateRepository($sidecar);

        $jsonl = $this->dir . '/data.jsonl';
        file_put_contents($jsonl, "{\"a\":1}\n", LOCK_EX);

        $sidecar->touch($jsonl, rowsDelta: 1, bytesDelta: 7, captureFileFacts: true);

        $state = $repo->load($jsonl);

        self::assertSame($jsonl, $state->getJsonlPath());
        self::assertSame($jsonl . '.sidecar.json', $state->getSidecarPath());
        self::assertTrue($state->exists());

        $stats = $state->getStats();
        self::assertSame(1, $stats->getRows());
        self::assertSame(7, $stats->getBytes());
        self::assertNotNull($stats->getStartedAt());
        self::assertNotNull($stats->getUpdatedAt());
        self::assertFalse($stats->isCompleted());
        self::assertNotNull($stats->getJsonlMtime());
        self::assertNotNull($stats->getJsonlSize());

        self::assertTrue($state->isFresh());
    }

    #[Test]
    public function freshness_becomes_false_if_jsonl_is_modified_outside_writer(): void
    {
        $sidecar = new SidecarService();
        $repo    = new JsonlStateRepository($sidecar);

        $jsonl = $this->dir . '/data.jsonl';
        file_put_contents($jsonl, "{\"a\":1}\n", LOCK_EX);

        $sidecar->touch($jsonl, rowsDelta: 1, bytesDelta: 7, captureFileFacts: true);

        self::assertTrue($repo->load($jsonl)->isFresh());

        usleep(20_000);
        file_put_contents($jsonl, "{\"b\":2}\n", FILE_APPEND | LOCK_EX);

        self::assertFalse($repo->load($jsonl)->isFresh());
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
