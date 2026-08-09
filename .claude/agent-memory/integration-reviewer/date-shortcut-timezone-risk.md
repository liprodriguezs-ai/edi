---
name: date-shortcut-timezone-risk
description: resolveDateRange() uses browser-local Date + toISOString() (UTC); backend due_date/issued_at are plain DATE columns — timezone mismatch risk for shortcuts
metadata:
  type: project
---

`resolveDateRange()` (DateRangeFilter.tsx:146) resolves shortcuts like `overdue` and `this_month` using `new Date()` (browser timezone) then calls `.toISOString()` which always returns UTC. 

If the user is in UTC-6 (Mexico City, the app's primary locale) and it is before midnight UTC, `.toISOString()` returns the previous day. The backend columns `due_date` and `issued_at` are cast as plain `date` (no time component). PostgreSQL will compare the ISO datetime string against a DATE column, which may include or exclude boundary-day rows unexpectedly.

Example: `overdue` sets `to = new Date(today - 1ms).toISOString()` = yesterday at 23:59:59.999 UTC → backend sees `due_date <= '2026-06-12T23:59:59.999Z'`; for a Mexico City user (UTC-6) it is 2026-06-12 at 17:59 local, but charges due today (2026-06-13) could be included.

**Risk level:** Low in practice (DATE comparison in PG casts the datetime to date, discarding time, so boundary effects only appear at exact midnight UTC). But worth monitoring once the feature is in prod.

**Recommended fix:** Send plain ISO date strings (`YYYY-MM-DD`) from `resolveDateRange()` instead of full ISO datetimes when the column is a plain date.

**Why:** Discovered during charges-filters review.
**How to apply:** Flag this pattern whenever a DateRangeFilter feeds a backend `date` column (not `datetime`/`timestamp`).
