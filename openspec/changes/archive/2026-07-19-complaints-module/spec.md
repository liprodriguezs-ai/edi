# Complaint Management Specification

> Change: `complaints-module` · New capability (no existing spec to delta against).
> Binding inputs: `proposal.md` §"USER DECISIONS — CONFIRMED 2026-07-19"; engram `sdd/complaints-module/decisions` (obs #1684).

## Purpose

Give a residential cluster one auditable place to register, triage, assign, and resolve
formal complaints spanning three scopes (neighbor coexistence, cluster incidences,
complaints against administration/board) in a single tenant-scoped `complaint` entity.

## Requirements

### Requirement: Registration Gate
The system MUST restrict `complaint` creation to users holding a role listed in the
tenant setting `complaint.reporter_role_keys` (default: the five board roles).

#### Scenario: Board member registers a complaint
- GIVEN a user with role President
- WHEN they submit a new complaint
- THEN it is created with `reporter_user_id` set to their user id

#### Scenario: Non-board resident denied
- GIVEN a user with role Resident and no `complaint.reporter_role_keys` membership
- WHEN they attempt to create a complaint
- THEN the request MUST be denied (403)

### Requirement: Denounced Subject Shape
`complaint` MUST store the denounced party as `against_description` (required free text)
plus `against_unit_id` (nullable FK `unit`). The system MUST NOT expose an
`against_user_id` or employee/provider subject FK in v1.

#### Scenario: Complaint against a unit
- GIVEN category "convivencia" and a selected unit
- WHEN the reporter submits with description
- THEN `against_unit_id` and `against_description` are both persisted

#### Scenario: Complaint with no unit
- GIVEN a general cluster incidence (e.g. broken gate)
- WHEN the reporter submits without selecting a unit
- THEN `against_unit_id` is null and `against_description` is persisted

### Requirement: Category Catalog
`complaint_category` MUST be a tenant-scoped catalog (`TenantCatalogModel`), seeded per
tenant on `TenantCreated`, admin-editable, and every `complaint` MUST reference exactly
one category.

#### Scenario: Tenant edits its categories
- GIVEN an admin on the category catalog screen
- WHEN they add a new category label
- THEN it is available for that tenant's complaints only

### Requirement: Priority Catalog
`complaint_priority` MUST be a tenant-scoped catalog seeded with `low`/`medium`/`high`
defaults per tenant. Priority MUST be cosmetic — the backend MUST NOT branch on it.

#### Scenario: Default priorities seeded
- GIVEN a new tenant is created
- WHEN `TenantCreated` fires
- THEN low/medium/high `complaint_priority` rows exist for that tenant

### Requirement: Status FSM
`complaint_status` MUST be a central catalog (fixed UUID constants, seeded once by
`CatalogSeeder`) with states `open`, `in_review`, `resolved`, `dismissed`. New complaints
MUST start `open`. Only `ComplaintService`, in a DB transaction, MUST write transitions,
and only for users holding a role in `complaint.manager_role_keys`.

#### Scenario: Valid transition
- GIVEN a complaint with status `open`
- WHEN a manager-role user changes it to `in_review`
- THEN `complaint_status_id` updates and a transition timestamp is recorded

#### Scenario: Manager may reopen a terminal complaint
<!-- AMENDED 2026-07-19 by user decision (was: terminal state immutable). -->
- GIVEN a complaint with status `resolved` or `dismissed`
- WHEN a manager-role user reopens it (to `open` or `in_review`)
- THEN the transition MUST be accepted and `resolved_at` MUST be cleared

#### Scenario: Non-manager cannot reopen
- GIVEN a complaint with status `resolved` or `dismissed`
- WHEN a user WITHOUT a `complaint.manager_role_keys` role attempts any status change
- THEN the system MUST reject the transition

#### Scenario: Non-manager blocked from transitioning
- GIVEN a reporter without a `complaint.manager_role_keys` role
- WHEN they attempt to change status
- THEN the request MUST be denied

### Requirement: Confidential Exclusion by Unit Linkage
WHEN `against_unit_id` is set, the system MUST exclude, from both visibility (list/detail
queries) and notification recipients, any manager-role user linked to that unit via
`unit_user`. WHEN `against_unit_id` is null, the system MUST NOT apply automatic exclusion.

#### Scenario: Denounced board member excluded
- GIVEN a complaint's `against_unit_id` matches the Treasurer's `unit_user` unit
- WHEN the manager recipient/visibility set is resolved for that complaint
- THEN the Treasurer is excluded from both the list/detail view and `complaint_created`

#### Scenario: No unit, no exclusion
- GIVEN `against_unit_id` is null (e.g. complaint against the administration)
- WHEN recipients are resolved
- THEN all configured manager-role users, including any implicated administrator, are included

### Requirement: Evidence Attachments
`complaint` MUST support an `evidence` Spatie media collection at filing; `complaint_message`
MUST support an `attachment` media collection. WHEN tenant setting
`complaint.requires_evidence` is true, creation MUST require at least one evidence file.

#### Scenario: Evidence required by config
- GIVEN `complaint.requires_evidence` is true
- WHEN a reporter submits with no attached file
- THEN creation MUST be rejected with a validation error

#### Scenario: Evidence optional by default
- GIVEN `complaint.requires_evidence` is false
- WHEN a reporter submits with no file
- THEN the complaint MUST be created successfully

### Requirement: Follow-up Message Thread
`complaint_message` MUST be tenant-scoped, linked to `complaint_id` and `user_id`, ordered
chronologically, and visible/writable only to users who can view the parent complaint
(non-excluded manager-role users, the reporter, and the assignee).

#### Scenario: Thread visible to an eligible manager
- GIVEN a manager not excluded per the unit-linkage rule
- WHEN they post a message
- THEN it appears in the thread ordered by `created_at`

#### Scenario: Excluded manager blocked from the thread
- GIVEN a manager excluded per the unit-linkage rule
- WHEN they attempt to view or post to the thread
- THEN access MUST be denied

### Requirement: Folio Generation
Each complaint MUST receive a unique folio formatted `D-YYYY-NNNNNN` at creation, generated
under a locking/retry strategy that MUST NOT produce duplicates under concurrent creation.

#### Scenario: Sequential folio within a year
- GIVEN the latest 2026 folio is `D-2026-000005`
- WHEN a new complaint is created in 2026
- THEN its folio is `D-2026-000006`

### Requirement: Notification Events
The system MUST register `complaint_created`, `complaint_assigned`,
`complaint_status_changed`, and `complaint_message` as `NotificationEvent` entries
dispatched via `NotifiesResidents::notifyEvent()`, gated by per-tenant
`NotificationSetting`, applying the unit-linkage exclusion to `complaint_created` recipients.

#### Scenario: Creation notifies eligible managers
- GIVEN `complaint.auto_notify_managers_on_create` is true
- WHEN a complaint is created
- THEN configured manager-role users minus any unit-linkage-excluded user receive a push/in-app notification

#### Scenario: Assignment notifies the assignee
- GIVEN a complaint is assigned
- WHEN the assign action completes
- THEN the assigned user receives a `complaint_assigned` notification

### Requirement: Assignment
`complaint` MUST support exactly one nullable `assigned_user_id`, set only via a custom
`assign` action restricted to users holding a `complaint.manager_role_keys` role.

#### Scenario: Manager assigns a complaint
- GIVEN an unassigned complaint
- WHEN a manager-role user assigns it to another manager-role user
- THEN `assigned_user_id` updates and `complaint_assigned` fires

### Requirement: Per-Tenant Module Configuration
The system MUST expose `complaint.manager_role_keys`, `complaint.reporter_role_keys`,
`complaint.auto_notify_managers_on_create`, `complaint.requires_evidence`, and
`complaint.resolution_days_target` as tenant `Setting`s, seeded with defaults on
`TenantCreated`, editable via `config/complaint-settings` endpoints.

#### Scenario: Defaults seeded for a new tenant
- GIVEN a new tenant is created
- WHEN `TenantCreated` fires
- THEN all five `complaint.*` settings exist with their documented defaults

### Requirement: Tenancy Isolation
All `complaint` and `complaint_message` rows MUST be scoped by `tenant_id` via
`BelongsToTenant`/`TenantScope`. No cross-tenant complaint MUST be visible or
referenceable through any endpoint.

#### Scenario: Cross-tenant read blocked
- GIVEN a complaint belongs to tenant A
- WHEN a tenant B manager requests it by id
- THEN the system MUST respond not-found

### Requirement: Module Registration
The system MUST register Complaint in the dynamic menu (modules/permissions/module_system
CSVs, `SystemTableSeeder` entity/route/icon, `RolePermissionTableSeeder`) and the frontend
router, so eligible manager-role users see "Denuncias" in the sidebar and reach `/complaint`.

#### Scenario: Sidebar visibility for a manager role
- GIVEN a user with a manager role and the `complaint` permissions
- WHEN they load the menu
- THEN "Denuncias" appears in the sidebar under its business system group

### Requirement: List + Detail UI
The frontend MUST provide a List view (filters: status, category, priority, assignee) and
a Detail view (subject, status timeline, assignment, evidence, message thread), entirely in
Spanish, showing only complaints the viewer is not excluded from.

#### Scenario: Filtered, exclusion-respecting list
- GIVEN a manager filters the list by status "en revisión"
- WHEN the list loads
- THEN only matching complaints not excluding that manager are shown

## Non-Goals (v1)

- **N1** Resident self-filing — schema stays compatible, UI/flow deferred.
- **N2** Kanban board — List + Detail only.
- **N3** Linked/related complaints (`ticket_associated` equivalent) — deferred to v1.1.
- **N4** SLA/time-based auto-escalation cron — `resolution_days_target` is display-only.
- **N5** A parallel audit table — `BaseModel`'s global auditing covers this.
- **N6** Email notification channel — push + in-app only.
- **N7** Multi-assignee — one `assigned_user_id` per complaint.
- **N8** Anonymity — every complaint records `reporter_user_id`.
