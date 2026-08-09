# Verification Report: chat-file-attachments

**Mode**: Full artifacts (proposal, specs ×3, design, tasks) — Standard (non-TDD), full spec-driven verification.
**Verdict**: PASS WITH WARNINGS (pass-with-gaps)

## 1. Task Completeness

28/29 tasks checked in `openspec/changes/chat-file-attachments/tasks.md`. Only unchecked item:
- **5.4** (manual browser smoke: attach PDF in composer → verify `MediaAttachmentCard` renders/downloads; publish announcement with attachment → verify cross-post shows downloadable file). Explicitly deferred in tasks.md as "no live dev server/browser session in this apply batch. Recommend for sdd-verify or manual QA."

This is the single known gap, exactly as flagged by the apply phase. No other unchecked tasks.

## 2. Test Execution (host php, one process at a time — no parallel runs)

| Suite | Result |
|---|---|
| `MediaAttachmentPolicyTest` | 19 passed (35 assertions) |
| `MediaAuthorizationServiceTest` | 4 passed (7 assertions) |
| `ChatRichMessageTest` | 9 passed (25 assertions) |
| `ChatModuleTest` | 16 passed (57 assertions) |
| `AnnouncementChatCrossPostTest` | 9 passed (34 assertions) |
| `AnnouncementsModuleTest` | 12 passed (39 assertions) |
| `MediaAttachmentTest` | 5 passed (12 assertions) |
| **Combined single-process run** (`--filter='ChatRichMessageTest\|ChatModuleTest\|AnnouncementChatCrossPost\|AnnouncementsModuleTest\|MediaAuthorizationServiceTest\|MediaAttachmentPolicyTest\|MediaAttachmentTest'`) | **74 passed (209 assertions)**, exit 0 — sum of the 7 suites above, no cross-suite interference |

Frontend:
- `pnpm vitest run src/modules/chat src/modules/announcement src/shared` → **23 test files, 243 tests, all passed**.
- `pnpm typecheck` (`tsc -p tsconfig.app.json --noEmit`) → 2 errors, both in `src/components/signup-form.tsx` (`OAuthButtons`/`handleOAuth` unused). This file is **not touched by this change** (confirmed against the apply report's file list and `git status`); pre-existing WIP breakage in the shared checkout, consistent with prior session notes. **Not a blocker for this change.**

All executed test commands exited 0 with zero failures.

## 3. Spec Compliance Matrix

### Spec: chat-attachments

| Requirement | Evidence | Test | Status |
|---|---|---|---|
| Shared whitelist/limits | `backend/app/Support/MediaAttachmentPolicy.php:23-51`; `Modules/Chat/app/Http/Requests/ChatMessageCreateRequest.php:24-27` | `ChatRichMessageTest::test_whitelist_matrix_persists_mixed_valid_attachments`, `test_disallowed_extension_is_rejected_with_no_media_persisted`, `test_oversize_attachment_is_rejected`, `test_too_many_attachments_is_rejected` (all pass) | PASS |
| Double-layer validation | `ChatService.php:264` (`assertAttachmentsAllowed`, called before `addMedia`), body at `ChatService.php:312-331` | `ChatRichMessageTest::test_service_layer_rejects_disallowed_mime_bypassing_form_request` | PASS |
| WebP conversion image-scoped | `Modules/Chat/app/Models/ChatMessage.php:100-110` (mime guard on `registerMediaConversions`) | `test_pdf_attachment_produces_no_webp_conversion`, `test_image_attachment_is_stored_and_converted_to_webp` (non-regression) | PASS |
| Composer accepts whitelist, compresses only images | `frontend/src/modules/chat/components/MessageComposer.tsx:164-195` (`addAttachment`: `isAllowedAttachment`/`isValidAttachmentSize` gate, `isImage` branch compresses only images, non-image passes through with `kind:'file'`) | `imageCompressor.test.ts` unit-tests the two new helpers (36 tests) but **no dedicated `MessageComposer` render/interaction test exists** — task 4.6 explicitly skipped net-new component test scaffolding | **WARNING — UNTESTED at component level** (logic verified by source inspection + helper unit tests only; overlaps with the pending 5.4 manual smoke) |
| Bubble renders images/non-image distinctly | `frontend/src/modules/chat/components/ChatMessageBubble.tsx:148-221` (`AttachedImages` filters `image/*`, `AttachedFiles` renders `MediaAttachmentCard` for the complement) | No dedicated `ChatMessageBubble` render test exists (none found under `src/modules/chat/**/*.test.{ts,tsx}`) | **WARNING — UNTESTED at component level** (same gap class as above) |

### Spec: media-authorization

| Requirement | Evidence | Test | Status |
|---|---|---|---|
| ChatMessage auth delegates to channel access | `backend/app/Services/MediaAuthorizationService.php:123-128` (delegates to `ChatService::canAccessChannel`, fails closed on null channel; NOT added to `MEDIA_READ_ABILITIES`, per ADR-2) | `MediaAuthorizationServiceTest::test_channel_member_can_download_chat_media` (uses a real `residentToken`, non-wildcard — the exact regression scenario), `test_non_member_is_denied`, `test_moderator_can_download_without_channel_membership` | PASS |
| Tenant isolation | Same file/branch; relies on upstream `TenantScope` nulling the owner for foreign-tenant media | `test_cross_tenant_download_is_denied` | PASS |

### Spec: announcement-chat-crosspost

| Requirement | Evidence | Test | Status |
|---|---|---|---|
| Cross-post carries attachments via `Media::copy` | `Modules/Announcements/app/Services/AnnouncementService.php:427-467` (`postToOfficialChatChannel` passes `$announcement->getMedia('attachments')->all()`); `Modules/Chat/app/Services/ChatService.php:174-186` (`postSystemMessage` loops `$media->copy($message,'attachments')`) | `AnnouncementChatCrossPostTest::test_publishing_an_announcement_with_a_pdf_carries_it_to_chat` | PASS |
| No attachments → no media, no error | Same call site; `getMedia()->all()` returns `[]` for text-only, loop is a no-op | `test_text_only_announcement_cross_posts_without_media` | PASS |
| Copy failure does not break publish | Existing `try { … } catch (\Throwable $e) { Log::warning(...); }` around the whole `postToOfficialChatChannel` body (`AnnouncementService.php:429-467`) — untouched, now also wraps the copy loop | `test_attachment_copy_failure_does_not_break_publish` | PASS |
| Idempotent, non-duplicating (non-regression) | `$wasDraft` guard captured before status flip (`AnnouncementService.php:~70`), unchanged; `postSystemMessage` still never calls `notifyChannelMessage` | `test_publishing_twice_does_not_duplicate_chat_message`, `test_republishing_does_not_duplicate_the_copied_attachment`, `test_cross_post_does_not_create_additional_chat_notifications` (all pre-existing, still pass) | PASS |

## 4. Design Coherence

All 6 ADRs verified against code:
- ADR-1 (single-source whitelist `MediaAttachmentPolicy`) — confirmed, no duplicated literals in Chat's new code.
- ADR-2 (delegate to `canAccessChannel`, no new ability) — confirmed, `MEDIA_READ_ABILITIES` map unchanged.
- ADR-3 (`Media::copy`, not reference) — confirmed.
- ADR-4 (mime-guarded conversion) — confirmed, guard applies to both `addMedia` and `copy` paths (same registration method).
- ADR-5 (`postSystemMessage` additive 5th param, sole caller updated) — confirmed via `rg` for `postSystemMessage(` call sites; only `postToOfficialChatChannel` calls it.
- ADR-6 (frontend composer/bubble/shared molecule) — confirmed; `AnnouncementViewPage.tsx:117` now consumes the shared `MediaAttachmentCard`.

No design deviations found.

## 5. Non-Regression Checks

- Announcement→chat cross-post idempotency (`$wasDraft`): intact, tests pass.
- No duplicate notification on cross-post: `postSystemMessage` still does not call `notifyChannelMessage`; explicit test passes.
- Fail-safe on copy failure: outer `try/catch(\Throwable)` still wraps the whole cross-post body; explicit test passes.
- Image attachment behavior (thumbnail render, WebP conversion): explicitly re-asserted by non-regression tests, all pass.

## 6. Uncommitted-Changes Sanity

`git -C backend status --short` and `git -C frontend status --short` were run. The touched-file set for **this change** matches the apply report's "Where" list exactly:

**Backend** (all present, correct status): `app/Support/MediaAttachmentPolicy.php` (A), `app/Services/MediaAuthorizationService.php` (M), `Modules/Chat/app/Http/Requests/ChatMessageCreateRequest.php` (M), `Modules/Chat/app/Models/ChatMessage.php` (M), `Modules/Chat/app/Services/ChatService.php` (M), `Modules/Chat/app/Http/Controllers/ChatMessageController.php` (M), `Modules/Announcements/app/Services/AnnouncementService.php` (M), `tests/Unit/Support/MediaAttachmentPolicyTest.php` (A), `tests/Feature/Media/MediaAuthorizationServiceTest.php` (A), `tests/Feature/Chat/ChatRichMessageTest.php` (M), `tests/Feature/Announcements/AnnouncementChatCrossPostTest.php` (A).

**Frontend** (all present, correct status): `src/modules/chat/helpers/imageCompressor.ts` (M) + `.test.ts` (M), `src/modules/chat/components/MessageComposer.tsx` (MM), `src/modules/chat/components/ChatMessageBubble.tsx` (M), `src/modules/announcement/AnnouncementViewPage.tsx` (M), `src/shared/components/molecules/MediaAttachmentCard.tsx` (new, untracked).

**Unexpected touched files found** — NOT part of this change, pre-existing uncommitted WIP from parallel/prior sessions sharing this checkout (per prior session memory `checkout-shared-multisession-wip`):
- Backend: `Modules/Collections/**` (PaymentController, Charge, CollectionsReportService, routes), `Modules/Reports/**`, `database/seeders/SystemTableSeeder.php` + CSVs, `tests/Feature/Collections/Delinquency*Test.php`, `tests/Feature/Reports/ReportsModuleTest.php`, untracked `storage/media-library/temp/**` (test-run artifacts), `.claude/agent-memory/**`.
- Frontend: a large board-period/chat-channel-editor/list-page WIP surface (`src/modules/boardPeriod/**`, `src/modules/chat/ChannelEditorPage.tsx`, `src/modules/chat/components/ChannelForm.tsx`, dozens of `*ListPage.tsx` files, `src/components/signup-form.tsx`), `.claude/agent-memory/**`.

These are confirmed unrelated (different modules/features, already broken/in-progress independent of this change) and are **not evaluated as part of this verification**. No file that belongs to this change's own scope is missing or unexpectedly modified.

## Issues

### CRITICAL
None.

### WARNING
1. **Task 5.4 (manual browser smoke) not performed** — the single known gap, already flagged in tasks.md. Recommend running it before archive: attach a PDF in the chat composer → confirm send + `MediaAttachmentCard` render/download; publish an announcement with an attachment → confirm the cross-posted chat message shows the same file downloadable.
2. **No dedicated component-level test for `MessageComposer` (file-chip vs thumbnail rendering) or `ChatMessageBubble` (`AttachedFiles`/`MediaAttachmentCard` render split)** — the underlying helpers (`isAllowedAttachment`, `isValidAttachmentSize`) are unit-tested and the rendering logic was verified by direct source inspection (both PASS on inspection), but per the "spec scenario compliant only when a covering test passed at runtime" rule these two frontend scenarios lack runtime test coverage. This overlaps with and reinforces the case for closing gap #1 before archive.

### SUGGESTION
1. `AnnouncementService::attachFile()` and `AnnouncementController::uploadMedia()` still hardcode their own whitelist literals (pre-existing duplication, confirmed still present, out of scope per design ADR-1 rejected-alternatives). `MediaAttachmentPolicy` now exists precisely to let Announcements adopt it in a follow-up — worth a small follow-up ticket to kill the last duplication.
2. PHPUnit 12 in this repo does not execute `@dataProvider` docblock annotations (must use `#[DataProvider]` attribute) — a pre-existing repo-wide gotcha (also affects `SpreadsheetSafeTest`), unrelated to this change but worth a project-wide fix.

## Final Verdict: PASS WITH WARNINGS

All CRITICAL-tier checks pass: 74/74 backend tests and 243/243 frontend tests green across every touched file, all three specs' backend requirements are fully covered by passing runtime tests, all 6 design ADRs hold, and the announcement→chat cross-post non-regression (idempotency, no duplicate notification, fail-safe) is intact. The two WARNINGs are both scoped to the same known, already-flagged gap (frontend manual/browser-level verification of composer and bubble rendering) and do not indicate a defect — they indicate deferred verification. Recommend closing WARNING #1 (5.4 manual smoke) before or during archive; WARNING #2 can be closed by either the manual smoke or a light follow-up component test.
