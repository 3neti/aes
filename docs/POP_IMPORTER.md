# POP Precinct Workbook Importer

## Purpose

The POP importer loads the COMELEC 2025 NLE clustered precinct workbook into the precinct appliance as local evidence and deterministic native registry files.

The source workbook is a precinct and polling-place registry. It does not contain contests, candidates, ballot styles, legal ballot definitions, or vote rules. Imported POP package skeletons are therefore not final election packages; they are location and precinct identity artifacts that can later be paired with proper election registries.

## Source Workbook

Current source file:

```text
resources/election/pop/2025NLE_POP.xlsx
```

The scenario default is configurable through `config/election.php`:

```text
election.pop.source_path
election.pop.profile
election.pop.clustered_precinct
```

The current repository default clustered precinct is `39010001` in Tondo, NCR - Manila. It is used because the available POP workbook and CLC PDFs can be combined into a local Manila candidate preview.

Workbook shape:

- Sheet: `FINAL_Clustered.POP_NLE_2025`
- Data rows: `93629`
- Unique clustered precinct ids: `93629`
- Total registered voters: `69773653`

Required columns:

| Column | Meaning |
| --- | --- |
| `REGION` | Region name. |
| `PROVINCE` | Province name. |
| `CITY_MUNICIPALITY` | City or municipality name. |
| `BARANGAY` | Barangay name. |
| `CLUSTERED_PRECINCT` | Unique clustered precinct identifier. |
| `PRECINCT_CLUSTER` | Comma-separated component precinct cluster values. |
| `CLUSTERTOTAL` | Registered voters in the clustered precinct. |
| `POLLING_PLACE` | Polling place name. |

The default mapping profile rejects a workbook whose first-row headers do not exactly match this shape.

## Mapping Profiles And Source Adapters

The importer is split into three responsibilities:

- `PopSourceAdapter` extracts rows from a physical source file.
- `PopMappingProfile` maps source headers into canonical POP registry fields.
- `PopWorkbookImporter` writes the deterministic registry files and manifest.

Current adapter:

| Adapter | Source type | Status |
| --- | --- | --- |
| `XlsxPopSourceAdapter` | `.xlsx` workbook | Active. |

Current mapping profiles:

| Profile | Purpose |
| --- | --- |
| `comelec-pop-2025-nle` | Default strict 2025 NLE POP workbook profile. Requires exact headers and ordering. |
| `comelec-pop-2025-nle-alt` | Strict alternate 2025 NLE profile for representative renamed headers. Requires exact headers and ordering. |
| `comelec-pop-renamed-reordered-demo` | Test/demo profile showing renamed and reordered source headers. Requires explicit `--profile`. |

If COMELEC changes the Excel shape, add a new explicit mapping profile and tests for the new headers. Do not silently change the default profile, because deterministic re-import of the known 2025 workbook is part of the appliance evidence chain.

If the source arrives as PDFs, add a new source adapter that extracts table rows and returns `PopSourceData`. The registry writer and canonical mapping contract should remain unchanged. PDF extraction should stay explicit and separately tested because PDF tables are layout artifacts, not structured registries.

Developer maintenance instructions are in `docs/POP_IMPORTER_DEVELOPER_MANUAL.md`.

## Import Strategy

The importer uses PHP `ZipArchive` and `XMLReader` to read the `.xlsx` file directly. It does not require PhpSpreadsheet, Laravel Excel, or a database.

The appliance keeps two forms of evidence:

- Original XLSX copy, preserved as source evidence.
- Normalized local files, used by the device for deterministic lookup and package-skeleton creation.

The importer writes journal events:

- `pop.imported` when import succeeds.
- `pop.import_failed` when validation or parsing fails.
- `pop.package_created` when an imported precinct package skeleton is written.

## Directories And Files

After import, source files and registries are written under source-data:

```text
storage/app/election/
  source-data/
    pop/
      imports/2025NLE_POP.xlsx
      registries/pop-2025-nle/
        manifest.json
        precincts.jsonl
        clustered-precinct-index.json
        location-summary.json
    imported-packages/{clustered_precinct}.json
  runs/{run_id}/
    00-start-here/*-report.json
    01-precinct-preparation/
```

Scenario reports are written inside the active run folder:

```text
storage/app/election/runs/20260508-080000-39010001-pop-import-demo/
  00-start-here/2026-05-08-080000-39010001-pop-import-demo-...-report.json
  run-summary.json
  artifact-index.json
```

Important runtime reset note: `storage/app/election` is reset by scenarios and tests. Each scenario starts with a clean run-first storage tree.

## Normalized Precinct Record

Each row in `precincts.jsonl` is one JSON object on one line:

```json
{
  "schema_version": "pop-precinct-row-1",
  "region": "NCR",
  "province": "NCR - MANILA",
  "city_municipality": "TONDO",
  "barangay": "BARANGAY 1",
  "clustered_precinct": "39010001",
  "precinct_cluster": "0001A, 0001B, 0002A, 0002B, 0003A",
  "cluster_total": 947,
  "polling_place": "ISABELO DELOS REYES ELEMENTARY SCHOOL",
  "source_row": 8284,
  "row_hash": "..."
}
```

Field notes:

- `source_row` is the original Excel row number.
- `row_hash` is the canonical hash of the normalized row before `row_hash` is added.
- `cluster_total` is stored as an integer.
- Text values are trimmed during import.

## Manifest

`manifest.json` records the import and the generated registry:

```json
{
  "schema_version": "pop-registry-manifest-1",
  "importer_version": "pop-workbook-importer-1",
  "registry_version": "pop-2025-nle",
  "sheet_name": "FINAL_Clustered.POP_NLE_2025",
  "headers": ["REGION", "PROVINCE", "CITY_MUNICIPALITY", "BARANGAY", "CLUSTERED_PRECINCT", "PRECINCT_CLUSTER", "CLUSTERTOTAL", "POLLING_PLACE"],
  "source_type": "xlsx",
  "source_label": "FINAL_Clustered.POP_NLE_2025",
  "source_headers": ["REGION", "PROVINCE", "CITY_MUNICIPALITY", "BARANGAY", "CLUSTERED_PRECINCT", "PRECINCT_CLUSTER", "CLUSTERTOTAL", "POLLING_PLACE"],
  "mapping_profile": "comelec-pop-2025-nle",
  "canonical_fields": ["region", "province", "city_municipality", "barangay", "clustered_precinct", "precinct_cluster", "cluster_total", "polling_place"],
  "source": {
    "original_path": "resources/election/pop/2025NLE_POP.xlsx",
    "copied_path": "storage/app/election/source-data/pop/imports/2025NLE_POP.xlsx",
    "filename": "2025NLE_POP.xlsx",
    "bytes": 5333574,
    "sha256": "..."
  },
  "row_count": 93629,
  "unique_clustered_precinct_count": 93629,
  "total_registered_voters": 69773653,
  "registry_hash": "eb102e2c5b4497f676bfbbb4c5d381cd9d2bbd91c037a69cc8f894080292d0e1",
  "precincts_path": ".../precincts.jsonl",
  "index_path": ".../clustered-precinct-index.json",
  "location_summary_path": ".../location-summary.json",
  "manifest_hash": "..."
}
```

`registry_hash` is computed from the emitted JSONL contents. Re-importing the same workbook should produce the same registry hash.

## Index And Lookup

`clustered-precinct-index.json` maps each `CLUSTERED_PRECINCT` to a byte position in `precincts.jsonl`:

```json
{
  "39010001": {
    "offset": 3210489,
    "bytes": 392,
    "row_hash": "..."
  }
}
```

`PopPrecinctRegistry::find()` consumes this index by:

1. Reading `manifest.json`.
2. Loading `clustered-precinct-index.json`.
3. Seeking to the JSONL byte offset.
4. Reading and decoding one line from `precincts.jsonl`.

This avoids scanning all `93629` rows for each lookup.

## Imported Package Skeleton

`php artisan election:pop-activate 39010001` writes:

```text
storage/app/election/source-data/imported-packages/39010001.json
```

Package shape:

```json
{
  "schema_version": "imported-pop-package-1",
  "election_id": "2025NLE-POP",
  "precinct_id": "39010001",
  "ballot_style_id": "unassigned",
  "registry_version": "pop-2025-nle",
  "transport": "pop-workbook-import",
  "signature": "UNSIGNED-POP-IMPORT-SIMULATION",
  "location": {
    "region": "NCR",
    "province": "NCR - MANILA",
    "city_municipality": "TONDO",
    "barangay": "BARANGAY 1",
    "polling_place": "ISABELO DELOS REYES ELEMENTARY SCHOOL"
  },
  "precinct_cluster": "0001A, 0001B, 0002A, 0002B, 0003A",
  "cluster_total": 947,
  "source": {
    "row": 8284,
    "row_hash": "...",
    "registry_hash": "...",
    "source_workbook_hash": "..."
  },
  "package_hash": "...",
  "artifact_path": "..."
}
```

`ballot_style_id` is intentionally `unassigned` because the POP workbook does not include ballot styles.

## Console Commands

Import the workbook:

```bash
php artisan election:pop-import resources/election/pop/2025NLE_POP.xlsx
```

Custom path import:

```bash
php artisan election:pop-import /path/to/2025NLE_POP.xlsx
```

Import with the strict alternate mapping profile:

```bash
php artisan election:pop-import /path/to/alternate-pop.xlsx --profile=comelec-pop-2025-nle-alt
```

Verified output:

```text
POP workbook imported.
Mapping profile: comelec-pop-2025-nle
Rows: 93629
Unique clustered precincts: 93629
Total registered voters: 69773653
Registry hash: eb102e2c5b4497f676bfbbb4c5d381cd9d2bbd91c037a69cc8f894080292d0e1
Manifest: /Users/rli/PhpstormProjects/aes/storage/app/election/source-data/pop/registries/pop-2025-nle/manifest.json
```

Look up a clustered precinct:

```bash
php artisan election:pop-lookup 39010001
```

Verified output:

```text
Clustered precinct 39010001
Region: NCR
Province: NCR - MANILA
City/Municipality: TONDO
Barangay: BARANGAY 1
Precinct cluster: 0001A, 0001B, 0002A, 0002B, 0003A
Cluster total: 947
Polling place: ISABELO DELOS REYES ELEMENTARY SCHOOL
```

Create an imported package skeleton:

```bash
php artisan election:pop-activate 39010001
```

Verified output:

```text
Imported POP precinct package 39010001 written.
Package hash: a54555376f7cd1819223f4f4052ceeee6555d102b2e732c3a28e887119b8be8b
Artifact: /Users/rli/PhpstormProjects/aes/storage/app/election/source-data/imported-packages/39010001.json
```

Run the deterministic scenario:

```bash
php artisan election:scenario pop-import-demo
```

Verified output:

```text
Scenario pop-import-demo passed.
Run ID: 20260508-080000-39010001-pop-import-demo
Run Folder: /Users/rli/PhpstormProjects/aes/storage/app/election/runs/20260508-080000-39010001-pop-import-demo
Report: /Users/rli/PhpstormProjects/aes/storage/app/election/runs/20260508-080000-39010001-pop-import-demo/00-start-here/2026-05-08-080000-39010001-pop-import-demo-...-report.json
```

The lifecycle `full-demo` and `evidence-folder-demo` scenarios now use the configured POP source by default. Their scenario reports include a `pop_import` section with source path, mapping profile, source label, row counts, registry hash, manifest hash/path, selected clustered precinct, precinct location, package hash, and package path.

The evidence folder scenario now writes a normal run folder with numbered ceremony directories. POP source evidence remains under `source-data`; the active precinct and active package are under `01-precinct-preparation`.

## Consumption Flow

Operator/import flow:

1. Place or provide the POP workbook path on the appliance.
2. Run `election:pop-import`.
3. Review `manifest.json` and command output.
4. Run `election:pop-lookup {clustered_precinct}` to inspect a precinct.
5. Run `election:pop-activate {clustered_precinct}` to create a package skeleton.

Device/service flow:

1. `PopWorkbookImporter` copies the XLSX and writes normalized registry files.
2. `PopPrecinctRegistry` reads the manifest and index.
3. `PopPrecinctRegistry::find()` performs direct JSONL lookup by clustered precinct id.
4. `ActivateImportedPrecinctPackage` writes a deterministic package skeleton.
5. `ScenarioRunner` can execute `pop-import-demo` for importer verification.
6. `ScenarioRunner` uses configured POP defaults in `full-demo` and `evidence-folder-demo` to activate the imported precinct identity for the lifecycle flow.

## Verification

Focused test file:

```text
tests/Feature/Election/PopWorkbookImportTest.php
```

Coverage:

- Real workbook import writes manifest, source copy, JSONL, index, and location summary.
- Known workbook facts are verified: `93629` rows, `93629` unique clustered precincts, expected headers, and `69773653` registered voters.
- Re-importing the same workbook preserves the same registry hash.
- Lookup for `7010001` remains covered as a registry regression.
- Package activation writes `packages/imported/7010001.json` in the focused regression test.
- Manifest metadata records source type, source label, source headers, mapping profile, and canonical fields.
- Invalid headers fail with a clear error under the default profile.
- Renamed and reordered headers import only when an explicit matching profile is selected.
- Alternate strict headers import only when the exact alternate profile order is used.
- Missing mapped fields fail with a clear error.
- Duplicate source headers fail with a clear error.
- Duplicate clustered precinct ids fail with a clear error.
- `pop-import-demo` scenario imports the configured workbook and writes a package skeleton.
- `full-demo` scenario imports POP, activates the configured clustered precinct, and writes POP details into the scenario report.
- `evidence-folder-demo` copies POP import evidence and includes POP details in the summary report.

Verified runs:

```text
vendor/bin/pest tests/Feature/Election/PopWorkbookImportTest.php --compact
```

Passed: 9 tests, 78 assertions.

```text
vendor/bin/pest --compact
```

Passed: 71 tests, 897 assertions.

## Operational Limits

- `storage/app/election` is resettable runtime storage. Scenario and test resets remove old generated runs and recreate the run-first skeleton.
- Scenario reports are retained inside the active run's `00-start-here` folder.
- Scenario POP source, profile, and clustered precinct are configurable through `election.pop.*` config values.
- There is no Inertia UI for POP import, lookup, or activation yet.
- There is no COMELEC workbook signature verification yet.
- The imported package skeleton is not a legal election package until paired with official contests, candidates, ballot styles, signatures, and certification procedures.
