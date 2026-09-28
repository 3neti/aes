# Air-Gapped Real-Time Runtime Compass

## North Star

WAES boots into a diagnosable state, proves that required equipment and services
work, records that proof, and only then permits the election lifecycle to advance.
The public may follow tally presentation live without gaining access to officer
controls or changing election state.

## Sources of Truth

1. The validated ballot or election-return QR payload is the document truth.
2. Persisted scanner events are the ingestion record.
3. Tally and canvass state are reconstructed from accepted persisted documents.
4. HTTP state snapshots are the recovery interface for every viewer.
5. Reverb events are notifications that a newer snapshot exists, never election
   evidence and never the canonical tally.

## Runtime Boundaries

`systemd` owns continuous operating-system processes: Reverb, the queue worker,
the scanner bridge, and the scheduler timer. Laravel's scheduler owns periodic
tasks that start and finish. The browser owns rendering and local interaction.
None may silently assume another layer's responsibility.

## Availability Rule

The diagnostic web application must remain reachable when an optional or required
service fails. Commissioning or a phase-specific operation may be blocked, but the
screen needed to understand and repair the failure must not be blocked.

## Degraded Operation

Every optional acceleration has a deterministic fallback. Reverb falls back to
HTTP polling. A missed event is repaired by a complete snapshot. Out-of-order
events are rejected by revision. Real-time failure must not reject an otherwise
valid scan.

There is no unsafe fallback for canonical storage, database integrity, precinct
identity, ballot printing, required scanner ownership, or required officer
approval. Those failures block only the affected lifecycle transition or action.

## Commissioning Rule

Running is not the same as ready. Commissioning uses functional probes, physical
confirmations, and officer attestations. Its output is a hashed, journaled artifact
bound to the run, precinct, application version, configuration, boot, and device
identities. A material identity change makes the report stale.

## Public Projection Rule

Phones receive a reconstructed public-safe view, not a pixel stream of the BEI
tablet. Follow mode synchronizes selected public document, view, filter, and tally
horizon. Scanner input, credentials, PINs, administrative controls, notifications,
and operating-system UI never enter the public channel.

## Scanner Rule

A physical scanner is owned by exactly one bridge process. Browser focus and an
interactive terminal are not operational dependencies. Precinct and canvassing
modes share framing and device ownership while retaining their separate canonical
validators.

## Security Rule

Services run without root privileges and receive only the filesystem and device
permissions they require. Public subscribers cannot publish presentation state.
Channel, station, precinct, election run, mapping, and revision boundaries are
validated independently.

## Evidence Rule

Operational health is useful evidence but does not replace election evidence.
Service starts, failures, recoveries, commissioning checks, and lifecycle gate
decisions are timestamped and journaled. A later operational failure does not
rewrite prior accepted ballots or prior commissioning facts.

## Operator Rule

Normal operation requires no remembered terminal commands. Powering on the
appliance starts the runtime. The officer UI explains readiness and degraded
states. A single administrative helper exists for trained support personnel.

## Change Test

A runtime change is acceptable only if it preserves all of the following:

- Canonical tallying works without Reverb.
- Scanner ingestion does not depend on browser focus.
- A missed notification is recoverable from persisted state.
- A public client cannot mutate operator or election state.
- Boot and deployment do not require an operator terminal.
- Failures are visible, bounded, journaled, and phase-appropriate.
- No service failure automatically closes polls or invalidates accepted ballots.
