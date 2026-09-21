# Guide: Deploying and Verifying the Thermal80 Control-Number Printer

## Who this is for
Whoever (human or agent) is implementing/testing `docs/CONTROL_NUMBER_THERMAL_PRINTING_DESIGN.md`.
Read that design doc first for the code architecture. This guide is only about
**where** to run things and **what `.env` values are required** so printing
actually succeeds.

## The one core fact that explains every failure so far
`lp`, `lpstat`, and the CUPS daemon backing the `Thermal80` and `HP` print
queues **only exist on the physical precinct appliance** (a Linux box,
currently reachable at `192.168.2.70` on the office LAN, hostname
`waes.consultel.ph` off-LAN). They do **not** exist on any local macOS
development machine.

If you run `lp`, `lpr`, or `lpstat` on a Mac and get `No such file or
directory`, that is expected and correct — there is no bug to fix. macOS does
not ship these CUPS client binaries at `/usr/bin/lp` the way Linux does, and
even if a same-named binary exists, it has no daemon/queue behind it. **This
is not a PATH, sandbox, or Laravel/PHP problem.** Do not spend time adding
`ELECTION_CUPS_LP_BINARY`-style configurable paths to work around this — the
binary path isn't the issue, the missing CUPS backend is.

**Rule: any real print test must run on the device itself (SSH in, or run
the code as part of the actual deployed app on that machine), never on a
local dev machine.**

## Verified-working device state (as of 2026-09-21)
Confirmed on `ace@192.168.2.70`:
- `which lp` → `/usr/bin/lp` (real Linux ELF binary)
- `lpstat -p` lists `Thermal80` (80 mm thermal, driver `zjiang/zj80.ppd`) and `HP` (laser), both idle
- `php -r 'shell_exec("/usr/bin/lpstat -p 2>&1")'` returns the same output — PHP's `shell_exec`/`PATH` are not restricted
- `lp -d Thermal80 -t "..." <real-pdf-path>` submits successfully and **prints correctly** when given an actual generated PDF
- Raw plain text piped into `lp` (e.g. `printf '...' | lp -d Thermal80`) printed once cleanly and once garbled in separate trials — raw stdin text goes through CUPS's text-to-raster filter chain, which is inconsistent on this driver. **This is a non-issue for the real feature**, because the app never sends raw text; it always submits a rendered PDF artifact (see design doc §2–3). Do not use raw-text `lp` tests as a pass/fail signal for the feature.

## What's still missing on the device (as of 2026-09-21)
Checked directly on `192.168.2.70:/var/www/aes` (`HEAD 8ea34c1`):
- `.env` has **no** `CONTROL_NUMBER`-related keys at all yet.
- None of the new classes exist yet: `app/Election/Printing/ControlNumberPrinter.php`, `CupsControlNumberPrinter.php`, `FileControlNumberReceiptPrinter.php` are all absent.
- Existing, already-working keys for comparison (do not change these):
  ```
  ELECTION_PRINTER_ADAPTER=cups
  ELECTION_PRINTER_DRIVER=cups
  ELECTION_CUPS_PRINTER=HP
  ELECTION_CLOSEOUT_PRINTER_DRIVER=cups
  ELECTION_CLOSEOUT_CUPS_PRINTER=HP
  ```

## Deployment steps
1. Implement the classes and config from `docs/CONTROL_NUMBER_THERMAL_PRINTING_DESIGN.md` locally, commit, and push to `origin/main`.
2. On the device:
   ```bash
   cd /var/www/aes
   git pull origin main
   composer install --no-interaction --prefer-dist --no-progress
   ```
3. Add these two lines to the device's `.env` (do not touch the existing `ELECTION_CUPS_PRINTER`/`ELECTION_CLOSEOUT_CUPS_PRINTER` lines):
   ```
   ELECTION_CONTROL_NUMBER_PRINTER_DRIVER=cups
   ELECTION_CONTROL_NUMBER_CUPS_PRINTER=Thermal80
   ```
   Queue name is case-sensitive: it is exactly `Thermal80` (verify with `lpstat -p` if unsure).
4. Clear cached config so the new `.env` values are picked up:
   ```bash
   php artisan config:clear
   php artisan optimize:clear
   ```
   (Not strictly required right now — there's no cached `bootstrap/cache/config.php` on the device today — but always safe to run after an `.env` change.)

## Verification checklist (run on the device, in order)
1. Confirm the queue is still present and idle:
   ```bash
   lpstat -p Thermal80
   ```
2. Confirm the app resolves the new config correctly:
   ```bash
   php artisan tinker --execute 'echo config("election.control_number_printer.cups.name"), PHP_EOL;'
   ```
   Expect: `Thermal80`.
3. Print an actual generated receipt PDF (not raw text) directly via `lp`, to isolate CUPS/hardware from Laravel:
   ```bash
   lp -d Thermal80 -t "Manual PDF Test" /path/to/a/generated/control-number/*.pdf
   ```
   Confirm it prints correctly before testing through the app.
4. Exercise the real code path end-to-end (adjust to whatever entry point the implementation exposes — either trigger `VoterBallotController::finalize()` through the actual voting flow in a browser pointed at the device, or call the bound service directly):
   ```bash
   php artisan tinker --execute '
   $printer = app(\App\Election\Printing\ControlNumberPrinter::class);
   var_dump(get_class($printer));
   '
   ```
   Expect `App\Election\Printing\CupsControlNumberPrinter` (not the `File...` fallback) once the driver is set to `cups`.
5. Only after steps 1–4 pass, test the full voter flow in the browser against the device and confirm both a laser ballot and a thermal receipt come out for the same voter session.

## Common mistakes to avoid
- Testing `lp`/`lpr`/`lpstat` on a local macOS machine and concluding CUPS is broken — it isn't; there's nothing to fix there.
- Using `printf ... | lp -d Thermal80` (raw text) as a correctness test for the feature — use a real PDF artifact instead.
- Forgetting the `.env` still needs the two new keys added on the device even after the code is deployed — `git pull` alone does not update `.env`.
- Assuming `ELECTION_CUPS_PRINTER=HP` and `ELECTION_CONTROL_NUMBER_CUPS_PRINTER=Thermal80` are the same setting — they are two fully independent config keys read by two different classes; setting one does not affect the other.
