---
name: pattern-octane-job-killswitch
description: Anti-pattern recurrente en jobs Kivo7 — kill-switch de estado process-global + tries=1 sin failed() en workers Octane/RoadRunner long-lived
metadata:
  type: pattern
---

Anti-patrón a flaggear en CUALQUIER job de este backend que mute estado de proceso (config(), Telescope, query log) y/o dependa de un finalizer para el estado terminal.

**Contexto del proyecto**: server es RoadRunner via Octane (`config/octane.php` → `OCTANE_SERVER=roadrunner`), queue default `database` (`config/queue.php`). Los workers son LONG-LIVED → el proceso se reutiliza entre jobs.

**El anti-patrón** (visto en Modules/DataImport: RunImportJob, ProcessImportChunkJob, FinalizeImportJob, trait SilencesInstrumentation):
1. El job silencia instrumentación con `config(['logging.query.except' => ['*'], ...])` + `Telescope::stopRecording()` y promete restaurar en `finally`.
2. `tries = 1` y NINGÚN `failed(Throwable $e)` hook.

**Por qué rompe**: el `finally` de PHP NO corre en (a) fatal error / OOM, ni (b) `$timeout` de queue (implementado con SIGALRM que mata el proceso). En esos paths:
- El estado process-global (config/Telescope) queda envenenado → silencia instrumentación de jobs ajenos en el MISMO worker.
- El run/entidad queda sin estado terminal (atascado en processing/pending) porque el finalizer/job murió y no hay `failed()` que escriba el estado.

**Fix canónico a exigir**:
- Añadir `failed(Throwable $e)` que escriba estado terminal (status=failed) bajo lock — fuente única de verdad cuando el job muere duro.
- Restaurar instrumentación de forma idempotente fuera del `finally` por-tick (p.ej. listener `Queue::after`/`Queue::failing`), o aislar la queue con worker dedicado + `--max-jobs` bajo para que el envenenamiento no toque otras queues.
- Finalizers/jobs reintenta­bles deben ser idempotentes: guarda de entrada que retorne si el estado YA es terminal (no solo ==failed), porque strip de accumulators interinos (ej. bank_deltas) en la 1ra corrida hace que la 2da pierda datos.

**How to apply**: cuando revises un job nuevo, preguntá: ¿muta estado de proceso? ¿hay `failed()`? ¿`tries=1`? ¿el estado terminal depende SOLO de un `->finally` de Bus::batch? Si sí a las primeras y no a `failed()` → CRITICAL.

---

**Variante más suave (WARNING, no CRITICAL) — `failed()` existe pero NO persiste el estado terminal** (visto en `Modules/Tracking/app/Jobs/DeliverTrackingEventJob.php`, revisión 2026-07-01): el job SÍ tiene `tries=3` + `backoff()` + un método `failed(Throwable $e)`, y el `catch` dentro de `handle()` SÍ escribe el estado terminal (`recordOutcome(STATUS_FAILED, ...)`) en el camino normal de excepción-capturada — pero `failed()` solo hace `report($e)`, sin volver a llamar al mismo método de persistencia. Funciona bien mientras el job muera por una excepción normal (el `catch` ya dejó la fila en `failed` antes de que el framework invoque `failed()`), pero en el escenario que este pattern describe arriba — proceso matado en seco por timeout/OOM a mitad del `Http::post()`, sin pasar por el `catch` — la fila de auditoría queda en `queued` para siempre, porque `failed()` no re-persiste.
**Fix**: `failed()` debe llamar EXPLÍCITAMENTE al mismo método de persistencia que usa el `catch` (ej. `$this->recordOutcome(self::STATUS_FAILED, $e->getMessage())`), no solo `report($e)`. Regla general: si un job escribe estado intermedio en un método privado (`recordOutcome`/similar) desde el `catch` de `handle()`, ese MISMO método debe invocarse también desde `failed()` — nunca asumir que el `catch` normal cubre todos los caminos de muerte del job.
