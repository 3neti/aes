# Evidence Folder Scenario Implementation Plan

## Summary

Add a deterministic `evidence-folder-demo` scenario that runs the full precinct lifecycle and persists a curated evidence folder under `storage/app/election-scenario-artifacts`. The command prints both the durable report path and the evidence folder path.

```bash
php artisan election:scenario evidence-folder-demo
```

## Evidence Folder Shape

```text
storage/app/election-scenario-artifacts/
  2026-05-08-080001-0421-a-evidence-folder-demo-{hash}/
    README.txt
    summary-report.json
    summary-report.txt
    artifact-index.json
    01-device-initiation-scan-documents/
    02-device-and-certification-reports/
    03-officer-attestations/
    04-ballots/
    05-counting-and-tally/
    06-election-return/
    07-journal/
```

## Scenario Flow

1. Reset runtime election storage and freeze the deterministic scenario clock.
2. Activate the sample precinct package.
3. Generate certification deck scan documents.
4. Run device certification.
5. Run Friday certification.
6. Capture officer attestation and signature artifact.
7. Open polls.
8. Finalize and print a counted ballot.
9. Finalize, print, and spoil a second ballot.
10. Close polls and start counting.
11. Count the valid ballot and reject the spoiled ballot.
12. Generate tally, tally sheet, Election Return, return attestation, and close precinct.
13. Copy evidence into a durable numbered folder.
14. Generate `artifact-index.json`, `summary-report.json`, and `summary-report.txt`.
15. Write the normal durable scenario report archive.

## Required Summary Report

The final report is the operator-facing table of contents. It must include:

- scenario name, precinct id, election id, generated timestamp, pass/fail
- lifecycle flow in order
- statistics for scan documents, device checks, certification ballots, attestations, signatures, ballots, prints, spoiled ballots, accepted ballots, rejected ballots, contests tallied, journal entries, total evidence files, and total bytes
- grouped artifact pointers with relative path, original source path, SHA-256 hash, and byte size
- important hashes for mapping, device certification, Friday certification, ballot payloads, tally, Election Return, and summary report

## Implementation Slices

1. Persist this plan and compass, then commit.
2. Add storage helpers and scenario registration, then commit.
3. Add evidence folder builder and artifact copying, then commit.
4. Add summary report JSON/TXT, then commit.
5. Add tally sheet TXT/PDF artifacts, then commit.
6. Add focused scenario verification tests, then commit.
7. Run final commands, update status and compass with paths/results, then commit.

## Tests And Commands

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact
php artisan election:scenario evidence-folder-demo
vendor/bin/pest --compact
```

## Assumptions

- The Friday certification deck QR artifacts are the documents to scan to initiate and certify the device.
- The output is readable folders plus summary reports, not only a TAR archive.
- Existing removable-media and TAR export workflows remain separate.
- No real scanner or printer hardware is required.
