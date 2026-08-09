---
name: collections-schema-audit
description: Audit del esquema de BD del módulo Collections — FKs faltantes, índices críticos, scope inconsistency en charge_concept_category, comentarios faltantes
metadata:
  type: project
---

# Collections Schema Audit

Fecha: 2026-06-26. Read-only audit de 29 migraciones.

## CRITICALs confirmados

1. `charge.unit_id`, `payment.unit_id`, `credit_balance.unit_id` — indexados pero SIN FK constraint declarado en ninguno de los tres.
2. Falta índice compuesto `(tenant_id, charge_status_id, due_date)` en `charge` — el cron diario de transición de estados lo necesita.
3. `charge_concept_category` implementada como CENTRAL (`BaseModel::columns($table, false)`) pero la spec (CLAUDE.md §8, modules.md) la declara TENANT-SCOPED (una de las 14). Falta `tenant_id`, `unique(['tenant_id','name'])` y columna `key` para `HasCatalogKey`.
4. Comentarios de tabla en `charge_status`, `charge_batch_status`, `payment_status` dicen "Tenant-scoped" pero la implementación es correctamente CENTRAL (documentación errónea en sentido contrario al punto 3).

## WARNINGs clave

- `discount_reason` en `2026_06_02_075949_add_discount_to_charge_table.php:24` — sin `->comment()`
- Sin índice en: `charge_policy.periodicity_id`, `charge_policy.amount_mode_id`, `charge_concept.charge_concept_category_id`, `charge.charge_concept_id`, `charge.charge_policy_id`, `payment.bank_account_id`, `payment.paid_at`
- `receipt_sequence` y `credit_balance` usan `unique()` no-parcial con SoftDeletes — bloquearía re-inserción si alguna fila se soft-delete
- Migration `2026_06_13_000001` usa Eloquent (`Periodicity::whereIn`) para backfill — temporal coupling si el modelo cambia

**Why:** Este audit es baseline pre-feature para el módulo de cobranza más complejo del sistema.
**How to apply:** Cualquier migración nueva en Collections debe resolver C1-C3 y agregar el índice del cron antes de go-live.
