---
name: payment-emails-phase4
description: 4 new payment notification email templates created 2026-06-27 for the configurable notifications plan (Fase 4)
metadata:
  type: project
---

## Templates added (2026-06-27, Fase 4 — Sistema de Notificaciones Configurable)

All 4 templates live in `frontend/emails/` and compile to `backend/Modules/Email/resources/views/`.

### Pattern used
- Flat snake_case Blade vars (NOT object-property `payment->receipt_number` style)
- `<Blade variable="snake_case_name" fallback="..." />` → compiles to `{{$snake_case_name}}`
- CTA button href: `href={'{{ $url }}'}` (literal Blade string in JSX)
- `{{ $emailBaseUrl }}` auto-injected by `getEmailAssetsBaseUrl()` in EmailHeader

### Template contracts (Blade vars per template)

**`payment-due-reminder.blade.php`** (→ residente, recordatorio amable)
- `$resident_name`, `$concept_name`, `$amount`, `$due_date`, `$days_left`, `$url`
- Also: `$emailBaseUrl`

**`payment-registered.blade.php`** (→ mesa directiva, pago registrado por residente que requiere revisión)
- `$resident_name`, `$unit_label`, `$amount`, `$method`, `$paid_at`, `$url`
- Also: `$emailBaseUrl`
- NOTE: originally created as `payment-for-review` — renamed 2026-06-27 to match event key `payment_registered` (see gotcha below)

**`payment-accepted.blade.php`** (→ residente, confirmación de validación)
- `$resident_name`, `$receipt_number`, `$amount`, `$paid_at`, `$unit_label`, `$url`
- Also: `$emailBaseUrl`

**`payment-rejected.blade.php`** (→ residente, pago no validado con motivo destacado)
- `$resident_name`, `$amount`, `$reason`, `$unit_label`, `$url`
- Also: `$emailBaseUrl`

**`payment-registered-direct.blade.php`** (→ residente, pago registrado por el administrador) — added 2026-06-27
- `$resident_name`, `$receipt_number`, `$amount`, `$paid_at`, `$unit_label`, `$url`
- Also: `$emailBaseUrl`
- Same variable contract as `payment-accepted`. Different copy: headline "Se registró tu pago", body "Tu administrador registró un pago a tu nombre."

### Backend Mailables still needed (Fase 2/3)
These templates are compiled but no Mailable PHP classes exist yet. The `BladeEmailChannel` (Fase 2) will resolve `email::{slug}` → `email::payment-due-reminder` etc. Backend must pass flat snake_case arrays in `->with([...])` (e.g. `$ctx['resident_name'] ?? 'Vecino'` as default).

### CRITICAL GOTCHA — template filename MUST match event key (2026-06-27)

`BladeEmailChannel` resolves the view by converting the event key: `payment_registered` → `email::payment-registered` (underscores → hyphens). If the `.tsx` filename doesn't match the event key, the channel silently falls back to plain text with NO error.

**Rule:** `{event_key}.tsx` (underscores replaced with hyphens) = `{event_key_hyphenated}.blade.php`. All 5 templates now follow this: `payment_due_reminder` → `payment-due-reminder`, `payment_registered` → `payment-registered`, `payment_accepted` → `payment-accepted`, `payment_rejected` → `payment-rejected`, `payment_registered_direct` → `payment-registered-direct`.

Do NOT name templates by intent/description — name them by the event key.

### Known pre-existing issue
`emails/static/logo.png` is missing — the header logo shows a 404 in all email templates (not introduced by this work).

### Compile process (copy-emails)
After creating .tsx files, ran `pnpm copy-emails` from `frontend/`. Script regenerates ALL blades.
Reverted 5 collateral-modified blades (preview-text padding drift): complete-account, invitation, owner-transferred, reset-password, welcome-owner.
Deleted out-of-scope newly-generated blades: loyalty-recovery, loyalty-reward-earned, loyalty-welcome, payment-receipt (all were untracked before this run).
Deleted `frontend/out/` artifact directory after copy.
