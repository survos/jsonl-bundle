<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\State;

use Survos\JsonlBundle\Contract\BlockNamingStrategyInterface;
use Survos\JsonlBundle\Contract\StateStoreInterface;

/**
 * Atomic JSON sidecar store. Writes to "<finalPath>.state" by default.
 */
final class FileStateStore implements StateStoreInterface
{
    public function __construct(
        private readonly ?BlockNamingStrategyInterface $namer = null
    ) {}

    public function read(string $finalPath): array
    {
        $path = $this->statePath($finalPath);
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return \is_array($data) ? $data : [];
    }

    public function write(string $finalPath, array $state): void
    {
        $path = $this->statePath($finalPath);
        $dir  = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: $dir");
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode state JSON.');
        }
        if (file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            throw new \RuntimeException("Failed writing temp state: $tmp");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Atomic rename failed to $path");
        }
    }

    private function statePath(string $finalPath): string
    {
        if ($this->namer) {
            return $this->namer->statePath($finalPath);
        }
        return $finalPath . '.state';
    }
}
