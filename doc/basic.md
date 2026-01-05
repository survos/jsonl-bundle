# JSONL Reader/Writer

This bundle provides streaming JSONL I/O:

- `JsonlWriter` appends rows to `.jsonl` or `.jsonl.gz`
- `JsonlReader` streams rows from `.jsonl` or `.jsonl.gz`

It also maintains a “progress sidecar” file:

- `<file>.sidecar.json`

The sidecar exists so applications can understand JSONL state (rows, bytes, timestamps, completion) without scanning the JSONL file.

## Writing JSONL (recommended pattern)

```php
use Survos\JsonlBundle\IO\JsonlWriter;

$writer = JsonlWriter::open($path);

foreach ($rows as $row) {
    $writer->write($row);
}

// Recommended: finish() returns a stable result object and refreshes sidecar state.
$result = $writer->finish(markComplete: true);

$state = $result->state;
$rows  = $state->getStats()->getRows() ?? 0;
$bytes = $state->getStats()->getBytes() ?? 0;
```

Notes:

- `close()` still exists and remains `void`. Use it for low-level lifecycle management.
- `finish()` is the application-friendly API: it finalizes, updates state, and returns it.

## Reading JSONL

```php
use Survos\JsonlBundle\IO\JsonlReader;

$reader = JsonlReader::open($path);

foreach ($reader as $row) {
    // ...
}
```

## Reading JSONL state (sidecar) without scanning the JSONL file

Use `JsonlStateRepository` as the single “how do I know the JSONL state?” entry point.

```php
use Survos\JsonlBundle\Service\JsonlStateRepository;

$repo  = new JsonlStateRepository();
$state = $repo->load($path);

$rows = $state->getStats()->getRows();
$completed = $state->getStats()->isCompleted();

if (!$state->isFresh()) {
    // Sidecar missing or stale; refresh persists filesystem validation (mtime/size)
    $state = $repo->refresh($path);
}
```

This avoids manual inspection in the application code (no `file()`, no line counting, no `method_exists()` checks on writer internals).
