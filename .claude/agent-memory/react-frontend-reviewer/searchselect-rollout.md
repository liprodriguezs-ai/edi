---
name: searchselect-rollout
description: SearchSelect (react-select v5 wrapper, §2.13) rollout across 23 form/filter selects — patterns, the isClearable-on-required UX change, and the react-select-in-Radix-modal QA risk
metadata:
  type: pattern
---

`<SearchSelect>` (`shared/components/SearchSelect.tsx`) is the unified searchable select (react-select v5, unstyled + classNames, IDs in/out, drop-in for shadcn Select inside RHF Controller). Skill §2.13.

Rollout canon observed (all verified submit/edit/side-effect safe):
- RHF coercion per field optionality: required string → `onChange={(v)=>field.onChange((v as string|null) ?? '')}`; optional → `?? undefined` (e.g. AccessNowPage manual-entry `visitor_motive_id` is `z.string().optional()` → null→undefined is correct; `access_verification_id` is required → `?? ''`).
- Custom labels via `getOptionLabel`; custom keys via `getOptionValue` (UserForm `role_id` keys on `o.uuid`, labels `o.description` — role catalog is uuid-keyed, NOT id).
- Fixed enums STAY shadcn `<Select>` (bankAccount `bank_account_type`); only catalogs migrate (bankAccount `bank_account_purpose`, collaborator/expense in-file `CatalogSelect`).
- Non-RHF action-select pattern (ChannelFormModal "Copiar config"): `value={null}` + `onChange={(v)=>{ if(v) copyFrom(v as string) }}` — forces reset-to-placeholder, side-effect preserved.
- Watch-driven side-effects survive because `field.onChange` still fires: VisitorFormModal `qr_type_id`→needsExpiry, ChargeBatchFormPage `charge_policy_id`→title/callouts.

Three things worth re-flagging on future SearchSelect work:
1. `isClearable` defaults TRUE → every migrated REQUIRED select is now user-clearable via the X (shadcn Select had no clear). Not a data bug (zod catches on submit) but a silent UX change. Consider `isClearable={false}` on required catalog selects.
2. react-select portals its menu to `document.body` (`menuPortal` default true). Most migrations live inside Radix `Sheet`/`Dialog` (modal=true → focus trap + pointer-events:none outside content). Classic failure mode = menu options unclickable / typeahead input can't focus. No evidence it's broken (component is purpose-built for this) but it's the #1 thing to manually QA.
3. TenantForm `subscription_plan_id` lost its inline "Stripe" `<Badge>` — SearchSelect label is a plain string. To restore rich labels you'd need react-select `formatOptionLabel`, which the wrapper doesn't expose. Cosmetic.

Lazy structure (§2.12) in this rollout — NO regressions, several IMPROVEMENTS:
- Child-extraction (gold variant 1) added to expense (ExpenseFormContent), chargeConcept (ChargeConceptFormBody), communityProject (ProjectForm), StartPeriodModal (StartPeriodForm), RegisterPaymentSheet (RegisterPaymentSheetBody); wrapper does `if(!isOpen) return null` then mounts child holding `useCatalogs`. The `reset(defaults)` effect moves to run-on-mount (drop the `if(isOpen)` guard). Note: these children still receive a now-constant `isOpen` prop (always true) — harmless dead prop.
- Early-return wrappers: CatalogFormModal (shared), provider.
- `{open && <Modal/>}` conditional mount: AnnouncementListPage.
- Filter pages went per-catalog `useLazyCatalogs` + `MultiSelectFilter onFirstOpen={enable}` (AccessLog, Visitor, Charge, Payment, Blacklist) and `<Select onOpenChange>` (UnitListPage).

Resolved prior tech-debt (supersedes part of lazy-catalogs-refactor note): `MultiSelectFilter` now has a real `onFirstOpen` prop → the 5x duplicated display:contents wrapper hack is GONE. Caveat: `onFirstOpen` fires on EVERY open, not just the first (JSDoc says "first time"); fine because `enable()` is idempotent, but name/doc mismatch.

UnitListPage tower filter: the old `{(catalogs[TOWER]?.length ?? 0) > 0 && ...}` guard was REMOVED (necessary — lazy means length is 0 until opened, which would deadlock the filter). Side effect: clusters with zero towers now show an empty tower filter. Acceptable tradeoff.

CORRECTION (2026-06-29): line above says UnitListPage filters use native `<Select onOpenChange>`. STALE — UnitListPage now uses `<MultiSelectFilter onFirstOpen={enable}>` for all 3 filters (type/status/tower) with Orion `in` filters. The non-memoized-request smell is also fixed (each request memoized). The `onFirstOpen`-fires-every-open caveat (item near "Resolved prior tech-debt") still holds and now lives inside MultiSelectFilter. See [multiselectfilter-searchable].
