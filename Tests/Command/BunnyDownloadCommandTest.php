<?php
namespace Survos\JsonlBundle\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Survos\JsonlBundle\Command\JsonlDownloadCommand;

class JsonlDownloadCommandTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        // Define paths to clean up after tests
        $filesToDelete = [
            getcwd() . "/test/coleccion.json",
            getcwd() . "/test.json",
            getcwd() . "/coleccion.json",
        ];

        foreach ($filesToDelete as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir(getcwd() . "/test")) {
            rmdir(getcwd() . "/test");  // Only removes if directory is empty
        }
    }

    public function testExecute(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('jsonl:download');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'remoteFilename' => 'data/coleccion.json',
            'localDirOrFilename' => 'test',
            '--unzip' => false,
            '--force' => false,
        ]);

        $commandTester->assertCommandIsSuccessful();

        $output = $commandTester->getDisplay();
        $expectedFilePath = getcwd() . "/test/coleccion.json";

        $this->assertStringContainsString('jsonl:download: downloaded to', $output);
        $this->assertFileExists($expectedFilePath, "Expected file was not downloaded: $expectedFilePath");
    }

    public function testWithLocalFilenameExecute(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('jsonl:download');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'remoteFilename' => 'data/coleccion.json',
            'localDirOrFilename' => 'test.json',
            '--unzip' => false,
            '--force' => false,
        ]);

        $commandTester->assertCommandIsSuccessful();

        $output = $commandTester->getDisplay();
        $expectedFilePath = getcwd() . "/test.json";

        $this->assertTrue(is_file($expectedFilePath), "Expected path is not a file: $expectedFilePath");
        $this->assertStringContainsString('jsonl:download: downloaded to', $output);
    }

    public function testNotZipExecute(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('jsonl:download');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'remoteFilename' => 'data/coleccion.json',
            'localDirOrFilename' => 'test',
            '--unzip' => true,
            '--force' => false,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Only zip files are supported', $output);
    }

    public function testWithoutLocalDir(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('jsonl:download');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'remoteFilename' => 'data/coleccion.json',
            '--unzip' => false,
            '--force' => false,
        ]);

        $output = $commandTester->getDisplay();
        $expectedFilePath = getcwd() . "/data/coleccion.json";
        $this->assertStringContainsString('jsonl:download: downloaded to', $output);
        $this->assertFileExists($expectedFilePath, "Expected file was not downloaded: $expectedFilePath");
    }

    public function testWithRootDirExecute(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('jsonl:download');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'remoteFilename' => 'data/coleccion.json',
            'localDirOrFilename' => '/test',
            '--unzip' => false,
            '--force' => false,
        ]);

        $commandTester->assertCommandIsSuccessful();

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Creating /test/', $output);
        $this->assertStringContainsString('Downloading coleccion.json at data to  /test/coleccion.json', $output);
    }

    public function testUnzipExecute(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('jsonl:download');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'remoteFilename' => 'data/ajs.zip',
            'localDirOrFilename' => 'test',
            '--unzip' => true,
            '--force' => false,
        ]);

        $commandTester->assertCommandIsSuccessful();
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('jsonl:download: downloaded', $output);
        $this->assertStringContainsString('Unzipped', $output);
        $this->assertFileExists(getcwd() . "/test/ajs");
    }
}
