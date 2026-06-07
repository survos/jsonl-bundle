# HANDOFF — jsonl-bundle SQLite sidecar (Phases 1–8) → next: dataset/provider integration

Date: 2026-06-07. This session rebuilt jsonl-bundle around a **per-file SQLite
sidecar** and took it through PLAN Phases 1–8. The next session is about **tighter
dataset integration, iterating over all providers**. This doc is the orientation.

## TL;DR — what exists now

Every `<file>.jsonl[.gz]` can have a sidecar `<file>.db` (SQLite ≥ 3.45). The
`.jsonl` is the archive/source of truth; the `.db` is a **derived, rebuildable**
cache. Commands (all in jsonl-bundle, registered in `SurvosJsonlBundle`):

| Command | Does |
|---|---|
| `jsonl:index <f> --pk id --facet a,b` | offsets (`pk→offset`), covering facets (`idx.attrs`), authoritative row count; persists `_rows` cache; incremental tail-scan on append |
| `jsonl:profile <f> [--top N] [--json] [--legacy-json]` | per-field stats via `json_tree`/`json_type` → `field_stats`; array element freq via `json_each`; builds `v_rows` VIEW; `--legacy-json` writes `<name>.profile.json` |
| `jsonl:vacuum <f>` | drop `_rows`/`v_rows` cache + `VACUUM`(+WAL checkpoint); keeps `meta`/`idx`/`field_stats` |
| `jsonl:clean <f|dir> [-r] [--dry-run]` | purge obsolete `*.sidecar.json` text sidecars |
| `jsonl:count` / `jsonl:info` / `jsonl:state` | counts / sidecar summary (now shows indexed keys, facets, profiled fields, cache) / full state |

`.db` schema (`SidecarDb`, `user_version=4`): `meta` (k/v state), `idx` (pk, offset,
line, attrs), `field_stats` (path, json_types, present, non_null, distinct_n, min/max,
len_*, top_values, is_array, heuristics, elements), and the persisted `_rows` cache +
`v_rows` view.

Public API to build on:
- `Sqlite\SidecarDb`: `loadMeta`/`saveMeta`, `loadFieldStats`, `facetCounts($field)`,
  `lookupOffset($pk)`, `keyCount`, `facetFields`, `hasIdx`/`hasCache`, `vacuumCache`, `checkpoint`, `connection()`.
- `Sqlite\JsonlIndexer::index($path,$pk,$facets,$persist)` → `IndexResult`.
- `Sqlite\SqlProfiler::profile($path,$topN,$maxDepth,$maxFields,$persist)` → `ProfileResult`.
- `Sqlite\LegacyProfile::full()/mapFields()` — maps `field_stats` → the legacy profile shape.
- `IO\JsonlReader::open($path)` (stream) + `->get($pk)` (offset/gz-seek random read).
- `Service\JsonlStateService` — unchanged public surface; storage is the `.db`.

## Done & verified (per phase, all green on dummy + Smithsonian/Commonwealth data)

1. **Sidecar swap** — state moved JSON → SQLite `meta`; `JsonlSidecar` DTO intact; `SidecarMeta` storage DTO (the ObjectMapper seam).
2. **`jsonl:index`** — offsets, covering `attrs` facets, authoritative count, `get($pk)`, incremental tail-scan.
3. **SQL profiler** — `field_stats` via `json_tree`/`json_type`, bounded (top-N + counts, exact distinct, **no value-list blobs**; the 512 MB OOM is gone — ~4 MB on a pathological 2000-row test).
4. **Arrays** — `json_each` element frequencies in `field_stats.elements` (scalars value-tracked; objects counted-not-tracked).
5. **Consolidation** — old in-PHP `Service\JsonlProfiler`, `JsonlProfilerInterface`, `Model\FieldStats` (push) `@deprecated` since 2.8; deprecation fires only on *use* of the accumulator, not on reads.
6. **Consumer bridge** — `LegacyProfile` proven equivalent to the old profiler; code-bundle `ProfileResolver` rewired onto `SqlProfiler` (drops the deprecated API); `--legacy-json` emitter.
7. **Persist cache + vacuum** — `_rows`/`v_rows` persist by default; `jsonl:vacuum` reclaims (WAL needs `wal_checkpoint(TRUNCATE)`).
8. **Cleanup** — purge (not migrate) legacy sidecars via `jsonl:clean`; `checkpoint()` copy helper; `jsonl:info` enriched; docs updated.

Design records: `doc/adr-0001-sqlite-sidecar.md` (this bundle), `doc/profiler.md`
(resolution), `PLAN.md` (per-phase status). Cross-bundle: `field-bundle/doc/adr-0002`
(shared FieldStat/TableSummary + UI), `field-bundle/doc/adr-0003` (Dexie/client-side).
Live demo + commands: `sleekdb-demo/JSONL-DEMO.md`, `App\Controller\JsonlController`.

## Carry-over / deferred (with pointers)

- **Repo rename** `sleekdb-demo → jsonl-demo` (user to do): rename dir, drop `rakibtg/sleekdb` + `src/Service/SleekService.php`, refresh README around `JSONL-DEMO.md`.
- **Tests infra is broken** — `phpunit.xml.dist` points at `tests` but the dir is `Tests/`, and there's no `autoload-dev` for `Survos\JsonlBundle\Tests\`. Fix both, then convert the Phase 1–7 standalone scripts (sidecar round-trip, offset/get, profiler memory bound, vacuum/WAL, incremental cache, LegacyProfile equivalence) into a real suite. **Highest-value cleanup.**
- **In-PHP profiler deletion** — blocked on migrating its consumers off `JsonlProfilerInterface`: **md app & meili app (out of tree — can't see here), folio `FolioSchemaSnapshotter` (should use its own SQLite/SchemaProperty), code-bundle, import-bundle, past-perfect.** Each migrates to `field_stats`/`SqlProfiler` independently, then delete.
- **`import:convert` wiring** — additively call `SqlProfiler` at convert finish (keep `buildProfile` until verified against the md app); untestable from this repo.
- **`code:entity` new-shape** — optionally read `field_stats` directly instead of via `LegacyProfile` (also where the `json_tree` **quoted-key** de-quote belongs: paths read `attributes."_version_"`).
- **JSONB body** (compact/faster `_rows`), **`jsonl:compact`** (rewrite the `.jsonl` log — distinct from `jsonl:vacuum`), **directory-level catalog** (own future ADR; scans per-file `.db` → feeds dataset-bundle `DatasetInfo`).
- **`*.idx.json`** writer token index still exists (separate from `.db idx`) — consolidate later.

## Next session: dataset/provider integration

Goal: iterate over **all providers** and wire their JSONL into datasets via the sidecar.

Context: providers ≈ the museado data sources (e.g. digital-commonwealth, the
Smithsonian units — we tested `museado/digitalcommonwealth-data` and
`museado/smithsonian-data` from HF: one `.jsonl.gz` per provider). The directory/
registry layer already exists in **dataset-bundle** (`DatasetInfo`/`Provider`
entities, `DatasetPaths`, `ScanDatasetsCommand`, `DatasetMetadataLoader`).

Suggested shape:
1. **A provider-iterating step**: for each provider's `.jsonl[.gz]`, run
   `JsonlIndexer::index()` + `SqlProfiler::profile()` to build its `.db` (offsets,
   facets, `field_stats`). This is the dataset↔jsonl seam — likely a command in
   dataset-bundle (or app) that loops providers and calls the bundle services.
   `dummy:load --fetch-only` (in dummy-bundle) is a working mini-example of
   producing per-collection `.jsonl` tables to index.
2. **Feed the registry**: have `ScanDatasetsCommand`/`DatasetInfo` read each
   provider's sidecar (`SidecarDb::loadMeta()->rows`, `loadFieldStats()`,
   `facetCounts()`) instead of rescanning — the directory catalog ADR formalizes this.
3. **Cross-source display**: when ready, land **field-bundle ADR 0002** (`FieldStat`/
   `TableSummary` contract in field-bundle + providers in jsonl/folio + one UI bundle)
   so the same summary/browser works over `.jsonl.db` and `.folio`. `App\Controller\
   JsonlController` in the demo is the app-level proof-of-concept to generalize.

Watch-outs: per-file `.db` is the unit of truth (don't centralize prematurely —
datasets are "later" per the user); facets come from `idx.attrs` (cheap, work on
`.gz` too); choose `--pk` carefully (Digital Commonwealth has non-unique `id`, 3:1 —
the `rows` vs `keys` gap surfaces it); persisting `_rows` ≈ a data-sized copy (use
`jsonl:vacuum`, or auto-vacuum above a size threshold in the provider loop).

## How to run / verify

- Demo app `sleekdb-demo` (symlinks `vendor/survos/jsonl-bundle` → this bundle, so edits are live): `php bin/console jsonl:* …`; home page at `/` (`symfony serve`).
- Test data: `bin/console dummy:load --fetch-only` → `var/dummy/*.jsonl`; or fetch from the two HF datasets above.
- Standalone verification pattern (used all session): a PHP script requiring
  `bu/jsonl-bundle/vendor/autoload.php`, exercising the `Sqlite\*` classes against a
  temp `.jsonl`. Convert these into the real PHPUnit suite (see carry-over).
