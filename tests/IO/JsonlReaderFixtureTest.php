<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Tests\IO;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\JsonlBundle\IO\JsonlReader;

final class JsonlReaderFixtureTest extends TestCase
{
    #[Test]
    public function it_reads_fixture_and_offsets_keys_only(): void
    {
        $path = __DIR__ . '/../Fixtures/data/products.jsonl';

        $r1 = new JsonlReader($path);
        $rows1 = iterator_to_array($r1);

        self::assertCount(3, $rows1);
        self::assertSame(['id' => 1, 'name' => 'alpha'], $rows1[1]);
        self::assertSame(['id' => 2, 'name' => 'beta'], $rows1[2]);

        $r2 = new JsonlReader($path, startAtLine: 101);
        $rows2 = iterator_to_array($r2);

        self::assertSame([101, 102, 103], array_keys($rows2));
        self::assertSame(['id' => 1, 'name' => 'alpha'], $rows2[101]);
    }
}
