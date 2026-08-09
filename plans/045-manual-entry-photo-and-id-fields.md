# Plan 045: Wire visitor photo capture + ID-document fields into the gate forms (§14.4.4)

> **Executor instructions**: Follow step by step, verify each step, honor STOP
> conditions, update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat 0887d5a..HEAD -- src/modules/access`

## Status

- **Priority**: P2
- **Effort**: S
- **Depends on**: none
- **Category**: spec gap (frontend completeness)
- **Planned at**: frontend submodule `0887d5a`, 2026-06-21

## Why this matters

The backend already accepts these fields; the gate UI just never sends them, so a
documented capability silently does nothing:

- **Photo (§14.4.4 + §14.5 r8 "Foto del visitante recomendable")**: the manual-entry
  endpoint accepts `photo` (`nullable|image|max:5120`) and `AccessService::manualEntry`
  attaches it to the `photo` media collection. But `ManualEntryModal` has no file input
  and `AccessLogResource.manualEntry` posts plain JSON (no `FormData`), so no photo is
  ever captured — security incidents lose photographic evidence.
- **ID document on entry request (§14.4.4 / §14.4.5)**: `manualCaptureRules` accepts
  `id_document_type` + `id_document_number` for the entry-request action, but
  `EntryRequestModal` / `entryRequestSchema` omit both, so guards can't capture ID when
  pushing an approval to the resident (they can only capture ID on the direct manual
  entry).

Both are additive UI; the backend contract is already in place.

## Current state

Backend `AccessLogController::manualCaptureRules` (`backend/Modules/Access/app/Http/Controllers/AccessLogController.php:193-203`):
```php
'unit_id' => [...tenant-scoped...],
'id_document_type' => ['nullable','string', Rule::in(['ine','passport','driver_license','other'])],
'id_document_number' => ['nullable','string','max:100'],
'plate' => [...], 'visitor_motive_id' => [...], 'photo' => ['nullable','image','max:5120'],
```
`manualEntry()` (line 71-77) reads `'photo' => $request->file('photo')` and passes it
to the service, which calls `$model->addMedia($photo)->toMediaCollection('photo')`.

Frontend `AccessResource.manualEntry` (`services/AccessResource.ts:47-50`):
```ts
manualEntry: async (payload) => {
  const { data } = await apiClient.post<{ data: IAccessLog }>('/access/manual-entry', payload)  // JSON, no photo
  return data.data
},
```
`ManualEntryModal` (`AccessNowPage.tsx:139-308`): fields name/unit/verification/motive/
plate/id-type/id-number/authorized-by. NO photo input.

`EntryRequestModal` (`components/EntryRequestModal.tsx`) + `entryRequestSchema`
(`schema.ts:90-96`): unit_id, name, visitor_motive_id, plate, notes. NO id-document.

> Convention: `createCrudApi`'s axios client auto-builds `FormData` for uploads (per
> react-frontend-architecture §2.4) — but `manualEntry` is a custom method posting a plain object, so
> it must build `FormData` itself when a photo is present. The id-document type select
> already exists in `ManualEntryModal` (lines 261-280) as the exemplar to copy into
> `EntryRequestModal`.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Typecheck | `cd frontend && pnpm build` | exit 0 |
| Lint | `cd frontend && pnpm lint` | no new errors |
| Tests | `cd frontend && pnpm test src/modules/access` | pass |

## Scope

**In scope**:
- `frontend/src/modules/access/AccessNowPage.tsx` (ManualEntryModal: add photo input)
- `frontend/src/modules/access/components/EntryRequestModal.tsx` (add id-document fields)
- `frontend/src/modules/access/schema.ts` (`entryRequestSchema`: add optional id fields; `manualEntrySchema` already lacks photo — photo is a `File`, kept out of zod, passed separately)
- `frontend/src/modules/access/services/AccessResource.ts` (`manualEntry`: build FormData when photo present; `entryRequest`: pass id fields)

**Out of scope**:
- Backend (already accepts the fields).
- Camera live-capture as a separate "take photo" UX beyond `<input capture>` — the
  `accept="image/*" capture="environment"` attribute is enough for the MVP.

## Steps

### Step 1: Make `AccessResource.manualEntry` send multipart when a photo is present

Change the `manualEntry` custom method signature to accept an optional `photo?: File`.
When `photo` is set, build a `FormData`, append every scalar field plus `photo`, and
POST with `apiClient.post(url, formData)` (axios sets the multipart boundary). When no
photo, keep the JSON path. Keep the return shape `{ data }.data`.

**Verify**: `cd frontend && pnpm build` → exit 0.

### Step 2: Add a photo input to ManualEntryModal

Add a `<input type="file" accept="image/*" capture="environment">` field (label
"Foto del visitante (opcional)") to the manual-entry form grid. Hold the selected
`File` in component state (not in the zod schema). Pass it to the mutation, which calls
`AccessResource.manualEntry({ ...values, photo })`.

**Verify**: `pnpm build` → exit 0; selecting a file and submitting sends multipart
(confirm by reading the code path — the FormData branch runs when `photo` is set).

### Step 3: Add ID-document fields to EntryRequestModal

Add `id_document_type` (Select: INE/Pasaporte/Licencia/Otra — copy the exemplar from
`ManualEntryModal` lines 261-280) and `id_document_number` (Input) to
`EntryRequestModal`. Extend `entryRequestSchema` with:
```ts
id_document_type: z.enum(['ine','passport','driver_license','other']).optional(),
id_document_number: z.string().max(100).optional(),
```
Pass them through `AccessResource.entryRequest` (add to the posted payload, stripping
empty strings to `undefined` like the existing `plate`/`notes`).

**Verify**: `pnpm build` → exit 0.

## Test plan

- Extend access tests: `entryRequestSchema` accepts a valid `id_document_type` and
  rejects an invalid enum value; `manualEntry` builds FormData (test the resource branch
  by spying on `apiClient.post` arguments — assert the body is a `FormData` instance
  when `photo` is provided and a plain object otherwise).
- Verification: `cd frontend && pnpm test src/modules/access` → pass.

## Done criteria

- [ ] `cd frontend && pnpm build` exits 0
- [ ] ManualEntryModal has a photo input and sends multipart when a photo is chosen
- [ ] EntryRequestModal has id-document type + number fields, validated by zod
- [ ] `AccessResource.manualEntry` posts `FormData` only when a photo is present (JSON otherwise)
- [ ] `cd frontend && pnpm test src/modules/access` passes
- [ ] No files outside scope modified
- [ ] `plans/README.md` row updated

## STOP conditions

- `apiClient` strips/forces a `Content-Type: application/json` header that breaks
  multipart for custom POSTs → inspect `frontend/src/shared/api/apiClient.ts`; if it
  hard-sets JSON, follow the existing upload pattern used by another module (e.g. the
  collaborator import `validateImport`/`batchImport` FormData methods) instead of
  hand-rolling, and note it.

## Maintenance notes

- The photo lands in the `photo` single-file media collection on the AccessLog (manual)
  / Visitor (entry-request). A future "view photo" affordance in the bitácora detail
  would surface it — not in this plan.
- Reviewer: confirm the multipart branch only triggers with a real `File` (avoid
  sending an empty FormData that drops other fields).
