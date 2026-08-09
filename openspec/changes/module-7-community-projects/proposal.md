# Module 7 — Community Projects (Proyectos Comunitarios)

## Intent

Implement a module for managing community-wide projects (construction, events, improvements,
donations, extraordinary works) that require collective participation from the residents of a
cluster. The module supports two fundamentally different participation modes: mandatory (generates
binding charges via the Collections module) and voluntary (tracks open contributions without
enforcement).

## Scope

New tenant-scoped module `Projects` (`backend/Modules/Projects`):

- **Catalogs** (tenant-scoped, string-key, seeded per tenant): `community_project_status`
  (5 states: draft/active/fully_funded/executed/cancelled), `community_project_category`
  (5 keys: construction/event/improvement/donation/extraordinary), `participation_type`
  (2 keys: mandatory/voluntary), `project_contribution_status` (6 keys:
  pending/partial/ideal/generous/below_min/declined).
- **Entities**: `community_project`, `project_contribution`, `project_comment`.
- **Cross-module FKs**: `expense.community_project_id` (FK added by Projects migration,
  nullOnDelete, column already existed in Treasury); `charge.community_project_id` (FK added
  by Projects migration, nullOnDelete, column already existed in Collections).
- **Services**: `ProjectActivationService` (draft → active; generates charges+contributions for
  mandatory, contributions only for voluntary; idempotent), `ContributionService`
  (contribute/decline/revertDecline for voluntary; getSummary rollups; recomputeFullyFunded).
- **Endpoints**: Orion CRUD for all 7 tables + custom actions (activate, close, summary per
  project; decline per contribution; hide per comment).
- Menu/permission registration, per-tenant seeding, integration tests (12 scenarios).

Reuses Collections `TargetResolver`, `ChargeStatusService`, `AmountMode` catalog, and
`ChargeConceptCategory.KEY_PROJECT` for charge concept auto-creation on mandatory activation.

## Approach

- Money is `bigInteger` minor units + `MoneyIntegerCast:MXN` on all amount columns, consistent
  with Collections convention. Contribution amounts use locked read-modify-write in
  `ContributionService.contribute`.
- Mandatory activation: one `project_contribution` + one `Charge` (with `community_project_id`
  set) per resolved unit. Charge concept auto-created with key `PROJ_{project.id}` (idempotent).
- Voluntary activation: one `project_contribution` per resolved unit; NO charges.
- Contribution status is computed from `contributed_amount` vs amount mode thresholds and written
  transactionally by `ContributionService`.
- `fully_funded` transition fires inside `recomputeFullyFunded` when
  `sum(contributed_amount) >= goal_amount`.
- Target resolution reuses the 8-type `TargetResolver` from Collections (all / by_type /
  by_tower / by_floor / manual / by_feature / by_share / single_unit).

## Out of Scope (v1)

- Resident-facing mobile contribution flow (no dedicated resident portal yet).
- PDF summary / export of project progress.
- Push notification to residents on project activation.
- Partial project cancellation / reversal of charges when a project is cancelled.
