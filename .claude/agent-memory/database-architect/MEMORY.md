# Database Architect — Memory Index

- [Package module schema review](package-module-schema.md) — Findings from WU-1 schema review: carrier/type soft-delete unique bug, missing received_at index, FK onDelete gaps
- [Soft-delete + unique constraint pattern](soft-delete-unique-pattern.md) — Codebase pattern for partial unique indexes; vehicle plate bug was fixed 2026-06-29, sets the standard
- [WU-2 pickup & custody review](wu2-pickup-custody-review.md) — 4 WARNINGs: handover multi-unit notification gap, concurrent code-gen race, media-in-transaction orphan risk, missing verify-code tenant isolation test
- [WU-4 retention cron review](wu4-retention-review.md) — 2 WARNINGs: abort(422) from returnPackage() crashes cron skipping all subsequent tenants; reminded_at stamped even when dispatchReminder() sends nothing
- [WU-5 reports & dashboard review](wu5-report-review.md) — 1 WARNING: duplicate Excel columns ("Ubicación de almacenamiento" and "Ubicación" map to same field); 4 SUGGESTIONs
- [Collections schema audit findings](collections-schema-audit.md) — hallazgos críticos y warnings del audit del módulo Collections (charge, payment, credit_balance, surcharge, due_soon). (recovered 2026-07-20 from a stray `frontend/.claude/agent-memory` copy)
