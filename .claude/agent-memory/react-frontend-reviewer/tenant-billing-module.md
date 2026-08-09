---
name: tenant-billing-module
description: Super-admin per-tenant billing UI (Slice 5) — TenantBillingModal/Panel + sections, patterns and issues found
metadata:
  type: project
---

## Module structure (reviewed 2026-06-30)
Super-admin screen to manage a tenant's billing. Files under `src/modules/tenant/`:
- `billing/schema.ts` — Zod DTOs (IBillingSummary, IBillingEntitlement, IBillingAvailablePackage, IBillingLedgerEntry, IPreferentialSwapPreview) + manualPaymentFormSchema (amount in PESOS) + request interfaces. No CRUD entity — correct Zod-for-response-validation use (mirrors checkoutSubscription).
- `billing/billingErrors.ts` — resolveBillingError() maps backend codes (contact_sales, not_account_owner, already_subscribed), prioritizes payload.message.
- `services/TenantBillingResource.ts` — hand-rolled object on apiClient (NOT createCrudApi — correct, these are custom non-REST actions). Reads Zod-validated + unwrap() for Orion `{data}` envelope. Mutations use skipPermissionsModal:true.
- `hooks/useTenantBilling.ts` — useQuery(summary, enabled by tenantId) + 5 useMutation, all invalidate the summary on success.
- `components/TenantBillingModal.tsx` — wrapper `if (!isOpen || !tenant) return null` → lazy: panel/query only mount on open.
- `components/billing/{TenantBillingPanel,ExpirationSection,PaymentMethodSection,CancelSubscriptionDialog,ManualPaymentSection,LedgerSection,EntitlementsSection,PreferentialSwapDialog}.tsx`

## Conventions HONORED
- No `any`; `unknown` used correctly. Zod validates all reads (fail-fast).
- Money: minor units everywhere; send = `Math.round(amount_pesos * 100)`; display = `formatPrice(cents, currency)` (/100). Correct.
- toast sileo `toast.success(title, desc)` / `toast.error(title, desc)`. No shadcn toast.
- boneyard Skeleton `name= loading`; `displayFormatDate(x, 'mediumDate')` (no `new Date()` for formatting).
- Lazy: no useCatalogs at all; modal returns null until open; query `enabled: Boolean(tenantId)`. No eager catalog load on the tenant list.

## a11y — RECURRING pattern FIXED here (contrast with prior reviews)
`PaymentMethodSection` and `CancelSubscriptionDialog` wrap `RadioGroupItem` in `<label>` WITH explicit `id`/`htmlFor` — this is the CORRECT fix for the prior recurring anti-pattern (RadioGroupItem in `<label>` missing id/htmlFor). CancelSubscriptionDialog also uses `<fieldset>`+`<legend>`. role=alert/role=status on inline messages. `scope="col"` on ledger headers.

## Issues found
- **ExpirationSection.getEarliestExpiration() does NOT filter expired entitlements** — backend sends ALL entitlements (active+expired). It sorts all expires_at and takes min, so a PAST date can render as "El acceso vence el {past}". Inconsistent with EntitlementsSection.isActiveEntitlement() which DOES filter expired. Fix: filter `new Date(v) >= now` (or reuse isActive) before min.
- **Dead code: TenantBillingResource.getLedger** — defined+exported, never consumed (panel uses summary.ledger + ledger_total from one read). Remove or wire.
- **DRY: tenant row extra-options duplicated** — billing/systems/modules options defined TWICE: `TenantList.tenantExtraOptions` (mobile cards via ResponsiveDataTable renderActions) AND `TenantTableRow.extraOptions` (desktop dropdown via renderRow). Slice added "Facturación" (IconReceipt2, order 4) to both. Extract a shared `buildTenantExtraOptions(tenant, handlers)`.
- formatPrice imported from `modules/checkoutSubscription/components/formatPrice` — now cross-module; a pure money formatter belongs in `shared/utils/`. Reuse is right; location is the nit.
- isActiveEntitlement uses `new Date(expires_at)` for COMPARISON (not formatting → not the new-Date anti-pattern) but on possibly date-only strings → day-boundary TZ edge.
- grant/revoke surface errors via toast only; changePaymentMethod/registerPayment/applyPreferentialSwap surface inline. revoke's ConfirmDialog shows no inline error on failure (toast only).
- "Activar gratis" button shows even on active-PAID packages (confusing); ManualPayment success banner lingers while editing next payment.

## ResponsiveDataTable contract (shared, reused here)
`renderRow` = desktop table branch; `renderActions` = mobile card branch. BOTH branches render simultaneously (CSS hides one). So action-option lists fed to each must be kept in sync — that's the root of the DRY duplication above.

## Manual-payment "covers all" redesign (reviewed 2026-06-30)
ManualPaymentSection rewritten from single-package select to ONE payment covering ALL paid modules. New contract `manual_payment: {lines:[{package_key,name,units,interval,amount}], total}`; entitlements now carry `units`+`amount` (plan-derived; null = misconfig). New file `billing/billingFormat.ts` consolidates `BILLING_CURRENCY`, `billingIntervalLabel`, `isActiveEntitlement` — RESOLVES the prior isActiveEntitlement duplication note. getLedger dead code from prior review is GONE (good).
- Money both directions CORRECT: preload `(amount ?? 0)/100`, send `Math.round(amount_pesos*100)`, display `formatPrice(cents)`. Total reduce is NaN-safe (`Number.isFinite`).
- RECURRING a11y gap: useFieldArray rows each label the amount input "Monto (MXN)" with NO programmatic link to which module (`fieldItem.name` is a sibling `<p>`). SR users can't tell rows apart. Fix: `aria-describedby`/`aria-labelledby` including the module-name element id, or `aria-label={`Monto de ${name} en MXN`}`.
- Skill §2.8 gap: form has `FieldLabel required` (good) but NO `<RequiredFieldsLegend/>` — module-wide (whole billing panel uses bespoke card layout, not FieldGroup/FieldSet).
- Total parity nit: total = `Math.round(sum(pesos)*100)`; backend charges `sum(round(pesos*100))` per line → 1-cent display drift possible with sub-cent inputs. Compute total from per-line rounded cents for guaranteed parity.
- No client-side max/2-decimal-min on amount_pesos → backend min:1/max:1e9 surfaces as 422 instead of inline.
- reset() effect resyncs form on `manualPayment` change; refetchOnWindowFocus is FALSE (queryClient) so focus won't clobber edits, but a grant/revoke in the same panel mid-edit WILL wipe typed amounts — guard with `formState.isDirty`.
- es-MX microcopy polish: "Plan por mes/año"→"Plan mensual/anual"; "Método del pago"→"Método de pago"; "Fecha del pago"→"Fecha de pago".
