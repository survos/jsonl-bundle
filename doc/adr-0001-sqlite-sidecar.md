# ADR 0001 — SQLite sidecar: unify state, offset index, and profiling

- **Status:** Proposed
- **Date:** 2026-06-07
- **Supersedes:** the plaintext sidecar zoo (`.sidecar.json`, `.idx.json`, `.profile.json`) and the in-PHP `Service/JsonlProfiler` accumulator.
- **Related:** `doc/profiler.md`, `doc/advanced.md`, `AGENTS.md`; folio-bundle (`FolioViewBuilder`, `FolioSummaryService`, `SchemaProperty.stats`); code-bundle (`code:entity`, `JsonlProfileLoader`, `ProfileResolver`, `JsonlProfile`); import-bundle (auto-profile at end of `import:convert`).

## Context

JSONL files are first-class, **append-only** artifacts. Today each file accretes a set of plaintext side artifacts:

- `<file>.sidecar.json` — state DTO (`rows`, `bytes`, `completed`, timestamps, `context`)
- `<file>.idx.json` — token de-dup index
- `<file>.profile.json` — profiler output
- `sf.*.lock` — Symfony flock file

Maintaining several files plus a hand-rolled JSON sidecar format (note the snake/camel fallbacks in `Model/JsonlSidecar::fromArray()`) is ongoing friction.

The profiler is the sharper problem. `Service/JsonlProfiler` reimplements `GROUP BY`/`DISTINCT` in PHP arrays and **OOMs** on array/blob fields (see `doc/profiler.md`: 512 MB exhausted accumulating distinct blobs). There are also **two parallel profilers** — `Service/JsonlProfiler` (distinct cap 100 000) and `Model/FieldStats` (cap 500) — doing overlapping work.

The bundle also lacks random access (no `pk → offset`), update, and any query layer.

Two facts make a better design available now that did not exist when this code was first written:

1. **Baseline is PHP 8.4 / Symfony 8.1**, so requiring **SQLite ≥ 3.45** is reasonable. That gives json1, `->`/`->>`, `json_each`/`json_tree`, generated columns, and JSONB. Verified locally: **3.45.1 / PHP 8.5.6**, all functions present.
2. **folio-bundle already proves the pattern** — load JSON into SQLite, then compute stats and projections with SQL: `FolioViewBuilder` does `CREATE VIEW … SELECT json_extract(dto_data,'$.field') AS "field" …`; `FolioArchiveService` builds expression indexes on JSON paths; `SchemaProperty.stats` is literally commented *"Profiler stats from jsonl-bundle."*

## Decision

Adopt a single per-file SQLite sidecar **`<file>.db`** as the derived index/metadata store, and move profiling from a PHP accumulator to **SQL over SQLite's JSON functions**.

**Load-bearing invariant:** the `.jsonl[.gz]` log is the **sole source of truth** and is append-only. `<file>.db` is a **derived cache, always rebuildable** by re-scanning the log, and is never authoritative. (This is the Bitcask model: append-only log + derivable index.)

### 1. Require SQLite ≥ 3.45 — no feature gating

`->>`, `json_each`/`json_tree`, generated columns, and JSONB are all assumed present. `jsonl:index` asserts the version once and errors clearly if unmet.

### 2. `<file>.db` schema

```sql
PRAGMA user_version = 1;   -- sidecar schema version; migrate-on-open

-- replaces .sidecar.json (JsonlStateService persists here)
CREATE TABLE meta (
  key   TEXT PRIMARY KEY,
  value             -- rows, bytes, completed, started_at, updated_at,
);                  -- jsonl_size, jsonl_mtime, context(json), profiler_version

-- pk -> byte offset (replaces/generalises .idx.json dedup)
-- COVERING INDEX: attrs inlines a bounded set of low-cardinality filter/facet
-- fields so filtering, counts, and facet counts survive after _rows is dropped
-- (no full mirror needed). attrs is JSON: {"marking":"new","year":1880}.
CREATE TABLE idx (
  pk     TEXT PRIMARY KEY,
  offset INTEGER NOT NULL,   -- byte offset into plain .jsonl (see §8 for .gz)
  line   INTEGER,
  attrs  TEXT                -- json_object of the chosen indexed fields
);
-- one expression index per indexed field -> true no-scan lookups/counts
-- CREATE INDEX ix_idx_marking ON idx(attrs ->> '$.marking');

-- one row per observed (possibly nested) field path
CREATE TABLE field_stats (
  path        TEXT PRIMARY KEY,   -- dotted, array indices collapsed: tags[], dim.w
  json_types  TEXT,               -- json_type histogram, e.g. {"text":1900,"null":100}
  present     INTEGER,
  non_null    INTEGER,
  distinct_n  INTEGER,            -- exact COUNT(DISTINCT), a number — never a value list
  min_v, max_v,
  len_min, len_max, len_avg,      -- string lengths
  top_values  TEXT,               -- bounded top-N [{value,count}], by construction
  is_array    INTEGER,            -- field is (predominantly) an array -> child table
  heuristics  TEXT                -- {urlLike,imageLike,naturalLanguageLike,localeGuess} (sampled)
);
```

**Data cache, persisted by default** (see §4a): `_rows(line_no, offset, body)` and, per array field, `_rows_<field>(parent, idx, value)`. Already populated to compute stats; kept so the file is browseable. `jsonl:vacuum` drops them.

### 3. `jsonl:index <file.jsonl>` pipeline

A single-command `__invoke` class (per this bundle's `AGENTS.md`).

1. **Stream lines, push raw text — do not decode in PHP.** Per line: record byte offset → `idx`; `INSERT INTO _rows(line_no, offset, body)` with the raw JSON string (`jsonb(?)` permitted since 3.45). SQLite parses; PHP does not. This removes the accumulation that OOMs today. Wrap inserts in one transaction.
2. **Field discovery is a query, not PHP bookkeeping:**
   ```sql
   SELECT key,
          COUNT(*) AS present,
          COUNT(*) FILTER (WHERE value IS NOT NULL) AS non_null,
          json_type(value) AS jtype
   FROM _rows, json_each(_rows.body)
   GROUP BY key;
   ```
3. **Stats at the end, in SQL.** Default is recursive/schema-agnostic via `json_tree` (every scalar leaf, including nested, in one pass):
   ```sql
   SELECT fullkey, COUNT(*), COUNT(DISTINCT atom), MIN(atom), MAX(atom)
   FROM _rows, json_tree(_rows.body)
   WHERE type NOT IN ('object','array')
   GROUP BY fullkey;
   ```
   `json_type` (not SQLite affinity) is the canonical type source — this fixes the long-standing typing weakness.
4. **Project to friendly names** for the optional VIEW exactly as `FolioViewBuilder` does: `SELECT json_extract(body,'$.field') AS "field"`, reusing its `identifier()`/`literal()` quoting.

### 3a. Covering attrs on `idx` — facets/filters without the bodies

`idx` is **persistent**; `_rows` is ephemeral. To keep filter/count/facet capability after staging is dropped (short of a full `--mirror`), `idx.attrs` inlines a **bounded, deliberately chosen** set of **low-cardinality** fields, each backed by an expression index:

- **Field set is not arbitrary** — it is the facet/filter set the profiler already identifies (`field_stats.distinct_n`) and/or the DTO's `#[Field(facet/filterable/sortable)]`. Profile → pick facet candidates → re-index with `--index marking,year`. Never inline high-cardinality or large fields (defeats "stays resident").
- **Facet counts from the sidecar:** `SELECT attrs->>'$.marking', COUNT(*) FROM idx GROUP BY 1` — no data scan, no mirror.
- **Filter-then-fetch:** `WHERE attrs->>'$.marking'='new'` returns pk **and** offset in one row (covering) → seek to only those offsets. Indexed random access without keeping bodies.
- **`.gz`:** filtering and facet counts still work (attrs lives in `.db`); only fetch-by-offset is unavailable (§8).
- Built in SQL from `_rows` (`json_object(...)`), recomputed on update/rebuild — still derived, never authoritative.

This is the lightweight tier that survives `jsonl:vacuum` (§4a). Rejected alternative: a separate `facets(pk,field,value)` EAV table gives all-facets-in-one-query but loses the covering property (join back for offsets); `attrs`-on-`idx` wins for the fetch-pks-and-offsets goal.

### 4. Arrays → child tables via `json_each`

"Largeness almost always comes from an array." So any field whose `json_type` is `array` is **exploded, not blob-profiled**:

```sql
CREATE TABLE _rows_tags AS
SELECT r.line_no AS parent, je.key AS idx, je.value AS value
FROM _rows r, json_each(r.body, '$.tags') je;
```

Then profile `_rows_tags.value` like any scalar → **element frequency** (the useful answer), never blob distinctness. This structurally eliminates the OOM cause: arrays become rows you `GROUP BY`.

### 4a. Data cache lifecycle — persist by default, `jsonl:vacuum` to reclaim

`_rows` is already built to compute stats, so dropping it discards paid work. Keep it by default; it makes the file **browseable** with no extra pass. The `.db` (including `_rows`) is fully **rebuildable from the `.jsonl` via `jsonl:index`**, so keeping or dropping is a pure cache decision, never data loss — the archive is always the `.jsonl[.gz]`.

Three tiers along one continuum:

- **Full** (default / `--persist`): `meta` + `idx`+`attrs` + `field_stats` + `_rows` + array children → arbitrary-field SQL browse/query (the standalone browser).
- **Lean** (after `jsonl:vacuum`): drop `_rows` + children + `VACUUM`; keep `meta` + `idx`+`attrs` + `field_stats` → facets/counts/filter on indexed fields + offset fetch.
- **Archive**: just `.jsonl[.gz]` (+ rebuildable `.db`).

Notes:
- Persisting ≈ a dataset-sized copy in SQLite. Default-keep suits dev/local browsing; the import pipeline may **auto-`vacuum` above a size threshold** — policy with the caller, mechanism is just "don't delete what's there." Persist the body as **JSONB** (compact, decode-once).
- **Two distinct "vacuums" — different targets, different verbs:** `jsonl:compact` rewrites the **`.jsonl`** dropping superseded/tombstoned rows (log compaction); `jsonl:vacuum` drops the **`.db`** data cache + SQLite `VACUUM` (cache reclaim). Do not conflate.

### 5. Transparent `JsonlStateService` swap

`Model/JsonlSidecar` stays a DTO. `JsonlStateService::loadSidecar()`/`saveSidecar()` swap from `.sidecar.json` read/write to a `meta`-table SELECT/UPSERT, returning the **same** object. `JsonlWriter` and every command keep calling the service unchanged — the service already abstracts storage (its docblock anticipates *"Future stores can persist … in dataset-bundle, Redis, or elsewhere"*). Consumers never know.

### 6. Keep `FlockStore` for the append mutex

SQLite locking protects `.db`, not `.jsonl`; concurrent appends to the log still need a mutex. `FlockStore` gives OS-level, crash-released, non-expiring locks — correct for long ETL appends, unlike a TTL-based `PdoStore`. Of the old side artifacts, the lock file is the one with a real reason to stay; hide it under a subdir if it's visually noisy, but don't trade away the semantics.

### 7. Richer profile, co-evolving consumers (no frozen JSON contract)

We control `code:entity` (and any `load:entities`), so the profile shape is **free to improve**. The new `field_stats` rows are the canonical profile; emitting a `.profile.json` becomes a thin projection of those rows. `code-bundle`'s `JsonlProfile` / `JsonlProfileLoader` and `code:entity`'s type derivation are updated in lockstep to read the new shape (`json_types` histogram + `distinct_n` + `len_max` + `heuristics`), replacing the old `storageHint`/`types`/`stringLengths.max` triple. Keeping a compatible `.profile.json` projection is optional, done only if cheap.

### 8. `.gz` is append/stream-only (accepted)

Byte offsets are not seekable inside gzip. `jsonl:index` still builds full stats for `.jsonl.gz` by streaming-decompress → staging → SQL, but `idx` offsets aren't usable for random `get($pk)`; point lookups on `.gz` fall back to scan/rebuild. Accepted tradeoff.

## Consequences

**Good**
- One sidecar instead of three + a format to hand-maintain.
- Profiling is bounded by construction (top-N + exact `distinct_n`, never value lists) — the OOM class is gone.
- Atomicity: append-then-metadata becomes a single SQLite transaction; the partial-failure window closes.
- The "index must fit in RAM" worry disappears — `SELECT offset FROM idx WHERE pk=?` is resident-free.
- The two profilers collapse into one engine (SQL) + one sampled heuristics pass; `FieldStats` becomes a read model.
- Persisting `_rows` by default (§4a) yields SQL query/browse over the file for free — it's the cache the profiler already built — and answers the original "can we get Sleek-style queries" question; `jsonl:vacuum` reclaims it, the `.jsonl` stays the archive.

**Bad / costs**
- `.db` is opaque vs a greppable JSON sidecar. Mitigation: `jsonl:info` dumps `meta`/`field_stats` as JSON for humans.
- WAL mode spawns `-wal`/`-shm` siblings; copy/move requires `PRAGMA wal_checkpoint(TRUNCATE)` first.
- `.db` carries its own schema (`user_version` + migrate-on-open) — replaces JSON format-versioning, roughly a wash.
- `json_tree.fullkey` includes array indices (`$.a[0].b`); collapse `[n]` → `[]` for per-field aggregation.

**Risks**
- Hard SQLite ≥ 3.45 requirement excludes ancient deploys — acceptable given the PHP 8.4 / Symfony 8.1 baseline.

## Alternatives considered

- **Keep plaintext sidecars, patch the profiler.** Rejected: doesn't fix the memory model, leaves the multi-file/format burden and two profilers.
- **One `.db` per directory (dataset).** Rejected as the unit of truth — couples tables, breaks "a table is a self-contained, movable artifact." A directory catalog stays a *derived aggregate* of per-file `.db`s, feeding dataset-bundle's `DatasetInfo` registry.
- **Wide generated-column table vs EAV `json_tree`.** Both kept: `json_tree` (EAV) is the schema-agnostic default; generated columns are used when a DTO shape is known and a queryable VIEW is wanted.
- **`PdoStore` on the same `.db` for the append lock.** Rejected for long appends (TTL expiry risk); `FlockStore` retained.

## References

- Bitcask (log-structured store: append-only log + in-memory/derived key→offset index).
- SQLite JSON1, `json_each`/`json_tree`, generated columns, JSONB (≥ 3.45).
- folio-bundle: `Service/FolioViewBuilder`, `Service/FolioSummaryService`, `Entity/SchemaProperty`.
