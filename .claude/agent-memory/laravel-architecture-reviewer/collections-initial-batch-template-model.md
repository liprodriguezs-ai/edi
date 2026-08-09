---
name: collections-initial-batch-template-model
description: Review del modelo "lote inicial = plantilla DRAFT sin cargos + clon 1er período" en Collections (2026-06-26). Invariante de plantilla y gap del guard de activación.
metadata:
  type: project
---

## Contexto (continúa [[collections-slice-a-charge-policy]])

Cambio de modelo de generación: el lote `is_initial=true` dejó de ser el 1er período
con cargos y pasó a ser una PLANTILLA PURA (DRAFT, `month=null`, SIN cargos). El 1er
período real se materializa como CLON (`is_initial=false`, ACTIVE, con cargos) en
`ChargePolicyService::create` → `ChargeGenerationService::generatePeriodicInitial`.
El cron clona la plantilla para los períodos 2,3,… Eventual = lote único
`is_initial=false` activado, sin plantilla.

## Invariante central
La plantilla (`is_initial=true`) debe quedar SIEMPRE DRAFT y SIN cargos. Los cargos
nacen SOLO en lotes activados (clones/eventual), nunca en la plantilla.

## Hallazgos

### CRITICAL — `activateBatch` no tiene guard `is_initial`
`ChargeGenerationService::activateBatch` (chokepoint único de generación) NO rechaza
plantillas. La ruta `POST /charge-batch/{id}/activate` (ChargeBatchController::activate)
hace `findOrFail($id)` + `activateBatch()` sobre CUALQUIER id. Activar la plantilla la
deja ACTIVE con cargos y `month=null`, lo que ADEMÁS la mete en `generateForNewUnit`
(branch `whereNull('month')`) → cada unidad nueva se cobra contra la plantilla para
siempre. El store NO deja setear `is_initial` (no está en el `Arr::only`), así que el
único vector es activar una plantilla existente. Fix: `if ($batch->is_initial) throw`
al tope de `activateBatch`.

### WARNING — el index de Orion no oculta la plantilla
`ChargeBatchController` no tiene scope/override que excluya `is_initial=true`. El
listado depende de que el FRONT filtre `is_initial=false` ("COBROS GENERADOS"). Robusto:
default scope o `buildIndexFetchQuery`/`buildListQuery` que excluya plantillas.

### WARNING — `valid_from` futuro materializa el 1er período igual
`ChargePolicyService::create` materializa+activa el 1er período sin chequear que
`valid_from <= today`. El request permite `valid_from` futuro (solo `date`). El cron sí
gatea por `whereDate('valid_from','<=',today)` → asimetría: el create crea cargos
fechados a futuro (o backfill de un período antiguo) que el cron no haría aún.

## Validado (CLEAN)
- Índices: plantilla `month=null` se excluye de `charge_batch_period_unique`
  (WHERE month IS NOT NULL). `charge_batch_initial_unique` (charge_policy_id WHERE
  is_initial=true) garantiza 1 plantilla por policy / 0 para eventual. Sin colisión.
- Cron idempotente: 1er período creado por create() → el cron choca con
  UniqueConstraintViolation y reactiva; 2º+ se clonan. Cubierto por
  `test_periodic_service_creates_first_period_then_cron_adds_second_without_duplicating`.
- El cron clona (no toca) la plantilla; nunca le genera cargos.
- Reportes (`ReportService::collectionProgress`) y PaymentService/ChargeService acceden
  batches por id desde un charge → nunca la plantilla (0 cargos). Seguro.
- Fixture `GenerateMonthlyChargesCommandTest::makeConcept` arma la plantilla con
  `month=1` (off-test 2019-01) en vez de `month=null` real — funciona pero no reproduce
  la plantilla de producción. SUGGESTION: alinear a month=null.
