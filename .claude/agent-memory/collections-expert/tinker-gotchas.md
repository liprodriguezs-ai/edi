---
name: tinker-gotchas
description: Gotchas running one-off maintenance scripts through php artisan tinker on the dev DB
metadata:
  type: discovery
---

# Tinker one-off gotchas (2026-07-03)

- `php artisan tinker < script.php` evaluates the buffer in the REPL. Top-level `use Foo\Bar;`
  statements FATAL with "Cannot use ... as X because the name is already in use" when tinker's context
  already imports that short name (e.g. `Tenant`). FIX: drop all `use` lines and reference classes
  fully-qualified (`\Modules\Collections\Models\ChargeConcept::...`) in tinker one-offs.
- Tinker echoes each expression result (noisy). For a clean report, write results to a temp file
  (`file_put_contents('/tmp/x.json', json_encode(...))`) and cat that file afterwards.
- Tinker hits the DEFAULT connection = the DEV database (`.env` DB), NOT the `testing` DB. That is the
  right target for pruning/backfilling existing dev tenants.
- Per-tenant maintenance: `foreach (\Modules\Tenant\Models\Tenant::all() as $t) { $t->run(fn() => ...); }`
  — `$t->run($closure)` initializes tenancy (row-level scope) and RETURNS the closure's return value.
