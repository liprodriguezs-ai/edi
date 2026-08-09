# Exploration — complaints-module (Denuncias)

> Mirrored from engram topic `sdd/complaints-module/explore` (obs #1682, project `residia-monorepo`, 2026-07-19).
> Phase: sdd-explore · Status: done · Next: sdd-propose

## Intent

Build a new Kivo7 **Complaints (Denuncias)** module, recovering the old CRM "Ticket" module as a starting base:

- Old backend (external, not in this repo): `/Users/rodriguez/code/employ-secure-pro-backend/Modules/Ticket/` (copy also at `/Users/rodriguez/code/tickets/backend/Modules/Ticket/`).
- Old frontend: deleted from `frontend/` submodule history at commit `500b4b1` ("eliminar módulos CRM"); recoverable via `git show 500b4b1^ -- src/modules/ticket src/modules/historyTicket src/components/kanban.tsx`. **Not yet inspected byte-for-byte** (explore agent had no Bash) — propose/apply must inspect before committing to frontend reuse estimates.

## Key discovery

The old Ticket module was actually a **legal-advisory (asesorías) ticket system** (lawyer/client domain, `Role::SLUG_LAWYER` gate, `client_id` FK) — its Orion/Service/status-catalog *structure* is a good skeleton, but domain rules must be discarded, and **zero migrations are copy-pasteable** (all tables central, no `tenant_id`, no `->comment()`).

## Backend reuse map (old Ticket → new Complaint)

| Old piece | Verdict | Why |
|---|---|---|
| Orion controller pattern (performStore/performUpdate → Service; custom `/move_status`) | Reutilizable (patrón) | Matches laravel-backend-architecture §2.6/§2.10 |
| `Ticket` model + table | Reescribir | Central, sin tenant_id ni comments — re-migrar tenant-scoped |
| `TicketStatus` (central FSM PENDING/ATTENDED) | Adaptar | Misma forma que `PackageStatus` (CentralCatalogModel + UUIDs fijos); definir set real de estados |
| `TicketImportance` | Decisión abierta | Display-only en el código viejo → candidato a tenant-scoped o descartar |
| `TicketMessage` (+ Spatie media) | Adaptar | Buen precedente para hilo de seguimiento con adjuntos; necesita tenant scoping |
| `TicketAssignment` (user_id libre) | Reescribir | Debe apuntar a un actor Kivo7 real (Employee/rol de mesa) |
| `TicketAssociated` (vinculadas) | Descartar v1 / diferir | Complejidad sin necesidad clara aún |
| `TicketCatalogService::ensureDefaults()` + seeder duplicado | Descartar patrón dual | Kivo7 centraliza en `CatalogSeeder` (ver Package) |
| `HistoryTicket` (auditoría paralela) | Descartar | BaseModel ya trae Laravel Auditing global |
| Push via `SendPushNotificationJob::dispatch` directo | Reescribir (hard rule) | Estándar actual: `NotifiesResidents::notifyEvent()` + catálogo `NotificationEvent` + `NotificationSetting` por tenant |
| Gate de lawyer + `client_id`/Client | Descartar | Dominio del producto viejo; mapear actores vía `unit_user`/`User`/`Employee` |

Extra backend notes: TicketService generates folio with `lockForUpdate` retry loop (useful precedent for complaint folio).

## Frontend reuse map

- `kanban.tsx` + páginas kanban/detalle viejas: **ausentes del tree actual** (confirmado); no hay precedente Kanban vivo en los 12 módulos Kivo7 — revivirlo = trabajo net-new contra shadcn + dnd-kit, no un restore.
- Mejor plantilla viva a imitar: **módulo Package** (`PackageDetailPage`, `PackageStatusBadge`, `AgingBucketCard`, `PendingPackagesWidget`) — badge FSM, timeline de detalle, evidencia media, widgets de cola pendiente.
- `ReportMessageModal` de Chat: referencia de patrón UI para captura rápida, dominio no relacionado.

## Overlap con `chat_message_report`

Es **moderación de contenido** (mensaje ofensivo → hide, estados pending/reviewed/dismissed, sin unit/assignment/SLA/evidencia). Denuncias es dominio distinto → **módulos separados**. Flag UX: desambiguar copy "reportar mensaje" vs "presentar denuncia".

## Decisiones de producto — resueltas por el usuario (2026-07-19)

1. **Alcance**: un solo módulo cubre convivencia entre vecinos + incidencias del clúster + quejas contra administración/directiva, con **categoría como catálogo tenant-scoped**.
2. **Anonimato**: nunca anónimas. **v1: solo integrantes de la mesa directiva registran denuncias** (el residente no captura directo).
3. **Gestión/visibilidad**: roles configurables por tenant → entra por flujo `module-config`.

## Decisiones abiertas (para sdd-propose)

- Denunciado: ¿FK a `unit`/`unit_user`, `employee`, `provider`, o sujeto libre?
- Asignación: ¿a qué rol/Employee se asigna para resolver?
- Set FSM real de estados; ¿algún estado dispara SLA/cron (precedente `ProcessPackageRetentionCommand`)?
- Prioridad: ¿central (si dispara lógica) / tenant-scoped (cosmético) / descartar?
- Evidencia: ¿adjuntos en denuncia inicial y/o seguimiento?
- UI: Kanban (sin precedente) vs Lista+Detalle (recomendado, patrón Package).
- Catálogo de eventos de notificación (`complaint_created`, `complaint_status_changed`, `complaint_assigned`, …) vía `notifyEvent()`.
- Config por tenant vía `module-config` (notificar a junta, evidencia obligatoria, días para resolver…).
- `ticket_associated` (denuncias relacionadas): ¿v1 o diferir?

## Risks

- Mayor riesgo: avanzar a design/apply sin resolver denunciado/asignación/FSM — cambian la forma del modelo de datos.
- Backend: presupuestar reescritura completa de migraciones, no adaptación.
- Estimación frontend blanda hasta inspeccionar `git show 500b4b1^` (hacer en propose/design).
