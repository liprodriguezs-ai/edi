---
name: custom-action-pattern
description: SANCTIONED pattern for non-CRUD custom action endpoints — customMethods + apiClient inside createCrudApi. No base helper exists.
metadata:
  type: pattern
---

For non-CRUD action endpoints (e.g. `POST /access/{id}/correction`, `/chat-message/{id}/edit`,
`/announcement/{id}/media`), the ONLY sanctioned frontend pattern is `customMethods: (basePath) => ({...})`
with `apiClient.post(...)` inside, declared via the 4th type-param of `createCrudApi`.

**Source:** `react-frontend-architecture/SKILL.md` §2.4 "Si necesitas métodos custom" (lines 284-303). The CollaboratorResource
`validateImport`/`batchImport` example IS the canonical template.

**Why:** There is NO base helper (`action()`/`query()`) in the skill. Inventing one in `resourceApi.ts`
violates §4.5 (no duplicate logic). The user explicitly rejected such helpers and reverted them — confirmed correct.

**How to apply:**
- Use the `basePath` callback param to build URLs: `apiClient.post(\`${basePath}/${id}/action\`)`.
- DO NOT discard `basePath` and hardcode a different URL segment — that breaks basePath-as-single-source-of-truth.
- `apiClient` inside a `*Resource.ts` service file is FINE; the user's ban is on `apiClient` inside COMPONENTS only.

**Known smell observed (2026-06-12):** ChatResource sets `basePath: '/chat_message'` (snake) but hardcodes
`/chat-message/...` (kebab) in custom methods, discarding basePath. AccessLogResource: `basePath '/access_log'`
but actions hit `/access/...`. Both diverge CRUD-route from action-route — verify against backend
`routes/api.php` before assuming OK. ReportsResource correctly does NOT use createCrudApi (not a CRUD entity).
