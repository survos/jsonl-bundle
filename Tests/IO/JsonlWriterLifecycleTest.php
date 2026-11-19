<?php

declare(strict_types=1);

namespace Survos\JsonlBundle\Tests\IO;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlStateService;
use Survos\JsonlBundle\Sqlite\SidecarDb;
use Survos\JsonlBundle\Tests\Util\TestPaths;

#[CoversClass(JsonlWriter::class)]
#[CoversClass(SidecarDb::class)]
final class JsonlWriterLifecycleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TestPaths::tempDir('jsonl-lifecycle-');
    }

    protected function tearDown(): void
    {
        TestPaths::rmDir($this->dir);
    }

    /** @return list<string> */
    private function names(string $file): array
    {
        return array_column((new JsonlStateService())->events($file), 'event');
    }

    #[Test]
    public function aFirstWriteLogsOpenedThenClosedWithTheFinalSize(): void
    {
        $file = $this->dir.'/doc.jsonl.gz';

        $writer = JsonlWriter::open($file);
        $writer->write(['id' => 1]);
        $writer->write(['id' => 2]);
        $writer->close();

        $state = new JsonlStateService();
        $events = $state->events($file);

        self::assertSame(['opened', 'closed'], array_column($events, 'event'));
        self::assertSame('w', $events[0]['detail']['mode']);
        self::assertSame(2, $events[1]['detail']['rows']);
        self::assertSame(2, $events[1]['detail']['sessionRows']);
        self::assertFalse($events[1]['detail']['completed'], 'close() alone does not claim the file is complete');
        // A gzip stream is only final once closed: the sidecar must hold that size, not the empty one from open().
        self::assertSame(filesize($file), $events[1]['detail']['jsonlSize']);
        self::assertSame(filesize($file), $state->loadSidecar($file)->jsonl_size);
        self::assertGreaterThan(0, $state->loadSidecar($file)->jsonl_size);
    }

    #[Test]
    public function finishLogsCompletedBeforeClosed(): void
    {
        $file = $this->dir.'/doc.jsonl';

        $writer = JsonlWriter::open($file);
        $writer->write(['id' => 1]);
        $writer->finish();

        self::assertSame(['opened', 'completed', 'closed'], $this->names($file));
        self::assertTrue((new JsonlStateService())->events($file)[2]['detail']['completed']);
    }

    #[Test]
    public function aSecondOpenIsLoggedAsReopenedAndKeepsTheEarlierHistory(): void
    {
        $file = $this->dir.'/doc.jsonl';

        $writer = JsonlWriter::open($file);
        $writer->write(['id' => 1]);
        $writer->finish();

        $append = JsonlWriter::open($file, 'a');
        $append->write(['id' => 2]);
        $append->close();

        self::assertSame(['opened', 'completed', 'closed', 'reopened', 'closed'], $this->names($file));
        $events = (new JsonlStateService())->events($file);
        self::assertSame('a', $events[3]['detail']['mode']);
        self::assertSame(1, $events[3]['detail']['previous']['rows']);
        self::assertTrue($events[3]['detail']['previous']['completed']);
        self::assertSame(2, $events[4]['detail']['rows']);
        self::assertSame(1, $events[4]['detail']['sessionRows']);
    }

    #[Test]
    public function aTruncatingOpenResetsTheCountersButNotTheLog(): void
    {
        $file = $this->dir.'/doc.jsonl';

        $writer = JsonlWriter::open($file);
        $writer->write(['id' => 1]);
        $writer->write(['id' => 2]);
        $writer->finish();

        $again = JsonlWriter::open($file);
        $again->write(['id' => 3]);
        $again->close();

        $state = new JsonlStateService();
        self::assertSame(['opened', 'completed', 'closed', 'reopened', 'closed'], $this->names($file));
        self::assertSame('w', $state->events($file)[3]['detail']['mode']);
        self::assertSame(2, $state->events($file)[3]['detail']['previous']['rows']);
        self::assertSame(1, $state->loadSidecar($file)->rows);
        self::assertFalse($state->loadSidecar($file)->completed);
    }

    #[Test]
    public function closingTwiceLogsOneClose(): void
    {
        $file = $this->dir.'/doc.jsonl';

        $writer = JsonlWriter::open($file);
        $writer->write(['id' => 1]);
        $writer->close();
        $writer->close();
        unset($writer);

        self::assertSame(['opened', 'closed'], $this->names($file));
    }

    #[Test]
    public function aWriterThatIsNeverClosedLeavesOnlyTheOpen(): void
    {
        $file = $this->dir.'/doc.jsonl';
        $state = new JsonlStateService();

        $writer = JsonlWriter::open($file);
        $writer->write(['id' => 1]);

        // Before close: the log shows an open with nothing after it, which is how a crashed run reads.
        self::assertSame(['opened'], $this->names($file));
        $writer->close();
        self::assertSame(['opened', 'closed'], array_column($state->events($file), 'event'));
    }

    #[Test]
    public function aSidecarFromBeforeTheLogGainsTheEventsTableWithoutLosingItsMeta(): void
    {
        $file = $this->dir.'/old.jsonl';
        $db = new \PDO('sqlite:'.$file.'.db');
        $db->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)');
        $db->exec("INSERT INTO meta VALUES ('rows', '7'), ('bytes', '70'), ('completed', '1'), ('startedAt', '2026-01-01T00:00:00+00:00'), ('updatedAt', '2026-01-01T00:00:00+00:00')");
        $db->exec('PRAGMA user_version = 4');
        $db = null;
        file_put_contents($file, "{\"id\":1}\n");

        $writer = JsonlWriter::open($file, 'a');
        $writer->close();

        $state = new JsonlStateService();
        self::assertSame(['reopened', 'closed'], $this->names($file));
        self::assertSame(7, $state->loadSidecar($file)->rows);
        self::assertSame(7, $state->events($file)[0]['detail']['previous']['rows']);
    }
}
