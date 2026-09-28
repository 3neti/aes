# Air-Gapped Real-Time Runtime Implementation Plan

## Objective

Operate WAES as a self-starting air-gapped appliance whose scanner ingestion,
queued work, real-time public views, scheduled maintenance, and commissioning
checks recover automatically after boot without requiring an operator terminal.

The real-time layer accelerates presentation only. Persisted scanner state and
the existing HTTP state endpoints remain canonical.

## Delivery Status

| Phase | Scope | Status |
| --- | --- | --- |
| 1 | Reverb, Echo, broadcast configuration, and lockfiles | Implemented; appliance acceptance pending |
| 2 | Scanner-state broadcast events and polling fallback | Implemented; appliance acceptance pending |
| 3 | Follow-BEI presentation state | Planned |
| 4 | Linux USB scanner bridge | Implemented; appliance acceptance pending |
| 5 | `systemd` runtime target and service units | Implemented; appliance acceptance pending |
| 6 | Laravel scheduler timer and runtime heartbeats | Scanner and CUPS probes implemented; broader probes pending |
| 7 | Commissioning gate and lifecycle enforcement | Planned |
| 8 | Device installation automation and runbook | Implemented; appliance acceptance pending |
| 9 | Physical appliance and Wi-Fi acceptance | Planned |

## Phase 1: Real-Time Foundation

1. Install Laravel Reverb, Laravel Echo, and the Vue Echo integration.
2. Commit package lockfiles and generated Laravel configuration.
3. Keep broadcasting disabled or logged unless appliance credentials explicitly
   enable Reverb.
4. Restrict allowed WebSocket origins through appliance configuration.
5. Proxy WebSocket traffic through the same local hostname used by the UI.

Acceptance:

- Production assets build without external runtime dependencies.
- Reverb starts using only local configuration.
- A public Echo client can subscribe through the appliance hostname.

## Phase 2: Canonical Scanner-State Updates

1. Broadcast `ScannerScanEventRecorded` after the scanner event is persisted.
2. Send station, scan type, revision, status, document hash, and time only.
3. Route broadcasts to a dedicated `broadcasts` queue.
4. Subscribe the precinct operator and public tally pages.
5. Fetch the canonical scanner-state endpoint when an event arrives.
6. Poll slowly while connected and return to the current fast polling interval
   when disconnected.
7. Apply the same transport to canvassing without changing its ingestion engine.

Acceptance:

- Scanning succeeds while Reverb or the queue is stopped.
- Connected viewers update without waiting for the polling interval.
- Reconnecting and newly opened viewers reconstruct the complete canonical state.
- Duplicate, rejected, and accepted scans remain idempotent and session-bound.

## Phase 3: Follow-BEI Presentation

1. Persist a public-safe presentation snapshot containing the selected ballot,
   selected tab, tally horizon, sort mode, and contest filters.
2. Allow only the operator station to update presentation state.
3. Broadcast monotonically sequenced presentation changes.
4. Add `Follow BEI`, `Explore independently`, and `Resume following` controls.
5. Never expose scanner buffers, controls, credentials, PINs, or system dialogs.

Acceptance:

- Public viewers follow the BEI's public-safe view in order.
- A viewer may explore independently without changing the operator or other viewers.
- Stale and cross-session presentation updates are ignored.

## Phase 4: Linux Scanner Bridge

1. Extract common CR, LF, and ETX framing from the existing scanner commands.
2. Read the configured Linux USB HID or serial device without terminal focus.
3. Use the kernel-provided stable scanner path at
   `/dev/input/by-id/usb-Scanner_Barcode_0215-event-kbd`.
4. Use a dedicated non-root group for scanner access.
5. Select `precinct` or `canvassing` ingestion through configuration.
6. Prevent multiple bridge processes from owning one device.
7. Reconnect after scanner removal and replacement.
8. Record a heartbeat only while the evdev device is open.

Acceptance:

- The scanner works after cold boot without an interactive shell.
- Browser focus does not affect device ingestion.
- Disconnect and reconnect do not duplicate a payload.
- Precinct and canvassing modes use their existing validators and idempotency rules.

## Phase 5: Boot-Managed Runtime

Create a `waes-runtime.target` containing:

- `waes-reverb.service`
- `waes-queue.service`
- `waes-scanner.service`
- `waes-scheduler.timer`

Each long-running service must use an explicit application directory, PHP binary,
service account, environment file, graceful stop, bounded restart delay,
journald logging, resource limits, and appropriate `systemd` hardening.

The queue worker processes `broadcasts,default`. Reverb binds to loopback and is
exposed by the local reverse proxy. The scanner service selects its ingestion
mode from appliance configuration.

Acceptance:

- Enabling one target starts the complete runtime at boot.
- Failure of one process causes only that process to restart.
- Deployments gracefully restart queue and Reverb processes.
- Operators do not need an interactive terminal.

## Phase 6: Scheduler and Runtime Health

1. Invoke `php artisan schedule:run` every minute using a `systemd` timer.
2. Define periodic tasks in `routes/console.php`.
3. Record scheduler and queue-worker heartbeats.
4. Add a read-only `election:runtime-check` command.
5. Check scanner presence, heartbeat freshness, queue progress, Reverb reachability,
   storage, database, disk space, local networking, and CUPS queues.
6. Distinguish required failures from degraded warnings.

Acceptance:

- Runtime health is based on functional probes, not process names alone.
- Health checks cannot start privileged services from the web process.
- Missing optional real-time services never alter canonical election state.

## Phase 7: Commissioning Gate

Build on `DeviceCertificationService` and the existing certification ceremony.
The application remains available for diagnosis, but commissioning and opening
polls require a current passing gate when appliance enforcement is enabled.

Hard blockers include storage, database, migrations, precinct package, clock,
disk space, ballot printer, control-number printer, scanner bridge, queue worker,
zero tally, certification evidence, and required officer approvals.

Reverb, public Wi-Fi, public presentation, and a temporarily late scheduler
heartbeat are warnings when their safe fallbacks remain available.

The commissioning report records the run and precinct, application version,
configuration and boot identity, device identities, checks, attestations,
officers, timestamp, and canonical report hash. Opening polls references the
commissioning report hash.

Acceptance:

- A hard failure blocks lifecycle advancement with an actionable message.
- A warning is visible and journaled without unnecessarily stopping voting.
- Relevant version, device, precinct, run, or configuration changes stale the gate.
- Later service failures never automatically close polls or invalidate ballots.

## Phase 8: Deployment Assets and Documentation

Version-control service, timer, scanner rule, reverse-proxy, environment, and
installation templates under `deployment/`. Provide an idempotent installer and
a single `waes-runtime` administrative helper for start, stop, restart, and status.

Maintain `docs/AIR_GAPPED_DEVICE_RUNTIME.md` as the appliance runbook covering
offline installation, configuration, commissioning, logs, recovery, deployment,
scanner replacement, degraded operation, rollback, and power-loss procedures.

## Phase 9: Verification

Automated tests cover broadcast payload minimization, queue isolation, scanner
framing, device-mode routing, idempotency, session isolation, presentation
ordering, commissioning blockers and warnings, stale reports, lifecycle gating,
polling fallback, reconnect synchronization, TypeScript, linting, and production
asset compilation.

Physical acceptance covers cold boot, commissioning, paper QR scanning, operator
and public updates, Reverb failure, queue failure, scanner reconnect, power-cycle
recovery, deployment restart, and the intended phone count on the actual access
point.
