# WAES Air-Gapped Device Runtime

## Purpose

This runbook operates the local WAES appliance without requiring an open terminal.
The web application remains the canonical source of election state. Reverb only
notifies browsers to fetch newer canonical scanner state.

The persisted plan and architectural constraints are in:

- `docs/AIR_GAPPED_REALTIME_RUNTIME_IMPLEMENTATION_PLAN.md`
- `docs/AIR_GAPPED_REALTIME_RUNTIME_COMPASS.md`

## Runtime Ownership

`systemd` owns continuous or boot-time processes:

- `waes-reverb.service`: local WebSocket server on `127.0.0.1:8080`.
- `waes-queue.service`: database queue worker for `broadcasts,default`.
- `waes-scanner.service`: evdev scanner bridge feeding the canonical precinct or
  canvassing Artisan ingestion command.
- `waes-scheduler.timer`: invokes Laravel's scheduler every minute.
- `waes-runtime.target`: starts the complete currently supported runtime.

Laravel's scheduler owns short periodic application work. It must not be used to
keep Reverb, queue workers, or a scanner reader alive.

## Current Boundary

The Reverb, queue, scheduler, heartbeat, scanner bridge, precinct notification,
and canvassing notification foundations are implemented. The browser
keyboard-wedge scanner remains available as a fallback.

The physical scanner is confirmed as USB HID/evdev only. The bridge reads
`/dev/input/by-id/usb-Scanner_Barcode_0215-event-kbd`, decodes US-QWERTY events,
handles Enter, keypad Enter, and Ctrl-C/ETX suffixes, and emits complete payloads
to the existing Artisan ingestion command. It retries the stable path after
unplug/replug, exclusively grabs the device to prevent duplicate readers, and
writes a heartbeat only while the device is open.

## Application Configuration

Set unique Reverb credentials in the device's application environment. Never
reuse the example values or commit appliance secrets.

Required production settings:

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database

REVERB_APP_ID=<unique-local-id>
REVERB_APP_KEY=<unique-local-key>
REVERB_APP_SECRET=<unique-local-secret>
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_HOST=waes.consultel.ph
REVERB_PORT=80
REVERB_SCHEME=http
REVERB_ALLOWED_ORIGINS=waes.consultel.ph,192.168.88.1,192.168.2.70

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

ELECTION_RUNTIME_HEARTBEAT_MAX_AGE=150
ELECTION_RUNTIME_CUPS_TIMEOUT=3
ELECTION_SCANNER_RUNTIME_MODE=evdev
ELECTION_SCANNER_DEVICE=/dev/input/by-id/usb-Scanner_Barcode_0215-event-kbd
ELECTION_SCANNER_INGESTION=precinct
ELECTION_SCANNER_STATION_ID=role-demo-precinct
```

The Laravel `.env` is read by PHP-FPM and during the Vite build. The protected
`/etc/waes/runtime.env` is read by the boot services. Reverb credentials and
server settings must match in both places. After changing `VITE_` values,
rebuild frontend assets. After changing only server values, clear Laravel's
cached configuration and restart the runtime.

## Installing the Runtime Units

The tracked templates are under `deployment/systemd/`. They assume:

- application directory: `/var/www/aes`
- PHP binary: `/usr/bin/php`
- service account: `ace:ace`
- scanner supplementary group: `input`
- writable directories: `storage` and `bootstrap/cache`

Install `python3-evdev`, confirm `ace` belongs to `input`, then run the idempotent
installer from the deployed repository:

```bash
sudo deployment/install-waes-runtime
```

The installer creates `/etc/waes/runtime.env` only when absent, preserving an
existing file. Configure unique Reverb credentials there and in Laravel `.env`.
It installs and enables the units but does not start them by default. It also
installs the support helper at `/usr/local/sbin/waes-runtime`.

Include `/etc/nginx/snippets/waes-reverb.conf` in the existing HTTP server block,
run `nginx -t`, reload Nginx, rebuild Vite, and then run:

```bash
sudo deployment/install-waes-runtime --start
```

The public network must never expose port `8080` directly.

## Support Commands

Use the installed helper instead of remembering individual unit names:

```bash
waes-runtime status
waes-runtime check
waes-runtime logs
sudo waes-runtime start
sudo waes-runtime stop
sudo waes-runtime restart
```

The helper does not install packages, change configuration, clear print jobs, or
modify election state.

## Functional Health Check

Run:

```bash
php artisan election:runtime-check
```

For machine-readable output:

```bash
php artisan election:runtime-check --json
```

The scheduler records its own heartbeat. It also dispatches a queue heartbeat
job, which is only recorded when a worker actually processes it. A process that
exists but cannot execute work therefore does not pass the functional check.

Current result policy:

- Missing or stale queue heartbeat: failure.
- Missing or stale scheduler heartbeat: failure.
- Reverb not configured: warning because polling remains available.
- Browser keyboard-wedge mode: warning because it depends on browser focus.
- Configured evdev scanner missing, unreadable, or without a fresh bridge
  heartbeat: failure.
- Configured CUPS queue unavailable, blocked, or unnamed: failure.
- Reachable CUPS queue with unfinished jobs: warning requiring operator review.

## Failure Behavior

If Reverb stops, ballot ingestion and persisted tally state continue. Connected
pages return to polling. Restart only `waes-reverb.service` after investigating.

If the queue stops, broadcast notifications and queued jobs pause. Canonical
scanner ingestion still persists through its normal request or ingestion path.
Restart the queue worker and confirm a fresh queue heartbeat.

If the scheduler stops, periodic heartbeats and future scheduled maintenance do
not run. The web application remains available for diagnosis.

If the scanner is unplugged, the bridge keeps retrying the stable `by-id` path.
Its heartbeat becomes stale until the device is open again. Do not substitute an
unstable `/dev/input/eventN` path.

If CUPS reports `Waiting for printer to become available`, power-cycle or repair
the physical printer and resolve its queued jobs before commissioning. Do not
cancel election print jobs without following the applicable custody procedure.

The runtime check is read-only apart from the normal heartbeat writers. It does
not start services and must not be used as a privileged web-process escape hatch.

## Deployment Sequence

1. Put the application into its normal deployment maintenance state.
2. Update source and install locked PHP and JavaScript dependencies offline.
3. Run database migrations using the existing deployment procedure.
4. Build frontend assets with the device's production environment values.
5. Clear Laravel caches.
6. Restart `waes-queue.service`, `waes-reverb.service`, and
   `waes-scanner.service` gracefully.
7. Start or restart `waes-runtime.target`.
8. Wait at least one scheduler interval, then run the runtime check.
9. Open a precinct tally page and verify both a Reverb-driven refresh and the
   polling fallback before commissioning.

## Remaining Commissioning Work

The current health command is the first probe layer, not yet the formal election
commissioning gate. The next implementation phases will:

- add storage, database, disk, clock, Reverb-reachability, and local-network probes;
- persist a signed or hashed commissioning report;
- enforce the report when opening polls on configured appliances;
- keep the diagnostic UI available when commissioning fails.
