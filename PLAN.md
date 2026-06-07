# PLAN — SQLite sidecar + SQL profiler

Implementation plan for **[ADR 0001](doc/adr-0001-sqlite-sidecar.md)**: replace the plaintext sidecar zoo and the in-PHP profiler with a single per-file SQLite sidecar (`<file>.db`) whose stats are computed with SQLite's JSON functions.

Most of this is **wiring under the hood** — the sidecar is already a DTO behind `JsonlStateService`, and import-bundle already profiles at the end of `import:convert`. We are swapping storage and the profiling engine, not changing the pipeline shape.

## Locked decisions (from ADR 0001)

- **Require SQLite ≥ 3.45.** No feature gating. Assert once in `jsonl:index`.
- **`.jsonl[.gz]` is the only source of truth (append-only); `.db` is a rebuildable derived cache.**
- **`json_each`/`json_tree` are the power tools; `json_type` is the canonical type source.**
- **Arrays are exploded into child tables, never blob-profiled.**
- **`FlockStore` stays** for the append mutex.
- **No backward-compat constraint on the profile shape** — we control `code:entity` and update it in lockstep.
- `.gz`: stats yes, seekable offsets no.

## House style (this bundle's `AGENTS.md`)

`declare(strict_types=1)`; `final` classes; `readonly` value objects; single-command `__invoke` classes with `#[AsCommand('name','desc')]` (positional desc), `SymfonyStyle $io` first, `#[Argument]`/`#[Option]`; `use function` imports for built-ins; no `@` suppression; throw on real failures.

---

## Phase 1 — SQLite sidecar store behind `JsonlStateService` (transparent swap)

**Goal:** `meta` table replaces `.sidecar.json` with zero consumer changes.

- New `src/Sqlite/SidecarDb.php` — opens/creates `<file>.db`, `PRAGMA user_version` migrate-on-open, WAL, raw `PDO` (no DBAL dependency in the bundle).
- `JsonlStateService::loadSidecar()`/`saveSidecar()` → `meta` SELECT/UPSERT; still return/accept `Model/JsonlSidecar`.
- `rows()` reads `meta.rows`; keep `countNewlines()` as the no-sidecar fallback.
- **Acceptance:** existing `JsonlWriter`, `jsonl:info`, `jsonl:count`, `jsonl:state` pass unchanged against `.db`; `JsonlSidecar` DTO untouched.

## Phase 2 — `jsonl:index` command + offset index

**Goal:** `jsonl:index <file.jsonl>` builds `<file>.db` with `idx` (pk→offset) and ephemeral `_rows` staging.

- `src/Command/JsonlIndexCommand.php` (`__invoke`): assert `sqlite_version() >= 3.45`; stream lines → record offset to `idx`, push raw `body` to `_rows` (one transaction); under `FlockStore`.
- `#[Option] --pk` (field name | list | callable-key) → `idx.pk`; reuse the existing token de-dup as the degenerate `pk→offset` case.
- `#[Option] --facet` (field list) → covering `idx.attrs` (`json_object` of low-cardinality fields) + one expression index per field (`idx(attrs ->> '$.field')`). Built from `_rows` in SQL before staging is dropped. Default set can come from `field_stats.distinct_n` / DTO `#[Field(facet)]`. (Named `--facet` rather than `--index` to avoid confusion with the command name.)
- `JsonlReader::get(string $pk)`: `SELECT offset` → `fseek` + `fgets` + decode (plain `.jsonl` only; `.gz` falls back to scan).
- Write the **authoritative `rows` count** into `meta` (it reads every line anyway). This is the home for counting externally-produced files and resolves the Phase 1 footgun where `ensure()` plants `rows:0` and shadows `countNewlines()` — `--ensure` stays facts-only by design; `jsonl:index` is what establishes the true count.
- **Acceptance:** point lookup by pk on a plain `.jsonl`; `idx` rebuilds from a tail-scan when `meta.jsonl_size < actual size`; facet count (`GROUP BY attrs->>'$.field'`) and filter-then-fetch run with no data scan, and facet counts work on `.gz`; `meta.rows` matches `countNewlines` for an external file after `jsonl:index`.

## Phase 3 — SQL profiler → `field_stats` (the core win)

**Goal:** replace the PHP accumulator with SQL over `_rows`.

- Field discovery via `json_each` (present / non_null / `json_type`).
- Stats via `json_tree` (every scalar leaf): `COUNT`, `COUNT(DISTINCT)`, `MIN`/`MAX`, string-length aggregates, bounded `top_values` (top-N), `json_types` histogram. Collapse `fullkey` array indices `[n]` → `[]`.
- Heuristics (`urlLike`, `imageLike`, `naturalLanguageLike`, `localeGuess`) run on a **bounded sample**, not all rows.
- Write `field_stats`; provide `JsonlProfile` hydration from `field_stats` (and an optional `.profile.json` projection).
- **Acceptance:** the dataset that OOMed in `doc/profiler.md` profiles in bounded memory; `field_stats` has exact `distinct_n` and no value-list blobs.
- **Status: DONE.** `SqlProfiler` + `jsonl:profile`; staging extracted to shared `RowStager` (Phase 2 reuses it). Verified: 23/23 (incl. 2000-row unique-array/blob file → distinct as a number, ~4 MB peak), plus real dummy + Commonwealth data. Object containers descended (`attributes.*`, `dimensions.*`); array interiors deferred to Phase 4 (`is_array=1`); heuristics from the bounded top-N sample.
  - Deferred to later phases (not Phase 3): `JsonlProfile` hydration + `.profile.json` projection + `code:entity` co-evolution (Phase 6); retiring the in-PHP `Service/JsonlProfiler` (Phase 5).
  - Cosmetic follow-up: `json_tree` quotes non-bareword keys in `fullkey`, so paths like `attributes."_version_"` keep the quotes (kept as-is to guarantee uniqueness for the `path` PRIMARY KEY). De-quote at display/consume time in Phase 6 / the FieldStat contract, not at write time (naive stripping risks `a."b.c"` vs `a.b.c` collisions).

## Phase 4 — Arrays → child tables

**Goal:** array fields profiled as element frequencies.

- Detect `is_array` from the `json_type` histogram; for each, `CREATE TABLE _rows_<field> AS SELECT … FROM _rows, json_each(body,'$.field')`.
- Profile the child `value` column; fold element stats into the parent `field_stats` row (`is_array=1`, element distinct/top-N).
- **Acceptance:** an array field (e.g. `tags`) yields a tag→count frequency table; no blob distinct-tracking anywhere.
- **Status: DONE.** `SqlProfiler::arrayElements()` explodes each `is_array` field with `json_each`, folding `{count, distinct, avgPerRow, top:[{value,count}]}` into a new `field_stats.elements` JSON column (schema v4). Scalar elements get value-tracked (bounded top-N); object/array elements are counted but not value-tracked (no blob lists — use `je.type`, not `json_type(value)`, which errors on bare scalars). Verified 21/21 incl. high-cardinality (5000 unique ints → distinct as a number, ~4 MB) and real data (products `tags` 138-distinct / `images` ×2.44 / `reviews` objects counted-not-tracked; Commonwealth `genre_basic_ssim` Cards 7878 / Photographs 609).

## Phase 5 — Consolidate the two profilers

**Goal:** one stats engine; retire the duplicate.

- Remove the in-PHP `GROUP BY`/distinct path from `Service/JsonlProfiler`; it becomes the SQL driver + sampled heuristics.
- `Model/FieldStats` becomes a pure read model over `field_stats` (drop its own cap/distinct logic and the duplicated `normalizeDistinctKey`).
- **Acceptance:** `doc/profiler.md`'s "two parallel profilers" open item closed; one accumulator remains.
- **Status: DONE (as deprecation; hard deletion deferred — see below).** `SqlProfiler` is the canonical engine; `Service/JsonlProfiler`, `JsonlProfilerInterface`, and `Model/FieldStats` (push path) are `@deprecated` since 2.8 with `trigger_deprecation` firing **only on accumulator use** (`profile()`/`push()`), not on the read path (`fromArray()`). `doc/profiler.md` "two parallel profilers" item resolved.
  - **Why not deleted:** the in-PHP profiler has wide, partly out-of-tree reach — the **md app (the big one)** and **meili app** (not in `mono/bu`, can't see/refactor here), plus **folio `FolioSchemaSnapshotter`** (should move off the profiler entirely — it has its own SQLite/`SchemaProperty` path), **code-bundle `CodeEntityCommand`**, **import-bundle `ImportConvertCommand`**, **past-perfect `HarvestDetailCommand`**. Deleting now (or changing the legacy output shape) would break the big consumer blind. Deletion is a coordinated follow-up after Phase 6 migrates these to `field_stats`/`SqlProfiler`.

## Phase 6 — Wire into import-bundle + co-evolve consumers

**Goal:** end-of-import profiling uses the new engine; downstream reads the new shape.

- `import:convert` finish → call `jsonl:index` instead of the PHP profile build (`ApplyProfileTransformsListener` / `buildProfile`).
- Update code-bundle `code:entity` / `JsonlProfileLoader` / `JsonlProfile` to read `json_types` + `distinct_n` + `len_max` + `heuristics` (replacing `storageHint`/`types`/`stringLengths.max`). Audit `load:entities` for profile use.
- **Acceptance:** `import:convert` → `code:entity` produces an entity from a SQLite-derived profile.
- **Status: core DONE (additive, non-breaking); import wiring + new-shape rewrite deferred.**
  - `Sqlite/LegacyProfile` maps `field_stats` → the legacy profile shape consumers read — **verified equivalent to the old `Service/JsonlProfiler`** on real data (storageHint/types/total/distinct match across all 20 top-level dummy fields; strict `inferStorageHint` parity incl. the mixed int+float→string quirk; heuristics + `arrayStats` carried; complete `distinctValues` only when distinct ≤ top-N).
  - code-bundle `ProfileResolver::profileJsonl()` rewired off the deprecated `JsonlProfilerInterface` → `SqlProfiler` + `LegacyProfile` (so `code:entity <file>.jsonl` is SQL-backed; same return shape).
  - `jsonl:profile --legacy-json` emits `<name>.profile.json` from `field_stats`, so `code:entity <name>.profile.json` consumes a SQLite-derived profile unchanged.
  - **Deferred (untestable from this repo / needs the consumers present):** wiring `import:convert` finish to `SqlProfiler` (additive — keep `buildProfile` until verified against the md app); rewriting `code:entity`/`JsonlProfileLoader` to read the NEW `field_stats` shape directly (the legacy adapter makes this optional, not urgent); folio `FolioSchemaSnapshotter` → `SqlProfiler`; `load:entities` audit. These unblock the eventual deletion of the in-PHP profiler (Phase 5).

## Phase 7 — Persist-by-default data cache + `jsonl:vacuum` + VIEWs

**Goal:** the `_rows` cache the profiler already built becomes a standalone browse/query DB; reclaim is explicit.

- Keep `_rows` + child tables by default (persist the body as JSONB); `--persist`/policy flag is a no-op default-on. Build friendly VIEWs via the `FolioViewBuilder` pattern (`json_extract(body,'$.field') AS "field"`) + expression indexes on hot paths.
- `jsonl:vacuum`: drop `_rows` + children + SQLite `VACUUM` (cache reclaim) — keeps `meta`/`idx`+`attrs`/`field_stats`. Distinct from `jsonl:compact` (rewrites the `.jsonl` log). Import pipeline may auto-vacuum above a size threshold.
- **Acceptance:** `SELECT … WHERE … ORDER BY` over a freshly indexed file with no extra pass; after `jsonl:vacuum`, facets/counts/offset-fetch still work and the `.db` shrinks; re-running `jsonl:index` repopulates `_rows` from the `.jsonl`.
- **Status: DONE.** `JsonlIndexer`/`SqlProfiler` take `bool $persist = true`; `jsonl:index` and `jsonl:profile` keep `_rows` by default. `SqlProfiler` builds the friendly `v_rows` VIEW (`json_extract(body,'$.field') AS "<path>"`, scalar fields). `jsonl:vacuum` (`SidecarDb::vacuumCache()`) drops `_rows`/`v_rows`/`_rows_*` and `VACUUM`s, keeping `meta`/`idx`+`attrs`/`field_stats`. Verified 19/19 + live (browse VIEW query; vacuum 462 KB→73 KB; facets/count/get retained).
  - **Two fixes found by testing:** (1) persisting `_rows` conflicts with Phase 2's incremental staging (which staged only the tail) — incremental now requires `hasCache()` and **appends onto the existing `_rows`** (whole-file materialization), so `rows = COUNT(_rows)`; after a vacuum the next index goes full and repopulates. (2) In WAL mode `VACUUM` alone doesn't shrink the main file — `vacuumCache()` follows it with `PRAGMA wal_checkpoint(TRUNCATE)` (verified: 1 MB→4 KB).
  - Deferred (matches ADR §4a notes): body kept as TEXT (JSONB optimization later); `jsonl:compact` (log rewrite — a separate concern from this `.db` cache vacuum); import auto-vacuum-above-threshold policy lives with the caller.

## Phase 8 — Cleanup, purge, docs, tests

- ~~One-time migrate~~ → **purge instead (decided):** no migration; `JsonlStateService` no longer reads legacy `.sidecar.json`, and `jsonl:clean` deletes obsolete `*.sidecar.json`(`.tmp`).
- WAL copy discipline: `wal_checkpoint(TRUNCATE)` helper before move/copy.
- `jsonl:info` dumps `meta` + `field_stats` as JSON (preserve human-readability).
- Tests: profiler memory bound on array/manifest fields (none exists today); offset round-trip; tail-scan rebuild; sidecar swap parity.
- Update `doc/profiler.md` (resolved) and `doc/advanced.md` (sidecar is now SQLite).
- **Status: DONE (purge variant); tests deferred.**
  - Purge: removed the `.sidecar.json` read fallback from `JsonlStateService`; added **`jsonl:clean`** (`--dry-run`, `--recursive`) to delete obsolete text sidecars. (Writer token index `*.idx.json` left as-is — separate future consolidation.)
  - `SidecarDb::checkpoint()` (`wal_checkpoint(TRUNCATE)`) for safe `.db` copy/move.
  - `jsonl:info` now reports indexed keys, facet fields, profiled-field count, and data-cache presence.
  - `doc/advanced.md` + `doc/profiler.md` updated for the SQLite sidecar.
  - **Deferred — tests:** the bundle's PHPUnit config is mis-pointed (`<directory>tests</directory>` vs the actual `Tests/`, and no `autoload-dev` for `Survos\JsonlBundle\Tests\`), so the existing suite doesn't run. Converting the Phase 1–7 standalone verification scripts into a real suite (sidecar round-trip, offset/get, profiler memory bound, vacuum/WAL, incremental cache) is the main carry-over — see HANDOFF.

---

## Sequencing notes

- Phases 1–4 are the substance; 5–8 are consolidation and reach.
- Phase 1 ships value alone (one fewer file, atomic state) and de-risks the storage swap before the profiler rewrite.
- The directory-level catalog (scan per-file `.db` → feed dataset-bundle `DatasetInfo`) is **out of scope here** — separate ADR; it consumes this one's per-file `.db`.
- The shared **`FieldStat`/`TableSummary` contract + summary UI** (one renderer + faceted data browser over both `.jsonl.db` and `.folio`, via a `SummaryProviderInterface`) is **out of scope here** — cross-bundle, specced in **[field-bundle ADR 0002](../field-bundle/doc/adr-0002-field-stats-contract-and-summary-ui.md)**: contract in field-bundle (`FieldStat` beside `FieldDescriptor`), providers in jsonl + folio, UI in a new neutral ui/display bundle. This bundle ships `JsonlSummaryProvider` (reads `field_stats` + `idx`/`attrs` + `meta`). The browser pages via the offset index and facets via `idx.attrs` (§3a).
