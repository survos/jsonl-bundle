<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\JsonlBundle\Command\JsonlStateCommand;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlStateRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

final class JsonlStateCommandTest extends TestCase
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
    public function it_reports_state_for_plain_jsonl(): void
    {
        $jsonl = $this->dir . '/products.jsonl';

        $writer = JsonlWriter::open($jsonl);
        $writer->write(['id' => 1, 'name' => 'alpha']);
        $writer->write(['id' => 2, 'name' => 'beta']);
        $writer->finish(markComplete: true);

        $repo = new JsonlStateRepository();

        $command = $this->wrapInvokable(new JsonlStateCommand($repo));
        $tester  = new CommandTester($command);

        $exit = $tester->execute(['path' => $jsonl]);

        self::assertSame(0, $exit);

        $out = $tester->getDisplay();
        self::assertStringContainsString('JSONL state', $out);
        self::assertStringContainsString('Rows', $out);
        self::assertStringContainsString('2', $out);
        self::assertStringContainsString('Completed', $out);
        self::assertStringContainsString('yes', $out);
    }

    #[Test]
    public function it_emits_json_output(): void
    {
        $jsonl = $this->dir . '/products.jsonl';

        $writer = JsonlWriter::open($jsonl);
        $writer->write(['id' => 10, 'name' => 'gamma']);
        $writer->finish(markComplete: true);

        $command = $this->wrapInvokable(new JsonlStateCommand(new JsonlStateRepository()));
        $tester  = new CommandTester($command);

        $exit = $tester->execute(['path' => $jsonl, '--json' => true]);

        self::assertSame(0, $exit);

        $data = json_decode($tester->getDisplay(), true);
        self::assertIsArray($data);

        self::assertSame($jsonl, $data['jsonl']);
        self::assertSame(1, $data['rows']);
        self::assertTrue($data['completed']);
        self::assertIsBool($data['fresh']);
    }

    /**
     * Wrap an invokable command-handler into a real Symfony Command for testing.
     * This avoids relying on Application wiring or container-based AsCommand registration.
     */
    private function wrapInvokable(JsonlStateCommand $handler): Command
    {
        $command = new Command('jsonl:state');

        $command->setDescription('Show JSONL sidecar/state (rows, bytes, completed, freshness).');

        $command->addArgument('path', InputArgument::REQUIRED, 'Path to a .jsonl (or .jsonl.gz) file.');
        $command->addOption('ensure', 'e', InputOption::VALUE_NONE, 'Ensure a sidecar exists and captures current file facts.');
        $command->addOption('json', 'j', InputOption::VALUE_NONE, 'Emit machine-readable JSON.');

        $command->setCode(function (InputInterface $input, OutputInterface $output) use ($handler): int {
            $io = new SymfonyStyle($input, $output);

            /** @var string $path */
            $path = (string) $input->getArgument('path');

            $ensure = (bool) $input->getOption('ensure');
            $json   = (bool) $input->getOption('json');

            return $handler($io, $path, $ensure, $json);
        });

        return $command;
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
