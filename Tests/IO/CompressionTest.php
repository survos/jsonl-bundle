<?php
declare(strict_types=1);
namespace Survos\JsonlBundle\Tests\IO;
use PHPUnit\Framework\Attributes\{Test, CoversClass};
use PHPUnit\Framework\TestCase;
use Survos\JsonlBundle\IO\{JsonlReader, JsonlWriter};
use Survos\JsonlBundle\Util\Jsonl;
use Symfony\Component\Filesystem\Filesystem;
use function sys_get_temp_dir;
use function bin2hex;
use function random_bytes;
use function file_get_contents;
use function iterator_to_array;
use function ord;
#[CoversClass(JsonlWriter::class)]
#[CoversClass(Jsonl::class)]
final class CompressionTest extends TestCase
{
    private string $dir;
    protected function setUp(): void { $this->dir = sys_get_temp_dir().'/jsonl-level-'.bin2hex(random_bytes(6)); }
    protected function tearDown(): void { (new Filesystem())->remove($this->dir); JsonlWriter::setDefaultCompressionLevel(1); }
    #[Test]
    public function default_and_override_reach_gzip_and_round_trip(): void
    {
        JsonlWriter::setDefaultCompressionLevel(9);
        foreach (['default' => null, 'fast' => 1, 'stored' => 0] as $name => $level) {
            $file=$this->dir.'/'.$name.'.jsonl.gz';
            $writer=JsonlWriter::open($file, compressionLevel: $level);
            $writer->write(['id'=>1,'text'=>'Repeated newspaper text']);$writer->close();
            self::assertSame([['id'=>1,'text'=>'Repeated newspaper text']], iterator_to_array(JsonlReader::open($file), false));
            self::assertSame($level === null ? 2 : 4, ord(file_get_contents($file)[8]));
        }
    }
    #[Test]
    public function append_and_plain_files_keep_their_contract(): void
    {
        $file=$this->dir.'/append.jsonl.gz';
        foreach (['w','a'] as $mode) { $w=JsonlWriter::open($file, mode:$mode, compressionLevel:1);$w->write(['mode'=>$mode]);$w->close(); }
        self::assertSame([['mode'=>'w'],['mode'=>'a']], iterator_to_array(JsonlReader::open($file),false));
        $plain=$this->dir.'/plain.jsonl';$w=JsonlWriter::open($plain,compressionLevel:9);$w->write(['id'=>2]);$w->close();
        self::assertStringStartsWith('{',file_get_contents($plain));
    }
    #[Test]
    public function policy_distinguishes_false_from_zero_and_discovery_handles_mixed_files(): void
    {
        $plain=$this->dir.'/data.jsonl';
        self::assertSame($plain,Jsonl::outputPath($plain,false));
        self::assertSame($plain.'.gz',Jsonl::outputPath($plain,0));
        $w=JsonlWriter::open($plain.'.gz');$w->write(['version'=>'old']);$w->close();
        self::assertSame($plain.'.gz',Jsonl::resolvePath($plain));
        $w=JsonlWriter::open($plain);$w->write(['version'=>'new']);$w->close();
        self::assertSame([$plain],Jsonl::files($this->dir));
        self::assertSame([['version'=>'new']],iterator_to_array(JsonlReader::open($plain),false));
    }
    #[Test]
    public function compaction_preserves_bytes_mtime_and_reader_state(): void
    {
        $file = $this->dir.'/compact.jsonl';
        $writer = JsonlWriter::open($file);
        $writer->write(['text' => str_repeat('newspaper ', 100)]);
        $writer->finish(markComplete: true);
        $writer->close();
        $original = file_get_contents($file);
        touch($file, 1234567890);
        $compressed = (new \Survos\JsonlBundle\IO\JsonlFileCompressor())->compress($file, 1);
        self::assertFileDoesNotExist($file);
        self::assertSame($original, gzdecode(file_get_contents($compressed)));
        self::assertSame(1234567890, filemtime($compressed));
        self::assertSame(1, JsonlReader::open($file)->state()->getStats()->getRows());
    }

    #[Test]
    public function invalid_level_fails_before_creating_output(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JsonlWriter::open($this->dir.'/bad.jsonl.gz', compressionLevel:10);
    }
}
