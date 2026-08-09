# Module 7 — Community Projects — Tasks

## Status: COMPLETE (green gate passing)

### Migrations
- [x] 4 catalog tables: `community_project_status`, `community_project_category`,
      `participation_type`, `project_contribution_status`
- [x] `community_project` (tenant-scoped, all amount columns bigInteger, target_selector JSONB)
- [x] `project_comment` (tenant-scoped, FK community_project_id cascadeOnDelete)
- [x] `project_contribution` (tenant-scoped, unique(tenant_id, community_project_id, unit_id))
- [x] Add FK `expense.community_project_id → community_project` (nullOnDelete); checks
      hasColumn before adding (Treasury column pre-existed)
- [x] Add FK `charge.community_project_id → community_project` (nullOnDelete); checks
      hasColumn before adding (Collections column pre-existed)

### Models
- [x] `CommunityProjectStatus` (KEY_DRAFT, KEY_ACTIVE, KEY_FULLY_FUNDED, KEY_EXECUTED, KEY_CANCELLED)
- [x] `CommunityProjectCategory` (KEY_CONSTRUCTION, KEY_EVENT, KEY_IMPROVEMENT, KEY_DONATION, KEY_EXTRAORDINARY)
- [x] `ParticipationType` (KEY_MANDATORY, KEY_VOLUNTARY)
- [x] `ProjectContributionStatus` (KEY_PENDING, KEY_PARTIAL, KEY_IDEAL, KEY_GENEROUS, KEY_BELOW_MIN, KEY_DECLINED)
- [x] `CommunityProject` (BelongsToTenant, MoneyIntegerCast on 5 amount columns, relations:
      status/category/participationType/amountMode/responsibleUser/contributions/comments)
- [x] `ProjectContribution` (BelongsToTenant, MoneyIntegerCast on expected_amount/contributed_amount)
- [x] `ProjectComment` (BelongsToTenant, hidden boolean)

### Services
- [x] `ProjectActivationService.activate` (mandatory → charges + contributions, voluntary →
      contributions only; idempotent; draft/active/cancelled/executed guards)
- [x] `ProjectActivationService.resolveProjectChargeConcept` (auto-creates PROJ_{id} concept)
- [x] `ProjectActivationService.computeExpectedAmount` (maps amount_mode → raw int)
- [x] `ContributionService.contribute` (RMW, resolves status, recomputeFullyFunded)
- [x] `ContributionService.decline` + `revertDecline`
- [x] `ContributionService.getSummary` (recaudado/ejecutado/disponible rollups)
- [x] `ContributionService.recomputeFullyFunded` (active → fully_funded transition)

### Controllers + Routes
- [x] Orion resources: `community_project_status`, `community_project_category`,
      `participation_type`, `project_contribution_status`, `community_project`,
      `project_contribution`, `project_comment` (all with withSoftDeletes + auth:sanctum +
      initializeTenancy)
- [x] Custom actions: `POST community-project/{id}/activate`, `POST community-project/{id}/close`,
      `GET community-project/{id}/summary`, `POST project-contribution/{id}/decline`,
      `POST project-comment/{id}/hide`

### Seeding + Menu
- [x] `TenantDatabaseSeeder` seeds all 4 catalogs per tenant
- [x] `modules.csv`, `permissions.csv`, `module_system.csv` rows added
- [x] `SystemTableSeeder`: permission entities, routes, icons, hidden catalog entries
- [x] `RolePermissionTableSeeder`: project entities in `$commonPermissions`

### Tests (ProjectsModuleTest.php — 12 scenarios)
- [x] A. Catalog seeding (5/5/2/6 rows per tenant)
- [x] B. Mandatory activation generates charges + contributions (all community_project_id linked)
- [x] C. Activation idempotency (no duplicate rows on re-activate)
- [x] D. Voluntary activation creates contributions, zero charges
- [x] E. Contribution status states for RANGE mode (all 5 transitions via cumulative contribute)
- [x] F. Decline, revertDecline, closed project rejection
- [x] G. Rollups (recaudado/ejecutado/disponible)
- [x] H. Fully funded transition at goal_amount
- [x] I. expense.community_project_id FK nullOnDelete
- [x] J. charge.community_project_id FK nullOnDelete
- [x] K. Money stored as integer minor units (project + contribution)
- [x] L. Tenant isolation (community_project and project_contribution tables)

### Deferred
- [ ] PDF/Excel project summary export (deferred — no PDF/Excel pipeline yet)
- [ ] Push notification to residents on project activation (Notification module cross-cut)
- [ ] Charge reversal when a mandatory project is cancelled (operator uses Collections cancel)
