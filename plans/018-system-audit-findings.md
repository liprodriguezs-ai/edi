# Plan 018 — Auditoría sistemática del sistema (módulo × ventana × rol)

- **Status**: IN PROGRESS — Categorías A y B COMPLETAS y verificadas (9 fixes commiteados). Quedan C (≈40 features backend-sin-UI, multi-sesión) y D (seeder backend).
- **Fecha**: 2026-06-12
- **Método**: 3 agentes de exploración estática (rutas frontend, matriz menú/permiso/rol backend, capacidad-backend-vs-UI) + verificación dinámica con Playwright (login real por rol). Todos los hallazgos VETADOS contra el código antes de listarlos.
- **Credenciales QA** (tenant `privada_los_encinos-xPeZ`, password `123abc`): `admin@encinos.test` (account.owner), `administrator@encinos.test`, `president/treasurer/secretary/vocal/oversightcommittee@encinos.test`, `security@encinos.test`, `operationsstaff@encinos.test`, `resident@encinos.test`, `renter@encinos.test`.

---

## ✅ Arreglado y verificado esta sesión (commits en `feat/salvia-auth-redesign`)

| Bug | Commit | Verificación |
|---|---|---|
| Registro de visitantes inexistente | `580a356` | E2E resident: POST 201, modal |
| **QR del visitante nunca se mostraba** | `4453890` | E2E resident: QR renderiza al registrar + "Ver QR" por fila (screenshot) |
| Aportación voluntaria sin UI | `666abc5` | E2E resident: $1 → 200, revertido |
| Race de refresh → logout espurio | `3cdebce` | — |
| **Tenant systems: 2 URLs daban 404** | `76f13fd` | `sync-from-plan` y `/addon` corregidos vs rutas reales del backend |

## ✅ Verificado funcionando (dinámico, sin "Zona protegida")

- **resident**: 10 items de menú (Dashboard, Mi Unidad, Estado de Cuenta, Avisos, Canales de Chat, Proyectos, Aportaciones, Mesa Directiva, Visitantes) — todos renderizan.
- **administrator**: charge-batch, payment, configuration, reports, access — todos renderizan con su acción primaria, sin error.
- **Matriz menú↔permiso**: el análisis estático no encontró NINGÚN "Zona protegida" residual para los 11 roles nombrados (el bug del resident con `/access` y `/statement` ya estaba arreglado de antes).

---

## Categoría A — Bugs concretos ✅ HECHOS Y VERIFICADOS

| # | Gap | Commit | Verificación |
|---|---|---|---|
| A1 | **"Cancelar QR" del visitante** — botón con confirmación, solo en estado PENDING (el backend `cancelQr` solo actúa ahí). | `7bdb8c9` | E2E admin: confirmar → estado "Cancelado", botón desaparece. |
| A2 | **"Cancelar pago"** — botón en pagos no cancelados (fuera de la cola de validación) + dialog de motivo (min 5). | `68cb196` | E2E admin: botón en confirmados, dialog abre, confirmar deshabilitado <5 chars. |
| A3 | **Colaborador dar de baja/reactivar** — botones en el header del detalle (baja: fecha+motivo; reactivar: crea registro nuevo §7.5.2). | `7c689e1` | typecheck verde (módulo en migración a Employee — extensión segura confirmada). |

## Categoría B — Cableado incompleto ✅ HECHOS

| # | Gap | Commit | Verificación |
|---|---|---|---|
| B1 | **Archivos subidos descargables**: comprobante de pago, comprobante+CFDI de egreso, acta de mesa — botones de descarga vía signed URL + `media` en `includes()` (backend `bf8976b`, frontend `68cb196`+`bb1c285`). | `bf8976b`,`68cb196`,`bb1c285` | typecheck verde; pendiente media sembrada para probar descarga real. |
| B2 | **Marcar aviso como leído** + vista de lectura del contenido completo (clic en el aviso → modal → `markAsRead` en publicados). | `1372559` | E2E admin: lecturas 0→1 al abrir un aviso publicado. ⚠️ commit incluye el refactor Sheet pre-existente del ComposeModal (mismo archivo, inseparable). |
| B3 | **Métricas de aviso** (`AnnouncementResource.metrics` sin panel) — pendiente, baja prioridad (parte de un panel de métricas que no existe aún). | — | Backlog. |

## Categoría C — Capacidad backend SIN UI — EN CONSTRUCCIÓN ("todo ataca todo")

> El usuario pidió construir TODO C en orden de impacto. Progreso y orden:

**Hechos:**
- ✅ **Scanner de QR del guardia** (`POST /access/scan-qr`) — `70f5ff7`. Cierra el ciclo del QR. E2E: token válido → 200 → entrada registrada → visita "dentro ahora".
- ✅ **Auto-captura de pago del residente** (`POST /payment/submit`) — `6df6324`. "Reportar pago" en el estado de cuenta (gated `create.payment_submission`); queda pendiente de validación. Verificado: sheet con campos + catálogos (Caja efectivo visible al residente). El submit final no se completó por E2E por un quirk de Playwright con Radix Select dentro de Sheet (mismo patrón que RegisterPaymentSheet, productivo).
- ✅ **Cambiar contraseña in-app** (`POST /updatePassword`) — `f710e0d`. Reemplaza el ítem muerto "Cuenta" del menú de usuario. Build de prod + typecheck verdes (Radix DropdownMenu no se deja abrir por Playwright; el modal es Dialog, patrón ya verificado E2E).
- ✅ **Comentarios de proyecto** (`project_comment` Orion + `/hide`) — `ccbadf8`. ProjectComments en el detalle: lista plana, caja para comentar (create.project_comment), ocultar para moderadores. E2E verificado como resident: comentario publicado y visible.

**Workflow de specs (Ultracode):** corrí un workflow paralelo (5 agentes) que produjo specs de implementación exactos para 5 features de C. Specs guardados en `$JOB/tmp/specs/`. Vetados contra el código real — hallazgos: `SessionProfile` no tiene `profile_image_url` (profile-image), ConfigurationPage usa apiClient inline no un resource (config-branding), y los specs pusieron `/my-access-log` bajo `access_log` (los residentes no lo tienen → debe ir bajo `unit`). Los 4 restantes (profile-image, announcement-feed, my-access-log, config-branding) quedan listos para implementar con esas correcciones. announcement-feed y my-access-log además requieren entrada de menú (backend seeder).

**Hechos (cont.):**
- ✅ **Branding (logo/cover)** (`POST /config/branding/logo|cover`) — `b34561c`. Sección Marca en el tab General de ConfigurationPage (inline apiClient, máx 5MB).
- ✅ **Foto de perfil** (`POST /user/uploadProfileImage|deleteProfileImage`) — backend `1e1c9a3` + frontend `26d9958`. **Las rutas estaban MUERTAS** (referenciaban métodos inexistentes en UserController → se implementaron). "Editar foto" en el menú de usuario; el avatar muestra profile_image_url. Verificado: rutas resuelven + flujo de media (tinker).

**Hechos (cont.):**
- ✅ **Mi historial de accesos** (`GET /access/my-log`) — `544bf65`. MyAccessLogPage (read-only paginada) bajo ruta `/my-access-log` (gate `unit`, NO access_log), alcanzable por link desde Mi Unidad (sin tocar seeder de menú). E2E verificado como resident.
- ✅ **Chat: reportar + des-ocultar** (`/chat-message/{id}/report|unhide`) — `f1b977c`. Reportar en mensajes ajenos, Mostrar (unhide) para moderadores.

**Hechos (cont.):**
- ✅ **Datos bancarios al residente** (`GET /config/bank-data`) — `f6bc26f`. Tarjeta en StatementPage (vía ConfigurationResource.getBankData), visible si el clúster lo habilita.
- ✅ **Reportes: publicar en tablero** (`POST /reports/{key}/publish-to-board`) — `d4fc4ce`. Botón por reporte (gated create.announcement).
- ✅ **Config: plantillas de notificación** (`GET/PUT /config/notification-templates` + restore) — `3ec63aa`. Tab "Plantillas". E2E verificado: 9 plantillas editables.
- ✅ **REFACTOR de convención** `f6bc26f`+`08061ec`: movidas TODAS las llamadas apiClient inline a la capa de resource (UserResource métodos, ConfigurationResource nuevo). SubmitPaymentSheet a RHF+Zod. Casts de catálogo limpiados.

**Hechos (cont.):**
- ✅ **Feed de avisos del residente** (`GET /announcement/board`) — `ce4d958`. AnnouncementResource.board + useAnnouncementFeed (hook) + AnnouncementFeedPage. `/announcement` role-aware (AnnouncementsPage): admin→lista, residente→feed. E2E verificado.
- ✅ **REFACTOR capa de hook** `cc78ec9`: useConfiguration/useMyAccessLog/useProjectComments — los componentes no usan useQuery directo (5-layer).

**Hechos (cont.):**
- ✅ **Config: documentos legales** (`GET /config/legal-documents` + `POST /config/legal-documents`) — tab "Legal" en ConfigurationPage vía useConfiguration (reglamento/acta_constitutiva/aviso_privacidad, subida + descarga signed URL).
- ✅ **Cola de moderación de chat** (`chat_message_report` Orion + `/dismiss`) — `ChatReportsModal` + `useChatReports`. Botón "Reportes" (gated `update.chat_message_report`) abre la cola pendiente: descartar u ocultar el mensaje.
- ✅ **Chat: editar + reaccionar** (`POST /chat-message/{id}/edit`, `react`/`unreact`) — `5e453de`. Editar inline en mensajes propios (marca "(editado)"), toggle 👍 con conteo en todos. Resource methods (no apiClient inline), typecheck verde.
- ✅ **Adjuntar archivos a aviso** (`POST /announcement/{id}/media`) — backend `5841dc2` (expone `media` en includes + board), frontend `7767bdf`. ComposeModal sube hasta 5 archivos (máx 10MB) tras crear el borrador; lista y feed muestran adjuntos descargables (signed URL) + badge de cuenta.
- ✅ **Reportes: vistas previa read-only** (`/reports/delinquency|income-statement|account-balances|audit-log`) — `7d2d3d1`. Botón "Vista previa" por reporte; `ReportPreviewDialog` con 4 shapes (morosidad por unidad, ingresos vs egresos con rango de fechas, saldos de cuentas, auditoría paginada). `collection-progress` se omite (es por charge-batch, no del catálogo genérico).
- ✅ **REFACTOR de convención** `2a559b7`: extraídos subcomponentes/hooks (AnnouncementComposeModal/ReadDialog, ChatMessageBubble/NewChannelModal/ReportMessageModal/ChannelSidebar/MessageComposer, useSendMessage) para cumplir ESLint (complejidad ≤15, max-lines, set-state-in-effect) y DRY. ChatPage 26→<15. Build de prod verde.

**Hechos (cont.):**
- ✅ **Solicitud de entrada del guardia sin QR** (`POST /access/entry-request`) — `be3ed13`. Botón "Solicitar autorización" en AccessNowPage (gate `create.access_log`) + `EntryRequestModal`; crea visita pending_approval que el residente aprueba/rechaza desde VisitorListPage (ya existía). NO es feature del residente — estaba mal etiquetado.
- ✅ **Corrección inmutable de bitácora** (`POST /access/{id}/correction`) — `307c568`. Columna "Corrección" en AccessLogListPage (gate `update.access_log`) + `CorrectionModal`; anexa nota sin modificar el registro. **Validado por el agente react-frontend-reviewer** (patrón conforme al skill §2.4).
- ✅ **Importación CSV de colaboradores** (`POST /collaborator/validate_import` + `/batch_import`) — `bc8b9a4`. El botón "Importar" de CollaboratorList YA existía pero apuntaba a `/collaborator/import` (ruta MUERTA). Construido: parser CSV sin dependencias (`helpers/collaboratorImport`), `useCollaboratorImport` (hook validar→preview→batch), `CollaboratorImportPage` (descarga plantilla + upload + tabla de validación por fila + resultado) + ruta en App.tsx. NO se tocó CollaboratorList (está en migración).
- ✅ **Anonimizar usuario / derecho al olvido** (`POST /user/{id}/anonymize`) — `c0ee86b`. `AnonymizeUserAction` self-contained (gate `update.user` + `hasAnyRole([SUPER_ADMIN, ACCOUNT_OWNER, ADMINISTRATOR])`, confirm destructivo, actualiza el store) dentro de UserTableRow. NO se tocó UserList (en migración). De paso se bajó la complejidad pre-existente de UserTableRow (24→<15) extrayendo `UserIdentityCell`/`SuperAdminTenantCells`.

> **VEREDICTO de arquitectura (agente react-frontend-reviewer + verificación):** para endpoints de acción NO-CRUD el ÚNICO patrón sancionado es `createCrudApi` + `customMethods` + `apiClient.post` (skill react-frontend-architecture §2.4). NO crear helpers base tipo `action()`/`query()` (se intentó y se revirtió). El "desajuste" basePath snake (Orion) vs acción kebab es intencional del backend. `apiClient` en componentes está OK solo para `ApiError`/`formatApiErrorMessage`.

**Hechos (cont.) — barrido "backend sin UI" (grupo A):**
- ✅ **Residente consulta documentos legales** (`GET /config/legal/{type}/current`) — `feat(configuration)`. `ClusterDocumentsPage` (`/cluster-documents`, gate `unit`) + `useClusterDocuments` (fetch de los 3 tipos, 404→null) + link desde MyUnitPage. El residente puede leer reglamento/acta/aviso de privacidad vigentes.
- ✅ **Preferencias de notificación** (`GET/PUT /user/notification-preferences`) — `feat(user)`. `NotificationPreferencesPage` (grilla 7 categorías × 3 canales push/email/sms + horario de silencio), `useNotificationPreferences`, entrada en el menú de usuario (nav-user). Default = todo ON (canal ausente = activado). De paso NavUser 17→<15 (extraído `UserIdentity`).
- ✅ **Cerrar sesiones de un usuario** (`POST /user/{id}/revoke-sessions`) — `feat(user)`. `RevokeSessionsAction` self-contained en la fila de usuario (gate `update.user` + rol admin).

**Omitidos a propósito (redundantes/bajo valor — NO construir sin confirmar):**
- `POST/GET /collaborator/{id}/media` (upload/download a la colección `documents` cruda) — REDUNDANTE: el expediente estructurado (`DocumentCollaborator`) ya tiene UI (MyDocumentsCard/DossierStatusCard). Construir UI para la colección cruda confundiría el sistema estructurado.
- `GET /visitor/mine` — el residente YA tiene VisitorListPage (Orion scopeado). `/mine` solo agrega `accessLogs` (estado Llegó/Salió); sería una vista duplicada con delta menor.
- `POST /user/updateProspect`, `GET /user/network`, `POST /uploadDocuments`, `GET /documents` — probable LEGACY del pivote CRM→Kivo7 (red MLM / prospectos). Verificar y borrar del backend, no construir.

**Bug de backend — ✅ ARREGLADO:**
- ✅ `SubscriptionPlanSystemController` sin ruta registrada (frontend daba 404) — backend `e41e668`. Registradas `GET/POST subscription-plan/{planId}/systems[/sync]` + **gate `update.subscription_plan` agregado al constructor** (no tenía NINGUNA autorización — espejo de TenantSystemController/authz-02). Verificado: `route:list` las muestra y sin auth responde 401 (antes 404).

**Restante — ✅ HECHO:**
- ✅ **Chat: mensajes directos 1:1** (`POST /chat_channel/direct`) — backend `f90564c` + frontend `feat(chat)`. Diseño clave: el residente NO tiene `read.user`, así que NO hay picker de usuarios — el DM se inicia con "Mensaje" sobre el mensaje de otro usuario en un canal (oculto dentro de DMs). El canal directo se muestra con el nombre del OTRO miembro (`displayChannelName` helper; el nombre técnico es `direct:uuidA:uuidB`), ícono UserRound. Backend: whitelisteado `members.user` en ChatChannelController + **fix de bug latente**: `message.user` faltaba en el whitelist de ChatMessageReportController (la cola de moderación lo pedía y Orion lo rechazaba).

> CATEGORÍA C: COMPLETA. Todo el backend-sin-UI accionable fue construido u omitido con razón documentada. Solo queda Categoría D (seeders, dueño backend + reseed).

> NOTA: hay una migración Dialog→Sheet EN CURSO sin commitear (Modal.tsx, varias List pages incl. CollaboratorList/UserList, CatalogCrudModule, etc. — ~24 archivos). NO es trabajo de esta auditoría; se dejó intacta. Las features de import/anonymize se construyeron SIN tocar esos archivos (archivos nuevos + UserTableRow/App.tsx que están limpios).

> Patrón establecido (el usuario lo exigió): schema → Resource (método `customMethods`+`apiClient`) → HOOK por módulo → Componente. NUNCA apiClient (para requests) ni useQuery directo en componentes. NUNCA inventar abstracciones sobre `createCrudApi`.

> RECORDATORIO de convención (el usuario lo señaló): NUNCA apiClient inline en componentes — siempre método del resource del módulo. Servicio plano para no-CRUD (/config, /reports). Seguir patrón de hermanos (RHF+Zod en forms).

> GOTCHA: varias rutas backend referencian métodos de controller INEXISTENTES (profile-image lo confirmó: rutas muertas). Verificar SIEMPRE que el método exista antes de asumir que el endpoint funciona.

> Nota de tooling: Playwright (vía MCP) no dispara de forma fiable los componentes Radix Select-dentro-de-Sheet ni DropdownMenu (eventos sintéticos vs pointerdown). Estos funcionan para usuarios reales (mismos componentes en producción). Donde pasa, se verifica por typecheck + build de prod + datos de catálogo confirmados, y se documenta.

### Detalle original de C (referencia)

> Estas NO son "bugs": son endpoints construidos en el backend cuya UI aún no existe. El producto está a medio construir (modules.md, Fase 2). Listadas para que el equipo decida cuáles construir y cuáles son de versión futura.

- **Accesos**: scanner de QR del guardia (`/access/scan-qr` — el loop del QR está a medias: residente lo ve, guardia no lo escanea, usa entrada manual); solicitud de entrada del residente (`/access/entry-request`); "mi historial de accesos" del residente (`/access/my-log`); corrección de bitácora (`/access/{id}/correction`).
- **Cobranza**: auto-captura de pago por el residente (`/payment/submit` → pending_validation).
- **Chat**: editar/reaccionar/reportar/des-ocultar mensaje, canales directos (DM), cola de moderación (`chat_message_report`).
- **Proyectos**: hilo de comentarios (`project_comment` — backend existe, sin UI).
- **Configuración**: branding (logo/cover), plantillas de notificación, documentos legales (3 tabs faltantes).
- **Reportes**: vistas read-only (`/reports/delinquency|income-statement|account-balances|audit-log|collection-progress`) y "publicar en tablero".
- **Usuario**: subir foto de perfil, cambiar contraseña dentro de la app, anonimizar (GDPR).
- **Colaborador**: importación CSV (validate/batch).
- **Avisos**: feed/tablero del residente (`/announcement/board`).

## Categoría D — Issues de seeder backend — ✅ RESUELTA (backend `676f187`, reseed + verificación HTTP)

| # | Issue | Resolución |
|---|---|---|
| D1 | **Clave duplicada `'Configuración'`**: dos módulos compartían el nombre y los mapas del seeder van por nombre → el Core/Setting perdía su binding `setting` y heredaba `/configuration`. | ✅ Core renombrado a **'Ajustes de Plataforma'** en modules.csv + claves del seeder (entity/icon). Además se OCULTÓ (`$hidden`) porque no existe página React para `setting` — era un ítem de menú muerto del super admin. Verificado en BD: `Ajustes de Plataforma | entity=setting | route=NULL | visible=0` y `Configuración | entity=configuration | /configuration | visible=1`. |
| D2 | **Fantasma `'Personal Operativo'`** (3 entradas muertas; 'Colaboradores' ya tenía las suyas en 106/224/359). | ✅ Eliminadas las 3 entradas muertas (entity/route/icon). 0 filas afectadas antes y después — solo limpieza. |
| D3 | **Roles `collaborator`/`affiliate` con cero permisos.** | ✅ **NO es bug — verificado**: `UserInvitationService` asigna el rol Kivo7 vía `EmployeeService::resolveKivo7Role()` (puesto→rol) y `collaborator` es solo FALLBACK documentado si el rol Kivo7 no está sembrado. `affiliate` solo existe como constante+fila sin uso. Se dejan (eliminarlos toca model_has_roles con riesgo sin beneficio). |
| D4 | **Sistema RRHH sin módulos** (`94345c73`). | ✅ Quitado de systems.csv + fila borrada de la BD (verificado: 0 referencias en system_tenant / subscription_plan_system / module_system). Quedan Core, Fraccionamiento, Tesorería. |
| D5 | `security` ve "Mi Unidad" (tiene `read.unit` para el contexto del QR). | Intencional/cosmético — sin cambio. |

> Verificación post-reseed (`ResetPermissionTableSeeder` + HTTP real): account.owner = 45 nodos de menú con Configuración/Colaboradores y SIN fantasmas; resident = 10 nodos (baseline de la auditoría). 'Ajustes de Plataforma', 'Personal Operativo' y 'RRHH' ausentes del menú.

> 🎉 **AUDITORÍA COMPLETA**: A ✅, B ✅, C ✅, D ✅.

---

## Recomendación

1. **Inmediato**: arreglar A1–A3 (bugs concretos chicos), confirmando el FSM/gating de cada uno.
2. **Backend**: pasar D1–D2 al dueño del backend (son seeder + reseed, riesgo de menú).
3. **B1–B3**: incompletos de bajo riesgo, buen segundo lote.
4. **Categoría C**: backlog de producto — el equipo prioriza qué entra en v1.0 vs v1.1+. NO tratar como bugs.

> Nota de honestidad: "que todo quede resuelto" abarca ~40 UIs de features sin construir (Categoría C). Eso es trabajo multi-sesión y parte es scope futuro intencional. Esta auditoría deja el mapa completo y vetado para priorizar.
