# AGENT.md — AI Agent Guide

Act as a Senior Software Architect and UX Expert. This is the definitive reference for AI agents working on this project.

**Product**: **Kivo7** — plataforma SaaS multi-tenant para administrar clústeres residenciales (fraccionamientos y condominios) en México. Ver `.project-context/domain.md` para el dominio completo.

> **Source-of-truth map (layering contract):**
> - `CLAUDE.md` (this file) = índice/router + filosofía + anti-patterns de frontend/meta.
> - `.agents/skills/laravel-backend-architecture/SKILL.md` = autoridad de backend (Laravel/Orion).
> - `.agents/skills/stancl-tenancy/SKILL.md` = autoridad de tenancy (multi-tenant).
> - `.project-context/*` = hechos (versiones, naming, dominio, módulos).
> - `.atl/skill-registry.md` = índice de skills.
>
> **Regla:** no repitas una regla en dos lugares; apuntá a su fuente.

---

## 1. CORE PHILOSOPHY

### KISS (Keep It Simple, Stupid)
- Prioritize the simplest, most readable solution over the "clever" or short one.
- Zero complex one-liners, zero nested ternaries, zero obscure syntax.
- Code must be self-explanatory.

### Semantic Names
- Verbs for functions: `handleCreate`, `onPageChanged`, `fetchDocuments`.
- Nouns for data: `documentList`, `selectedEntity`, `pagination`.
- Descriptive variables: `isRegisteredForDiscounts`, NOT `discount()`.

### SOLID
- Apply strictly, but without unnecessary premature abstractions.

### Active DRY
- Before generating new code, scan the context.
- If you see similar logic or UI, REUSE or extract to a hook/utility/component.
- This project already has base helpers and hooks covering 90% of CRUD cases.

### Pragmatic Atomic Design (Frontend)
- Organize by Atoms (`components/ui/`), Molecules (`shared/components/molecules/`), and Organisms (modules).
- Do NOT over-atomize. If an atom is only used in one molecule, keep it there.

### Quality, Security & Performance
- Validate all inputs, escape rendered data, handle errors with try/catch and user feedback (toasts).
- Avoid unnecessary re-renders, but don't prematurely optimize at the cost of readability.
- Strong typing: NEVER use `any` in TypeScript. Define clear interfaces.

### UX/UI
- Constant feedback: visible loading states, errors, and success messages.
- Semantic HTML and keyboard accessibility.
- **Always think webapp: mobile, tablet, and desktop.**

### Agent Response Format
1. Brief analysis: explain your approach in 1-2 sentences before coding.
2. Complete code, commented only where logic is complex.
3. If you detected duplicate code, indicate which existing component you're reusing.

---

## 2. PROJECT STACK

See `.project-context/stack.md` for frameworks, versions, and tools.

---

## 3. STRICT GENERAL RULES

These apply to the **entire project**, regardless of frontend or backend.

1. **NEVER use `any`** in TypeScript. Always define concrete interfaces or types.

2. **UI always in Spanish:** All text, placeholders, error messages, toasts, labels.

3. **Register new modules in the dynamic menu:** When creating a module that should appear in the sidebar, follow this checklist in order:

   **Backend (CSV + Seeder):**
   - `database/seeders/csv/modules.csv` — add row: `{uuid},{Spanish name},{Group}`
   - `database/seeders/csv/permissions.csv` — add 6 rows (read/create/update/delete/restore/report) referencing the `module_id`
   - `database/seeders/csv/module_system.csv` — add row assigning the module to the corresponding system. **Core is super admin only**, business modules go in RRHH, CRM, Soporte, or Fidelidad
   - `database/seeders/SystemTableSeeder.php` > `seedModulePermissionEntities()` — add `'ModuleName' => 'permission_entity'`
   - `database/seeders/SystemTableSeeder.php` > `seedModuleRoutes()` — add `'ModuleName' => '/frontend-route'`
   - `database/seeders/SystemTableSeeder.php` > `seedModuleIcons()` — add `'ModuleName' => 'IconName'` (MANDATORY: all modules must have an icon. Use icons from `frontend/src/shared/utils/iconMap.ts`)
   - `database/seeders/SystemTableSeeder.php` > `seedModuleVisibility()` — add to `$hidden` if the module is technical/internal
   - `database/seeders/RolePermissionTableSeeder.php` — **MANDATORY**: add the new module's permissions to the corresponding role array (`$permissionsOnlySuperAdmin` for Core modules, or verify they enter via `whereNotIn` for account_owner/company_admin). Without this, the user can't access the endpoint even if the permission exists in DB
   - Run: `php artisan db:seed --class=ResetPermissionTableSeeder`
   - Note: `GET /api/menu` is served by `Modules/System/app/Http/Controllers/MenuController` and consumes the assignments configured by the steps above.

   **Catálogos consumidos por dropdowns (`POST /selects`) — si el módulo introduce alguno:**
   - `backend/app/Http/Controllers/AngularController.php` > `$mapperSelects` — add one entry per new catalog
   - `frontend/src/shared/hooks/useCatalogs.ts` > `CatalogType` — add the matching member(s)
   - > Sin esto los dropdowns del módulo quedan VACÍOS en silencio (sin error) — el checklist de permisos/menú NO lo cubre. Descubierto en el módulo Complaint (2026-07-19).

   **Frontend (Router) — app React activa:**
   - `frontend/src/App.tsx` — add `<Route element={<PermissionRoute permissionEntity="entity" />}>` with path and component

   > Without these steps: sidebar won't show the module and protected routes won't work.

   **Configuración global del módulo (por tenant) — OBLIGATORIO si el módulo tiene parámetros:**
   - Si el módulo lleva config (toggles, avisos, tiempos/retención, requisitos de entrega como QR/firma, textos legales, umbrales), definila con el skill **`module-config`** (se auto-activa al crear el módulo, o invocá `/module-config`). Conduce el diálogo (propone/confirma parámetros, repregunta) y delega al agente **`module-config-scaffolder`**, que escribe el backend (`Setting` + `getX/updateXSettings` en `ConfigurationController` + rutas `config/x-settings` + **defaults sembrados en `TenantCreated`**) y registra el componente `XConfigSection` con UNA entrada en `frontend/src/modules/configuration/configSections.tsx`.

   > Sin esto: el módulo no tiene configuración por tenant ni aparece en la pantalla de Configuración del condominio.

4. **System Architecture (dynamic menu):**
   - **Core** = super admin only (no tenant). Never include Core in tenant fallback
   - Business systems for tenants = the Kivo7 functional modules (Housing, Collections, Treasury, Board, etc.)
   - Super admin does NOT have wildcard `*` — has specific permissions in `$permissionsOnlySuperAdmin` + `$commonPermissions` from `RolePermissionTableSeeder`
   - Account Owner and Company Admin receive ALL permissions EXCEPT those in `$permissionsOnlySuperAdmin` (via `whereNotIn`)

5. **Reglas de Orion/pivotes de backend -> laravel-backend-architecture skill.**

6. **Naming convention — English identifiers only (HARD):**
   - ALL identifiers in English: table names (singular), columns, model classes & properties, relationship method names, status/enum keys stored in DB, service/job/event names.
   - Spanish ONLY in: user-facing UI text, and `label`/`name`/`description` column VALUES (the data shown to users).
   - Critical disambiguations: `inquilino` → `renter` (NOT `tenant` — "tenant" = the multi-tenant cluster); `indiviso` → `ownership_share`; `folio` → `receipt_number` / `physical_receipt_number`.

7. **Money = bigInteger minor units + cknow/laravel-money:**
   - All money columns are `bigInteger` in **minor units** (cents: `150000` = $1,500.00 MXN).
   - Cast: `Cknow\Money\Casts\MoneyIntegerCast` (package `cknow/laravel-money`).
   - NEVER use `decimal` or `float` for money columns.

8. **Statuses/enums = catalog tables (NEVER hardcoded PHP enums), split CENTRAL vs TENANT-SCOPED:**
   - **CENTRAL** (FSM states + behavior selectors the code branches on): `BaseModel::columns($table, false)`, NO `BelongsToTenant`, fixed UUID constants in the model (e.g. `ChargeStatus::OVERDUE`), seeded once by `CatalogSeeder`, read-only for tenant roles. E.g. `charge_status`, `payment_status`, `charge_batch_status`, `community_project_status`, `project_contribution_status`, `board_period_status`, `amount_mode`, `periodicity`, `payment_method`, `unit_relationship`, `board_role`, etc.
   - **TENANT-SCOPED** (admin-editable label catalogs, no branching logic): `BaseModel::columns($table, true)` + `BelongsToTenant`, `unique(['tenant_id','name'])`, seeded per-tenant by `TenantCatalogSeeder` on `TenantCreated`. The 14: `unit_type`, `unit_status`, `employee_position`, `pay_frequency`, `employee_shift`, `bank_account_purpose`, `charge_concept_category`, `community_project_category`, `provider_type`, `provider_service_type`, `visitor_motive`, `announcement_type`, `expense_category`, `unit_feature`. Behavior lives in columns (`requires_floor`, `is_billable`, `grants_role`, `key` via the `HasCatalogKey` trait), never in UUIDs.
   - Decision rule: code branches on the row (FSM/behavior) → central; free classification → tenant-scoped. Contract: `CatalogSeeder` (central) vs `TenantCatalogSeeder` (tenant). References by stored FK (e.g. `charge.charge_status_id`). NEVER hardcoded constants/PHP Enums, NEVER views/on-read compute.

9. **Migrations = data dictionary (`->comment()` mandatory):**
   - EVERY own column in a migration (`create` or `alter`) MUST end with `->comment('...')` describing its purpose. Money columns note "minor units (cents)"; FKs note "FK to {table}"; flags/status note their semantics.
   - When creating a table, also comment the TABLE **inside the closure**: `$table->comment('what the entity represents');` as the first line. NEVER chain `->comment()` onto `Schema::create(...)` — it returns `void` and throws "comment() on null".
   - Do NOT comment the base columns from `BaseModel::columns()` (already commented) — only your own. Canonical rule + example: laravel-backend-architecture skill §2.5 and strict rule 13.

10. **Emails = React Email source compiled to Blade (NEVER hand-write the `.blade.php`):**
    - **Source of truth:** `frontend/emails/*.tsx` (React Email), built from the shared `frontend/emails/_components/` (`EmailLayout`, `ButtonBase`, `Title`, footer, `_theme`). To add/edit an email you create or edit the **`.tsx`**, never the Blade.
    - **Dynamic data:** emit Laravel placeholders from the `.tsx` — `<Blade variable="userName" />` → `{{ $userName }}`, or a literal `href={'{{ $acceptUrl }}'}`. There is NO Blade `@if` in this flow, so the **Mailable must pass non-null values** (use defaults in `->with([...])`) — empty vars would leave gaps.
    - **Compile step:** from `frontend/`, run `pnpm copy-emails` (it runs `email export` with `BLADE_OUTPUT=true`, copies the HTML to `backend/Modules/Email/resources/views/*.blade.php`, and static assets to `backend/public/email/static/`). This regenerates ALL blades — after running, `git checkout` any blade you did not intend to change so the diff stays scoped.
    - **Mailable:** `backend/Modules/Email/app/Emails/{Name}Mail.php` extends `Mailable`, renders `->view('email::{name}')` and injects variables via `->with([...])` (+ `emailBaseUrl`). Sent from a Service via `Mail::to(...)->send(...)`.
    - The `.blade.php` files are GENERATED OUTPUT (you'll see React Email's signature: `<!--$-->`, `data-skip-in-text`, MSO conditionals). Editing them by hand is a bug — your change is lost on the next `copy-emails`.

> For naming conventions and architecture patterns, see `conventions.md`. For frontend/backend specific rules, see the corresponding skills.

---

## 4. ANTI-PATTERNS (NEVER DO)

> STATUS: React (React 19 + Vite + Tailwind v4 + shadcn) is the ACTIVE frontend stack, in `frontend/`. This is the go-forward frontend.

- **`any` in TypeScript**: Always define interfaces. Use `IEntity` as base
- **Stores without factory**: Always use `createEntityStore`, never manual stores
- **Direct axios fetch**: Always use `createCrudApi` or `resourceApi` for REST endpoints
- **Double-fetch in stores**: Use `useInitOnce` pattern to prevent duplicate loading in React components
- **Inline/hardcoded interfaces**: NEVER define local interfaces that duplicate an existing schema. Always import `type` from the module's `schema.ts`
- **Artificial response types**: NEVER create `IXxxResponse` wrappers. Endpoints return ENTITIES. Type with entity interface (`IClient`, `ITicket`). Computed fields go as optional on the entity interface
- **Type overengineering**: `schema.ts` = entity interfaces + Zod form schema + FormValues type. Nothing more
- **`new Date()` for formatting**: Always use `displayFormatDate()` from `@/shared/utils/dateHelper`
- **Skeleton loading**: Use `boneyard-js`. `<Skeleton name="name" loading={isLoading}>`. Import from `'boneyard-js/react'`. DO NOT use Tailwind's `animate-pulse`
- **`useToast` hook**: Toast uses `sileo`, NOT shadcn. Import `{ toast }` from `@/shared/hooks/useToast`. Use `toast.success('msg')`, `toast.error('msg')`. NEVER `useToast()` or `toast({ title, variant })`
- **Hand-writing email `.blade.php`**: email Blade views are GENERATED from `frontend/emails/*.tsx` via `pnpm copy-emails`. NEVER author/edit the Blade directly — edit the React Email `.tsx` and recompile (see strict rule 10)
- **Eager catalog loading (costo de infra)**: NUNCA llamar `useCatalogs` al scope de una `List`/página ni en el cuerpo de un modal SIEMPRE montado para datos que sólo usa el form/dropdown — dispara `POST /selects` al montar la lista. Forms → load-on-form-open; filtros → load-on-dropdown-open vía `useLazyCatalogs` (+ `MultiSelectFilter onFirstOpen`). Ver react-frontend-architecture skill §2.12; auditado por `@infra-cost-reviewer`

> Anti-patterns de backend y tenancy: ver los skills laravel-backend-architecture y stancl-tenancy (para no duplicar reglas).

---

## 5. SKILL DICTIONARY

Quick reference for which skill to load based on work context.

> Índice canónico de skills: `.atl/skill-registry.md`.

### Project Architecture

| Context / Task | Skill | Path |
|---|---|---|
| Create/modify React components, modules, forms, stores (React — active stack) | `react-frontend-architecture` | `.agents/skills/react-frontend-architecture/SKILL.md` |
| Create/modify Laravel models, Orion controllers, migrations, routes, permissions | `laravel-backend-architecture` | `.agents/skills/laravel-backend-architecture/SKILL.md` |
| Full-stack new module | `react-frontend-architecture` + `laravel-backend-architecture` | Both skills |
| Definir la configuración global por tenant de un módulo (toggles, avisos, tiempos, requisitos, textos, umbrales) | `module-config` | `.agents/skills/module-config/SKILL.md` |

### Frontend Design & UI

| Context / Task | Skill | Path |
|---|---|---|
| Distinctive, high-quality web interfaces, landing pages (React — active stack) | `frontend-design` | `.agents/skills/frontend-design/SKILL.md` |
| Dashboards, admin panels, SaaS apps, product interfaces (React — active stack) | `interface-design` | `.agents/skills/interface-design/SKILL.md` |

### Quality & Security

| Context / Task | Skill | Path |
|---|---|---|
| Web accessibility (WCAG 2.1, ARIA, keyboard nav) | `web-accessibility` | `.agents/skills/web-accessibility/SKILL.md` |
| API security, OWASP Top 10, rate limiting, secrets | `owasp-security` | `.agents/skills/owasp-security/SKILL.md` |
| Bug investigation, test failures, unexpected behavior | `systematic-debugging` | `.agents/skills/systematic-debugging/SKILL.md` |
| React code scan for security, performance, architecture (React — active stack) | `react-doctor` | `.agents/skills/react-doctor/SKILL.md` |

### Content, SEO & Database

| Context / Task | Skill | Path |
|---|---|---|
| Marketing copy, headlines, value propositions | `copywriting` | `.agents/skills/copywriting/SKILL.md` |
| DB schema design, relations, indexes, migrations | `database-schema-designer` | `.agents/skills/database-schema-designer/SKILL.md` |

### Testing & Planning

| Context / Task | Skill | Path |
|---|---|---|
| Explore ideas, define requirements, validate design | `brainstorming` | `.agents/skills/brainstorming/SKILL.md` |

### SDD (Spec-Driven Development)

| Context / Task | Skill |
|---|---|
| Initialize SDD context | `sdd-init` |
| Explore code area before proposing a change | `sdd-explore` |
| Create change proposal (intent, scope, approach) | `sdd-propose` |
| Write technical spec with requirements and scenarios | `sdd-spec` |
| Design technical solution with architecture decisions | `sdd-design` |
| Generate implementation tasks organized by phases | `sdd-tasks` |
| Apply plan changes, write code per specs and design | `sdd-apply` |
| Verify implementation vs spec (compliance, tests, build) | `sdd-verify` |
| Archive completed change, sync delta specs to main | `sdd-archive` |

### How to Load a Skill

Before writing code, read the corresponding skill:
- **Frontend** (React — active stack): `.agents/skills/react-frontend-architecture/SKILL.md`
- **Backend**: `.agents/skills/laravel-backend-architecture/SKILL.md`
- **Full-stack**: Both skills
- **Other**: The corresponding `SKILL.md`

### Golden Rule

> Not sure which skill? Ask: "Am I touching files in `src/`?" > Frontend. "In `backend/`?" > Backend. "Both?" > Both. "New UI design?" > frontend-design or interface-design. "Debugging?" > systematic-debugging. "Large multi-file feature?" > SDD workflow.
>
> Nota: el frontend `src/` (React) es el stack activo. Stack canónico: `.project-context/stack.md`.

---

## Review Pipeline — provided by the `qch` plugin

The fullstack review pipeline (5 specialist reviewers + `/review-stack` command) lives in the **qch** plugin, not in this repo. Install qch once and every Laravel+React project (including this one) gets:

| Reviewer | Scope |
|----------|-------|
| `laravel-architecture-reviewer` | Backend (Laravel/Orion/SOLID) |
| `react-frontend-reviewer` | Frontend (React/TS/shadcn/Zustand/TanStack) |
| `integration-reviewer` | Contract seam (back ↔ front) |
| `tenancy-reviewer` | Multi-tenant isolation only |
| `database-architect` | DB design, optimization, queries |

**Command**: `/review-stack [--scope <path>] [--since <ref>]` — launches the relevant reviewers in parallel based on which files changed.

Reviewers load skills with fallback: project-local (`.agents/skills/crm-*`) wins if present, otherwise the plugin's bundled version is used. If you need a project-specific override of any `crm-*` skill, keep it in `.agents/skills/` — the reviewer picks it up automatically.

### Install qch

```bash
/plugin marketplace add ~/code/ia-plugin     # or Gentleman-Programming/qch once published
/plugin install qch@qch
```

### Complement: judgment-day

For deep adversarial review (two blind judges) on top of the specialists, use the `judgment-day` skill (also in qch). Good for milestones or PRs needing extra confidence.

### Sub-agents vs Agent Teams — quick rule

If workers could do their job with zero knowledge of each other → **sub-agents** (qch's default). If workers' value comes from challenging or coordinating with each other → **Agent Teams** (experimental, token-heavy). For review pipelines, stick with sub-agents.

---

## Project module experts & reviewers — cuándo delegar (y por qué a veces "no se cargan")

Este proyecto define subagentes especialistas en `.claude/agents/` (locales) y vía el plugin `qch`, con memoria persistente en `.claude/agent-memory/<name>/`. Cuando una tarea toca el área que un experto posee, **delegá a ese experto** en vez de hacerlo en el thread principal: cargan conocimiento acumulado del módulo que el thread principal no tiene, y mantienen su propio `MEMORY.md`. Saltearlos tira ese aprendizaje a la basura.

> **⚠️ Si sentís que un experto "no se carga", son SIEMPRE una de estas dos causas:**
> 1. **No se recargó el registry.** Un experto recién creado o editado NO entra solo a mitad de sesión. Corré `/agents` (recarga el registry) o reiniciá Claude Code. *Síntoma:* el agente no figura como tipo disponible al delegar.
> 2. **El trigger no matcheó.** El thread principal decide delegar leyendo el `description` del agente + esta tabla. Si el path/keyword es vago, no dispara y termina haciéndolo inline. *Mitigación:* los triggers de abajo son explícitos — **si tu tarea matchea CUALQUIER ítem de una fila → delegá, no lo hagas inline.**

### A. Module experts — DELEGAR para HACER el trabajo (proactivo, por path)

Estos escriben código del módulo. Si vas a tocar su área, abrís el trabajo delegando a ellos (no escribís vos y después les avisás).

| Delegá a | Cuándo (cualquiera de estos triggers) |
|---|---|
| `@notifications-expert` | `backend/Modules/Notification/**` · `backend/app/Notifications/**` · el dispatcher o cualquier channel (`BladeEmailChannel`, `FirebasePushChannel`, SMS/WhatsApp) · `NotifiesResidents` · `NotificationSettingService` · la config de notificaciones en `Modules/Configuration` (`/config/notifications`, tab "Notificaciones") · Firebase push (`user.token_sender`, `SendPushNotificationJob`, `FIREBASE_CREDENTIALS`) · cualquier flujo de notificación multi-canal (in-app / email-como-notificación / push / SMS / WhatsApp) · routing por board-role de destinatarios |
| `@email-expert` | `frontend/emails/**` (fuentes React Email) · `backend/Modules/Email/**` (Mailables, Blade generado, pipeline `copy-emails`) · crear/editar un template de email · wiring de un Mailable · bug de render · cambiar el copy de un correo transaccional |
| `@module-config-scaffolder` | escribir la **configuración global por tenant** de un módulo: tabla `Setting` + `getX/updateXSettings` en `ConfigurationController` + rutas `config/x-settings` + **defaults sembrados en `TenantCreated`** + componente `XConfigSection` registrado con UNA entrada en `frontend/src/modules/configuration/configSections.tsx`. Normalmente lo dispara el skill `module-config` (que conduce el diálogo proponé/confirmá); también podés delegarle directo con un manifiesto ya cerrado. NO construye la lógica de dominio del módulo |
| `@collections-expert` | `backend/Modules/Collections/**` · el clúster de frontend de cobranza (`frontend/src/modules/{charge,chargeBatch,chargeConcept,chargePolicy,payment}/**`, `configuration/components/CollectionConfigSection.tsx`) · cómo se CALCULAN los montos (`AmountCalculator`, `ownership_share`, prorrateo) · generación de cargos (`TargetResolver`, `ChargeGenerationService`, `charge_batch`) · aplicación FIFO de pagos + `credit_balance` (`PaymentService`) · FSM de estados (`ChargeStatusService`, worst-wins en `unit.charge_status_id`) · cron diario de transiciones temporales (`RefreshChargeStatusesCommand`) · recargos (`SurchargeMode`, `ChargeSurchargeRule`) · `receipt_number` (`ReceiptSequence`) · config de cobranza (`tenant_collection_setting`, `CollectionSettings`) · reportes/aging (`CollectionsReportService`) · detectar errores y huecos en REQUERIMIENTOS de cobranza |

| `@docs-writer` | el **manual público de producto** publicado en `kivo7.com/docs` (`landing/src/content/docs/docs/**`) · escribir una página nueva del manual · auditar el manual contra la app · corregir desfase. Se dispara también cuando un cambio pudo dejar la doc mentirosa: `frontend/src/modules/**`, `frontend/src/App.tsx`, `configSections.tsx`, `modules.csv`, `RolePermissionTableSeeder.php`, `CatalogSeeder.php`/`TenantCatalogSeeder.php`, `PlanModuleMatrixSeeder.php`. Escribe documentación de USUARIO FINAL en español de México; **nunca** escribe código de producto, ni docs de arquitectura/API. Su contrato de escritura es `landing/DOCS-STYLE-GUIDE.md`. El hook `Stop` `.claude/hooks/docs-drift-check.sh` avisa cuándo conviene lanzarlo |

**Solape notifications ↔ email:** flujo de NOTIFICACIÓN (canales, destinatarios, config, push, in-app, email-como-notificación) → `@notifications-expert`. Autoría/copy de un TEMPLATE puro sin relación con el motor de notificaciones → `@email-expert`. Si abarca ambos, **`@notifications-expert` lidera** y jala la parte de email.

**Solape docs ↔ expertos de módulo:** `@docs-writer` documenta, no investiga desde cero. Para el detalle fino de un módulo con experto propio (Cobranza, Notificaciones, Email), que se lo pida a ese experto en vez de reconstruirlo.

**Módulos de dominio SIN experto todavía** (Housing, Treasury, Board & Staff, Access Control, Community Projects, Providers, Chat, Reports, Cluster Config): no tienen experto dedicado. Para esos trabajá con los skills `laravel-backend-architecture` / `react-frontend-architecture` o el pipeline SDD. Si querés crear uno, se genera con `/create-module-expert` (después acordate de mapearlo acá y correr `/agents`).

### B. Reviewers — correr DESPUÉS de escribir código (AUDITAN, no hacen)

NO se les delega "escribí X" — eso es un error de categoría. Escribís el código (skill correspondiente / `sdd-apply`) y **LUEGO** los corrés, en paralelo, vía `/review-stack` (ver sección "Review Pipeline"). También tienen memoria en `.claude/agent-memory/`.

| Reviewer | Cuándo correrlo |
|---|---|
| `@laravel-architecture-reviewer` | tras escribir/modificar backend Laravel/Orion (SOLID, layering, ubicación de archivos) |
| `@react-frontend-reviewer` | tras tocar `frontend/` (componentes, hooks, stores, services, forms) |
| `@integration-reviewer` | cuando cambian back **Y** front juntos, o se toca un archivo de contrato (schema/endpoint) |
| `@tenancy-reviewer` | cuando se toca algo tenant-scoped, middleware de tenancy o `config/tenancy.php` |
| `@database-architect` | diseño de schema, índices, optimización de queries, modelado (dual-uso: además de auditar, **también DISEÑA**) |
| `@infra-cost-reviewer` | tras escribir código que hace fetching (listas/forms/dropdowns, endpoints, queries): caza carga **eager** de catálogos, over-fetch sin `enabled`, N+1, falta de cache/paginación, polling. Objetivo del proyecto = minimizar costo de infra. Local (corré a mano, no entra en `/review-stack`) |

### Reglas
- **Para trabajo sustancial multi-archivo**, el experto recomienda el pipeline **SDD** en vez de editar directo — honralo.
- **Cuándo NO delegar:** preguntas read-only generales, tareas cross-cutting que ningún experto solo posee, o cuando vos decís explícitamente "hacelo en el thread principal".
- **Mantenimiento:** cada vez que agregás/renombrás un experto en `.claude/agents/`, actualizá esta tabla **y** corré `/agents`. Tabla desactualizada o registry sin recargar = experto que "no se carga".

---

## Entornos de desarrollo local & QA — acceder por la IP de LAN, NO por `localhost`

Los servidores de dev corren atados a la **IP de LAN** (no a `localhost`).

> **NUNCA escribas la IP ni los puertos a mano acá.** La IP cambia con DHCP y los
> puertos también se mueven: `bin/urls.sh` documenta que Vite salta al siguiente
> puerto libre cuando el contenedor `laravel.test` ya publicó `VITE_PORT`. Cada
> valor hardcodeado en esta tabla terminó desactualizado y costó una sesión de
> depuración creyendo que el backend estaba caído.

**Cómo obtener los valores reales, siempre:**

```bash
./backend/bin/urls.sh          # puertos REALES, leídos de Docker en vivo
ipconfig getifaddr en0          # IP de LAN (macOS). Linux/WSL: hostname -I | awk '{print $1}'
```

| Servicio | Cómo se arma |
|---|---|
| API (backend Laravel) | `http://<IP-de-LAN>:<APP_PORT>/api` — es a donde debe apuntar `VITE_API_BASE_URL` |
| App React (frontend) | `http://<IP-de-LAN>:<puerto que reporte urls.sh>/` |
| Landing (Astro, público sin auth) | `http://localhost:4321/` (acá sí da igual; no usa sesión) |

> **REGLA (QA / Playwright / navegador): abre la app React autenticada por la MISMA IP que la API, NUNCA por `localhost`.** La auth de Sanctum usa una cookie httpOnly de refresh ligada al ORIGEN; si mezclas origen `localhost` (app) con la IP (API), la cookie no matchea y **la sesión se cae a `/login`** en la primera navegación/refresh de la SPA. Usando la misma IP para ambos, la sesión se mantiene.

> **Si la app carga pero nada responde, o el backend "no levanta": es casi siempre un desfase de IP.** DHCP le cambió la dirección a la máquina y quedó la vieja escrita en los `.env`. Revisa que coincidan:
>
> ```bash
> ipconfig getifaddr en0                                    # la IP real de hoy
> grep -h VITE_API_BASE_URL frontend/.env frontend/.env.localhost frontend/.env.staging
> grep -E '^(APP_URL|APP_FRONTEND_URL|SANCTUM_STATEFUL_DOMAINS)' backend/.env
> ```
>
> `frontend/.env.localhost` es el que de verdad usa `start.sh` (`pnpm dev:localhost` → `vite --mode localhost`); el `.env` a secas se carga siempre pero el de modo le gana. Tras corregir `backend/.env` hay que **reiniciar el contenedor** para que lo lea.

> **REGLA (HARD) — la herramienta de navegador para pruebas es SIEMPRE Playwright (`mcp__playwright__*`). NUNCA la extensión del navegador (`mcp__claude-in-chrome__*`) ni `chrome-devtools-mcp`.** Cualquier QA, smoke test, reproducción de bug, captura de pantalla o navegación de la app se hace con Playwright. No asumas que la extensión de Chrome está disponible: puede figurar en la lista de MCPs y aun así no estar conectada/permitida, y depende de una sesión de Chrome del usuario. Playwright es el único camino soportado y reproducible.
>
> Aplica también a los subagentes: si delegás QA de navegador, indicale explícitamente al agente que use Playwright. Si Playwright falla, reportá el error — no hagas fallback a la extensión.

### Cómo correr los tests de backend (COPIA ESTO, no improvises)

Desde `backend/`. Una sola vez por sesión, deja arriba el Postgres de tests:

```bash
docker compose up -d pgsql.test
```

Y corre así — **este es el comando, no inventes otro**:

```bash
# Suite completa (paralelo, ~8 min). 10 procesos es el punto óptimo medido:
# 16 da un 8% (ruido) y 20 EMPEORA (188s / 173s / 215s en Collections).
DB_HOST=127.0.0.1 DB_PORT=5441 DB_USERNAME=rodriguez DB_PASSWORD=password \
  php artisan test --parallel --processes=10

# Un archivo / módulo (serie; para poco test el paralelo no compensa el arranque)
DB_HOST=127.0.0.1 DB_PORT=5441 DB_USERNAME=rodriguez DB_PASSWORD=password \
  php artisan test --compact tests/Feature/Collections
```

Formateo (no necesita DB): `./vendor/bin/pint --dirty --format agent`.

**Por qué esas variables son OBLIGATORIAS.** `phpunit.xml` fija `DB_HOST=pgsql.test`, un nombre de la red de Docker que el host NO resuelve (`could not translate host name`). El Dotenv de Laravel es inmutable, así que las variables reales de entorno le ganan a `phpunit.xml`. Sin ellas no corre.

**Por qué el puerto 5441 y no 5440.** `pgsql.test` es un Postgres dedicado con el data directory en **tmpfs (RAM)** y `fsync=off` — hecho a propósito para tests. Antes no publicaba puerto al host, así que todo caía a `pgsql` (5440), que es la base de DESARROLLO, en disco y con durabilidad completa. Hoy publica `FORWARD_TEST_DB_PORT=5441` (variable propia: NUNCA compartas `FORWARD_DB_PORT` entre dos servicios de DB, ambos pelean el mismo puerto del host).

**UN SOLO proceso de test a la vez.** `RefreshDatabase` hace `migrate:fresh` (DROP ALL + remigrar) una vez por proceso. Dos corridas simultáneas (CLI + runner del IDE, o dos agentes) se pisan el esquema → fallos NO deterministas con firma `SQLSTATE 42P01/42703 "relation/column ... of relation 'tenant' does not exist"`. No es bug de código ni de aislamiento: es concurrencia. `--parallel` sí es seguro (paratest usa `testing_test_1..N`, bases separadas), pero **dos comandos a la vez no**.

**NO corras los tests con `docker exec` ni con `sail`.** Dentro del contenedor `CACHE_STORE` no resuelve a `array` y los 238 tests truenan en `tests/TestCase.php:61` (`Cache::flush()`). Verificado 2026-08-08. El camino soportado es PHP del host con el comando de arriba.

**Si `--parallel` truena con `SQLSTATE 08006 Connection refused`:** se llenó la tmpfs y Postgres murió (`docker logs kivo7-pgsql.test-1` muestra `No space left on device`). Revive con `docker compose up -d --force-recreate pgsql.test` — es RAM, recrear es instantáneo y gratis.

> **La causa NO es el tamaño de la tmpfs, es el WAL.** Diagnóstico real (2026-08-08): `pg_wal` había crecido a **2.7 G** mientras los datos pesaban 450 M. `max_wal_size` es lo que el WAL alcanza antes de forzar un checkpoint, y estaba en `4GB` sobre un disco de 4 GB: Postgres tenía permiso de llenarlo él solo. **Subir el `size` de la tmpfs no arregla nada** — el techo del WAL escala con el disco que le des (se intentó 2g→4g y en el repo hermano 2g→5g; solo pospone el choque). Hoy está acotado en `max_wal_size=768MB` + `checkpoint_timeout=5min`; checkpointear seguido es barato porque es RAM con `fsync=off`. Efecto medido: corrida 11:24 → **8:06** y uso del disco 77% → 26%.
>
> **NO enciendas `autovacuum` para "recuperar espacio".** Se probó el 2026-08-08 con esa teoría: no era la causa (era el WAL) y costó 80% de velocidad — 11:24 → 20:27 en el mismo suite.

Tras un `migrate:fresh`, corre `php artisan billing:sync-stripe` para que el catálogo de billing tenga los `stripe_price_id`.

### Variables de puerto de Sail (`.env` de `backend/`) y correr VARIOS proyectos a la vez

Este repo (kivo7) y otros proyectos Sail (p.ej. `gestioly`) pueden convivir levantados al mismo tiempo. Solo chocan los **puertos publicados en el host**; los servicios de infra pueden duplicarse sin problema. Reglas:

**1. `DB_HOST` / `DB_PORT` = conexión INTERNA de la app. NUNCA se tocan.**
- La app (Octane dentro del contenedor) SIEMPRE conecta por el nombre de servicio de la red Docker: `DB_HOST=pgsql`, `DB_PORT=5432` (puerto INTERNO, fijo). Igual `REDIS_HOST=valkey`, `MAIL_HOST=mailpit`.
- Estos nombres (`pgsql`, `valkey`…) **solo resuelven DENTRO de Docker**. Por eso **corre artisan SIEMPRE con `./vendor/bin/sail artisan ...`**, nunca `php artisan` pelón (desde el host `pgsql` no existe → `could not translate host name "pgsql"`).

**2. `FORWARD_*` / `APP_PORT` / `VITE_PORT` = puertos PUBLICADOS al host (tu Mac).** Solo sirven para: navegador, TablePlus, y para que dos proyectos no choquen. NO cambian el puerto interno. `FORWARD_DB_PORT=5440` significa "veo el Postgres de kivo7 en `127.0.0.1:5440`", pero la app adentro sigue usando `pgsql:5432`.
- **Solo se APLICAN con `sail up`.** `sail artisan` con el stack abajo hace `docker compose run` que NO publica puertos → el contenedor queda `5432/tcp` pelón y a veces sin red. Si `pgsql` "no resuelve" ni por dentro, revisa que el contenedor esté en la red (`docker inspect <c> --format '{{.NetworkSettings.Networks}}'`) y recrea con `sail down && sail up -d`.

**3. Cada proyecto necesita `COMPOSE_PROJECT_NAME` único + puertos de host únicos.** Mapa actual de kivo7 (todos distintos de los de gestioly, que usa los base 5432/3306/6379/…):

| Var | kivo7 | Nota |
|---|---|---|
| `COMPOSE_PROJECT_NAME` | `kivo7` | namespacea contenedores y red (`kivo7_sail`) |
| `APP_PORT` / `VITE_PORT` | `8089` / `5189` | web + vite |
| `FORWARD_DB_PORT` | `5440` | Postgres publicado (interno sigue 5432) |
| `FORWARD_MARIADB_PORT` | `3316` | MariaDB publicado (interno 3306) |
| `FORWARD_VALKEY_PORT` | `6389` | |
| `FORWARD_MEILISEARCH_PORT` | `7709` | |
| `FORWARD_RUSTFS_PORT` / `_CONSOLE_PORT` | `9009` / `9010` | |
| `FORWARD_MAILPIT_PORT` / `_DASHBOARD_PORT` | `1029` / `8029` | SMTP / UI |
| `FORWARD_RABBITMQ_PORT` / `_DASHBOARD_PORT` | `5679` / `15679` | |
| `PUSHER_PORT` / `PUSHER_METRICS_PORT` | `6009` / `9609` | soketi |

> **GOTCHA (arreglado 2026-07-25):** en `compose.yaml`, `pgsql` y `mariadb` compartían la MISMA `FORWARD_DB_PORT` → ambas intentaban bindear el mismo puerto del host → `Bind for 0.0.0.0:XXXX failed: port is already allocated` y `sail up` quedaba a medias (pgsql sin red). Fix: `mariadb` ahora usa su propia `${FORWARD_MARIADB_PORT:-3306}`. Si clonas el patrón a otro proyecto, dale a cada servicio de DB su propia variable de forward.

> **`port is already allocated` en puertos consecutivos** = casi siempre puertos fantasma de Docker Desktop (contenedores muertos sucio). Limpia: `sail down --remove-orphans` → `docker container prune -f` → `docker network prune -f`; si persiste, reinicia Docker Desktop.

> **OJO tests desde el host:** la nota de arriba ("Postgres host `127.0.0.1:5432`") aplica cuando kivo7 tiene su pgsql publicado en 5432. Si corres kivo7 con `FORWARD_DB_PORT=5440` (para convivir con otro proyecto en 5432), los comandos desde el HOST deben apuntar a `127.0.0.1:5440`. Lo más simple: corre tests/seed **por sail** (`./vendor/bin/sail artisan ...`) y olvídate del puerto del host.

---

## Dev tooling — WebMCP spike (EXPERIMENTAL, dev-only)

> **Estado: SPIKE / POC. NO es producción.** WebMCP es un W3C Community Group *draft* (origin trial en Chrome 149), no un estándar — la API puede cambiar. Sirve HOY para **QA / dogfooding asistido por IA**. NO construir features de producción sobre esto sin una evaluación formal (alternativa más sólida para "IA opera Kivo7": un **MCP server server-side** sobre la API Orion REST, que funciona sin depender del browser ni del estándar emergente).

**Qué hay**: un provider dev-only que expone tools de Kivo7 a agentes de IA en el browser vía `navigator.modelContext`. Aislado en `frontend/src/dev/webmcp/` (`types.ts`, `tools.ts`, `provider.ts`) + un `if` en `frontend/src/main.tsx`. Gateado por `import.meta.env.DEV` (dead-code en `vite build` → **cero superficie en prod**) y kill-switch `VITE_WEBMCP_ENABLED=false`. Revertir = `rm -rf frontend/src/dev/webmcp` + quitar el `if` de `main.tsx`.

**Cómo sé si está disponible (probe de detección)** — ejecutar en la página con Playwright (`mcp__playwright__browser_evaluate`); la referencia histórica a `evaluate_script` de chrome-devtools MCP queda solo como contexto del spike, NO como herramienta a usar (ver la REGLA HARD de Playwright arriba):
```js
const mc = navigator.modelContext
const tools = mc ? await mc.getTools() : []
// disponible si: mc existe Y tools.length > 0. Marcador de auto-anuncio: window.__KIVO_WEBMCP__
```

**Contrato del API nativo (Chrome 149, verificado)**:
- `navigator.modelContext.registerTool({ name, description, inputSchema, async execute(args) → { content:[{type:'text',text}] } })` (devuelve void)
- `await navigator.modelContext.getTools()` → **Promise**<RegisteredTool[]> (asíncrono)
- `await navigator.modelContext.executeTool(registeredTool, JSON.stringify(args))` (1er arg = handle de getTools, NO el nombre; 2do arg = args como **JSON string**)

**Activar el API nativo**: Chrome 149+, flag `chrome://flags/#enable-webmcp-testing` = Enabled + relaunch; entrar por **`localhost`** en el puerto que reporte `./backend/bin/urls.sh` (una IP de LAN NO es secure context, `localhost` sí). Es la única excepción a la regla de abrir la app por la IP: acá no hay sesión de Sanctum en juego.

**Regla para el agente**: si trabajás sobre Kivo7 y el probe devuelve tools → podés usarlas para QA / reproducir flujos (`whoami`, `list_capabilities`, …). NO asumas que está adoptado en prod. Detalle completo en engram (topic `webmcp/native-api-and-activation`).
