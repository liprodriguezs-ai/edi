---
name: payment-actions-refactor
description: Payment actions refactor — shared usePaymentActions hook + PaymentActionButtons/Dialogs components; key bugs and patterns
metadata:
  type: project
---

## Payment Actions Refactor (2026-06-28)

Refactored validate/reject/cancel mutations + download + dialog state into a shared `usePaymentActions` hook, consumed by both `PaymentListPage` and `PaymentDetailPage`. Introduced `PaymentActionButtons` (presentational, driven by a `show` prop) and `PaymentActionDialogs` (rendered once per page, avoids per-row duplication in `ResponsiveDataTable`).

## Critical Bug: stale reason on dialog dismiss

`usePaymentActions.ts` lines 148–149:
```typescript
closeReject: () => setRejecting(null),
closeCancel: () => setCancelling(null),
```
Neither resets `rejectReason` / `cancelReason`. If a user opens the reject dialog, types a reason, cancels (Escape or "Cancelar" button), then opens reject for a DIFFERENT payment, the stale reason is pre-filled. Same for cancel. Money-action data contamination.

**Fix:**
```typescript
closeReject: () => { setRejecting(null); setRejectReason('') },
closeCancel: () => { setCancelling(null); setCancelReason('') },
```

## `renderActions` useCallback is effectively no-op

`PaymentListPage.tsx` line 133:
```typescript
const renderActions = useCallback((payment: IPayment) => (...), [tab, actions])
```
`actions` is a plain object returned from `usePaymentActions` — new reference on every render (contains inline arrow functions and mutation state). So `renderActions` re-creates on every render, defeating the `useCallback`. Performance concern; all rows re-render on any state change.

**Fix:** stabilize opener functions in `usePaymentActions` with `useCallback`, or destructure only the stable parts into the dependency array.

## `invalidateQueries(['payments'])` is dead code

`PaymentListPage.tsx` line 93:
```typescript
void queryClient.invalidateQueries({ queryKey: ['payments'] })
```
No query in the codebase uses `queryKey: ['payments']`. The detail page uses `['payment', id]` (singular). The actual list refresh is driven by `onSearch({ ...paramsSearch })` via `useBaseList`. The invalidation is a no-op and gives false confidence.

## List gating (correct)

`rowVisibility()` correctly separates list-by-tab from detail-by-status:
- pending tab → validate + reject only
- all tab → cancel only (if not already CANCELLED)
- download → presence of `receipt` media

## Detail gating (correct)

`canManage && payment &&` wraps `PaymentActionButtons` with `update.payment` permission gate. Status-based show:
- validate/reject → only when `PENDING_VALIDATION`
- cancel → any status except `CANCELLED`

## `onDownload` in detail is dead prop

`PaymentDetailPage` wires `onDownload={() => actions.downloadReceipt(payment)}` but passes `show.download: false`, so the Download button never renders. `DownloadsCard` handles both receipt types in the detail independently. The prop is harmless but confusing dead code.

## Pattern established: dialog-per-page strategy

Single `<PaymentActionDialogs>` at page level (not per-row) is the correct approach with `ResponsiveDataTable` which mounts both desktop table and mobile cards simultaneously. Opener functions set `validating/rejecting/cancelling` state; the dialog renders based on non-null value.
