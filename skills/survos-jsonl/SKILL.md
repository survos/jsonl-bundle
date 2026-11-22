---
name: survos-jsonl
description: Read and write JSON Lines (.jsonl, .jsonl.gz) files in a Symfony app with survos/jsonl-bundle, which streams, locks concurrent writers, resumes partial writes and keeps a progress sidecar. Use whenever code reads, writes, caches, exports, harvests or counts records as JSONL or NDJSON — a harvest or API cache, an import or export pipeline, a dataset dump, a search-index feed — and before writing any fopen/json_encode/fgets loop for line-delimited JSON. Triggers on .jsonl, .jsonl.gz, ndjson, JSON Lines, JsonlWriter, JsonlReader, JsonlWriterOptions, jsonl:info, jsonl:count, jsonl:index. Do not use for a single JSON document or for CSV.
---

# JSONL with survos/jsonl-bundle

Do not write your own JSONL reader or writer. `survos/jsonl-bundle` is already a
dependency of most Survos apps and bundles (`composer show survos/jsonl-bundle`);
if it is missing, `composer require survos/jsonl-bundle`.

Hand-rolled `fopen` + `json_encode` + `"\n"` loops lose what the bundle gives for
free: an exclusive lock so two workers cannot interleave lines, transparent gzip
by file extension, resume after a crash, and a sidecar recording rows, bytes and
whether the dataset is complete.

## Writing

```php
use Survos\JsonlBundle\IO\JsonlWriter;

$writer = JsonlWriter::open($path);        // 'w': truncate and reset the sidecar
foreach ($rows as $row) {
    $writer->write($row);                   // array or object, one line each
}
$writer->finish();                          // marks complete, then closes
```

- **Mode is the intent.** `'w'` (default) starts a fresh dataset. `'a'` appends
  and is how a crashed or paginated job resumes: `JsonlWriter::open($path, 'a')`.
  Long-running harvests should use `'a'`.
- **Gzip is the filename.** `products.jsonl.gz` compresses; nothing else changes.
- **Directories are created** by default. Pass
  `new JsonlWriterOptions(ensureDir: false)` (or `JsonlWriterOptions::noDirs()`)
  when the app owns its paths, and `JsonlWriterOptions::noLock()` only for a
  single writer you control.
- **Dedupe with a token.** `$writer->write($row, tokenCode: $row['id'])` records the
  key; `JsonlReader::open($path)->containsToken($id)` checks it before refetching.
- Call `finish()` (or `markComplete()` then `close()`) only when the whole dataset
  is written, so readers can tell a finished file from a partial one.

## Reading

```php
use Survos\JsonlBundle\IO\JsonlReader;

foreach (JsonlReader::open($path) as $row) {   // streams; .jsonl and .jsonl.gz
    // $row is an associative array
}
```

- Constant memory. Never `file()` or `json_decode(file_get_contents(...))` a JSONL file.
- `->first()` returns the first record, for sniffing the shape.
- `->get($pk)` is random access by primary key after `bin/console jsonl:index <file>`.
- `->state()->getStats()` gives rows, bytes and `isCompleted()` without reading the file.
- `JsonlReader::openArchive($zip, $member)` reads a JSONL member inside a local archive.

## Console

```bash
bin/console jsonl:info var/data            # rows, size, completion; a dir or a file
bin/console jsonl:count data/products.jsonl
bin/console jsonl:state data/products.jsonl
bin/console jsonl:index data/products.jsonl   # SQLite sidecar: offsets, facets, row count
bin/console jsonl:profile data/products.jsonl # field types and top values
```

## Sidecars

The writer keeps `<file>.db` (SQLite) beside the data. Leave it in place and do
not edit it; delete it only with the data file. Old `.sidecar.json` files are
superseded and `jsonl:clean` removes them.

## Before changing the bundle itself

Rules for editing survos/jsonl-bundle are in its `AGENTS.md`; this skill is for
using it from an app or another bundle.
