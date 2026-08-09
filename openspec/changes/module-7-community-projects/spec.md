# Module 7 — Community Projects — Spec

## Requirements

### R1 — Community project entity
A `community_project` has: `title`, optional `description`, FK `community_project_category_id`,
FK `participation_type_id`, FK `amount_mode_id` (from Collections), money fields
`fixed_amount/min_amount/ideal_amount/max_amount/goal_amount` (nullable, minor units),
`allow_decline` (boolean, voluntary only), `target_type` + JSON `target_selector`
(same targeting schema as Collections), `start_date`, `due_date`, optional `responsible_user_id`,
`visible_to_residents`, `allow_comments`, FK `community_project_status_id`.

### R2 — Participation types
`mandatory`: all resolved units receive a charge (Collections) AND a project_contribution.
`voluntary`: resolved units receive a project_contribution only; no charges.

### R3 — Project activation (draft → active)
`ProjectActivationService.activate` transitions draft → active inside a single DB transaction.
Resolves units via `Collections\TargetResolver`. For mandatory projects with a defined amount,
creates a `Charge` linked via `charge.community_project_id` using a per-project `ChargeConcept`
(`PROJ_{id}` clave, KEY_PROJECT category, KEY_EVENTUAL periodicity). For all projects, creates
one `project_contribution` per resolved unit. Idempotent: units already holding a contribution
row are skipped.

### R4 — Contribution status lifecycle
`ContributionService` resolves contribution status from `contributed_amount` and the project's
`amount_mode` key:
- `free`: any contribution → `ideal`
- `fixed_required` / `fixed_optional`: contributed < fixed → `partial`; == fixed → `ideal`;
  > fixed → `generous`
- `range`: contributed < min → `below_min`; min ≤ contributed < ideal → `partial`;
  == ideal → `ideal`; > ideal → `generous`
- Zero contributed → `pending`
- Explicitly declined → `declined` (flag `declined=true` + status)

### R5 — Voluntary contributions
`ContributionService.contribute(contribution, amountRaw)`: adds to `contributed_amount`
(read-modify-write), resolves new status, calls `recomputeFullyFunded`. Closed or cancelled
projects throw `InvalidArgumentException`.
`decline(contribution)`: sets `declined=true`, status `declined`.
`revertDecline(contribution)`: sets `declined=false`, re-resolves status from current amount.

### R6 — Fully funded
When `sum(project_contribution.contributed_amount) >= community_project.goal_amount` and the
project is active, `ContributionService.recomputeFullyFunded` transitions the project status to
`fully_funded`.

### R7 — Rollup summary
`ContributionService.getSummary(project)` returns:
- `recaudado`: `sum(project_contribution.contributed_amount)` for this project.
- `ejecutado`: `sum(expense.amount WHERE expense.community_project_id = project.id)`.
- `disponible`: `max(0, recaudado - ejecutado)`.

### R8 — Cross-module FKs
- `expense.community_project_id`: nullable UUID, FK → `community_project`, nullOnDelete.
  Column pre-existed in Treasury; FK constraint added by Projects migration.
- `charge.community_project_id`: nullable UUID, FK → `community_project`, nullOnDelete.
  Column pre-existed in Collections; FK constraint added by Projects migration.

### R9 — Project contribution uniqueness
`unique(tenant_id, community_project_id, unit_id)` on `project_contribution` prevents
duplicate contribution rows per unit per project per tenant.

## Scenarios (acceptance — from tests in ProjectsModuleTest.php)

- **A. Catalog seeding**: TenantDatabaseSeeder produces 5 community_project_status,
  5 community_project_category, 2 participation_type, 6 project_contribution_status.
- **B. Mandatory activation**: 3-unit mandatory project (FIXED_REQUIRED, $1,200 per unit)
  → 3 contributions (expected_amount = 120,000 minor units) + 3 charges
  (expected_amount = 120,000, community_project_id set on each).
- **C. Idempotency**: re-activating an active mandatory project does not create duplicate
  contributions or charges.
- **D. Voluntary activation**: 2-unit voluntary project (RANGE, $500 ideal) → 2 contributions
  (expected_amount = ideal = 50,000), zero charges created.
- **E. Contribution status states**: RANGE mode — initial pending → below_min at $50 →
  partial at $250 → ideal at $500 → generous at $600 (cumulative contribute calls).
- **F. Decline / revert / closed rejection**:
  - Declining sets `declined=true` and status `declined`; unit excluded from "sin aportar" query.
  - Revert restores `declined=false` and status `pending` (no prior contributions).
  - Contributing to an executed/cancelled project throws `InvalidArgumentException`.
- **G. Rollups**: $800 contributed, $300 expense linked → recaudado=80,000, ejecutado=30,000,
  disponible=50,000.
- **H. Fully funded**: $1,000 goal; contribute $500 → still active; contribute another $500
  → status transitions to `fully_funded`.
- **I. expense FK nullOnDelete**: deleting the project sets `expense.community_project_id = NULL`.
- **J. charge FK nullOnDelete**: deleting the project sets `charge.community_project_id = NULL`.
- **K. Money storage**: `fixed_amount`, `goal_amount`, `contributed_amount` stored as integer
  minor units (verified via raw DB query).
- **L. Tenant isolation**: projects and contributions from tenant A invisible in tenant B.
