# Module 10 — Access Control — Tasks

## Status: COMPLETE (green gate passing)

### Migrations
- [x] `visitor_motive` (tenant-scoped, key/name/color/order/is_active)
- [x] `visitor_status` (tenant-scoped, key/name/color/order/is_active)
- [x] `qr_type` (tenant-scoped, key/name/order/is_active)
- [x] `access_entry_type` (tenant-scoped, key/name/order/is_active)
- [x] `access_verification` (tenant-scoped, key/name/order/is_active)
- [x] `blacklist_type` (tenant-scoped, key/name/order/is_active)
- [x] `visitor` (tenant-scoped; qr_token unique per tenant; qr_expires_at datetime; arrives_by_vehicle boolean)
- [x] `access_log` (tenant-scoped; visitor_id nullable FK; entered_at/exited_at datetimes; correction text nullable)
- [x] `blacklist_entry` (tenant-scoped; value string; is_active boolean)

### Models
- [x] `VisitorMotive` (KEY_SOCIAL, KEY_SERVICE, KEY_DELIVERY, KEY_MAINTENANCE, KEY_OTHER)
- [x] `VisitorStatus` (KEY_PENDING, KEY_USED, KEY_CANCELLED, KEY_EXPIRED)
- [x] `QrType` (KEY_SINGLE_ENTRY, KEY_TIME_BASED, KEY_EVENT)
- [x] `AccessEntryType` (KEY_QR, KEY_MANUAL)
- [x] `AccessVerification` (KEY_PHONE_CALL, KEY_VIDEO_CALL, KEY_IN_PERSON)
- [x] `BlacklistType` (KEY_VISITOR, KEY_VEHICLE)
- [x] `Visitor` (BelongsToTenant; casts arrives_by_vehicle→boolean, qr_expires_at→datetime;
      relations unit/createdByUser(withoutGlobalScope)/motive/qrType/status/accessLogs)
- [x] `AccessLog` (BelongsToTenant; casts entered_at/exited_at→datetime;
      relations visitor/unit/entryType/verification/guardUser(withoutGlobalScope))
- [x] `BlacklistEntry` (BelongsToTenant; is_active boolean)

### Services
- [x] `AccessService.generateQr` (unique XXXX-XXXX token; expiry by QrType; creates Visitor pending)
- [x] `AccessService.scanQr` (validates status/expiry/blacklist; creates AccessLog; flips single_entry to used)
- [x] `AccessService.manualEntry` (creates AccessLog with visitor_id=null, entry_type=manual)
- [x] `AccessService.checkout` (sets exited_at)
- [x] `AccessService.addCorrection` (appends timestamped note; accumulative)
- [x] `AccessService.cancelQr` (pending → cancelled; no-op otherwise)
- [x] `AccessService.checkBlacklist` (case-insensitive LOWER() match on name/plate)
- [x] `AccessService.currentlyInside` (read-only; whereNull exited_at)
- [x] `AccessService.resolveExpiry` (per QrType key; single_entry→endOfDay; time_based→+24h; event→provided or null)
- [x] `AccessService.generateUniqueToken` (do-while uniqueness check)

### Controllers + Routes
- [x] Orion resources: `visitor_motive`, `visitor_status`, `qr_type`, `access_entry_type`,
      `access_verification`, `blacklist_type`, `visitor`, `access_log`, `blacklist_entry`
      (all with withSoftDeletes + auth:sanctum + initializeTenancy)
- [x] Custom actions: `POST access/scan-qr`, `POST access/manual-entry`,
      `POST access/{id}/checkout`, `POST access/{id}/correction`,
      `POST visitor/{id}/cancel`, `GET visitor/mine`

### Seeding + Menu
- [x] `TenantDatabaseSeeder` seeds all 6 catalogs per tenant
- [x] `modules.csv`, `permissions.csv`, `module_system.csv` rows added
- [x] `SystemTableSeeder`: permission entities, routes, icons, hidden catalog entries
- [x] `RolePermissionTableSeeder`: access entities in `$commonPermissions`

### Tests (AccessModuleTest.php — 12 tests)
- [x] A. Catalog seeding (5/4/3/2/3/2 per catalog per tenant)
- [x] B. generateQr creates visitor with unique 9-char token and expiry for single_entry
- [x] C. 5 consecutive generateQr calls produce unique tokens
- [x] D. scanQr single_entry: valid=true, access_log created, status flips to used
- [x] E. Second scan of used QR: valid=false, reason "usado"
- [x] F. Scan nonexistent token: valid=false
- [x] G. access_log immutability: checkout sets exited_at; addCorrection appends twice
- [x] H. Blacklist match flagged on scan (does not block)
- [x] I. Manual entry creates access_log with visitor_id=null, entry_type=manual
- [x] J. cancelQr sets cancelled status; subsequent scan returns valid=false
- [x] K. Visitor from tenant A not visible in tenant B
- [x] L. Same QR token allowed in different tenants (unique is tenant-scoped)

### Deferred
- [ ] Proactive cron to expire pending QRs past their expiry date
- [ ] Notification to unit residents on visitor entry (noted in manualEntry comment)
- [ ] Real-time guard dashboard (WebSocket / push for visitor arrival)
- [ ] Per-scan log for multi-entry (time_based/event) QRs
- [ ] Photo capture for visitor identity
