# email-expert — MEMORY

Transactional email subsystem. Source of truth = `frontend/emails/*.tsx` (React Email);
compiled to `backend/Modules/Email/resources/views/*.blade.php` via `pnpm copy-emails`.
Full pipeline + hard rules are in my agent prompt (CLAUDE.md strict rule 10).

## Email inventory (.tsx source ↔ generated blade ↔ Mailable)

| Email (`frontend/emails/`) | Blade view | Mailable (`backend/Modules/Email/app/Emails/`) | Notes |
|---|---|---|---|
| `invitation.tsx` | `invitation.blade.php` | `UserInvitationMail.php` | Collaborator/employee invite. Var: `{{ $acceptUrl }}`. Subject "Completa tu cuenta" |
| `resident-invitation.tsx` | `resident-invitation.blade.php` | `ResidentInvitationMail.php` | Resident invite (added 2026-06). Vars: `acceptUrl, inviterName, unitLabel, clusterName, relationshipName, ttlDays`. Mailable passes NON-NULL defaults ("Un residente"/"tu unidad"/"tu condominio"/"residente"/7). CTA "Activar mi cuenta" |
| `reset-password.tsx` | `reset-password.blade.php` | (auth reset flow) | |
| `complete-account.tsx` | `complete-account.blade.php` | | |
| `welcome-owner.tsx` | `welcome-owner.blade.php` | | |
| `owner-transferred.tsx` | `owner-transferred.blade.php` | | Owner change notice |
| `payment-receipt.tsx` | (not committed) | | `.tsx` exists, blade NOT compiled in repo |
| `loyalty-welcome / loyalty-recovery / loyalty-reward-earned` | (not committed) | | `.tsx` exist, blades NOT compiled in repo — do not regenerate unless asked |

## Shared building blocks (`frontend/emails/_components/`)

`EmailLayout` (Html/Head/Preview/Tailwind/Body/Container + header/footer; prop `previewText`),
`EmailHeader`, `EmailFooter`, `ButtonBase` (href + primary style, uses `_theme.primaryDark`),
`Title`, `TextBase`, `Card`, `OrderStatus`, and `LaravelBlade` (`<Blade variable="x" fallback=".." />`
→ `{{$x}}` in build, fallback in preview; `BladeTrans` currently renders children as-is — the
`__('email::...')` translation branch is COMMENTED OUT, so text is effectively static). Theme: `_theme.ts`.

## Wiring / gotchas

- Mailable `build()`: `->view('email::{name}')->with(['acceptUrl'=>..., 'emailBaseUrl'=>..., ...])`.
  `emailBaseUrl` = `config('email.assets_url') ?? rtrim(config('app.url'),'/').'/email'`.
- Sent synchronously: `Mail::to($email)->send($mailable)` (no `ShouldQueue`) — from module Services
  (e.g. `UserInvitationService::sendInvitationEmail` picks the Mailable by `inviteable_type`).
- Resend channel: `app/Notifications/Channels/ResendEmailChannel.php`; resident notifications via
  `NotifiesResidents` (channels log/sms/resend_email).
- `copy-emails` regenerates ALL blades — after running, `git -C backend checkout` the ones you didn't
  mean to touch, delete stray newly-generated blades, and `rm -rf frontend/out`. Verify with tinker
  `->render()` (Mail::fake does not render).

## Topic files (detail) — recovered 2026-07-20 from stray submodule `.claude/agent-memory` copies

- [Payment email templates (Fase 4)](payment-emails-phase4.md) — 4 new payment notification templates added 2026-06-27: due-reminder, for-review, accepted, rejected
- [System email unification (Cambio 5)](system-email-unification.md) — SystemMailer = ungated system-email seam; blade_email canonical domain channel; legacy EmailService/EmailSenderService/ResetPasswordNotification deleted (2026-06-28)
- [Authoring: unused destructured props break lint](authoring-unused-props-lint.md) — only destructure props the JSX uses as fallbacks; detailRows use valueFallback constants
- [announcement-published email](email-announcement-published.md) — new 2026-07-04 announcement email: source, 5 vars, sent via BladeEmailChannel template key
- [copy-emails diff scoping](pipeline-copy-emails-scoping.md) — copy-emails regenerates ALL blades (incl. uncommitted loyalty-\*/payment-receipt + stray static svg): how to scope the diff back down + how to verify render for real
- ["condominio", nunca "clúster"](terminology-condominio-not-cluster.md) — vocabulario de producto en el copy de todos los emails (barrido 2026-08-08, 9 archivos); no toca identificadores ni `clusterName`
