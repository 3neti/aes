# Print Form Profiles

The Alternative Election System produces print renditions from sealed ballot, tally, and Election Return records. A print profile changes layout only. It never changes selections, QR payloads, tally totals, or source hashes.

## Available Profiles

| Profile | Intended form |
| --- | --- |
| `a4` | Full-page evidence, review, and posting copy. |
| `thermal-80` | 80 mm thermal-roll printer. |
| `thermal-58` | Compact 58 mm thermal-roll printer. |

Set the ballot printer default with `ELECTION_PRINT_FORM_PROFILE`. The default is `a4`. The currently available profiles are configured in `config/election.php` under `print_forms`.

## Operator Workflow

- In **Ballot Printing**, select the installed printer form before printing. The ballot keeps an archival A4 rendition and the selected printer rendition.
- In **Counting and Tally**, open the A4, 80 mm, or 58 mm tally-sheet rendition.
- In **Election Return**, open the same three Election Return renditions.
- In **Diagnostics**, review the configured profile list and whether tally and Election Return print-form manifests exist.

The selected form is recorded in the ballot print job as `print_form_profile`. CUPS submission uses the selected rendition; the domain continues to depend only on the printer adapter contract.

## Evidence Layout

Within an election run, the artifacts are stored beside the ceremony they support:

```text
04-voting/print-forms/ballots/{ballot-id}/
  a4.pdf
  thermal-80.pdf or thermal-58.pdf
  manifest.json

06-counting-and-tally/print-forms/tally-sheet/
  a4.pdf
  thermal-80.pdf
  thermal-58.pdf
  manifest.json

07-election-return/print-forms/{precinct-id}/
  a4.pdf
  thermal-80.pdf
  thermal-58.pdf
  manifest.json
```

Each `manifest.json` records the form profile, its width, its rendered-file SHA-256, and the canonical source hash. The form-file hash identifies a particular rendition; the source hash identifies the sealed ballot payload, tally, or Election Return shared by every rendition.

## Thermal Printer Trial

Before operational use, verify the printer's actual printable width, margins, darkness, QR scan reliability, paper stock, and cutter behavior. The `thermal-80` and `thermal-58` layouts are simulation forms, not prescribed COMELEC forms. Their headers explicitly state that approval is still required.

Run a complete evidence bundle with:

```bash
ELECTION_PRINT_FORM_PROFILE=thermal-80 php artisan election:scenario full-demo
```

The resulting `run-summary.json`, `artifact-index.json`, and print-form manifests make the trial repeatable and inspectable.
