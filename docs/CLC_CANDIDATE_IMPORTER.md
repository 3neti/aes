# CLC Candidate Importer

## Purpose

The CLC importer turns COMELEC Certified List of Candidates PDFs into deterministic local candidate registry files. The PDFs remain source evidence; parsed JSONL files are a device read model for review and future ballot generation.

Default source directory:

```text
resources/election/clc
```

## Commands

Import all default CLC PDFs:

```bash
php artisan election:clc-import
```

Import a custom directory or one PDF:

```bash
php artisan election:clc-import /path/to/clc-directory
php artisan election:clc-import /path/to/CLC2025_Senator.pdf
```

View candidates for a clustered precinct after POP and CLC imports:

```bash
php artisan election:precinct-candidates 7010001
php artisan election:precinct-candidates 76010001 --district="FIRST DIST"
php artisan election:precinct-candidates 7010001 --json
php artisan election:precinct-candidates 7010001 --write-report
```

`election:precinct-candidates` combines:

- POP precinct location from `registries/pop-2025-nle`.
- CLC candidate contests from `registries/clc-2025-nle`.

District-level contests require `--district` when the city has more than one district in the CLC registry.

## Output Files

Import writes:

```text
storage/app/election/
  imports/clc/*.pdf
  registries/clc-2025-nle/
    candidates.jsonl
    contests.json
    contest-index.json
    source-files.json
    needs-review.jsonl
    manifest.json
  precinct-candidates/{clustered_precinct}-candidates.json
  precinct-candidates/{clustered_precinct}-candidates.txt
```

## Candidate Records

Each candidate has source pointers, contest scope, ballot name, legal name, party, and placeholder image metadata:

```json
{
  "schema_version": "clc-candidate-1",
  "source_file": "CLC2025_Senator.pdf",
  "source_page": 1,
  "scope": "national",
  "geography": "PHILIPPINES",
  "office": "SENATOR",
  "ballot_number": 1,
  "name_on_ballot": "ABALOS, BENHUR (PFP)",
  "sex": "MALE",
  "full_name": "ABALOS, BENJAMIN JR. DE CASTRO",
  "political_party": "PARTIDO FEDERAL NG PILIPINAS",
  "candidate_image": {
    "status": "placeholder",
    "type": null,
    "uri": null,
    "source": null,
    "sha256": null,
    "alt_text": "Candidate photo placeholder for ABALOS, BENHUR (PFP)"
  },
  "candidate_hash": "..."
}
```

Candidate photos are not imported yet. The placeholder shape is stable so a future slice can attach local image files or external URL metadata without changing registry consumers.

## Ballot Use

This importer creates the candidate/contest read model that ballots should consume later. It does not yet replace the sample ballot definition in lifecycle scenarios.

Current behavior:

- POP determines precinct identity and location.
- CLC determines candidate/contest lists.
- `election:precinct-candidates` previews the combined POP + CLC candidate set.

Future ballot-generation work should consume the precinct candidate report or resolver directly.

## Verification

Focused tests:

```bash
vendor/bin/pest tests/Feature/Election/ClcCandidateImportTest.php --compact
vendor/bin/pest tests/Feature/Election/PrecinctCandidatesCommandTest.php --compact
```
