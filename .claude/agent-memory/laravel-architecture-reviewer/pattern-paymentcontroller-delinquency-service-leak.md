---
name: pattern-paymentcontroller-delinquency-service-leak
description: PaymentController grew delinquencyRows()/validateDelinquencyFilters() as private controller methods instead of a Service, duplicating the effectiveExpected() debt formula a 3rd time (Collections module review)
metadata:
  type: pattern
---

Reviewed 2026-07-19: `PaymentController::delinquencyRows()` (~:508) and `validateDelinquencyFilters()` (~:454) — private controller methods doing multi-level aggregation (group by unit, filter by concept/search/months-overdue/amount range) shared between `delinquency()` (JSON dashboard) and `delinquencyReport()` (xlsx export).

**Violation**: laravel-backend-architecture skill Strict Rule §7 ("las reglas de negocio se declaran en la clase Service de cada módulo, no en el Controller"). The SAME module already has the exact precedent for this: `Modules/Collections/app/Services/CollectionsReportService.php` (injected via constructor DI into `CollectionsReportController`, method `aging()`) — a read-only aggregation service for a near-identical aging/delinquency report. The new filtered-delinquency logic should have extended/joined that service instead of living as private controller methods.

**Compounding DRY issue**: the "outstanding" formula (`max(0, max(0, expected-discount)+surcharge-paid)`) is now hand-rolled via `getAttributes()` in THREE places: `ChargeStatusService::effectiveExpected()` (the canonical source — `max(0,expected-discount)+surcharge`, i.e. everything except the `-paid` part), `PaymentController::cachedStatementAggregate()` (pre-existing), and `PaymentController::delinquencyRows()` (new, this diff). None of the three call `effectiveExpected()` — they all reimplement it inline with a comment promising "parity". A trivial extraction (`app(ChargeStatusService::class)->effectiveExpected($charge) - paid`) would remove the duplication risk entirely; it's pure arithmetic on already-loaded attributes, no extra queries.

**Verified NOT violations in this diff**: validation (`validateDelinquencyFilters`) is properly tenant-scoped + soft-delete-aware (`Rule::exists(...)->where('tenant_id', tenant('id'))->whereNull('deleted_at')`), consistent array-style rules matching sibling `register()`/`submit()` methods; route registration (`Route::get('delinquency', ...)` / `Route::get('delinquency/report', ...)`) correctly avoids the Orion `GET /{resource}/search` 500 trap (laravel-backend-architecture §2.11) since these are static custom-action routes, not `/search`; 8 feature tests in `DelinquencyFilterTest.php` cover search/concept/months/amount-range filters, composition, 422 validation (incl. cross-tenant concept id), and export-filter-parity — good coverage, no gaps found.

See also [[pattern-report-controller-export-hardening]] for another Collections report duplication finding (filter-whitelist-from-getFillable, lazy()+orderBy tie-break bug) — same module keeps producing report/export endpoints with logic living outside the Service layer.
