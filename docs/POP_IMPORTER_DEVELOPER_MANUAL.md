# POP Importer Developer Manual

## Architecture

The POP importer is intentionally small and deterministic:

```text
physical source file
  -> PopSourceAdapter
  -> PopSourceData
  -> PopMappingProfile
  -> PopWorkbookImporter
  -> storage/app/election/source-data/pop/registries/pop-2025-nle
```

`PopSourceAdapter` reads a physical source format and returns headers plus rows. `XlsxPopSourceAdapter` is the active adapter for `.xlsx` files.

`PopMappingProfile` maps source headers into the canonical registry fields:

```text
region
province
city_municipality
barangay
clustered_precinct
precinct_cluster
cluster_total
polling_place
```

`PopWorkbookImporter` writes the source copy, JSONL registry, clustered precinct index, location summary, manifest, and journal events. The registry writer should not know about COMELEC column name variants.

## Adding An Excel Mapping Profile

Add profiles in `App\Election\Preparation\PopMappingProfiles`.

Use a stable profile name:

```text
comelec-pop-{year}-{election-code}
```

For a variant of an existing source, use a suffix:

```text
comelec-pop-2025-nle-alt
```

Each profile must define:

- `name`: the command-line profile value.
- `sourceLabel`: the Excel sheet name.
- `fieldMap`: source header names keyed by canonical field.
- `requiresExactHeaders`: `true` for operational profiles unless there is a documented reason to allow column reordering.

Do not change `comelec-pop-2025-nle` unless the verified 2025 workbook profile itself was incorrect. Add a new profile for new source shapes.

## Strict Versus Flexible Headers

Use `requiresExactHeaders: true` when:

- the workbook is an official source format;
- deterministic evidence reproduction matters;
- the profile is intended for operator use.

Use `requiresExactHeaders: false` only for demos, exploratory imports, or a source format where COMELEC explicitly does not guarantee column order. Flexible profiles still require every mapped source header to be present and unique.

## Required Tests

Every new Excel profile needs focused tests in `tests/Feature/Election/PopWorkbookImportTest.php`:

- successful import using a representative workbook fixture;
- manifest records the selected `mapping_profile`;
- manifest records the exact `source_headers`;
- lookup returns normalized canonical fields;
- the default profile rejects the alternate headers;
- strict profiles reject reordered headers;
- missing mapped fields fail clearly;
- duplicate source headers fail clearly;
- duplicate clustered precinct ids fail clearly.

Use the existing `makePopWorkbook()` helper for synthetic fixtures. Do not add a spreadsheet dependency only to build tests.

## Verification Commands

Run the focused importer suite first:

```bash
vendor/bin/pest tests/Feature/Election/PopWorkbookImportTest.php --compact
```

Then run formatting and the full configured suite:

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/pest --compact
```

If the real POP workbook is available locally, the focused suite also verifies the known 2025 workbook facts. If it is not available, those tests are skipped and synthetic profile tests still run.

## Operational Notes

The manifest records `source_type`, `source_label`, `source_headers`, `mapping_profile`, and `canonical_fields`. These fields are operator evidence and should stay stable.

Lifecycle scenarios consume POP through configurable defaults in `config/election.php`:

```text
election.pop.source_path
election.pop.profile
election.pop.clustered_precinct
```

`full-demo` and `evidence-folder-demo` import the configured POP workbook before certification, activate the configured clustered precinct, then bind that POP precinct identity to the sample ballot definition. POP supplies precinct identity and location; the simulation ballot contests still come from `resources/election/sample`.

Scenario reports include a `pop_import` section. Evidence-folder summaries also include `pop_import`, POP hashes in `important_hashes`, and a `pop_import_and_precinct_source` artifact category.

PDF import is not enabled. If COMELEC provides PDF sources, add a separate `PopSourceAdapter` with PDF fixtures and extraction tests before allowing operator use. PDF table extraction must prove row counts, field alignment, totals, and deterministic output.
