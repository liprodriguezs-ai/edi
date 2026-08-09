# Module 10 — Access Control (Control de Accesos)

## Intent

Implement a visitor access-control system for gated residential clusters. The module handles
the full visitor lifecycle: QR pass generation by residents, QR scanning by guards, manual
entry without a QR, exit checkout, blacklist checks, and cancellation. Access logs are
immutable records with controlled mutation paths (checkout and correction only).

## Scope

New tenant-scoped module `Access` (`backend/Modules/Access`):

- **Catalogs** (tenant-scoped, string-key, seeded per tenant): `visitor_motive`
  (5 keys: social/service/delivery/maintenance/other), `visitor_status`
  (4 keys: pending/used/cancelled/expired), `qr_type` (3 keys: single_entry/time_based/event),
  `access_entry_type` (2 keys: qr/manual), `access_verification` (3 keys:
  phone_call/video_call/in_person), `blacklist_type` (2 keys: visitor/vehicle).
- **Entities**: `visitor`, `access_log` (append-only), `blacklist_entry`.
- **Services**: `AccessService` (generateQr/scanQr/manualEntry/checkout/addCorrection/
  cancelQr/checkBlacklist/currentlyInside; QR token generation).
- **Endpoints**: Orion CRUD for all catalogs + main entities; custom actions
  (`scan-qr`, `manual-entry`, `access/{id}/checkout`, `access/{id}/correction`,
  `visitor/{id}/cancel`, `visitor/mine`).
- Menu/permission registration, per-tenant seeding, integration tests (10 scenarios).

## Approach

- `access_log` is effectively append-only: only `checkout` (sets `exited_at`) and
  `addCorrection` (appends timestamped note to `correction` text) are allowed mutations.
  There is no general update endpoint for access_log.
- QR tokens are generated as `XXXX-XXXX` (8 random uppercase chars with a dash, 9 chars total)
  and unique within the tenant via a do-while loop.
- `single_entry` QR: expires at end of day; flips visitor_status to `used` on first scan.
- `time_based` QR: expires at provided `qr_expires_at` (or now +24h if not provided).
- `event` QR: expires at provided `qr_expires_at` (no default expiry).
- Blacklist check runs on every QR scan and returns a `blacklist_match` flag (does NOT block
  entry; it is advisory — the guard decides).

## Out of Scope (v1)

- Resident mobile app QR scanner interface.
- Real-time guard dashboard (WebSocket updates when a visitor arrives).
- OCR / plate recognition for automatic vehicle verification.
- Integration with physical access hardware (turnstile, barrier gate).
- Photo capture for visitor identity verification.
