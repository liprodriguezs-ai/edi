# Module 10 — Access Control — Design Decisions

## access_log immutability

Access logs are security-critical records. The deliberate design decision is to provide NO
general update path via Orion (no `performUpdate`). Only two controlled mutations exist:
`checkout` (sets `exited_at`) and `addCorrection` (appends a timestamped note). Both are
custom action routes that hit specific service methods.

`addCorrection` does not replace the existing correction text — it appends with a ISO
timestamp prefix. This creates an auditable trail of corrections rather than a single
overwritten note.

## QR token format and uniqueness scope

The `XXXX-XXXX` format (4 random uppercase alphanumeric chars, dash, 4 more) is intentionally
short and human-readable for guard entry via keyboard. The uniqueness check is scoped to the
tenant (`WHERE qr_token = $token` runs inside the tenant context, so `TenantScope` filters
the query). The same token can exist in different tenants, which is correct behavior tested
explicitly.

A do-while loop retries on collision; with 36^8 ≈ 2.8 trillion possible tokens and typical
cluster sizes below 100,000 visitors, collision probability is negligible.

## Blacklist check is advisory, not blocking

`checkBlacklist` returns a boolean flag. The `scanQr` method includes this flag in the
response payload but does NOT return `valid=false` for a blacklist hit. The guard sees the
flag and decides whether to allow entry. This is a deliberate design choice: the blacklist is
a safety signal, not an automated barrier that could falsely deny access.

Case-insensitive comparison uses `LOWER()` in raw SQL, consistent with Postgres collation.

## Visitor status transitions

Status transitions are not mediated by a dedicated status service (contrast with
`ChargeStatusService`). The simpler state machine (pending → used/cancelled/expired) is handled
inline in the service methods. Expired status is only set lazily when the QR is scanned
after expiry — no cron refreshes statuses proactively.

## Manual entry and notification deferral

`AccessService.manualEntry` creates the access log. The code comment notes that dispatch to
unit residents should be done from the controller via the Notification module. This is deferred
to v1.1 to avoid the Notification module dependency in the core entry path.

## Access verification catalog

`access_verification` is a separate catalog (phone_call/video_call/in_person) that records
HOW the guard verified the visitor's identity for manual entries. This is required for
compliance audit trails in Mexican residential complexes.

## Accepted limitations / deferrals

- No proactive cron to flip `pending → expired` for past-expiry QRs (lazy expiry on scan only).
- No automatic notification to unit residents when a visitor enters (noted in `manualEntry`
  comment, deferred).
- Real-time guard dashboard (currently-inside list exists as `currentlyInside()` service method
  but no push/WebSocket delivery).
- Photo capture for visitor identity not implemented.
- Multi-entry passes (time_based / event QR types can be scanned multiple times — no
  per-scan log limit beyond the expiry date).
