---
name: defectos-encontrados
description: Seis defectos reales de Kivo7 detectados al leer el código para documentar; están documentados con advertencia, no como funcionalidad operativa
metadata:
  type: discovery
---

# Defectos encontrados al documentar (2026-08-06)

Salieron de leer el código a fondo para escribir el manual. **Ninguno está
arreglado.** En las páginas correspondientes se documentaron con
`<Aside type="caution">` y su camino alterno, nunca como funcionalidad que sirve.

Si alguno se arregla, hay que quitar la advertencia de la página que lo menciona.

## 1. Subida del comprobante de un egreso

`ExpenseResource.uploadMedia` solo manda `file` en el FormData; el backend
(`ExpenseController::uploadMedia`) exige además
`'collection' => ['required','string','in:receipt,cfdi']`. Debería fallar con 422
siempre. La subida de CFDI tampoco tiene punto de entrada en la interfaz.

→ Documentado en `tesoreria/egresos.mdx`.

## 2. Cancelar un lote de cobro no tiene interfaz

`POST /charge-batch/{id}/cancel` existe y `ChargeBatchResource.cancelBatch()`
está definido, pero **ningún componente lo llama**. Consecuencia real para el
usuario: **activar un lote es irreversible desde la aplicación**.

→ Documentado en `cobranza/cobros-programados.mdx` y `cobranza/como-funciona.mdx`.

## 3. Motivo de cancelación de cargo, mínimos desalineados

Backend `ChargeController::cancel` exige `reason` de mínimo **10** caracteres; el
frontend solo valida que no esté vacío → 422 confuso. (El de condonación es
mínimo 5, y ese sí coincide.)

→ Documentado en `cobranza/cargos.mdx`, diciendo el mínimo real.

## 4. El permiso `change_owner.unit` no existe

No está en `backend/database/seeders/csv/permissions.csv`. El botón "Cambiar
propietario" está envuelto en `PermissionGuard permission='change_owner.unit'` y
`usePermissions` solo aprueba permisos sembrados → **el botón queda oculto para
todos los roles, incluido el Administrador**, y el endpoint respondería
Unauthorized.

→ Documentado en `estructura/cambio-de-propietario.mdx`, con el camino manual
(finalizar la asociación anterior + asociar al nuevo como titular de pago).

## 5. Código de error del límite de unidades desalineado

Frontend `UnitForm.tsx` busca `err.payload?.code === 'unit_limit_reached'`;
backend `UnitLimitExceededException` manda `'UNIT_LIMIT_EXCEEDED'`. El diálogo
"Alcanzaste el límite de tu plan" con su botón **Mejorar mi plan** probablemente
nunca se muestra. **El bloqueo del alta sí funciona correctamente**; lo que falla
es la presentación.

→ Documentado en `estructura/limite-de-unidades.mdx`.

## 6. El catálogo `unit_feature` no está conectado al formulario de unidad

`UnitForm` guarda `characteristics` como arreglo de **texto libre**, con cuatro
sugerencias escritas en el propio componente. La tabla puente `unit_unit_feature`
existe en la base pero la interfaz no la usa. Riesgo real: el alcance
**Por característica** de una `charge_policy` puede no encontrar las unidades
esperadas.

→ Documentado en `estructura/caracteristicas-de-unidad.mdx`, con las alternativas
que sí funcionan (por tipo de unidad, o selección manual).
