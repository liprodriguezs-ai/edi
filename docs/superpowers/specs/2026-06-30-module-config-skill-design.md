# Module Config Skill + Scaffolder Agent — Design

**Date:** 2026-06-30
**Status:** Approved (Parte A + Parte B), pending implementation
**Constraint:** Apply on the CURRENT branch (`main`), on EXISTING code. No new branch, no worktree, no parallel system. Reuse `Setting`, `ConfigurationController`, `ConfigurationPage`.

## Problem

Every new Kivo7 module needs its own per-tenant "global configuration" (like Paquetería has: enable signature/QR, notify-on-arrival, retention days, etc.). Today this is created ad-hoc by copying the Package pattern, with no guided flow and no guarantee the config component is registered into the tenant Configuration screen. We want a repeatable, interactive flow that fires whenever a new feature/module is created.

## What we build (the TOOL, not a specific module's config)

Two artifacts + wiring. The agent is only *used* on future modules.

| Piece | Role | Location |
|---|---|---|
| Skill `module-config` | Drives the dialogue (ask target module → analyze → propose params → confirm → re-ask). Does NOT write code. | `.agents/skills/module-config/SKILL.md` |
| Agent `module-config-scaffolder` | Takes the confirmed config manifest and writes the full slice (backend + section component + registry entry). Loads `laravel-backend-architecture`/`react-frontend-architecture`/`stancl-tenancy` as authority. | `.claude/agents/module-config-scaffolder.md` |
| Wiring | Registry entry + skill dictionary + mandatory checklist item. | `CLAUDE.md`, `.atl/skill-registry.md` |

## Trigger

Auto (via skill `description` matching "create a new module/feature needing tenant configuration") **plus** manual invocation (`/module-config`). A line in the existing "register new module" checklist in `CLAUDE.md` makes it mandatory.

## Existing pattern (the reference — Paquetería)

- **Storage:** ONE generic tenant-scoped `setting` table (`slug` + `value` + `type`). Slugs namespaced per module: `package.auto_notify_on_arrival`, etc. Read via `Setting::getValue('package.x')`.
  - `backend/Modules/Setting/app/Models/Setting.php`
  - `backend/database/migrations/2026_02_26_135348_create_setting_table.php`
- **API:** single `ConfigurationController` with per-module method pairs `getXSettings`/`updateXSettings`; routes `GET/PUT config/x-settings`. Flat keys in API ↔ `module.*` slugs in storage. Cross-field validation in controller. Permission `read/update.configuration` (unified).
  - `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php` (package: ~1050-1200)
  - `backend/Modules/Configuration/routes/api.php` (~49-50)
- **Seed defaults:** `Setting::firstOrCreate` on `TenantCreated` (Collections does it; **Package does NOT — known gap**, falls back to hardcoded defaults in `PackageService`).
  - Reference: `backend/Modules/Collections/database/seeders/CollectionsDatabaseSeeder.php` (~30-45)
- **Frontend:** `ConfigurationPage.tsx` is a tabbed page; each module = `XConfigSection` component (React Query + `useSectionEdits` + resource to `/config/x-settings`, shadcn inputs). **Registration is MANUAL: 3 edits** (import + `TabsTrigger` + `TabsContent`). No registry.
  - `frontend/src/modules/configuration/ConfigurationPage.tsx`
  - `frontend/src/modules/configuration/components/PackageConfigSection.tsx`
  - `frontend/src/modules/configuration/hooks/useSectionEdits.ts`, `useConfiguration.ts`

## Interactive workflow (driven by the skill)

1. Ask/confirm the target system module → derive slug namespace (`accesscontrol.*`).
2. Analyze the requirement against the archetype catalog → infer candidate params.
3. Propose concrete params in a table (`key`, type, default, label/help ES, validation, UI).
4. Confirm one-by-one (accept / edit / drop).
5. Loop "¿algún otro?" until done.
6. Emit the config manifest and delegate to `module-config-scaffolder`.

### Archetype catalog (keeps proposals concrete & consistent)

- 🔔 Notification: `auto_notify_on_*` (boolean)
- ✍️ Verification/handover: `require_signature`, `require_qr`, `require_code`, `requires_evidence` (boolean)
- ⏱️ Time/retention: `*_after_days`, `grace_days`, `reminder_after_days` (integer + min/max + cross-field validation)
- 🔢 Thresholds: `max_*`, `min_*` (integer)
- 🔘 Feature toggles: `enabled`, `*_enabled` (boolean)
- 📝 Legal/disclaimer text: `*_disclaimer`, `*_terms` (text/textarea + maxlength)
- 🎯 Mode/selector: simple enum (string with options)

Each archetype maps to a type, validation, and shadcn input (Checkbox / Number / Textarea / Select).

## Config manifest (skill → agent contract)

```yaml
module: AccessControl
slug_namespace: accesscontrol
settings:
  - key: require_qr
    type: boolean
    default: true
    label: "Exigir QR en el acceso"
    ui: checkbox
  - key: visitor_expiry_hours
    type: integer
    default: 24
    validation: "min:1|max:168"
    ui: number
guard_read_endpoint: false
```

## Canonical checklist executed by the agent

### Backend (replicates Paquetería + fixes the seed gap)

1. Add `getXSettings`/`updateXSettings` to `ConfigurationController` (flat keys ↔ `module.*` slugs, cross-field validation).
2. Add 2 routes `GET/PUT config/x-settings`, gated `read/update.configuration`.
3. **Seed defaults ALWAYS** via `Setting::firstOrCreate` in the module's `XDatabaseSeeder`, wired to `TenantDatabaseSeeder`/`TenantCreated`. No more loose hardcoded defaults.
4. (Conditional) guard-accessible read endpoint, only if `guard_read_endpoint: true` (settings affecting runtime for non-admin roles, like `/package/pickup-policy`). The skill ASKS; never assumes.

### Frontend (mini-registry)

5. Create `XConfigSection.tsx` (schema type + resource to `/config/x-settings` + section UI from archetype `ui` + `useSectionEdits` + React Query key `['config','x-settings']`).
6. Add ONE entry to the `configSections` registry: `{ key, label, icon, permission, component }`.

### One-time refactor (first run)

7. Create `frontend/src/modules/configuration/configSections.tsx` (the registry array) and refactor `ConfigurationPage.tsx` to map over it. Migrate existing tabs (packages, collections, etc.) into entries. After this, a new module = ONE entry.

### Close the loop

8. Wire `.atl/skill-registry.md`, the `CLAUDE.md` Skill Dictionary, and add a mandatory item to the "register new module" checklist.
9. Remind to run reviewers: `@tenancy-reviewer` (setting is tenant-scoped), `@integration-reviewer` (back↔front contract), `@infra-cost-reviewer`.

## Baked-in defaults (approved)

- Storage = generic `Setting` table with `module.key` slugs (no per-module tables).
- Seeding via `firstOrCreate` (preserves admin edits, never overwrites).
- Unified `read/update.configuration` permission.
- Agent loads `laravel-backend-architecture`/`react-frontend-architecture`/`stancl-tenancy`; focuses only on config shape + registration.

## Out of scope (for now)

- Backfilling the Package seeding gap in production data (the tool prevents it going forward; fixing existing Paquetería is a separate, optional follow-up).
- A fully dynamic/DB-driven config-section discovery (the mini-registry is a static array, intentionally).

## Implementation tasks (current branch, existing code)

1. Frontend: refactor `ConfigurationPage` → `configSections` registry; migrate existing sections.
2. Create `.agents/skills/module-config/SKILL.md`.
3. Create `.claude/agents/module-config-scaffolder.md`.
4. Wire `CLAUDE.md` + `.atl/skill-registry.md`.
