# Módulos

## Backend — `backend/Modules/` (nwidart)

Que una tabla sea tenant-scoped lo define `BaseModel::columns($table, true)` +
el trait `BelongsToTenant`, no la carpeta ni ninguna clave de `module.json`.

### Infraestructura — 16 módulos, existentes

| Módulo | Qué hace |
|---|---|
| `Audit` | Auditoría (owen-it/laravel-auditing) |
| `Company` | Identidad legal/fiscal/branding de la organización |
| `Country` | Catálogo de países (central) |
| `Dashboard` | Métricas y checklist de onboarding |
| `Email` | Mailables + vistas Blade generadas desde React Email |
| `Module` | Registro de módulos en BD: permission_entity, ruta, icono, visibilidad |
| `Municipality` | Catálogo de municipios (central) |
| `Notification` | Motor de notificaciones multicanal + plantillas por tenant |
| `Role` | Roles y permisos (spatie/laravel-permission) |
| `Setting` | Settings key/value por tenant |
| `SubscriptionPlan` | Planes, cuotas, bandas de facturación, Stripe/Cashier |
| `System` | Agrupadores de menú; sirve `GET /api/menu` |
| `Tenant` | Gestión de organizaciones (stancl/tenancy) |
| `Tracking` | Analytics (pixels + eventos server-side) |
| `User` | Usuarios e invitaciones |
| `UserProfile` | Perfil por tenant |
| `Clinic` | `health_center`, `practitioner_profile` — estructura organizacional (centros de salud, profesionales que aplican tamizajes) |
| `ChildRecord` | `sex`, `population_type` (catálogos centrales), `caregiver_relationship`, `education_level`, `occupation` (catálogos tenant-scoped), `child`, `caregiver`, `child_caregiver`, `birth_record`, `visit` — expediente clínico del niño. PII de menores: auditoría completa vía `audits`, nunca en logs de aplicación (REQ-XC-005) |

### Dominio EDI — a construir

| Módulo | Posee | Por qué separado |
|---|---|---|
| `Instrument` | `instrument_version`, `age_group`, `screening_item`, `screening_item_option`, `risk_factor`, `head_circumference_reference`, `area_scoring_rule`, `global_scoring_rule` | El libro de reglas. CENTRAL, read-only, inmutable tras publicar |
| `Screening` | `screening`, `screening_answer`, `screening_area_result`, `screening_risk_factor_answer`, `screening_finding`, `screening_score_run`, `confirmatory_test` | Alto volumen, tenant-scoped, append-only. Acá vive el motor de calificación |
| `Stimulation` | `stimulation_session`, `stimulation_group`, `stimulation_attendance` | Segundo alcance, vendible por separado |
| `FollowUp` | `follow_up`, `referral` | FSM de seguimiento disparada por evento desde Screening |

Dependencias (acíclicas):
`Reports → {Screening, Stimulation, FollowUp} → ChildRecord → Clinic`;
`Screening → Instrument`; `FollowUp → Screening`.

## Frontend — `frontend/src/modules/`

Existentes: `audit`, `billingPackage`, `catalogs`, `checkoutSubscription`, `company`,
`dashboard`, `notification`, `onboarding`, `role`, `system`, `tenant`, `user`,
`userProfile`, `healthCenter`, `practitioner`, `child`, `caregiver`, `visit`.

`caregiver_relationship`, `education_level` y `occupation` no tienen módulo propio:
son 3 registros en `catalogs/pages.tsx` (`createSimpleCatalogPage`, variante sin
columna `order` de `createCatalogPage`). `caregiver`/`child_caregiver` no tienen
página de listado propia (D11/D17 del diseño de `edi-clinic-and-child-registry`):
se gestionan desde `ChildDetailPage` (tab "Cuidadores").

A construir: `screening`, `stimulation`, `stimulationGroup`, `followUp`,
`instrument`, `reports`.

Casi todo es CRUD con el andamiaje compartido (`createEntityStore`, `createCrudApi`,
`useBaseList`, `useBaseCrud`). Sólo dos pantallas necesitan lógica propia:

- **`ScreeningApplyPage`** — el asistente de aplicación. Los campos los determina el
  servidor (los reactivos del grupo), hay un round-trip a mitad de flujo (sustitución
  por edad corregida, bloque alterno) y el resultado llega del backend. Usa un store
  plano, no `useBaseCrud`.
- **`AttendanceSheetPage`** — grilla niños × fecha con guardado masivo idempotente.
