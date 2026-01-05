# JsonlBundle — Advanced Usage Guide

This document captures **non-obvious, production-grade patterns** for using JsonlBundle correctly and efficiently.

If `README.md` answers *“what is this?”*, this file answers *“how do we actually use this in real pipelines?”*

This document is intentionally example-heavy. That is by design.

---

## Design Principles (Read This First)

JsonlBundle is built around these ideas:

* JSONL files are **first-class artifacts**, not temporary caches
* Pipelines must be **restartable by default**
* Progress belongs on disk, not in memory or databases
* Filenames, not flags, control behavior
* Streaming beats random access

If your code fights any of these principles, you are probably using the bundle incorrectly.

---

## Sidecar Files (Progress Is Not Optional)

Every JSONL file written by `JsonlWriter` maintains a **sidecar file**:

```
<file>.jsonl.sidecar.json
<file>.jsonl.gz.sidecar.json
```

The sidecar records:

* rows written
* bytes written
* started_at timestamp
* updated_at timestamp
* completed flag

Example:

```json
{
  "version": 1,
  "rows": 170072,
  "bytes": 98234112,
  "completed": false,
  "startedAt": "2026-01-05T14:03:21+00:00",
  "updatedAt": "2026-01-05T14:22:09+00:00"
}
```

### Important Rules

* You **never** write sidecars yourself
* Sidecars are updated automatically on each `write()`
* Sidecars are authoritative once they exist
* Sidecars enable fast resume and progress reporting

---

## Counting Rows (The Only Supported Way)

### ❌ Do Not Do This

```php
wc -l file.jsonl
```

```php
fgets() in a loop
```

```php
substr_count(file_get_contents(...))
```

These approaches:

* are slow
* break on `.gz`
* duplicate logic
* ignore sidecars

---

### ✅ Correct Approach

Always use the bundle service:

```php
use Survos\JsonlBundle\Service\JsonlCountService;

$rows = $jsonlCount->rows($jsonlPath);
```

Policy:

1. If a sidecar exists → use it (O(1))
2. Otherwise → fall back to newline counting
3. Works for `.jsonl` and `.jsonl.gz`

This is why `JsonlCountService` exists.

---

## Resume Patterns (Critical)

### Correct Resume Logic

```php
$existingRows = $jsonlCount->rows($jsonlPath);
$startPage = intdiv($existingRows, $pageSize) + 1;
```

This works because:

* 1 JSONL line == 1 logical record
* sidecars stay consistent even after crashes
* resume math stays simple and deterministic

### Marking Completion

At the **end of a successful run**:

```php
$writer->markComplete();
$writer->close();
```

The `completed` flag allows future tooling to distinguish:

* partial datasets
* finished datasets

---

## Writing JSONL Correctly

### Minimal, Correct Writer Usage

```php
$writer = JsonlWriter::open($file);

foreach ($records as $row) {
    $writer->write($row);
}

$writer->markComplete();
$writer->close();
```

What you get automatically:

* streaming writes
* Symfony Lock protection
* sidecar progress tracking
* `.jsonl.gz` support (by filename)
* crash-safe resume

---

## Reading JSONL (Streaming Only)

JsonlBundle readers are **sequential by design**.

### Correct Pattern

```php
$reader = JsonlReader::open($file);

foreach ($reader as $row) {
    // process row
}
```

### Convenience Helper

```php
$first = $reader->first();
```

### Explicit Non-Features

JsonlReader intentionally does **not** support:

* `current()`
* `seek()`
* random access
* rewinding cursors

If you need random access, JSONL is the wrong format.

---

## Gzip Is Filename-Driven

Compression is inferred **only** from the filename.

```php
JsonlWriter::open('data/products.jsonl.gz');
JsonlReader::open('data/products.jsonl.gz');
```

No flags. No config. No options.

Changing the filename is the only supported switch.

---

## CLI: jsonl:count

### Single File

```bash
bin/console jsonl:count data/products.jsonl
```

### Directory Scan

```bash
bin/console jsonl:count var/data
```

### Recursive Directory Scan

```bash
bin/console jsonl:count var/data -r
```

Output is sidecar-aware and additive:

| Rows | File |
|----:|------|
| 170072 | place.jsonl |
| 3340 | concept.jsonl |
| … | … |
| **179685** | **TOTAL** |

This command exists so applications **never** need to re-implement counting.

---

## Real-World Example: API Dumps (Europeana)

Why sidecars matter:

* Europeana dumps take hours
* APIs fail mid-run
* Restarting from scratch is unacceptable

Correct approach:

1. Use `JsonlWriter`
2. Resume using `JsonlCountService`
3. Drive progress bars from sidecar counts
4. Mark completion explicitly

Manual line counting is a code smell in this context.

---

## Anti-Patterns (Do Not Copy These)

### ❌ Manual file appends

```php
file_put_contents($file, json_encode($row)."\n", FILE_APPEND);
```

### ❌ Custom resume math

```php
$lines = $this->countJsonlLines($file);
```

### ❌ Treating JSONL as “just a file”

JSONL in this bundle is a **tracked, resumable dataset**, not a blob.

---

## Why This File Exists (For Humans and AI)

This document is intentionally explicit because:

* humans forget details
* AI agents infer APIs from examples
* undocumented behavior gets re-implemented badly

If you follow the patterns here:

* your pipelines will be restartable
* your counts will be correct
* `.gz` will just work
* future tooling will compose cleanly

If you deviate, you are on your own.

---

## Summary

* Use sidecars
* Use `JsonlCountService`
* Stream everything
* Resume by math, not guesswork
* Let filenames control behavior

This is the **intended** way to use JsonlBundle in production pipelines.

---
