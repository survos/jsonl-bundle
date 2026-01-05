<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

use Survos\JsonlBundle\Util\Jsonl;

/**
 * Canonical row-count utility for JSONL artifacts.
 *
 * Policy:
 *  1) Prefer sidecar rows when available (fast, authoritative)
 *  2) Fallback to counting newline characters in the file
 *
 * Supports both .jsonl and .jsonl.gz
 *
 * This service exists so applications do NOT re-implement counting logic.
 */
final class JsonlCountService
{
    public function __construct(
        private readonly SidecarService $sidecarService = new SidecarService(),
    ) {}

    /**
     * Return the number of rows in a JSONL file.
     */
    public function rows(string $jsonlPath): int
    {
        $sidecarPath = $this->sidecarService->sidecarPath($jsonlPath);

        if (is_file($sidecarPath)) {
            $sc = $this->sidecarService->load($jsonlPath);
            return max(0, $sc->rows);
        }

        // No sidecar yet: fallback to physical counting
        return $this->countNewlines($jsonlPath);
    }

    /**
     * Count newline characters directly from disk.
     * Works for .jsonl and .jsonl.gz.
     */
    public function countNewlines(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }

        if (Jsonl::isGzipPath($path)) {
            $fh = @gzopen($path, 'rb');
            if ($fh === false) {
                return 0;
            }

            try {
                $lines = 0;
                while (!gzeof($fh)) {
                    $chunk = gzread($fh, 1024 * 1024);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $lines += substr_count($chunk, "\n");
                }

                return $lines;
            } finally {
                gzclose($fh);
            }
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return 0;
        }

        try {
            $lines = 0;
            while (!feof($fh)) {
                $chunk = fread($fh, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $lines += substr_count($chunk, "\n");
            }

            return $lines;
        } finally {
            fclose($fh);
        }
    }
}
