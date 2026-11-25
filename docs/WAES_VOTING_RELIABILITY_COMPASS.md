# WAES Voting Reliability Compass

## North Star

WAES preserves one consistent election record through concurrent requests, service failures, interrupted processes, and operator-guided recovery. Availability never takes precedence over preserving that record.

## Sources of Truth

Persisted election evidence and lifecycle state are authoritative. Service activity, HTTP responses, and notifications do not prove that a ballot was deposited or paper was printed.

## Concurrency and Recovery

Voting, printing, and closeout must respect a shared lifecycle boundary. A busy response may say an operation did not start only when acquisition failed before its callback began. An interrupted or uncertain operation requires reconciliation, not an automatic retry.

A single-host lock is valid only when all writers use the same local filesystem and locking protocol. Deployments changing lock protocols stop all writers before cutover. A lock does not make multiple file writes transactional.

## Physical Side Effects

A print request, CUPS submission, or generated PDF is not proof of paper output. Preserve the release and pending VVPAT state. Before retry, the operator must account for queued and printer-buffered copies. Never automatically duplicate a print or deposit a ballot.

## Diagnostics and Evidence

Record safe operation names and elapsed times without control numbers, selections, QR payloads, credentials, or secrets. Preserve prior evidence and pending work during diagnosis and recovery. Never reset election data to make a health check pass.

## Change Test

A change must preserve ballot identity, prevent overlapping state transitions, retain unresolved print state, and provide an actionable failure without claiming an uncertain action did not occur. Automated tests and physical operator checks establish different kinds of evidence.
