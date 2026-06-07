# JSONL Profiler — notes & memory fix

Status: working notes, continue later.
Last updated: 2026-06-07

## TL;DR

`Service/JsonlProfiler` was exhausting memory (512MB OOM) when profiling fields
whose values are arrays/objects (nested records, IIIF manifest blobs). Fixed by
**not distinct-tracking non-scalars**. Scalar behavior is unchanged.

## The crash

```
PHP Fatal error: Allowed memory size of 536870912 bytes exhausted
  in Service/JsonlProfiler.php on line 237  (json_encode in normalizeDistinctKey)
  <- profile() distinct-tracking block
  <- ImportConvertCommand::buildProfile() / convert()
  <- DatasetStageCommands::normalize()
```

### Root cause

The distinct-tracking loop called `normalizeDistinctKey($value)`, which
`json_encode()`d whole array/object values into string keys and stored them in
`$fieldStats['distinctValues']`. With `distinctCap = 100000` and payload-ish
fields where every blob is unique, the set grew with multi-KB JSON strings until
memory ran out. The `json_encode` line was just where the final allocation
failed — the real cost was accumulating the blobs.

Distinct-tracking arrays/objects is pointless anyway: they're never facet / enum
/ term-set / boolean-like candidates. The consumer-side model
(`Model/FieldStats::computeFacetCandidate`) already excludes array/object types,
and the only readers of `distinctValues` (`data-bundle TermSetExtractor`,
`distribution` in code-bundle) are scalar-only.

## The fix (applied)

`src/Service/JsonlProfiler.php`, one behavioral change:

- **Distinct-track scalars only.** The hot-loop block now guards on
  `\is_scalar($value)`; non-scalars are skipped (their type is still recorded via
  `types`, so `storageHint` for arrays stays `json`).
- Deleted the now-dead `normalizeDistinctKey()` method (the only `json_encode`).
- `distinctCap` kept at **100000** so high-cardinality *scalar* fields still
  report an exact distinct count for analysis. Safe for memory because only
  scalars (short keys) are tracked now.

Verified: 4000 rows with 50-image manifests profile in ~12MB peak.
- `id` → exact `distinct=4000` (analysis fidelity preserved)
- `status` → low-cardinality, facets/term sets intact
- `manifest` → `distinct=0`, not json-encoded, but `storageHint=json` / `types=array` preserved

## Why DTO / entity generation is unaffected

`code-bundle CodeEntityCommand::determineTypesFromStats()` decides Doctrine types
from only three inputs: `storageHint`, `types` (array presence), and
`stringLengths.max`. It never reads `distinct` / `distinctCapReached` /
`distinctValues`. None of those three inputs changed, so generated entities are
byte-identical.

## Resolution (Phase 5, 2026-06-07)

The real engine is now **`Sqlite/SqlProfiler`** — it stages the file into SQLite and
computes per-field stats with `json_tree`/`json_type` into the `field_stats` table,
bounded by construction (exact `COUNT(DISTINCT)` as a number + top-N values, never a
value-list blob). Array fields are exploded with `json_each` into element frequencies.
This is what `jsonl:profile` runs; read it via `SidecarDb::loadFieldStats()`.

Both in-PHP profilers below are now **`@deprecated` (since 2.8)** and emit a runtime
deprecation **only when their accumulator is used** (`JsonlProfiler::profile()`,
`FieldStats::push()`) — the read path (`FieldStats::fromArray()`) is unaffected, so
profile *readers* aren't spammed. They remain functional until consumers migrate:
**md & meili apps, folio `FolioSchemaSnapshotter` (should move off entirely), code-bundle
`CodeEntityCommand`, import-bundle `ImportConvertCommand`, past-perfect `HarvestDetailCommand`.**
Hard removal happens after that migration (tracked in PLAN Phase 6).

## Historical: two parallel profilers

There were **two parallel profilers** in this bundle doing the same job:

| | `Service/JsonlProfiler` (write path) | `Model/FieldStats` (read/consumer side) |
|---|---|---|
| distinct cap | 100000 | `DISTINCT_CAP = 500` |
| on overflow | stop adding, unset values at finalize | discard values immediately |
| arrays/objects | now skipped (this fix) | excluded from facet/distinct logic |
| extra heuristics | splitCandidate, naturalLanguageLike, urlLike, imageLike | none |

`ImportConvertCommand::buildProfile()` builds with the Service profiler;
`FieldStats` is only hydrated `fromArray()` on the read side (code-bundle). They
even carried an identical copy of `normalizeDistinctKey`.

Consolidating to one accumulator was the real cleanup — done in Phase 5 by making
`SqlProfiler` canonical and deprecating both in-PHP accumulators (see Resolution above).

## How to validate further

- Re-run `import:convert` (via dataset `normalize`) against the dataset that
  crashed, or any dataset with array-valued / IIIF-manifest fields.
- A saved profile artifact + `code:entity` generation is the definitive check
  that DTO output is unchanged.
- No profiler unit test exists yet (`tests/` has no JsonlProfiler coverage) —
  worth adding one that asserts array fields don't accumulate `distinctValues`.
