# Design: Route Control Number to Thermal Printer, Ballot to Laser Printer

## Status
Design only — not yet implemented. This document is written to be handed to a
code-scaffolding agent/AI to build against.

## Goal
Two physical printers are attached to the precinct appliance:
- A **laser printer** that must keep printing the official paper ballot (unchanged).
- A **thermal printer** that should print the voter's control number receipt
  (the code/QR a voter uses to redeem their ballot at the print station).

The app needs a way to route each artifact to its own CUPS queue.

## Current architecture (already built, do not change)
The codebase already has two independent, config-driven CUPS routing points.
Both follow the same shape: an env var holds a CUPS queue name, and a
dedicated class shells out to `lp -d <queue> ...` via `Illuminate\Support\Facades\Process`.

1. **Ballot → laser printer**
   - Config: `config/election.php` → `devices.printer.cups.name`, sourced from `ELECTION_CUPS_PRINTER` (currently `HP`).
   - Bound in `app/Providers/AppServiceProvider.php::register()` — `BallotPrinter::class` resolves to `App\Election\Printing\CupsBallotPrinter` when `election.devices.printer.driver === 'cups'`.
   - `CupsBallotPrinter::print()` (`app/Election/Printing/CupsBallotPrinter.php`) generates the ballot PDF via `FileBallotPrinter`, then submits it with `lp -d {printerName} ...`.
   - Called from `App\Http\Controllers\Election\PrintStationController::print()` (`app/Http/Controllers/Election/PrintStationController.php:58-72`), which injects `BallotPrinter $printer` and calls `$releases->print($releaseId, $printer)` (`App\Election\Voting\PrivateBallotRelease::print()`).

2. **Closeout artifacts (tally sheet / Election Return) → its own queue**
   - Config: `election.closeout_printer.cups.name`, sourced from `ELECTION_CLOSEOUT_CUPS_PRINTER`.
   - Implemented directly in `App\Election\Printing\CloseoutArtifactPrinter` (not behind an interface/binding — it reads config internally and calls `lp -d {printerName} ...`).
   - Called from `DemoRoomPrintStationController::submitCloseoutArtifact()`.

**There is currently no third pathway for the control number.** The control
number (4–6 digit code) and its QR (`aes-print-release:{code}`) are generated
in `App\Election\Voting\PrivateBallotRelease::create()` and are only ever
rendered on-screen (`resources/js/pages/Election/VoterComplete.vue`). Nothing
in the codebase submits them to a physical printer today.

## Verified production hardware (as of 2026-09-21)
On the reference/production appliance, both CUPS queues already exist and are
tested working — no CUPS-side setup is needed, only the Laravel-side wiring:
- `HP` — laser printer (existing `ELECTION_CUPS_PRINTER` target).
- `Thermal80` — 80 mm (3-inch) thermal printer, added 2026-09-21, driver `zjiang/zj80.ppd`, confirmed printing correctly.

## Proposed design

### 1. New config block
Add to `config/election.php`, mirroring `closeout_printer` exactly:

```php
'control_number_printer' => [
    'driver' => env('ELECTION_CONTROL_NUMBER_PRINTER_DRIVER', 'file'), // file|cups|disabled
    'cups' => [
        'name' => env('ELECTION_CONTROL_NUMBER_CUPS_PRINTER', ''),
        'timeout' => (int) env('ELECTION_CONTROL_NUMBER_CUPS_TIMEOUT', 10),
    ],
],
```

Add matching entries to `.env.example` (near the existing `ELECTION_CUPS_PRINTER` / `ELECTION_CLOSEOUT_CUPS_PRINTER` lines) and, for this deployment, set in `.env`:
```
ELECTION_CONTROL_NUMBER_PRINTER_DRIVER=cups
ELECTION_CONTROL_NUMBER_CUPS_PRINTER=Thermal80
```
`ELECTION_CUPS_PRINTER=HP` stays unchanged — the ballot keeps printing on the laser printer.

### 2. New artifact: control-number receipt
Add a receipt renderer, e.g. `App\Election\Printing\ControlNumberReceiptArtifact`, that produces a small PDF (or raw ESC/POS text — see Open Questions) sized to `PrintFormProfile::Thermal80` (reuse the existing enum in `app/Election/Printing/PrintFormProfile.php`; do not invent a new profile system). Content:
- Precinct/station label (from `runtime/active-precinct.json`).
- The control number, printed large.
- QR code for `aes-print-release:{code}` (reuse `App\Election\Voting\StandardQrCode::renderPrintPng()`, same helper `PrivateBallotRelease` already uses for the QR data URI).
- Expiry timestamp (`expires_at`).
- No ballot selections and no `payload_hash`/QR ballot payload — this receipt must not carry any selection data, only the redemption code.

Persist the rendered artifact under `ElectionStorage`, e.g. `print-forms/control-number/{release_id}.pdf`, consistent with the existing `print-forms/...` layout described in `docs/PRINT_FORM_PROFILES.md`.

### 3. New printer service, mirroring `BallotPrinter`/`CupsBallotPrinter`
- Interface `App\Election\Printing\ControlNumberPrinter`:
  ```php
  interface ControlNumberPrinter
  {
      /** @param array<string, mixed> $release */
      public function print(array $release): array;
  }
  ```
- `FileControlNumberReceiptPrinter implements ControlNumberPrinter` — renders the artifact only (parity with `FileBallotPrinter`), returns a job array, writes `print-jobs/control-number/{release_id}.json`, journals `control_number.print_recorded`.
- `CupsControlNumberPrinter implements ControlNumberPrinter` — wraps the file printer, then submits via `Process::run(['lp', '-d', $printerName, '-t', "AES Control Number {$releaseId}", $artifactPath])`, following `CupsBallotPrinter::submit()` exactly. Include the same fallback behavior as `CupsBallotPrinter::fallbackReason()`: if `$printerName === ''`, fall back to file-only and journal `control_number.print_fallback` (do not throw — printing this receipt must never block ballot finalization).

### 4. Service binding
In `AppServiceProvider::register()`, alongside the existing `BallotPrinter::class` binding:
```php
$this->app->bind(ControlNumberPrinter::class, function (Application $app): ControlNumberPrinter {
    if (config('election.control_number_printer.driver') === 'cups') {
        return new CupsControlNumberPrinter(
            $app->make(FileControlNumberReceiptPrinter::class),
            $app->make(ElectionStorage::class),
            $app->make(ActivityJournal::class),
            (string) config('election.control_number_printer.cups.name', ''),
            (int) config('election.control_number_printer.cups.timeout', 10),
        );
    }

    return $app->make(FileControlNumberReceiptPrinter::class);
});
```

### 5. Call site
Trigger printing right when the control number is generated, in
`App\Http\Controllers\Election\VoterBallotController::finalize()`
(`app/Http/Controllers/Election/VoterBallotController.php:49-103`), immediately after:
```php
$release = $releases->create($authorizationId, $selections, $controlNumber);
```
Inject `ControlNumberPrinter $receiptPrinter` into `finalize()` and call:
```php
$receiptPrinter->print($release);
```
Keep this a controller-level call (not inside `PrivateBallotRelease::create()`), matching the existing convention where `BallotPrinter` is passed into `PrivateBallotRelease::print()` as a parameter rather than a constructor dependency — hardware I/O stays explicit and injectable per call, and domain classes stay hardware-free.

Wrap the call so a printer failure cannot fail voter finalization (it must never re-throw past this point):
```php
try {
    $receiptPrinter->print($release);
} catch (\Throwable) {
    // already journaled by the printer implementation; swallow here.
}
```

### 6. Routing summary (for the scaffolding agent)
| Artifact | Env var | Current value | Physical printer |
| --- | --- | --- | --- |
| Ballot | `ELECTION_CUPS_PRINTER` | `HP` | Laser |
| Control number receipt (new) | `ELECTION_CONTROL_NUMBER_CUPS_PRINTER` | `Thermal80` | Thermal (80 mm) |
| Closeout (tally sheet / ER) | `ELECTION_CLOSEOUT_CUPS_PRINTER` | unset | Configurable — either queue |

Each is independently wired: no shared code path decides "which physical printer" beyond reading its own env var and shelling out to `lp -d <queue>`.

### 7. Testing
Add a Pest feature test, e.g. `tests/Feature/Election/ControlNumberReceiptPrintingTest.php`, following the pattern in `tests/Feature/Election/PublicSimulationTest.php`:
- Fake `Process` (`Process::fake()`) and assert `lp -d Thermal80 ...` is invoked when `ELECTION_CONTROL_NUMBER_PRINTER_DRIVER=cups` and `ELECTION_CONTROL_NUMBER_CUPS_PRINTER=Thermal80`.
- Assert the fallback path (file-only, no `lp` call, no exception) when the CUPS printer name is empty.
- Assert the receipt artifact never contains ballot selections or `payload_hash`.
- Assert `VoterBallotController::finalize()` still redirects successfully to `election.voter.complete` even when the `Process::fake()` simulates an `lp` failure (non-blocking behavior).

## Open questions for implementation
1. **Artifact format**: PDF (via the existing `PrintFormArtifactService`/DomPDF-style rendering, matching `thermal-80.pdf` conventions in `docs/PRINT_FORM_PROFILES.md`) vs. raw ESC/POS text sent directly to the printer. PDF is more consistent with the current codebase pattern (`CupsBallotPrinter` already prints a PDF via `lp`); raw ESC/POS would need a new code path in the driver classes. Recommend PDF for consistency unless print speed/formatting on the ZJ-80 proves unacceptable in trial.
2. **Timing**: should the receipt print automatically on ballot finalization (as designed above), or should the print station require an explicit action first (mirroring how ballot printing itself is a separate `PrintStationController::print()` step from redemption)? The design above assumes automatic-at-finalize since the control number must exist before a voter can walk to the print station.
3. **Retry/reprint**: no reprint action is designed here. If a receipt fails to print, the voter still sees the code on-screen (`VoterComplete.vue`), so no data is lost — but no operator-facing "reprint receipt" action currently exists. Decide whether to add one alongside this change.
