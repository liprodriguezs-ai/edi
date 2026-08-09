# Cobranza — cadenas literales de UI para el manual de usuario (2026-08-06)

Levantamiento COMPLETO de copy literal ES del clúster de cobranza (todo menos la
pantalla de Pagos, que ya vive en `payment-ui-strings.md`). Verificado contra disco
el 2026-08-06. Si algo cambia, corregir con nota fechada.

## Rutas + guards (frontend/src/App.tsx:662-703)
| Ruta | Página | Guard |
|---|---|---|
| `/charge-concept` | ChargeConceptListPage | `permissionEntity='charge_concept'` |
| `/charge-policy` | ChargePolicyAllListPage | `permissionEntity='charge_policy'` |
| `/charge-policy/new` | ChargeRuleFormPage (alta global) | `permission='create.charge_policy'` |
| `/charge-concept/:conceptId/policies` | ChargePolicyListPage | `charge_policy` |
| `/charge-concept/:conceptId/policy/new`, `/charge-policy/:id`, `/charge-policy/:id/edit` | ChargeRuleFormPage | `charge_policy` |
| `/charge-batch`, `/charge-batch/new`, `/charge-batch/:id` | Cobros programados | `charge_batch` |
| `/charge` | ChargeListPage | `charge` |
| `/payment`, `/delinquency` | lista de pagos / morosidad | `payment` |
| `/statement`, `/payment/:id` | estado de cuenta / detalle pago | `payment_application` |
| `/import` | ImportPage (importar pagos) | `data_import` (solo admin/owner/super) |

## Catálogos — etiquetas literales sembradas (database/seeders/CatalogSeeder.php)
- `charge_status` (224): Completo · Parcial · Vencido · Por vencer · Saldo a favor · Pendiente · En convenio. **NO existe "Cancelado"** (es la columna `charge.is_cancelled`; el pill "Cancelado" lo pinta el FE).
- `charge_batch_status` (246): Borrador · Activa · Cancelada.
- `amount_mode` (255): Fijo obligatorio · Fijo opcional · Rango · Libre · Por indiviso.
- `periodicity` (266): Mensual · Anual · Eventual · Cada 2 días (pruebas). (Bimestral/Semanal/Quincenal comentados.)
- `surcharge_mode` (294): Porcentaje · Monto fijo.
- `payment_method` (284): Efectivo · Transferencia · Depósito · Cheque.

## Roles (RolePermissionTableSeeder)
- **Administrador / account owner**: todo (whereNotIn superAdminExclusives), incl. `data_import`.
- **Presidente** (línea ~359): collections `read/create/update/report` (SIN delete) + `discount.charge` + `cancel.charge`.
- **Tesorero** (~404): collections CRUD completo (incl. delete de concepto/política/lote) pero **NUNCA** `cancel.charge` ni `discount.charge`.
- **Secretario / Vocal**: solo `read` de `charge`, `payment_application`, `credit_balance`.
- **Comité de vigilancia**: collections readOnly.
- **Residente / inquilino**: `read.charge`, `read.payment_application`, `read.credit_balance`, `create.payment_submission`.

## Gaps de requerimiento detectados (2026-08-06)
1. **Cancelar cargo — mínimo de motivo desalineado**: el backend exige `reason min:10`
   (`ChargeController::cancel`), el FE solo valida `trim().length > 0`
   (`ChargeListPage.tsx:623`) → 422 con motivo de 1-9 caracteres. Mismo patrón que el
   reject de pagos (min:5 no validado en FE).
2. **Cancelar LOTE no tiene UI**: existe `POST /charge-batch/{id}/cancel` (reason min:5)
   y `ChargeBatchResource.cancelBatch`, pero **ningún componente lo invoca**
   (grep 2026-08-06). El detalle del lote solo ofrece "Activar y generar cargos".
3. **Concepto de cobro no se puede borrar/desactivar desde la UI**: la lista solo tiene
   editar + ver programaciones; `is_active` es el único apagado (switch del form).
4. **`AmountModeSelector` ya no deja ELEGIR modo**: producto lo fijó a `FIXED_REQUIRED`.
   Rango / Por indiviso (prorrateo) solo hidratan políticas legacy → el manual NO debe
   documentar "elige el modo de monto" como paso de alta.
5. **Restricciones por morosidad NO bloquean cobranza**: solo amenidades, accesos de
   visita y recepción de paquetes. No hay bloqueo de chat, anuncios ni proyectos.
6. **`min_overdue_amount = 0` significa "cualquier vencido"** (no "sin restricción") —
   contra-intuitivo; el texto de ayuda lo aclara pero la semántica del 0 es una trampa.
7. **Solo `OVERDUE` restringe** — nunca `PARTIAL` (ver DelinquencyRestrictionService:20).

## Notas finas para el manual
- El ciclo de vida del cargo se pinta como 4 hitos: **Nace · Por vencer · Vence · Moroso**
  (`ChargeLifecycleTimeline.tsx:74-77`). El resumen colapsado dice "Nace … · Vence … · Moroso …".
- La vista previa del alcance dice literalmente `Vista previa: N unidades incluidas`,
  `Calculando…`, `Selecciona el alcance para ver la vista previa`, `No se pudo calcular el alcance`
  (`shared/components/target/TargetPreview.tsx:63-79`).
- El alcance visible en cobranza son 7 tarjetas: Todo el condominio · Por tipo de unidad ·
  Por torre · Por sección · Por característica · Con vehículos · Selección manual.
  `by_floor`, `single_unit` y `by_share` están OCULTOS (ramas vivas solo para legacy).
- Importación: la hoja se llama **PAGOS**, el template es `template-importacion-pagos.xlsx`,
  26 columnas (`TemplateGenerator::PAGOS_HEADERS_PUBLIC`), obligatorias 6:
  `tipo_unidad, numero, nombre, apellido_paterno, fecha_pago, monto`.
