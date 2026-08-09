# Module 4 — Collections (Cobranza y Estado de Cuenta)

## Intent

Implement the hardest module of the Kivo7 platform: residential-complex billing and
account statements. The module generates charges per unit, registers payments with full
flexibility (any amount accepted), applies payments FIFO, tracks credit (saldo a favor),
and exposes per-unit account statements plus a delinquency dashboard.

## Scope

New tenant-scoped module `Collections` (`backend/Modules/Collections`):

- **Catalogs** (tenant-scoped, FK-referenced, seeded per tenant): `charge_status`,
  `payment_status`, `charge_batch_status`, `charge_concept_category`, `amount_mode`,
  `periodicity`, `payment_method`.
- **Entities**: `charge_concept`, `charge_batch`, `charge`, `payment`,
  `payment_application`, `credit_balance`.
- **Services**: `TargetResolver`, `AmountCalculator`, `ChargeGenerationService`,
  `ChargeStatusService`, `PaymentService`.
- **Endpoints**: Orion resources for all tables + custom actions (activate batch,
  preview targets, register payment, cancel payment, cancel charge, unit statement,
  delinquency dashboard).
- **Cron**: `collections:generate-monthly`, `collections:refresh-statuses` (per tenant).
- Menu/permission registration, per-tenant seeding, integration tests (4 spec flows).

Touches Housing `Unit` only to add/maintain the aggregate `unit.charge_status_id`.

## Approach

- Money is `bigInteger` minor units (centavos) + `Cknow\Money\Casts\MoneyIntegerCast`
  everywhere. Never decimal/float.
- Enumerations are tenant-scoped catalog tables with string `key` constants (NOT PHP
  enums, NOT shared UUIDs). The Estados Visuales Globales are STORED (charge_status etc.),
  not computed-on-read.
- Charge status is STORED and maintained transactionally by `ChargeStatusService` on
  every mutation (generate, pay, cancel, cron). Unit aggregate uses worst-wins.
- FIFO: credit consumed first, then open charges by due_date ASC, issued_at ASC, id ASC;
  remainder → credit.
- All money mutations use locked read-modify-write (lockForUpdate) — NOT `DB::raw`
  Expressions, which cannot pass through MoneyIntegerCast.

## Out of scope (v1)

- Resident self-submitted transfers + tesorero validation flow (pending_validation seeded
  but no dedicated flow).
- Accounting-month close / undo-after-close guard (undo allowed while payment not cancelled).
- payment_plan / en_convenio status (seeded as key, no flow — v1.2).
- PDF receipt generation, push notifications (cross-module, deferred).
