---
name: collections-form-page-migration
description: Findings/conventions from the Collections Sheet→full-page form migration + chargePolicy module split. Merged 2026-07-20 with a parallel review of the same migration that had been logged in a stray frontend/ copy (form-page canon details + title-overwrite MAJOR bug).
metadata:
  type: project
---

# Collections form-page migration + chargePolicy split (reviewed 2026-06-25)

Concept→Policy split: `ChargeConcept` is now ONLY identity (clave/name/description/category/is_active). Amount/periodicity/grace/validity moved to new `charge_policy` (1:N per concept). `ChargeBatch` now references `charge_policy_id` (was `charge_concept_id`); `override_amount`/`amount_override` removed (batch inherits money from policy). Batch list includes `policy` + `policy.concept`; `batch.concept` kept as convenience on interface only.

Form migration pattern (Sheet/Modal → full-page route): new `*FormPage.tsx` files use `useNavigate` + `useParams`, RHF + zodResolver, `useBaseCrud` for create/update, section cards (`SECTION`/`SECTION_TITLE` const class strings), back-link header. FormModals deleted (`ChargeBatchFormModal`, `ChargeConceptFormModal`). Routes added in App.tsx under correct PermissionRoute (`charge_concept`, `charge_policy`, `charge_batch`). New StatusPill canonical home: `@/shared/components/atoms/StatusPill` (moved out of treasurerPanel).

**RECURRING BUG — double success toast:** `useBaseCrud.onSave`/`onUpdate` ALREADY fire `toast.success('Registro creado/actualizado exitosamente')` internally. The new FormPages ALSO call `toast.success('Política creada'/...)` after `crud.submit()`. Result = TWO toasts. Affected: `ChargePolicyFormPage`, `ChargeConceptFormPage`. `ChargeBatchFormPage` uses raw `useMutation` (single toast) — OK. Fix: drop the page-level toast OR switch off the internal one. Watch for this in every future useBaseCrud-based form.

Typing smell: `Resource.show(id, { include: '...' } as never)` — `as never` is unnecessary since `show(id, params?: Partial<TForm>)` and TForm is `Record<string, unknown>`; pass the object plain.

chargePolicy schema: `collection_mode` periodic⇒valid_from/valid_to required (target/issued/due prohibited); direct⇒target_type+issued_at+due_date required (validity prohibited). superRefine mirrors backend requiredIf/prohibited. Payload built by `buildPolicyCreatePayload`/`buildPolicyUpdatePayload` (pesos→minor units, mode-gated). Good test coverage (schema.test.ts, AmountModeSelector.test.tsx). Money fields typed `IMoney | number | null` consistently.

## ChargePolicy COCKPIT review (2026-06-25)

`ChargePolicyCockpitPage` = single screen replacing fragmented policy-form + batch views. LEFT = editable config (`PolicyConfigForm`), RIGHT = generated charges (`CockpitBatchList` drill when isEdit; `CockpitDirectScope` capture for new direct policy; empty card for new periodic). Routed under `PermissionRoute permissionEntity="charge_policy"` at `/charge-concept/:conceptId/policy/new`, `/charge-policy/:id`, `/charge-policy/:id/edit`. GOOD: no double-toast (cockpit doSubmit relies on useBaseCrud's internal toast — does NOT add its own, unlike the earlier FormPages). GOOD: drill defers fetch via `BatchUnitChargesTable enabled={isOpen}`; aria-expanded on expand button; boneyard Skeleton; empty/error states present everywhere; tabular-nums on money. GOOD: `show(id,{include:...})` called WITHOUT `as never` (TForm=Record<string,unknown> accepts it plainly).

Query-key dedupe: `BatchUnitChargesTable` and `ChargeBatchDetailPage` both use `['charges','by-batch',id]` with identical queryFn → React Query dedupes to ONE request (correct, not a bug). `CockpitBatchList` uses `['charges','batches','by-policy',policyId]` — distinct, fine.

OPEN findings this cycle (no critical):
- MINOR `ChargeBatchDetailPage.tsx:45` still `show(id,{include:...} as never)` + 5 `(batch as IChargeBatch)` casts (lines 102,124,125,234) — same dead `as never` flagged before; the cockpit's identical call proves it's removable.
- MINOR Header "Guardar" button (ChargePolicyCockpitPage.tsx:199) sits OUTSIDE `<form>`, fires `void doSubmit()` directly — works but not `type=submit`; pressing Enter inside form also submits (form onSubmit wired). Redundant but not a bug.
- MINOR `BatchUnitChargesTable` desktop branch uses a raw `<table>` (not shadcn Table atom) — intentional for compact drill styling; acceptable.
- The Recargos section is a deliberate empty SLOT placeholder (Slice B pending). Not dead code — documented intent.

---

# Parallel review of the same migration (recovered 2026-07-20 from stray `frontend/` copy)

Reviewed 2026-06-25 (uncommitted working tree). Migration from `<Sheet>` form modals to
full-page create+edit forms; new `chargePolicy/` module (1:N under chargeConcept). Implementation
is HIGH quality: 0 TS errors in charge modules, 60/60 charge tests pass, 0 eslint errors.

## Established form-page pattern (no precedent before this; treat as the new canon for big forms)
- Header = `<Link>` Volver (ArrowLeft) + `h1` (`text-[28px]`) + subtitle.
- Body = `<section>` cards with constants `SECTION = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'`
  and `SECTION_TITLE` (uppercase tracking label). Wrapper `mx-auto max-w-3xl/4xl space-y-6`.
- Submit via `useBaseCrud` (`crud.submit(payload)`) + `toast.success` + `navigate(backUrl)`. NO `<Sheet>`.
- Edit loads entity via TanStack `useQuery` then `reset(defaultValues)` in a `[reset, defaultValues]` effect.
- defaults built by a PURE `buildXDefaults(id, entity, now: Date)` function (testable with fixed Date).
- Money: pure `buildXCreatePayload`/`buildXUpdatePayload` convert pesos→minor units, gated by amount-mode flags.

## Recurring smells seen again here (already in MEMORY: as-never-cast)
- `Resource.show(id, { include: '...' } as never)` — repeats the as-never cast on createCrudApi show().
  Same root cause noted in `as-never-cast.md`. Whole-codebase fix needed in resourceApi show() typing.

## Real findings worth remembering
- ChargeBatchFormPage title-overwrite: a `useEffect([selectedPolicy, month, year, setValue])` does
  `setValue('title', ...)` unconditionally. The title is ALSO a registered editable input → if the user
  edits the title and then changes period/policy, their text is clobbered. UX bug (MAJOR). Fix: only
  auto-fill while the field is untouched (track dirtyFields.title or a hasUserEditedTitle flag).
- `formValues = watch()` (full-form watch) in both ChargePolicyFormPage and ChargeBatchFormPage causes a
  re-render every keystroke and `buildTargetSelector(formValues)` recomputes unmemoized each render.
  Minor perf; acceptable for a form this size but worth narrowing to the target_* fields.
- MONTH_NAMES defined in chargeBatch/constants.ts, re-exported through MonthYearSelector; form imports the
  re-export. Single source of truth (OK) but the re-export hop is a slight indirection smell.

## Confirmed-correct (do not re-flag)
- `new Date()` only feeds input defaults (buildPolicyDefaults / buildChargeBatchDefaults) — allowed.
- toast from `@/shared/hooks/useToast` (sileo), no animate-pulse, no `any` anywhere in chargePolicy.
- chargeBatch list/detail whitelist `policy` + `policy.concept` includes correctly.
- Sheet→page migration complete: no Sheet remnants in charge* (RegisterPaymentSheet in ChargeBatchDetail
  is a payment-registration concern, NOT a form remnant).
- Deleted: ChargeConceptFormModal, ChargeBatchFormModal, chargeConcept/components/{AmountModeSelector,
  PeriodicitySelector}. Components MOVED to chargePolicy/components with id prefix concept-→policy-.
- Routing: /charge-concept/{new,:id/edit}, /charge-concept/:conceptId/{policies,policy/new},
  /charge-policy/:id/edit, /charge-batch/new — all permission-gated correctly (charge_policy own guard).
