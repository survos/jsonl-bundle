<?php

declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\String\Slugger\SluggerInterface;
use ZipArchive;

final class JsonlDirectoryConverter
{
    public function __construct(
        private readonly SluggerInterface $slugger,
    ) {}

    /**
     * Convert JSON files from a directory or .zip into a JSONL[.gz] file.
     *
     * @param string      $input        Directory path or .zip file
     * @param string      $output       Target .jsonl or .jsonl.gz file
     * @param string|null $path         Optional sub-path filter (e.g. "/records")
     * @param string|null $pattern      Glob pattern for JSON files (default: "*.json")
     * @param string|null $slugifyField Field whose value should be slugified into "code"
     * @param string|null $pkSpec       Primary key spec: field name or pattern like "car-{lineNumber}"
     *
     * @return int Number of JSON objects written
     */
    public function convert(
        string $input,
        string $output,
        ?string $path = null,
        string $pattern = '*.json',
        ?string $slugifyField = null,
        ?string $pkSpec = null,
    ): int {
        $isZip   = \is_file($input) && \str_ends_with(\strtolower($input), '.zip');
        $pattern ??= '*.json';

        $writer     = JsonlWriter::open($output);
        $lineNumber = 0;
        $count      = 0;

        try {
            if ($isZip) {
                $count = $this->convertFromZip(
                    input: $input,
                    writer: $writer,
                    path: $path,
                    pattern: $pattern,
                    slugifyField: $slugifyField,
                    pkSpec: $pkSpec,
                    lineNumber: $lineNumber,
                );
            } elseif (\is_dir($input)) {
                $count = $this->convertFromDirectory(
                    input: $input,
                    writer: $writer,
                    path: $path,
                    pattern: $pattern,
                    slugifyField: $slugifyField,
                    pkSpec: $pkSpec,
                    lineNumber: $lineNumber,
                );
            } else {
                throw new RuntimeException("Input '$input' is neither a directory nor .zip file.");
            }
        } finally {
            $writer->close();
        }

        return $count;
    }

    # -------------------------------------------------------------------------
    # ZIP handling
    # -------------------------------------------------------------------------

    private function convertFromZip(
        string $input,
        JsonlWriter $writer,
        ?string $path,
        string $pattern,
        ?string $slugifyField,
        ?string $pkSpec,
        int &$lineNumber,
    ): int {
        $zip = new ZipArchive();
        if (true !== $zip->open($input)) {
            throw new RuntimeException("Unable to open zip archive '$input'.");
        }

        $filterPath = $this->normalizeFilterPath($path);
        $count      = 0;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }

                if (! $this->matchesPath($name, $filterPath)) {
                    continue;
                }

                if (! $this->matchesPattern(\basename($name), $pattern)) {
                    continue;
                }

                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    throw new RuntimeException("Failed to read entry '$name' from '$input'.");
                }

                $decoded = \json_decode($contents, true);
                if (\json_last_error() !== \JSON_ERROR_NONE) {
                    throw new RuntimeException(
                        "Invalid JSON in '$name': " . \json_last_error_msg()
                    );
                }

                $lineNumber++;
                $this->processRow(
                    decoded: $decoded,
                    writer: $writer,
                    slugifyField: $slugifyField,
                    pkSpec: $pkSpec,
                    sourceName: $name,
                    lineNumber: $lineNumber,
                );
                $count++;
            }
        } finally {
            $zip->close();
        }

        return $count;
    }

    # -------------------------------------------------------------------------
    # Directory handling
    # -------------------------------------------------------------------------

    private function convertFromDirectory(
        string $input,
        JsonlWriter $writer,
        ?string $path,
        string $pattern,
        ?string $slugifyField,
        ?string $pkSpec,
        int &$lineNumber,
    ): int {
        $base = \rtrim($input, \DIRECTORY_SEPARATOR);
        if ($path) {
            $base = Path::join($base, \ltrim($path, \DIRECTORY_SEPARATOR));
        }

        if (! \is_dir($base)) {
            throw new RuntimeException("Directory '$base' does not exist.");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $base,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            )
        );

        $count = 0;

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! $this->matchesPattern($file->getFilename(), $pattern)) {
                continue;
            }

            $contents = \file_get_contents($file->getPathname());
            if ($contents === false) {
                throw new RuntimeException("Failed to read '{$file->getPathname()}'.");
            }

            $decoded = \json_decode($contents, true);
            if (\json_last_error() !== \JSON_ERROR_NONE) {
                throw new RuntimeException(
                    "Invalid JSON in '{$file->getPathname()}': " . \json_last_error_msg()
                );
            }

            $relativeName = \str_replace($base . \DIRECTORY_SEPARATOR, '', $file->getPathname());

            $lineNumber++;
            $this->processRow(
                decoded: $decoded,
                writer: $writer,
                slugifyField: $slugifyField,
                pkSpec: $pkSpec,
                sourceName: $relativeName,
                lineNumber: $lineNumber,
            );
            $count++;
        }

        return $count;
    }

    # -------------------------------------------------------------------------
    # Row enrichment + tokenCode
    # -------------------------------------------------------------------------

    /**
     * @param mixed $decoded
     */
    private function processRow(
        mixed $decoded,
        JsonlWriter $writer,
        ?string $slugifyField,
        ?string $pkSpec,
        string $sourceName,
        int $lineNumber,
    ): void {
        if (! \is_array($decoded)) {
            $decoded = ['value' => $decoded];
        }

        // 1) Optional slugify → "code" field as first key.
        $code = null;
        if ($slugifyField !== null && isset($decoded[$slugifyField])) {
            $code = $this->slugify((string) $decoded[$slugifyField]);

            // Ensure "code" is the first key, but don't lose existing "code" if already set.
            unset($decoded['code']);
            $decoded = ['code' => $code] + $decoded;
        }

        // 2) Compute tokenCode based on pkSpec / code / lineNumber.
        $tokenCode = $this->computeTokenCode(
            row: $decoded,
            code: $code,
            pkSpec: $pkSpec,
            lineNumber: $lineNumber,
            fallbackSource: $sourceName,
        );

        // 3) Write via JsonlWriter (handles index + dedupe).
        $writer->write($decoded, $tokenCode);
    }

    private function slugify(string $value): string
    {
        return \strtolower((string) $this->slugger->slug($value));
    }

    /**
     * Compute a stable tokenCode for JsonlWriter based on:
     *  - pkSpec as a pattern (contains "{lineNumber}" or "{slug}")
     *  - pkSpec as a field name
     *  - otherwise fall back to "code", then lineNumber, then sourceName.
     */
    private function computeTokenCode(
        array $row,
        ?string $code,
        ?string $pkSpec,
        int $lineNumber,
        string $fallbackSource,
    ): string {
        // Pattern mode: pkSpec contains placeholders like {lineNumber}, {slug}.
        if ($pkSpec !== null && \str_contains($pkSpec, '{')) {
            return \strtr($pkSpec, [
                '{lineNumber}' => (string) $lineNumber,
                '{slug}'       => $code ?? '',
            ]);
        }

        // Field name mode: pkSpec is interpreted as "use row[pkSpec]".
        if ($pkSpec !== null) {
            if (isset($row[$pkSpec]) && $row[$pkSpec] !== '') {
                return (string) $row[$pkSpec];
            }

            // If field not present or empty, fall back to lineNumber.
            return (string) $lineNumber;
        }

        // No pkSpec: prefer "code" if present.
        if ($code !== null && $code !== '') {
            return $code;
        }

        // Next best: "id" if present.
        if (isset($row['id']) && $row['id'] !== '') {
            return (string) $row['id'];
        }

        // Fallback to lineNumber, and finally to sourceName to break ties.
        return $lineNumber . ':' . $fallbackSource;
    }

    # -------------------------------------------------------------------------
    # Matching helpers
    # -------------------------------------------------------------------------

    private function normalizeFilterPath(?string $path): ?string
    {
        return $path ? \trim($path, '/') : null;
    }

    private function matchesPath(string $entry, ?string $filter): bool
    {
        if ($filter === null) {
            return true;
        }

        $entry = \str_replace('\\', '/', $entry);

        return \str_contains($entry, '/' . $filter . '/')
            || \str_starts_with($entry, $filter . '/');
    }

    private function matchesPattern(string $filename, string $pattern): bool
    {
        if (\function_exists('fnmatch')) {
            return \fnmatch($pattern, $filename);
        }

        // Fallback: treat "*.json" specially, otherwise accept everything.
        if ($pattern === '*.json') {
            return \str_ends_with(\strtolower($filename), '.json');
        }

        return true;
    }
}

