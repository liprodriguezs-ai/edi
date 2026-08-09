# Plan 043: Complete QR share/download/cancel (§14.3.2) and the invalid-scan screen (§14.4.3)

> **Executor instructions**: Follow step by step, verify each step, honor STOP
> conditions, update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat 0887d5a..HEAD -- src/modules/access/components`

## Status

- **Priority**: P2
- **Effort**: M
- **Depends on**: plans/040 (soft — 040 fixes `visitor.visitor_motive` read used in the QR modal)
- **Category**: spec gap (frontend completeness)
- **Planned at**: frontend submodule `0887d5a`, 2026-06-21

## Why this matters

Two spec screens are partially built:

- **§14.3.2 QR generado** requires **Compartir** (WhatsApp/SMS/email), **Descargar
  imagen**, and **Cancelar QR** directly on the QR screen. `VisitorQrModal.tsx`
  renders the QR + code + details but has NO share, NO download, and NO cancel —
  cancel only exists as a row action back in the list, so a resident who just
  generated a QR and changed their mind must close the modal, find the row, and
  cancel there. Sharing is the primary resident workflow ("comparte por WhatsApp"),
  and today residents must screenshot manually.
- **§14.4.3 QR inválido** requires showing the reason PLUS visitor/unit/used-date
  detail and a **Registro manual** fallback. `ScanQrModal.tsx` shows only a one-line
  reason badge and discards the rich `IScanResult` payload (visitor name, unit,
  used date) that the backend already returns; there is no fallback-to-manual action,
  forcing the guard to close and reopen a different modal.

Both are additive UI over data/endpoints that already exist. They close real gaps in
the two highest-traffic Access screens (resident QR share, gate scan).

## Current state

`frontend/src/modules/access/components/VisitorQrModal.tsx` — props `{ visitor, onClose }`;
renders `<QRCodeSVG value={token} size={208} .../>` (line 33) inside a bordered box,
plus unit/motive/expiry and the raw code. No buttons beyond the dialog close.

`frontend/src/modules/access/components/ScanQrModal.tsx`:
```tsx
type LastResult = { ok: boolean; text: string } | null      // line 22
// onError (line 55-63): extracts reason only, setLast({ ok:false, text: reason })
// The ApiError payload IS IScanResult (visitor, access_log, reason, blacklist_match)
```
Parent `AccessNowPage.tsx` owns `setManualOpen` (manual entry) and `setScanOpen`; it
renders `<ScanQrModal isOpen={scanOpen} onClose={...} onScanned={...} />` (line 130).

`IScanResult` (`schema.ts:55-62`): `{ valid, reason?, visitor?, access_log?, blacklist_match? }`.
`VISITOR_STATUS.PENDING` (`schema.ts:11`) is the only cancellable state.
`VisitorResource.cancelQr(id)` already exists (`services/AccessResource.ts:14`).

> Convention: dates via `displayFormatDate` (already imported in VisitorQrModal).
> Toasts via `@/shared/hooks/useToast` (`toast.success/error`). No `new Date()` for
> display. UI text in Spanish. No `any`.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Typecheck | `cd frontend && pnpm build` | exit 0 |
| Lint | `cd frontend && pnpm lint` | no new errors |
| Tests | `cd frontend && pnpm test src/modules/access` | pass |

## Scope

**In scope**:
- `frontend/src/modules/access/components/VisitorQrModal.tsx`
- `frontend/src/modules/access/components/ScanQrModal.tsx`
- `frontend/src/modules/access/AccessNowPage.tsx` (wire the manual fallback callback)
- `frontend/src/modules/access/VisitorListPage.tsx` (pass `onCancel` into VisitorQrModal)

**Out of scope**:
- Camera-based scanning (separate spike — see README direction note).
- Two-step scan-then-confirm (separate, needs product decision — README direction note).
- Any backend change (endpoints + payloads already sufficient).

## Steps

### Step 1: Add "Descargar imagen" to VisitorQrModal

Render the QR also as a downloadable PNG. Simplest approach without new deps: keep the
`<QRCodeSVG>` for display and add a hidden `<QRCodeCanvas>` (also from `qrcode.react`,
already a dependency) ref'd; on "Descargar", read `canvas.toDataURL('image/png')` and
trigger an `<a download>` click with filename `qr-${visitor.name}.png`. If
`qrcode.react` does not export `QRCodeCanvas` in the installed version, serialize the
SVG to a canvas via an offscreen `Image`. Add a "Descargar QR" button.

**Verify**: `cd frontend && pnpm build` → exit 0; button visible when a token exists.

### Step 2: Add "Compartir" to VisitorQrModal

Add a "Compartir" button using the Web Share API: build a text like
`Visita para ${unit} — código ${token}. Muéstralo en la caseta.` and call
`navigator.share({ title, text })` when available; otherwise fall back to copying the
text to the clipboard (`navigator.clipboard.writeText`) and `toast.success('Copiado')`.
Guard with `typeof navigator !== 'undefined' && navigator.share`. (Sharing the PNG file
via `navigator.share({ files })` is optional polish; text share is the MVP.)

**Verify**: `pnpm build` → exit 0; no `any` (type the share payload).

### Step 3: Add "Cancelar QR" to VisitorQrModal

Extend `VisitorQrModalProps` with `onCancel?: (visitor: IVisitor) => void`. Render a
destructive "Cancelar QR" button ONLY when
`visitor.visitor_status_id === VISITOR_STATUS.PENDING`. Wire it in `VisitorListPage`
to the existing `setCancelVisitor` flow (which already shows the ConfirmDialog +
`actionMutation` cancel). Do not duplicate the cancel mutation inside the modal — call
the parent callback.

**Verify**: `pnpm build` → exit 0. In `VisitorListPage`, `VisitorQrModal` now receives
`onCancel`.

### Step 4: Enrich the invalid-scan result in ScanQrModal

Change `LastResult` to `{ ok: boolean; text: string; detail?: IScanResult } | null`.
In `onError`, store the full parsed `IScanResult` payload as `detail`. In the result
banner, when `!last.ok && last.detail`, render the visitor name, unit
(`detail.visitor?.unit?...` after plan 040 keys), and — if `detail.access_log?.entered_at`
exists — "Usado: {displayFormatDate(...)}". Keep the reason line.

**Verify**: `pnpm build` → exit 0.

### Step 5: Add a "Registro manual" fallback action to the invalid-scan state

Add an optional prop `onFallbackToManual?: () => void` to `ScanQrModal`. When
`last && !last.ok`, render a "Registrar entrada manual" button that calls it. In
`AccessNowPage`, pass `onFallbackToManual={() => { setScanOpen(false); setManualOpen(true) }}`.

**Verify**: `pnpm build` → exit 0; clicking it (manually) closes scan and opens manual.

## Test plan

- Extend `frontend/src/modules/access/components/ScanQrModal.test.tsx`:
  - on an invalid scan (422 with `IScanResult` payload), the rendered banner contains
    the visitor name from the payload (not just the reason).
  - the "Registrar entrada manual" button calls `onFallbackToManual`.
- Add a `VisitorQrModal.test.tsx` (model after ScanQrModal.test.tsx): "Cancelar QR"
  renders only for `PENDING` status and calls `onCancel`.
- Verification: `cd frontend && pnpm test src/modules/access` → all pass.

## Done criteria

- [ ] `cd frontend && pnpm build` exits 0
- [ ] VisitorQrModal has Descargar, Compartir, and (PENDING-only) Cancelar QR
- [ ] ScanQrModal invalid state shows visitor/unit/used-date detail + a manual fallback button
- [ ] `cd frontend && pnpm test src/modules/access` passes with the new tests
- [ ] No files outside scope modified
- [ ] `plans/README.md` row updated

## STOP conditions

- The installed `qrcode.react` exposes neither `QRCodeCanvas` nor a way to rasterize —
  STOP and report; do NOT add a new heavyweight dependency without approval (the
  download feature can ship in a follow-up; share + cancel + invalid-detail still land).
- `navigator.share` typings are missing in the TS lib config causing a build error —
  feature-detect with a narrow local type, do not loosen `tsconfig`.

## Maintenance notes

- Web Share API requires HTTPS + a user gesture; on desktop browsers without it the
  clipboard fallback is the path — keep both.
- Deferred to a direction/spike item (README): camera-based scan (§14.4.1) and the
  two-step scan→confirm preview (§14.4.2). Both need a product decision and/or a new
  backend preview endpoint, so they are intentionally NOT in this plan.
