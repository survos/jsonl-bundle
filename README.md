# Survos JsonlBundle

**Streaming JSONL read/write utilities for Symfony**, with first-class support for **resumable writes**, **sidecar progress tracking**, **transparent gzip compression**, and **CLI-level inspection and querying**.

JsonlBundle is intentionally small at the API surface, but *feature-rich under the hood*. Many of its most powerful capabilities are currently “hidden” because they require no extra code once you adopt the bundle’s reader/writer abstractions.

This README makes those capabilities explicit, with concrete, copy-pasteable examples.

---

## Why JSONL?

JSON Lines (`.jsonl`) is the ideal format for:

* Large datasets
* Streaming ingestion pipelines
* Fault-tolerant ETL
* CLI-driven workflows
* Append-only logs
* Partial resume after failure

JsonlBundle embraces these properties rather than fighting them.

---

## Installation

```bash
composer require survos/jsonl-bundle
```

Symfony Flex will register the bundle automatically.

---

## Core Concepts

### Files
* One JSON object per line
* Append-only
* Safe for streaming

### Sidecar
Every JSONL file written with `JsonlWriter` has an optional **sidecar file** that tracks:

* Total records written
* Byte offset
* Completion status
* Timestamps
* Resume metadata

Sidecars enable **resume**, **progress inspection**, and **CLI tooling**.

### Compression
If the filename ends in `.gz`, compression is automatic.

No flags. No config. Just naming.

---

## Writing JSONL

### Basic Write

```php
use Survos\JsonlBundle\IO\JsonlWriter;

$writer = JsonlWriter::open('data/products.jsonl');

$writer->write([
    'id' => 1,
    'name' => 'Widget',
    'price' => 9.99,
]);

$writer->close();
```

This immediately gives you:

* File locking
* Atomic writes
* Sidecar tracking

---

### Appending Multiple Records

```php
$writer = JsonlWriter::open('data/products.jsonl');

foreach ($products as $product) {
    $writer->write($product);
}

$writer->close();
```

No buffering required. Memory-safe for large datasets.

---

### Resume a Partial Write

If the process crashes midway, simply reopen the same file:

```php
$writer = JsonlWriter::open('data/products.jsonl');

foreach ($remainingProducts as $product) {
    $writer->write($product);
}

$writer->close();
```

The writer will:

* Detect the sidecar
* Resume safely
* Avoid corrupt output

---

### Writing with Gzip Compression

Just use `.gz`:

```php
$writer = JsonlWriter::open('data/products.jsonl.gz');

$writer->write([
    'id' => 1,
    'name' => 'Compressed Widget',
]);
```

Features retained:

* Streaming
* Resume
* Sidecar
* Locking

---

## Reading JSONL

### Basic Read

```php
use Survos\JsonlBundle\IO\JsonlReader;

$reader = new JsonlReader('data/products.jsonl');

foreach ($reader as $row) {
    echo $row['name'] . PHP_EOL;
}
```

The reader:

* Streams line-by-line
* Uses constant memory
* Works for `.jsonl` and `.jsonl.gz`

---

### Read with Offset (Resume Processing)

```php
$reader = new JsonlReader(
    filename: 'data/products.jsonl',
    offset: 5000
);

foreach ($reader as $row) {
    // Continue processing after record 5000
}
```

Ideal for batch pipelines and workers.

---

## Sidecar Files (The Hidden Superpower)

For a file:

```
data/products.jsonl
```

JsonlBundle maintains:

```
data/products.jsonl.sidecar.json
```

### What’s Inside the Sidecar?

Typical contents:

```json
{
  "rows": 12450,
  "bytes": 9876543,
  "completed": false,
  "started_at": "2026-01-05T10:21:11Z",
  "updated_at": "2026-01-05T10:25:02Z"
}
```

You do **not** manage this manually.

---

### Query Progress Programmatically

```php
use Survos\JsonlBundle\Model\JsonlSidecar;

$sidecar = JsonlSidecar::fromFilename('data/products.jsonl');

echo $sidecar->rows;       // 12450
echo $sidecar->completed; // false
```

---

### Mark Completion Explicitly

```php
$writer = JsonlWriter::open('data/products.jsonl');

// write rows...

$writer->markCompleted();
$writer->close();
```

This is especially useful in multi-stage pipelines.

---

## CLI Utilities

JsonlBundle ships with CLI tooling to inspect files *without writing code*.

### Inspect a File

```bash
php bin/console jsonl:info data/products.jsonl
```

Example output:

```
File: data/products.jsonl
Rows: 12450
Bytes: 9.4 MB
Compressed: no
Completed: false
```

---

### Inspect a Gzipped File

```bash
php bin/console jsonl:info data/products.jsonl.gz
```

Works exactly the same.

---

### Peek at Records

```bash
php bin/console jsonl:head data/products.jsonl --limit=5
```

---

### Resume-Aware Counting

Unlike `wc -l`, JsonlBundle uses the sidecar:

```bash
php bin/console jsonl:count data/products.jsonl
```

Fast and accurate even for huge files.

---

## Patterns Enabled by JsonlBundle

### ETL Pipelines

```text
download → normalize → enrich → translate → index
```

Each step emits JSONL, resumes safely, and records progress.

---

### Doctrine Import Pipelines

* Stream JSONL
* Batch insert entities
* Resume on failure
* Track progress via sidecar

---

### Translation Caches

* `source.jsonl`
* `target.jsonl`
* De-duplication
* Resume after API failure

---

### Search Index Feeds

* Emit JSONL once
* Replay into Meilisearch / OpenSearch
* Deterministic re-runs

---

## When *Not* to Use JsonlBundle

* Small datasets fully loaded into memory
* Interactive CRUD forms
* Request/response APIs

JsonlBundle shines in **pipelines**, **CLI tools**, and **long-running jobs**.

---

## Design Philosophy

* Zero configuration
* Filename-driven behavior
* Streaming first
* Fail-safe defaults
* Sidecar over database state
* CLI-friendly

---

## Summary

JsonlBundle is more than a reader/writer:

* ✔ Resumable writes
* ✔ Sidecar progress tracking
* ✔ Transparent gzip compression
* ✔ CLI inspection & querying
* ✔ Streaming everywhere

Most of this works automatically once you adopt `JsonlWriter` and `JsonlReader`.

---

## Example: Caching a Remote API as JSONL (DummyJSON Products)

This example demonstrates how JsonlBundle can be used as a **simple, durable cache** for a remote HTTP API, without introducing a separate caching abstraction.

Goal:

* Command: `bin/console products:list`
* If `products.jsonl` does **not** exist:
    * Fetch `https://dummyjson.com/products`
    * Write each product as a JSONL record
* Then:
    * Read `products.jsonl`
    * Display the **first product**

This turns a one-shot HTTP call into a **replayable, CLI-friendly dataset**.

---

### The Command (Minimal, Accurate API Usage)

```php
<?php
declare(strict_types=1);

namespace App\Command;

use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;

#[AsCommand('products:list', 'List products using a JSONL cache')]
final class ProductsListCommand
{
    public function __invoke(SymfonyStyle $io): int
    {
        $file = 'var/products.jsonl';

        if (!file_exists($file)) {
            $client = HttpClient::create();
            $data   = $client->request('GET', 'https://dummyjson.com/products')->toArray();

            $writer = JsonlWriter::open($file);
            foreach ($data['products'] as $product) {
                $writer->write($product);
            }
            $writer->close();
        }

        $reader = JsonlReader::open($file);
        foreach ($reader as $product) {
            $io->title($product['title']);
            $io->listing([
                'Price: ' . $product['price'],
                'Category: ' . $product['category'],
            ]);
            break; // first product only
        }

        return Command::SUCCESS;
    }
}
```

---

## What This Example Actually Uses

This command relies only on **existing, stable APIs**:

### JsonlWriter
* `JsonlWriter::open($filename)`
* `->write(array $row)`
* `->close()`

### JsonlReader
* `JsonlReader::open($filename)`
* `foreach ($reader as $row)`

No hidden methods. No imaginary helpers.

---

## What You Still Get (Implicitly)

Even with this minimal API surface, you still benefit from:

* ✔ Streaming writes (no memory pressure)
* ✔ File locking
* ✔ Safe append semantics
* ✔ Sidecar tracking (rows / bytes / timestamps)
* ✔ Resume-safe re-execution
* ✔ Transparent `.gz` support via filename

None of this appears in the command — which is the point.

---

## Optional Variations

### Enable Compression (Zero Code Changes)

```php
$file = 'var/products.jsonl.gz';
```

### Inspect the Cache from the CLI

```bash
bin/console jsonl:info var/products.jsonl
```

### Re-run Offline

Once written, the command works without network access.

---

## Why This Pattern Matters

This example shows the intended JsonlBundle workflow:

* Treat JSONL as a **first-class artifact**
* Use filenames, not flags, to control behavior
* Let sidecars track progress instead of databases
* Make pipelines **restartable by default**

You are not “caching HTTP”.
You are **materializing data**.

That distinction is why JsonlBundle scales cleanly from demos to production pipelines.

## Inspecting JSONL Files (`jsonl:info`)

In addition to counting rows, JsonlBundle provides a command to **inspect progress and status metadata** stored in sidecar files.

### Show info for a single file

```bash
bin/console jsonl:info data/products.jsonl
```

Example output:

```
JSONL info
──────────
File:             data/products.jsonl
Rows:             12450
Sidecar:          data/products.jsonl.sidecar.json
Sidecar exists:   yes
Completed:        no
Started:          2026-01-05T14:03:21+00:00
Updated:          2026-01-05T14:22:09+00:00
Bytes (sidecar):  98234112
```

This is the fastest way to answer questions like:

* “How many records are written so far?”
* “Did this pipeline finish?”
* “When was this dataset last updated?”

---

### Inspect a directory of JSONL files

```bash
bin/console jsonl:info var/data
```

### Recurse into subdirectories

```bash
bin/console jsonl:info var/data -r
```

Directory mode renders a table with one row per file, including completion status and timestamps:

| Rows | Complete | Updated | Started | File |
|----:|:--------:|---------|---------|------|
| 170072 | no | 2026-01-05T14:22:09 | 2026-01-05T14:03:21 | place.jsonl |
| 3340 | yes | 2026-01-05T13:01:44 | 2026-01-05T12:58:10 | concept.jsonl |
| … | … | … | … | … |

---

### Why `jsonl:info` matters

* Uses **sidecar metadata**, not slow file scans
* Works for `.jsonl` and `.jsonl.gz`
* Distinguishes **partial** vs **completed** datasets
* Ideal for long-running or resumable pipelines

For deeper discussion of sidecars, resume logic, and CLI workflows, see the
[advanced usage guide](doc/advanced.md).


## Advanced Usage

This bundle is intentionally small at the surface but powerful in real-world pipelines.

For production patterns—including **resume semantics**, **sidecar files**, **row counting**, **CLI workflows**, and **anti-patterns to avoid**—see the advanced documentation:

👉 **[Advanced Usage Guide](doc/advanced.md)**

That document is where long-running jobs, API dumps, and restartable ingestion pipelines are covered in detail.

