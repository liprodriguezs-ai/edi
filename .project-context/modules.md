# Project Modules

## Frontend

> STATUS: React (React 19 + Vite + Tailwind v4 + shadcn) es el stack de frontend ACTIVO, en `frontend/`. Stack canónico: `stack.md`.

Modules live in `frontend/src/modules/`. Each module follows the structure: `schema.ts`, `Form.tsx`, `List.tsx`, `Detail.tsx`, `hooks/`, `services/`, `store/`.

> Los módulos de frontend listados son los módulos React activos del proyecto.

## Backend

Modules live in `backend/Modules/`. Each module follows the Nwidart structure: `app/` (Controllers, Models, Services, Requests), `config/`, `database/` (migrations, seeders, factories), `routes/` (api.php, web.php).

> The `module.json` `"tenancy"` key was REMOVED — it no longer exists or classifies anything. Whether a table is tenant-scoped is expressed ONLY by `BaseModel::columns($table, true)` + the `BelongsToTenant` trait on the model. Central/platform modules (Tenant, Role, User, System, Country, Municipality) are NOT tenant-scoped. Canonical: stancl-tenancy skill.

### Infrastructure Modules (15 — currently on disk)

These are the surviving base modules after the CRM pivot. They provide the platform foundation for Kivo7.

| Module | Directory | Status | Description |
|--------|-----------|--------|-------------|
| Audit | `Audit/` | Active | System auditing (Laravel Auditing integration) |
| Collaborator | `Collaborator/` | Active → **becoming Employee** | Repurposed as `employee` for cluster staff (security, gardener, cleaning, maintenance, administrator) |
| Company | `Company/` | Active | Cluster legal/fiscal/branding identity (legal_name, rfc, address, logo, colors, tagline) |
| Country | `Country/` | Active | Country geographic catalog (central, not tenant-scoped) |
| Dashboard | `Dashboard/` | Active | Metrics and statistics |
| Email | `Email/` | Active | Email sending and management |
| Module | `Module/` | Active | Meta-module management |
| Municipality | `Municipality/` | Active | Municipality geographic catalog (central, not tenant-scoped) |
| Notification | `Notification/` | Active | Push/in-app notifications (Firebase) |
| Role | `Role/` | Active | Roles and permissions (Spatie); holds the 11 Kivo7 roles |
| Setting | `Setting/` | Active | System settings |
| SubscriptionPlan | `SubscriptionPlan/` | Active | Subscription plans (Stripe / Cashier) |
| System | `System/` | Active | Top-level aggregate: modules ↔ systems ↔ tenants ↔ plans; serves `GET /api/menu` |
| Tenant | `Tenant/` | Active | Tenant/cluster management + `TenantPaymentType`; backbone for Stancl Tenancy |
| User | `User/` | Active | System users ("Everything is User" pattern; email/password nullable + `auth_active`) |

### Kivo7 Functional Modules (12 — BUILT)

> **Estado (2026-07): los 12 están CONSTRUIDOS** y en producción (backend `Modules/` + frontend `src/modules/`). La tabla de abajo es la referencia de dominio, ya NO un backlog. Además, tres módulos enviados NO figuran en esta tabla y existen como módulos propios: **Package** (paquetería), **Tracking** (marketing/SEO — Facebook CAPI + GA4) y **DataImport** (importación masiva vía Excel); **UserProfile** también es su propio módulo. Los ítems genuinamente futuros están en "Not in v1.0 (v1.1+)" más abajo.

These modules implement the Kivo7 residential-complex domain. Build order followed the dependency chain.

| # | Module | Key Tables (EN, singular) | Description |
|---|--------|--------------------------|-------------|
| 1 | **Auth / Onboarding** | `user_profile` | Cluster selection, onboarding flow, per-cluster PII. Extends the existing Sanctum auth |
| 2 | **Housing** | `tower`, `section`, `unit`, `unit_feature`, `unit_unit_feature`, `unit_user`, `vehicle`, `unit_status` | Physical structure of the cluster + residents management. Status catalog: `unit_status` |
| 3 | **Board & Staff** | `board_period`, `board_member`, `employee`, `board_period_status` | Mesa directiva (grants/revokes Spatie roles on open/close) + cluster employees (repurposed Collaborator). Status catalog: `board_period_status` |
| 4 | **Collections** | `charge_concept`, `charge_batch`, `charge`, `payment`, `payment_application`, `credit_balance`, `charge_status`, `payment_status`, `charge_batch_status`, `tenant_collection_setting` | Hardest module: FIFO payments, status FSM (ChargeStatusService), daily time-transition cron, TargetResolver, AmountCalculator, receipt_number generation. All money in bigint minor units |
| 5 | **Treasury** | `bank_account`, `expense`, `expense_category` | Ingresos/egresos del clúster. `bank_account.current_balance` recomputed transactionally. Expense links nullable to employee/provider/community_project |
| 6 | **Providers** | `provider` | Proveedores de servicios del clúster (service_type, is_recurring, estimated_amount, usual_payment_day) |
| 7 | **Community Projects** | `community_project`, `project_contribution`, `project_comment`, `community_project_status`, `project_contribution_status` | Proyectos obligatorios (generan cargos) y voluntarios (aportaciones libres). States: ideal/generous/declined |
| 8 | **Announcements** | `announcement`, `announcement_read` | Comunicados y avisos del clúster; despachados vía módulo Notification |
| 9 | **Chat** | `chat_channel`, `chat_channel_member`, `chat_message`, `chat_message_report` | Chat comunitario con canales de sistema no eliminables. Mensajes ocultos, nunca borrados físicamente |
| 10 | **Access Control** | `visitor`, `access_log`, `blacklist_entry` | QR de visita, bitácora inmutable (`access_log`), lista negra |
| 11 | **Reports** | `scheduled_report` | Reportes y exportación. Lectura de auditoría desde módulo Audit. `reportColumns()` wired incrementally |
| 12 | **Cluster Configuration** | `tenant_collection_setting`, `notification_template` | Configuración operacional del clúster (timezone, currency, type, uses_ownership_share, collection settings, notification templates). Edita la `company` principal (legal/branding data) |

### Key Catalog Tables — CENTRAL vs TENANT-SCOPED

Los catálogos se dividen en dos grupos. La decisión: un catálogo que el código
ramifica (FSM de estados, selector de comportamiento) es CENTRAL; un catálogo
de etiquetas que el admin del clúster edita y cuyas filas no llevan lógica de
negocio es TENANT-SCOPED. Verificar SIEMPRE contra `CatalogSeeder` (central) y
`TenantCatalogSeeder` (tenant) — esos seeders son el contrato.

**CENTRALES (global, sin `tenant_id`, `BaseModel::columns($table, false)`, sin `BelongsToTenant`, UUIDs fijos como constantes de modelo, sembrados una vez por `CatalogSeeder`, read-only para roles del clúster).** Incluye los 6 FSM de estados de abajo más selectores de comportamiento (`amount_mode`, `periodicity`, `payment_method`, `beneficiary_type`, `bank_account_type`, `unit_relationship`, `board_role`, `participation_type`, `chat_channel_type`, `qr_type`, `access_entry_type`, `access_verification`, `blacklist_type`, `visitor_status`, `announcement_priority`, `announcement_status`, `tenant_payment_type`):

| Catalog Table (CENTRAL) | Keys (EN) | Owner Module |
|---|---|---|
| `charge_status` | completed, partial, overdue, due_soon, overpaid, pending, payment_plan | Collections |
| `payment_status` | confirmed, pending_validation, cancelled | Collections |
| `charge_batch_status` | draft, active, cancelled | Collections |
| `community_project_status` | draft, active, fully_funded, executed, cancelled | Community Projects |
| `project_contribution_status` | pending, partial, ideal, generous, below_min, declined | Community Projects |
| `board_period_status` | current, closed | Board & Staff |

**TENANT-SCOPED (con `tenant_id` vía `BelongsToTenant`, `unique(['tenant_id','name'])`, sembrados POR tenant por `TenantCatalogSeeder` en `TenantCreated`, administrables por el admin del clúster).** Los 14: `unit_type`, `unit_status`, `employee_position`, `pay_frequency`, `employee_shift`, `bank_account_purpose`, `charge_concept_category`, `community_project_category`, `provider_type`, `provider_service_type`, `visitor_motive`, `announcement_type`, `expense_category`, `unit_feature`. Su lógica de negocio (cuando la hay) vive en columnas, no en UUIDs: `requires_floor` (unit_type), `is_billable` (unit_status), `grants_role` (employee_position), `key` (announcement_type, charge_concept_category — "filas de sistema" protegidas por el trait `HasCatalogKey`).

## Module Dependencies

### Core Dependencies (all modules depend on these)
- **User**: Authentication, audit trails (`user_created_id`, etc.), "Everything is User" pattern
- **Tenant**: Multi-tenant data isolation (single-DB row-level via `tenant_id`)
- **Company**: Cluster legal/fiscal/branding identity
- **Role**: Spatie permissions (11 Kivo7 roles)

### Functional Dependencies (Kivo7)
- `System` → `Module` (via `module_system`), `Tenant` (via `system_tenant`), `SubscriptionPlan` (via `subscription_plan_system`)
- `Housing` → `Tenant`, `User` (via `unit_user`)
- `Board & Staff` → `User` (board_member grants Spatie role), `Collaborator` (repurposed as Employee)
- `Collections` → `Housing` (unit), `Treasury` (bank_account for payment deposit)
- `Treasury` → `Collections` (payments), `Providers`, `Employee`, `Community Projects`
- `Community Projects` → `Housing` (unit), `Collections` (generates charges for mandatory projects)
- `Announcements` → `Notification`
- `Access Control` → `Housing` (unit, visitor linked to unit_user)
- `Reports` → `Audit`, all domain modules (read-only)
- `Cluster Configuration` → `Company` (edits legal/branding), `Tenant` (edits operational config)

## Roadmap

Modules being built (Kivo7 v1.0):
- Phase 0: Foundation stabilization (15 surviving modules, green baseline)
- Phase 1: Auth/Onboarding + Housing + Board & Staff base (Roles + cluster config)
- Phase 2: Collections (hardest — FIFO, status FSM, crons)
- Phase 2 cont.: Treasury, Providers, Community Projects, Announcements, Chat, Access Control, Reports, Cluster Configuration

Not in v1.0 (v1.1+): payroll detail, bank reconciliation, digital assemblies, common areas, online payments, license plate recognition.
