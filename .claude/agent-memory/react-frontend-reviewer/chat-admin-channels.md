---
name: chat-admin-channels
description: Patterns and issues found in the Fase 2 chat channel admin UI (ChannelAdminModal, ChannelFormModal, ChannelTargetSection, ManualUserPicker)
metadata:
  type: project
---

## Key patterns (Fase 2, reviewed 2026-06-28)

- `ChannelAdminModal` is a pure list + CRUD coordinator; delegates create/edit to `ChannelFormModal` and delete to `ConfirmDialog` + `useMutation`.
- Copy-config is done **client-side** in `ChannelFormModal.copyFrom()` via `buildChannelFormDefaults` + `setValue`. The `copyConfig` API method in `ChatResource.ts` is dead code (never called).
- `buildChannelFormDefaults` and `buildChannelTargetSelector` are pure helpers in `schema.ts`; correctly unit-tested in `buildChannelTargetSelector.test.ts`.
- `useWatch({ control, name: 'target_type' })` correctly used to subscribe to a single field without re-rendering parent.
- System channels: shown in edit list, edit allowed, delete gated by `!is_system` — correct product intent.

## Known bugs / TODOs

- **ManualUserPicker labels in edit mode**: `labels` state initializes empty; editing channels with existing `target_user_ids` shows UUID prefix. Fix: `initialLabels` prop derived from `channel?.members`.
- **copyConfig dead code**: `ChatResource.ts:27-33` — remove or document.
- **RadioGroupItem accessibility**: `ChannelFormModal` + `ChannelTargetSection` use `<label>` wrapping RadioGroupItem without explicit `id`/`htmlFor`. Fix: explicit `id`/`htmlFor` pair, consistent with CheckboxList.
- **Catalog item type cast**: `(item as { name?: string })` in ChannelFormModal + ChannelTargetSection — IEntity needs an ICatalogItem subtype with `name: string`.
