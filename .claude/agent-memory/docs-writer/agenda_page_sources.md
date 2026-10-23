---
name: agenda-page-sources
description: Which code backs /docs/agenda/citas (Agenda module) — labels, rules, suggestion logic.
metadata:
  type: reference
---

`/docs/agenda/citas` is backed by:
- UI labels: `frontend/src/modules/appointment/{AgendaPage.tsx, components/*}`; action visibility in `utils/appointmentStatus.ts` (Llegó only on appointment day; No asistió only after start time; Cancelar also from En espera).
- Lifecycle + messages: `backend/Modules/Appointment/app/Services/AppointmentLifecycleService.php` (start allowed without "Llegó" on the appointment day; Cerrar día skips arrived).
- Next-appointment suggestion: `AppointmentSuggestionService.php`; intervals in `CatalogSeeder::seedWellChildIntervals` (NOM-031).
- Status/type labels and durations: `CatalogSeeder.php` (~line 793–807).
- Entry points: `child/ChildDetailPage.tsx` + `ChildAppointmentsPanel.tsx` (tab Citas, row action via `AppointmentRowAction`), `screening/components/CloseScreeningAction.tsx` (Cerrar prueba y agendar cita), `NextAppointmentButton` (Agendar próxima cita). Views/KPIs: `utils/agendaView.ts`, `AgendaToolbar`, `AgendaTodaySummary`, `AgendaListView`; default center `utils/defaultAgendaCenter.ts`.
- Permissions: Administrador + Recepción (no Iniciar atención); start needs update.appointment + create.visit.
- Landing route per role: `frontend/src/shared/interfaces/Entity.ts::roleRoutes` — Administrador → /dashboard, Recepción → /agenda (don't claim Agenda is everyone's home). Assignable roles: `Role::ORGANIZATION_SLUGS`.
- The booking form has NO health-center field (center derives from practitioner); the S13 422 "El profesional no atiende en ese centro de salud." is API-only, not documented.
