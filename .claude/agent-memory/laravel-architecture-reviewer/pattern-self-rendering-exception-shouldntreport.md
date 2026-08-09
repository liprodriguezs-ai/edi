---
name: pattern-self-rendering-exception-shouldntreport
description: This project's convention for domain exceptions is catch-at-call-site (never self-render); a self-rendering exception that skips this silently gets logged as an error unless it implements ShouldntReport.
metadata:
  type: project
---

Every domain exception that existed BEFORE `delinquency-restrictions` in this codebase (`ChatAccessDeniedException`, `ChatRateLimitException`, `Modules/Chat/app/Exceptions/`; `CheckoutUnavailableException`, `Modules/SubscriptionPlan/app/Exceptions/`) is a **plain exception with no `render()`**, caught EXPLICITLY at every call site: `catch (X $e) { abort(403, $e->getMessage()); }` or `response()->json([...], code)`. `abort()` throws Symfony's `HttpException`, which is in Laravel's `Handler::$internalDontReport` (`vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php:157-171`) — so it is never logged as an application error.

`DelinquencyRestrictedException` (`Modules/Collections/app/Exceptions/DelinquencyRestrictedException.php`) broke this pattern: it's a plain `Exception` with a self-`render(): JsonResponse` method, never caught at any of its 3 call sites (`ReservationService`, `AccessService`/`VisitorController`, `PackageService`). Laravel's handler DOES support self-rendering exceptions natively (`method_exists($e, 'render')`), so it works — but a plain `Exception` that isn't `HttpException` and doesn't implement `Illuminate\Contracts\Debug\ShouldntReport` (`vendor/laravel/framework/src/Illuminate/Contracts/Debug/ShouldntReport.php` — confirmed present, Laravel 13/v11+) WILL be passed to `report()` on every occurrence — i.e. every normal, expected 403 gets logged as an ERROR and sent to Nightwatch (installed in this project) as if it were a bug.

**Review rule going forward:** any self-rendering domain exception (`render()` method present, no catch site) MUST implement `ShouldntReport` (or override `report(): bool { return false; }`) unless the case is genuinely exceptional (i.e. should alert). Flag CRITICAL if missing — it's a silent production-observability bug that only shows up once the feature is live.

Self-render + named-constructor pattern (`::forResident()`, `::forGuard()`) itself is fine and arguably better than 3x duplicated payload-building at each catch site — the fix is `ShouldntReport`, not reverting to catch-at-call-site.

See also [[pattern-report-controller-export-hardening]] for other exception-handling review history in this codebase.
