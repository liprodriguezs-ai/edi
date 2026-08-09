# DELETE /api/charge_policy/{id} — guard de "facturación viva" + cascada

## 2026-07-25 — BUG (arreglado): ninguna política periódica se podía borrar JAMÁS

`ChargePolicyController::performDestroy` abortaba 422 si
`charge_batches()->exists() || charges()->exists()`. Como `ChargePolicyService::create`
SIEMPRE deja un lote PLANTILLA (`charge_batch.is_initial = true`, DRAFT, sin cargos) para
que el cron clone los períodos, `charge_batches()->exists()` era verdadero desde el
instante cero → el guard bloqueaba el 100% de las políticas periódicas. Segundo defecto:
no distinguía estado, así que lotes/cargos CANCELADOS contaban como facturación viva
(síntoma reportado: "el único cargo está cancelado y aun así 422").

### Regla nueva (implementada en `ChargePolicyService::delete`)

Bloquea SOLO si hay **facturación viva** = existe al menos un `charge` de la política
que NO está cubierto por un lote CANCELADO:

```php
$policy->charges()
    ->whereDoesntHave('batch', fn ($q) => $q->where('charge_batch_status_id', ChargeBatchStatus::CANCELLED))
    ->exists();
```

`whereDoesntHave` es deliberado: un cargo SIN lote (`charge.charge_batch_id` es nullable —
cobro directo/ad-hoc) cuenta como VIVO, porque no hay lote cancelado que pruebe lo
contrario. Mensaje: "Esta política ya generó cobros; desactívala en su lugar."

Cuando SÍ se permite borrar, cascada de **soft-delete** a los lotes de la política y sus
cargos (en ese punto sólo pueden ser la plantilla y lotes cancelados). Sin la cascada
quedaban lotes huérfanos apuntando a una política borrada, que además seguían apareciendo
en `ChargeBatchListPage` (esa lista NO filtra `is_initial`). La cascada es un mass update
(`deleted_at` + `user_deleted_id`): los eventos de modelo NO disparan en mass delete, por
eso la columna de auditoría que estamparía `GlobalObserver::deleted` se escribe a mano.

La lógica vive en el SERVICIO (como create/update); el controller sólo delega.

### DATO DE DOMINIO (verificado)

NO existe un `charge_status` "Cancelado". Los estados de cargo son Completo, Parcial,
Vencido, Por vencer, Saldo a favor, Pendiente, En convenio. La cancelación de un cargo es
la columna booleana `charge.is_cancelled` (+ `cancellation_reason`); "Cancelada" como
ESTADO existe sólo en `charge_batch_status` (Borrador / Activa / Cancelada). Un cargo se
ve "Cancelado" en la UI porque su LOTE está cancelado (`cancelBatch` marca
`is_cancelled = true` en cada cargo vivo del lote y pasa el lote a CANCELLED).

### Test

`backend/tests/Feature/Collections/ChargePolicyDeleteTest.php` (3 casos, HTTP):
plantilla sola → 200 + cascada; único lote CANCELADO con cargos → 200 + cascada; lote
activo con cargos → 422 y nada se toca. Con el guard viejo fallaban los 2 primeros.
Truco de fixture: `valid_from` FUTURO crea SOLO la plantilla (sin cargos) — es la forma
limpia de montar el escenario "cero facturación" sin tocar el servicio.

## Frontend: el 422 se tragaba en silencio

`useBaseCrud.onDelete` (`src/shared/hooks/useBaseCrud.ts`) tiene `try/finally` SIN catch y
**no muestra toast**, a diferencia de `onSave`/`onUpdate` que sí llaman
`formatApiErrorMessage` + `toast.error`. Y el interceptor de `apiClient.ts` sólo actúa en
401 (retry) y 403 (modal de permisos); todo lo demás sale por `rejectWithApiError` sin
toast. Por eso el comentario `// toast global lo maneja` en los `catch {}` vacíos de las
listas es FALSO: el error desaparecía y sólo se veía un re-render (el `finally` de
`putDeleting(false)`), que parecía "refrescó y no pasó nada".

Arreglado en `ChargePolicyAllListPage.tsx` y `ChargePolicyListPage.tsx`: catch tipado
(`error instanceof ApiError ? formatApiErrorMessage(error) : fallback`), `toast.error(title,
description)` (sileo), y `setToDelete(null)` movido al `finally` para que el diálogo de
confirmación cierre también en el camino de error (reintentar daría el mismo 422).

**Mismo `catch {}` vacío con el comentario falso en 6 pantallas de OTROS módulos** (no
tocadas): `tenant/TenantList.tsx:78`, `role/RoleList.tsx:67`,
`billingPackage/BillingPackageList.tsx:72`, `user/UserList.tsx:86`,
`collaborator/CollaboratorList.tsx:105`, `company/CompanyList.tsx:70`. El arreglo de raíz
sería agregar el toast a `useBaseCrud.onDelete` (paridad con onSave/onUpdate) y borrar los
catch vacíos — candidato a SDD, cambia el comportamiento de 8 pantallas.
