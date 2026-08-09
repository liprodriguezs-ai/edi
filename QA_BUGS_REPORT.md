# Reporte QA — Kivo7/Residia (panel web) — 2026-06-12

Entorno: frontend http://localhost:5174 · backend http://127.0.0.1:8000/api · tenant `privada_los_encinos`
Usuarios: `<rol>@encinos.test` / `123abc` · superadmin `superadmin@company.web` / `123abc`

## Leyenda severidad
- 🔴 BLOCKER: rompe flujo crítico / página caída / pérdida de datos / fuga entre roles
- 🟠 MAJOR: funcionalidad importante rota o validación faltante
- 🟡 MINOR: UX/cosmético/edge case no crítico

---

## BUGS


## ✅ CAPA API — Permisos y aislamiento (VERIFICADO, sin fuga)

Método: login real por API de los 12 usuarios → matriz `POST /{recurso}/search` por rol + comparación de conteos (`meta.total`) y referencias de datos.

**Resultado: el modelo de autorización es sólido.**
- `authorize()` (QCH Core `TraitController`) valida `tokenCan({accion}.{recurso})`; el login mete como abilities del token Sanctum los permisos reales del rol (`getPermissionsViaRoles`).
- Roles bajos reciben **403** correctamente en recursos sensibles: resident/renter NO pueden listar `payment`, `user`, `bank_account`, `expense`, `provider`, `charge_batch`, `collaborator`, `company`, `audit`.
- Row-scoping por unidad VERIFICADO en: `unit`, `charge`, `credit_balance`, `payment_application`, `vehicle`, `unit_user` (trait `ScopesToResidentUnits`). Ej: resident ve 1 unidad (la suya) de 5; 5 cargos de 13; sus 8 payment_applications referencian solo cargos visibles por él. renter ve 0 (sin unidad asignada en el escenario).
- Info pública compartida correctamente: `announcement` (2/2 todos), `board_member` (3/3 todos).

> NOTA METODOLÓGICA: un primer probe con tokens acuñados vía `createToken('qa')` dio falsos 200 en todo porque esos tokens llevan ability `*`. Se descartó re-probando con tokens de **login real**. No es bug.

## 🟡 Hallazgos menores capa API (a confirmar)

- **MINOR** `afterSearch` en `core/src/Http/Controllers/Controller.php` inyecta `total_records_database = Model::count()` SIN scope de tenant → expone el conteo global (cross-tenant) de cada tabla en la metadata de toda respuesta `/search`. Revisar si debe respetar el TenantScope.
- **MINOR** `POST /{recurso}/search?limit=500` devuelve **500** (excede `maxLimit` de Orion). Debería ser 422, no 500. Riesgo bajo (el front no suele pedir > maxLimit) pero es un crash ante input de usuario.

## ✅ Tests automatizados backend (php artisan test)
En ejecución; hasta ahora TODO verde (Notifications, FanOut por tenant, etc.). Resultado final pendiente.

## ✅ CAPA UI — Barrido administrator (sesión real navegador)
Páginas verificadas (render OK, sin errores de consola/boundary): dashboard, unit, statement, delinquency, payment (5 pagos $1,450), announcement (2), charge-batch (8), expense (1), provider (1), community-project (1), configuration (tabs General/Operación/Cobranza/Plantillas), access (control en vivo), reports (Morosidad/Ingresos-egresos con PDF/Excel).
- NOTA: el health-check inicial dio falsos "0 filas/vacío" por leer ANTES de cargar datos (async). Re-verificado con espera → todo OK. Dashboard "Pagos recientes" e "Ingresos del mes $1,450" consistentes con los 5 pagos.

## ✅ CAPA UI — Menús por rol (GET /api/menu con token real)
| Rol | Grupos | Items |
|---|---|---|
| superadmin | Core | 7 |
| admin / administrator | Fraccionamiento, Tesorería | 17 |
| president | Fraccionamiento, Tesorería | 15 |
| treasurer | Fraccionamiento, Tesorería | 14 |
| oversight | Fraccionamiento, Tesorería | 12 |
| secretary | Fraccionamiento | 7 |
| resident / renter | Fraccionamiento | 7 |
| vocal | Fraccionamiento | 6 |
| security | Fraccionamiento | 6 |
| operations | Fraccionamiento | 4 |
Menús generados server-side desde permisos del rol → coherentes. Sin items huérfanos detectados a nivel de estructura.

## 🟢 TESTS AUTOMATIZADOS BACKEND — RESULTADO FINAL
**777 passed (2894 assertions) · 0 fallos · 23min.**
Cubre: E2E ClusterLifecycle, RolePermissionBoundary, validaciones (RFC/teléfonos/email duplicado), login (bloqueo tras 5 intentos), notificaciones multi-canal y fan-out por tenant, catálogos, tesorería, cobranza, etc. → backend en estado sólido para release.

## ✅ CAPA UI — Formularios y validaciones
- **Registrar pago** (modal): valida correctamente en submit vacío → "La unidad es requerida", "El monto debe ser mayor a 0", "La cuenta destino es requerida". No envía datos inválidos.
- **Nueva unidad** (modal): valida correctamente (confirmado por screenshot) → "El tipo es requerido", "El número es requerido", "El estado es requerido". Placeholders de ejemplo (23/120/180/1.30) no se confunden con valores.

## ✅ CAPA UI — Límites de permisos (sesión REAL resident@encinos.test)
- Login resident → redirige a `/my-unit` (landing correcto).
- Menú correctamente limitado: Dashboard, Mi Unidad, Estado de Cuenta, Avisos, Documentos, Mi bitácora de accesos. SIN finanzas/admin/lista global.
- Boundary OK: `/expense` → **/acceso-denegado** ("Zona protegida"); `/user` → **/acceso-denegado**. Cero datos filtrados.
- `/unit` (lista global) accesible por URL directa (comparte `permission_entity=unit` con `/my-unit`) PERO muestra solo SU unidad (Casa 1), SIN botón "Nueva unidad" ni Editar/Eliminar. Sin fuga (scoping backend). Nota UX menor: la ruta es alcanzable por URL aunque no esté en su menú.

## 🟡 Corrección de hallazgo previo
- `total_records_database`: NO aparece en respuestas de recursos scopeados (charge devuelve solo data/links/meta con meta.total scopeado=5). El residente recibe conteos filtrados. Queda como observación de code-review de baja prioridad (revisar que el inyector del Controller base respete TenantScope en recursos donde sí aplique), no es fuga confirmada.

---

## RESUMEN EJECUTIVO

**Veredicto: NO se encontraron bugs BLOCKER ni MAJOR en permisos, aislamiento de datos, render de páginas, validación de formularios ni en el backend.**

Cobertura realizada:
- ✅ 12 usuarios (1 por rol) — login real verificado
- ✅ Matriz de permisos API por rol × ~26 recursos (autorización por abilities de token, correcta)
- ✅ Aislamiento de datos / row-scoping verificado (resident/renter NO ven datos ajenos)
- ✅ Menús por rol (coherentes, generados desde permisos)
- ✅ Render UI: ~16 páginas del administrator sin errores de consola/boundary
- ✅ Validaciones de formularios (pago, unidad)
- ✅ Límites de permisos en UI (resident bloqueado en rutas prohibidas)
- ✅ 777 tests automatizados backend (2894 assertions) — TODOS PASAN

Hallazgos (solo MENORES):
1. 🟡 `POST /{recurso}/search?limit=500` → 500 en vez de 422 (robustez ante input).
2. 🟡 `total_records_database` (code-review, baja prioridad).
3. 🟢 `/unit` alcanzable por URL para resident (read-only, scopeado) sin estar en su menú — nota UX.

### ⚠️ COBERTURA PENDIENTE (no exhaustivo — recomendado antes de liberar)
NO se probó a profundidad (por alcance/tiempo): creación/edición/borrado real end-to-end de cada módulo; páginas de catálogos (CRUD); generación de reportes PDF/Excel; subida de archivos/comprobantes; chat en tiempo real; flujos de QR/visitantes y control de accesos (scan/checkout); flujos profundos de treasurer/president/secretary/oversight/security/operations; cancelación de pagos; creación de lotes de cobro (charge-batch) con prorrateo; proyectos comunitarios (contribuciones). El backend de estos SÍ está cubierto por los 777 tests, pero la UI no se ejerció clic-a-clic.

---
# FASE 2 — Flujos interactivos (todos los roles)

## FLUJO A — Dinero (treasurer@encinos.test)
### 🟠 MAJOR — La lista de Pagos NO se refresca tras registrar un pago
- Pasos: /payment → "Registrar pago" → Casa 2, $250, Efectivo, Caja efectivo, FIFO → "Registrar pago".
- Backend OK: `POST /api/payment/register` → **201 Created** (con vista previa FIFO).
- BUG: tras el 201, la tabla sigue mostrando las filas viejas (5), NO aparece el pago nuevo hasta **recargar** la página (entonces sí salen 7).
- IMPACTO REAL: como no se ve el pago, el usuario cree que falló y vuelve a registrar → **pago DUPLICADO**. Lo reproduje sin querer: se crearon 2 pagos idénticos (Casa 2 $250) = R-2026-000007 y 000008. **Riesgo de doble cobro en módulo financiero.**
- Recomendación: invalidar/refetch la query de pagos (TanStack Query `invalidateQueries`) en el onSuccess del registro.

### ✅ Cancelar pago — correcto
- Requiere "Motivo de la cancelación" (mín. 5 caracteres); botón confirmar deshabilitado hasta llenarlo.
- Revierte aplicaciones a cargos + saldo de cuenta; queda en auditoría; status → Cancelado. La fila visible SÍ se actualiza tras cancelar.
- (Limpieza QA: cancelé 007 y 008, los 2 duplicados de prueba.)

### 🟡 VERIFICAR — Hueco en folios de recibo
- Secuencia actual: R-2026-000001..05, 000007, 000008 → **falta 000006**. 
- Los folios de recibos de pago deberían ser CONSECUTIVOS (requisito fiscal/auditoría MX). Un intento de registro fallido/rollback parece consumir el folio sin liberarlo. VERIFICAR generación de `receipt_number` (¿se asigna antes del commit?). Pudo originarse en un intento fallido durante la prueba.

### ✅ Validación de registro de pago (revalidado): unidad/monto>0/cuenta requeridos; vista previa FIFO calcula bien.

## FLUJO A (cont.) — Crear lote de cobro / charge-batch (treasurer)
### ✅ Asistente "Nuevo cobro" — bien construido
- Campos: Concepto, Año, Mes, Título (autocompleta), Descripción, Alcance flexible (Todo el clúster / por tipo / torre / piso / selección manual / característica / una unidad), Fecha generación, Vencimiento, Monto personalizado, Estado inicial (Activo / Borrador).
- Validación correcta: "Vencimiento es requerido" bloquea submit (confirmado por screenshot). Vista previa de alcance OK ("3 unidades incluidas" para Todo el clúster).

### 🟠 MAJOR — charge_batch: 500 crudo ante lote mensual duplicado
- Intento de crear MANT_MENSUAL Junio 2026 (ya existe) → `POST /api/charge_batch` → **500** con `PDOException 23505: duplicate key value violates unique constraint "charge_batch_monthly_unique"`.
- La constraint protege integridad (BIEN) pero el controlador NO la captura → el usuario recibe un error 500 genérico en vez de un mensaje 422 claro ("Ya existe un cobro para ese concepto/periodo").
- Archivo: Modules/Collections/app/Http/Controllers/ChargeBatchController.php (~línea 55, performStore/save). Recomendación: validar unicidad en el FormRequest o capturar QueryException y devolver 422.

## 🟠 MAJOR (TRANSVERSAL) — Endpoints de creación sin validación server-side de campos requeridos → 500 (NOT NULL) en vez de 422
Detectado vía probe de API (body incompleto). El backend deja pasar a la BD y revienta con `SQLSTATE[23502] not null violation`. Afecta (al menos): announcement_type (name), blacklist_entry (blacklist_type_id), provider_type (name), provider_service_type (name), charge_concept_category (name), employee_position (name), employee_shift (name), pay_frequency (name), community_project_category (name), visitor_motive (name).
- Mitigado en uso normal porque el FRONT valida `name` requerido, pero la API queda expuesta: cualquier cliente que omita el campo recibe un 500 que filtra estructura de BD. Falta FormRequest con `required` por recurso.
- (Resultados completos de la matriz de escritura por rol: ver api_write_matrix.md del subagente.)

## FLUJO C — Seguridad / caseta (security@encinos.test)
- Login → landing `/access` (correcto para caseta). Sin errores de render.
- Acciones presentes: Escanear QR, Solicitar autorización, Registrar entrada, Registrar salida.
- "Registrar entrada manual": form con Nombre, Unidad, Verificación, Motivo (Social/Trabajador/Reparto/Profesional/Otro), Placa, Identificación, Autorizado por. Validación bloquea submit vacío. ✅
- Backend de accesos (scan-qr/manual-entry/checkout/correction) cubierto por los 777 tests. Happy-path UI completo no ejecutado (comboboxes encadenados + genera registro inmutable de auditoría).

## FLUJO E — Reportes (administrator) ✅
- /reports: "Vista previa · Morosidad" muestra datos reales (Unidades morosas: 1, Total vencido $250.00, tabla por unidad). Sin errores.
- **Descarga PDF funciona**: clic en "PDF" → descarga `delinquency.pdf` válido (882 KB, header %PDF-1.7).
- (Excel y "Publicar en tablero" no probados individualmente — mismo mecanismo, probable OK.)

## FLUJO D — Comunicación / Avisos (administrator)
- /announcement: lista OK (2 avisos). "Nuevo aviso": form con Título, Tipo, Prioridad, Contenido, Expira, Canales (Push/Tablero/Correo/SMS), Adjuntos; submit "Guardar borrador". Validación bloquea submit vacío. ✅
- Happy-path de creación no completado vía harness (comboboxes Tipo/Prioridad no fijables con eventos sintéticos — limitación de la prueba, no bug). Backend de avisos/notificaciones cubierto por tests (NotificationDelivery/FanOut PASS).
- Chat (/chat-channel): no probado interactivamente (tiempo real); render previo OK.

---
# FASE 2 (cont.) — Matriz de ESCRITURA por rol (subagente) + VERIFICACIÓN

## ❌ FALSA ALARMA descartada — "bypass de autorización en CREATE"
El subagente infirió un bypass crítico (roles bajos podrían crear recursos prohibidos) a partir de que el body vacío daba 422 para todos. **NO lo probó con payload válido.** Lo verifiqué yo:
- `resident` con payload VÁLIDO → **403 "Unauthorized action."** en unit_type, expense_category, bank_account_purpose, unit_status.
- `security` con payload VÁLIDO → **403** en expense_category, unit_type.
- `resident` PATCH y DELETE sobre registro REAL → **403**; el registro quedó intacto (verificado en BD: vivo, deleted_at=NULL).
- `administrator` con payload válido → **201** (correcto).
**CONCLUSIÓN: la compuerta `tokenCan('{verbo}.{recurso}')` SÍ se aplica en create/update/delete. No hay escalada de privilegios.** (El 422 con body vacío es solo la validación cortocircuitando antes; con payload válido el gate dispara 403.)

## 🟠 MAJOR (REAL) — `collaborator` se crea con body vacío (sin validación)
`POST /api/collaborator {}` como admin/administrator → **201 Created** con un registro `name=NULL` (2 ghost records creados, ya eliminados). Falta `FormRequest` con campos requeridos en CollaboratorController. (Riesgo: registros basura/incompletos.)

## 🟠 MAJOR (REAL, ya citado) — 500 (NOT NULL) por falta de validación en create de 10 catálogos
announcement_type, blacklist_entry, provider_type, provider_service_type, charge_concept_category, employee_position, employee_shift, pay_frequency, community_project_category, visitor_motive. Body incompleto → 500 con stack trace de PostgreSQL expuesto. Falta validación `required`.

## ✅ Matriz de escritura — lo correcto
- payment / credit_balance: write por Orion no registrado (405) — por diseño (payment usa /payment/register; credit_balance lo gestiona el sistema).
- Roles restringidos → 403 en create de recursos sensibles (verificado).
- UPDATE/DELETE con UUID inexistente → 404 (tras pasar el gate) para roles con permiso.

> Limpieza QA: eliminados los 3 ghost records (1 expense_category + 2 collaborator). Pagos duplicados 007/008 cancelados.

---
# RONDA 2 — Variaciones, áreas no tocadas e inspección visual (screenshots)

## 🔬 Bug #1 (no-refresco de Pagos) — CAUSA RAÍZ CONFIRMADA
La lista de Pagos se alimenta de un **store Zustand** (`usePaymentStore` vía `useBaseList`, `src/modules/payment/PaymentListPage.tsx:148-165`), NO de TanStack Query. Pero `RegisterPaymentSheet.tsx:233-238` (onSuccess) solo hace `invalidateQueries(['statement','payments','charges','delinquency'])` — TanStack, que NO afecta al store Zustand. Y `PaymentListPage.tsx:314` monta `<RegisterPaymentSheet onClose=.../>` SIN pasar callback de refresco del store.
→ **Fix preciso**: el sheet debe invocar el `refresh()`/`onSearch` del store del padre (o el store recargar) en onSuccess, no solo `invalidateQueries`. (Mismo patrón a revisar en otros módulos que combinan store Zustand + sheets de alta.)

## ✅ Pantallas inspeccionadas visualmente (pulidas)
- **Unidades (lista)**: búsqueda + 3 filtros, badges Activa/Inactiva, editar/eliminar por fila. Limpio.
- **Detalle de unidad** (/unit/:id): "Información general", "Personas asociadas" (Resident · Propietario · Titular de pago, Editar/Finalizar, Agregar persona), "Vehículos" (Registrar). Excelente.
- **Editar unidad**: form pre-cargado correctamente (Tipo/Número/Estado/features), botón "Actualizar". OK.
- **Chat Comunitario**: 2 paneles (canales con candado según permiso / mensajes), input + enviar, Nuevo canal. Envío de mensaje FUNCIONA (persiste en BD).
- **Catálogo "Tipos de Aviso"**: tabla con entradas base protegidas (candado, sin borrar) vs editables; form de alta (Nombre/Icono/Orden/Activo) **valida** submit vacío → protege del 500 del bug #3 en uso normal por UI.

## 🟡 Hallazgos nuevos (menores)
- **Breadcrumb usa el slug crudo**: en catálogos el breadcrumb muestra "Announcement-type" en vez del nombre amigable "Tipos de Aviso". Revisar el resolver de migas para rutas de catálogo.
- **A11y**: consola arroja `AlertDialogContent requires an AlertDialogTitle for screen reader users` (Radix) — un AlertDialog (apareció en flujo de Chat) sin `<AlertDialogTitle>`. Romper lectores de pantalla. Agregar título (o VisuallyHidden).
- **Chat**: se observó `POST /api/chat_message → 403` en consola al postear en "Avisos oficiales" (canal restringido), pero el mensaje igual se persistió en otros casos. AMBIGUO — verificar manejo de error cuando el POST de mensaje falla (¿el optimistic update hace rollback y muestra error?).

## Mitigación bug #3 (validación faltante)
Confirmado: el **frontend SÍ valida** los catálogos (form bloquea submit vacío), así que el 500 NOT NULL del bug #3 NO se dispara en uso normal por UI — solo por llamadas API directas sin el campo. Mantiene severidad 🟠 a nivel API pero bajo riesgo en operación normal.

> Limpieza QA ronda 2: eliminados 2 mensajes de chat de prueba.

---
# FIXES APLICADOS Y VERIFICADOS (2026-06-13)

| # | Bug | Estado | Verificación |
|---|---|---|---|
| 1 | 🟠 Lista de Pagos no refresca tras registrar (doble cobro) | ✅ RESUELTO | UI en vivo: lista 7→8 sin recargar. `RegisterPaymentSheet` ahora llama `onRegistered` → `PaymentListPage.refresh()` recarga el store Zustand |
| 2 | 🟠 `charge_batch` duplicado → 500 | ✅ RESUELTO | API: duplicado ahora → **422** "Ya existe un cobro para este concepto en ese periodo". Regla `unique` en `ChargeBatchCreateRequest` |
| 3 | 🟠 Validación faltante (collaborator + 10 catálogos) → 500/ghost | ✅ RESUELTO | API: los 11 ahora → **422**. Creados 9 `*CreateRequest`+`*UpdateRequest` (name required+unique tenant), `BlacklistEntryCreateRequest` (type+value), `name` required en `CollaboratorRequest::storeRules` |
| 5 | 🟡 Breadcrumb con slug crudo en catálogos | ✅ RESUELTO | UI en vivo: ahora "Tipos de Aviso". Agregadas 13 rutas de catálogo a `SECTION_LABELS` en `site-header.tsx` |
| 6 | 🟡 A11y AlertDialogTitle (warning) | ◻️ INVESTIGADO | Todos los Dialog/AlertDialog/Sheet revisados YA tienen título; el warning es transitorio, no ubicable estáticamente. No bloqueante |
| 7a | 🟡 Login no envía con Enter | ❌ NO ERA BUG | El form está bien cableado (`<form onSubmit>` + `type="submit"`); fue artefacto del test (timing) |
| 4 | 🟡 Hueco en folios | ⏸️ DECISIÓN DE PRODUCTO | Folios consecutivos requieren generar el número POST-commit; cambio riesgoso, requiere confirmar requisito fiscal antes de tocarlo |
| 7b | 🟡 `?limit=500` → 500 | ⏸️ PENDIENTE | maxLimit de Orion; robustez menor |
| 7c | 🟡 chat 403 optimistic | ⏸️ PENDIENTE | ambiguo (mensajes persistieron); verificar manejo de error en posteo a canal restringido |

## Regresión
**777 tests backend (2894 assertions) siguen PASANDO tras los fixes** (0 regresiones). Type-check frontend de mis archivos: sin errores; cambios verificados en runtime vía Vite HMR.

## Archivos modificados
**Frontend** (`frontend/`):
- `src/modules/payment/components/RegisterPaymentSheet.tsx` (prop `onRegistered` + llamada en onSuccess)
- `src/modules/payment/PaymentListPage.tsx` (pasa `onRegistered={refresh}`)
- `src/components/site-header.tsx` (13 labels de catálogo)
**Backend** (`backend/`):
- `Modules/Collections/app/Http/Requests/ChargeBatchCreateRequest.php` (unique mensual)
- `Modules/Collaborator/app/Http/Requests/CollaboratorRequest.php` (name required en alta)
- 9 nuevos `*CreateRequest`+`*UpdateRequest` (Access/Providers/Announcements/Collections/Projects/Collaborator)
- `Modules/Access/app/Http/Requests/BlacklistEntryCreateRequest.php` (nuevo)

## Limpieza QA
Pagos de prueba 007/008/009 cancelados (saldos revertidos); ghost records eliminados; mensajes de chat de prueba borrados. Datos en estado limpio.

---
# PRUEBAS EXHAUSTIVAS — MÓDULO COBRANZA (Collections) — 2026-06-13

Método: lectura del motor (AmountCalculator, PaymentService, ChargeStatusService, TargetResolver) + UI pantalla-por-pantalla con screenshots + suite backend de Collections.

## ✅ Backend del motor — sólido
- **119 tests de Collections passed (411 assertions)** + parte de los 777 globales. Cubren: AmountCalculator (10), Batch lifecycle (10+18), ChargeMutations (8), Module (26), PaymentManual & guards (6), ReceiptSequence (4), StatusCron/worst-wins (10), PaymentConcurrency (2), OwnershipGate, UploadValidation.
- Motor bien diseñado: FIFO (consume saldo a favor primero → cargos por due_date/issued_at/id), prorrateo por indiviso con reparto de centavos por mayor-residuo (cero drift), invariante de libro mayor en cancelación con clawback de crédito consumido, locks de concurrencia, folio secuencial por tenant/año.

## ✅ UI Cobranza — pantallas verificadas (pulidas, cálculos correctos)
1. **Conceptos de cobro**: lista (CLAVE/NOMBRE/CATEGORÍA/MODO/MONTO/ESTADO); form con **campos de monto dinámicos** según modo — Fijo→Monto fijo, Rango→Mínimo/Ideal/Máximo, Por indiviso→Monto total a prorratear; valida requeridos (clave/nombre/monto/categoría/periodicidad).
2. **Cobros (lotes)**: lista; asistente de alta con alcance flexible + validación; **detalle** con 4 cards (esperado/pagado/faltante/vencidos), barra de progreso, desglose completos/parciales/sin-pago, tabs Por unidad/Por estado, botón Cobrar por unidad.
3. **Cargos**: lista con TODOS los estados correctos según hoy (jun 13): Vencido (jun 1), Por vencer (jun 15, ≤7d), Pendiente (jun 25+), Completo (pagado), Cancelado (tachado). Código de colores = spec §4. ✓
4. **Estado de cuenta**: buscador de cualquier unidad; vista poblada Casa 3 con cards (Adeudo $670, Saldo a favor $0, Pagado histórico $0) y tabla que suma exacto ($250+$120+$300=$670). Reconcilia con Cargos y Morosidad. ✓
5. **Morosidad**: cards (Adeudo total, promedio, más morosa, antigüedad) + tabla; cuenta SOLO lo vencido ($250), correcto. ✓
6. **Pagos** (rondas previas): registrar+FIFO+cancelar (motivo obligatorio, revierte saldo+banco), validación; **bug de refresco YA RESUELTO**.

## 🟡 Hallazgos menores (Cobranza)
- Link **"Estado de cuenta →"** en Morosidad va a `/statement` SIN pre-seleccionar la unidad → el admin cae en el estado vacío y debe re-buscar. Debería pasar el unit_id.
- Etiqueta **"CARGOS CON SALDO"** en Morosidad muestra el conteo de VENCIDOS (1), pero Casa 3 tiene 3 cargos con saldo; en el contexto de morosidad se entiende, pero la etiqueta puede confundir (mejor "cargos vencidos").

## Veredicto Cobranza
**Módulo robusto y pulido.** Motor con cobertura de tests profunda (119 verdes) y UI correcta en las 6 pantallas (estados, totales y reconciliación verificados). Los 2 bugs 🟠 previos de este módulo (refresco de Pagos, charge_batch 500→422) ya fueron RESUELTOS. Solo quedan 2 nits 🟡 de UX en Morosidad. Sin bugs nuevos de cálculo/integridad.

---
# FIX — Estado de cuenta del residente (multi-unidad) + nits Morosidad — 2026-06-13

## 🔴 Bug encontrado al probar como RESIDENTE (gracias al usuario por insistir)
Al entrar como `resident@encinos.test` a "Estado de Cuenta", el residente veía la interfaz de ADMIN: subtítulo "Consulta los cargos y saldos de **cualquier unidad**", buscador y estado vacío "Selecciona una unidad" — NO cargaba su propia cuenta. Afectaba a **cualquier rol con unidad propia** (residente, inquilino, etc.).

## ✅ Fix aplicado — `src/modules/payment/StatementPage.tsx`
- Trae las unidades propias del usuario (`UnitResource.my()`).
- **Modo residente** (tiene unidad propia): auto-carga su estado de cuenta SIN buscador. Soporta **1 o N unidades**: una **sección por unidad** (cards + tabla + total) y, si hay 2+, un **TOTAL GENERAL consolidado** (adeudo / saldo a favor / pagado histórico sumados). Botón "Reportar pago" por unidad (gated por permiso; el residente no ve "Registrar pago").
- **Modo admin/tesorero** (sin unidad propia): se mantiene el buscador "cualquier unidad" intacto. El `?unit=` (links de Morosidad) sigue funcionando.
- Subtítulo dinámico: "Tu estado de cuenta" / "Tus N unidades" / "...cualquier unidad".

### Verificado en vivo (screenshots)
- Residente 1 unidad (Casa 1): cuenta directa, Total $1,450, todos Completo, solo "Reportar pago". ✓
- Residente 2 unidades (Casa 1 + Casa 2): TOTAL GENERAL adeudo $700 / pagado $1,450 + sección Casa 1 ($0 adeudo) + sección Casa 2 ($700 pendiente). Totales cuadran exacto. ✓
- Admin: buscador "cualquier unidad" preservado. ✓
- type-check: StatementPage sin errores (también corregí un error pre-existente de tipo en `BankDataInfo`). Verificado en runtime (Vite HMR).

## ✅ Nit corregido — etiqueta Morosidad
"Cargos con saldo" → **"Cargos vencidos"** en `DelinquencyPage.tsx` (el endpoint `delinquency` filtra por `ChargeStatus::OVERDUE`; `charge_count` = cargos vencidos). Verificado en vivo.

## ℹ️ "Bug" descartado
El link "Estado de cuenta →" de Morosidad **sí** pasa `?unit=` (DelinquencyPage:159) y StatementPage lo lee — fue falso positivo de mi prueba previa (cliqueé el item del sidebar por error).

## Limpieza
Asigné temporalmente una 2ª unidad al residente para probar multi-unidad y la **revertí** (residente de vuelta a 1 unidad). Sin data de prueba pendiente.

## Archivos modificados (esta ronda)
- `frontend/src/modules/payment/StatementPage.tsx` (estado de cuenta multi-unidad)
- `frontend/src/modules/payment/DelinquencyPage.tsx` (etiqueta)

---
# FIX — Inconsistencia de etiqueta "Adeudo total" Morosidad vs Estado de cuenta — 2026-06-13

## El problema (reportado por el usuario)
La misma unidad (Casa 3) mostraba dos "adeudo" distintos con la MISMA etiqueta "Adeudo total":
- **Morosidad**: $250 (solo el cargo VENCIDO de jun 1).
- **Estado de cuenta**: $670 (TODO lo pendiente: $250 vencido + $120 por vencer + $300 pendiente).
Ambos números son correctos (miden cosas distintas) pero la etiqueta compartida confundía: "¿debe $250 o $670?".

## Fix (`DelinquencyPage.tsx`) — etiquetas de Morosidad ahora dicen "vencido"
- Subtítulo: "unidades con saldo pendiente" → "unidades con **adeudo vencido**".
- KPI card: "Adeudo total" → "**Adeudo vencido**"; subtexto "unidades con saldo" → "unidades morosas".
- Columna: "Adeudo total" → "**Adeudo vencido**" (y antes "Cargos con saldo" → "Cargos vencidos").

Resultado: **Morosidad = "Adeudo vencido"** (lo que ya pasó de fecha) vs **Estado de cuenta = "Adeudo total"** (todo lo pendiente). Sin etiqueta ambigua compartida. Verificado en vivo.

Nota: el número de Morosidad ($250) es correcto por definición (el endpoint `delinquency` filtra `ChargeStatus::OVERDUE`). Si el negocio prefiere que "morosidad" muestre TODO el adeudo ($670), es decisión de producto — avísame y lo cambio; por ahora se respeta el significado estándar (morosidad = vencido) y se aclaran las etiquetas.

---
# MEJORAS UX Cobranza (claridad de lo automático) — 2026-06-13

Para reducir la confusión "¿registro el cobro cada mes?":
1. **Conceptos de cobro**: los conceptos mensuales activos muestran un badge **"🔁 Automático · genera cada mes · próx: <fecha>"** (la próxima fecha se calcula con el día de generación configurado). Verificado: MANT_MENSUAL → "próx: jul 1, 2026".
2. **"Nuevo cobro"**: al elegir un concepto mensual, aparece un aviso: *"Este concepto es mensual y se genera automáticamente cada mes; usa este formulario solo para un cobro fuera de calendario"*.
3. **Placeholder del título** "Nuevo cobro": era fijo "Mantenimiento Marzo 2026" → ahora dinámico ("Ej. Mantenimiento Junio 2026", respeta el mes/año seleccionados).

**Convención aplicada** (corrección del usuario): sin constantes inline (se reusó el enum `PERIODICITY` de `chargeConcept/schema.ts`) y las funciones puras se movieron al helper `src/modules/chargeConcept/helpers/chargeGeneration.ts` (`isAutoMonthly`, `nextChargeGenerationDate`, `resolveChargeGenerationDay`). Type-check limpio.

Archivos: `chargeConcept/ChargeConceptListPage.tsx`, `chargeConcept/helpers/chargeGeneration.ts` (nuevo), `chargeBatch/components/ChargeBatchFormModal.tsx`.
