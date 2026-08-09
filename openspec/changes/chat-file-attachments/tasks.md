# Tasks: chat-file-attachments

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~550-650 (7 backend files + 4 feature tests + 4 frontend files + 1 shared molecule) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (policy + auth fix) → PR 2 (chat generalization) → PR 3 (cross-post + frontend) |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: size-exception
400-line budget risk: High

Maintainer accepted `size:exception` (exception-ok strategy) — proceed as a single PR unless the implementer sees the diff trending materially larger than this estimate, in which case fall back to the 3-unit split below.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | `MediaAttachmentPolicy` + `MediaAuthorizationService` ChatMessage branch, fixing the 403 auth defect | PR 1 | `php artisan test --filter=MediaAuthorizationServiceTest` | `php artisan tinker` manual download of a real chat image attachment as channel member/non-member | Revert `MediaAuthorizationService.php` branch + delete `MediaAttachmentPolicy.php`; no schema change |
| 2 | Backend chat attachment generalization (FormRequest, `ChatMessage` collection/mime-guard, `ChatService::postMessage`/`postSystemMessage`) | PR 2 | `php artisan test --filter=ChatMessageTest` (or module's chat attachment test file) | `POST /api/chat/messages` with a PDF via existing dev session | Revert `ChatMessage.php`, `ChatMessageCreateRequest.php`, `ChatService.php` to pre-change (Unit 1 stands alone) |
| 3 | Announcement→chat cross-post attachment carry-over + frontend (composer, bubble, shared `MediaAttachmentCard`) | PR 3 | `php artisan test --filter=AnnouncementServiceTest` + `pnpm vitest run MessageComposer` | Publish an announcement with 1 PDF attachment, verify chat cross-post message shows a downloadable card | Revert `AnnouncementService.php::postToOfficialChatChannel` call site + frontend files; Units 1-2 remain functional standalone |

## Phase 1: Shared Policy + Media Authorization Fix

- [x] 1.1 Create `backend/app/Support/MediaAttachmentPolicy.php` (final class): `EXTENSIONS` array (pdf,jpg,jpeg,png,webp,gif,mp4,mp3,doc,docx,xls,xlsx), full `MIME_TYPES` map, `MAX_FILES = 5`, `MAX_FILE_BYTES` (10MB), `MAX_FILE_KILOBYTES = 10240`, helpers `mimesRule(): string`, `isAllowedExtension(string $ext): bool`, `withinSizeLimit(int $bytes): bool`.
- [x] 1.2 RED test `backend/tests/Unit/Support/MediaAttachmentPolicyTest.php`: assert whitelist membership, rejects `.zip`, rejects 11MB, `mimesRule()` produces the expected Laravel rule string.
- [x] 1.3 GREEN: verify 1.2 passes against 1.1. (19 passed)
- [x] 1.4 Add `ChatMessage` branch to `MediaAuthorizationService::ownsMediaSubject()` (`backend/app/Services/MediaAuthorizationService.php`, after the `Payment`/`Visitor` branch, before the final `return false`): delegate to `app(\Modules\Chat\Services\ChatService::class)->canAccessChannel($owner->channel, $user->getKey())`, guard `$owner->channel === null` → `false`. Do NOT add `ChatMessage::class` to `MEDIA_READ_ABILITIES` (per ADR-2).
- [x] 1.5 RED tests in `backend/tests/Feature/Media/MediaAuthorizationServiceTest.php` (new file — none existed): channel member downloads own-channel image attachment → succeeds (fixes today's 403 for real tokens); non-member → 403; moderator via `userCanModerate` path → succeeds; cross-tenant `ChatMessage` owner → 403 regardless of channel-access outcome (tenant isolation, spec `announcement-chat-crosspost`/`media-authorization` scenario).
- [x] 1.6 GREEN: run 1.5, fix `ownsMediaSubject` guard clauses until green. (4 passed)

## Phase 2: Backend Chat Attachment Generalization

- [x] 2.1 `backend/Modules/Chat/app/Http/Requests/ChatMessageCreateRequest.php`: replace `'attachments.*' => ['image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120']` with `['file', MediaAttachmentPolicy::mimesRule(), 'max:' . MediaAttachmentPolicy::MAX_FILE_KILOBYTES]`; `'attachments' => ['sometimes', 'array', 'max:' . MediaAttachmentPolicy::MAX_FILES]`.
- [x] 2.2 `backend/Modules/Chat/app/Models/ChatMessage.php::registerMediaCollections()`: widen `acceptsMimeTypes()` to `MediaAttachmentPolicy::MIME_TYPES` values (full whitelist, 1:1 with extensions — R1 from design, avoid Announcements' narrower collection-vs-service drift).
- [x] 2.3 `backend/Modules/Chat/app/Models/ChatMessage.php::registerMediaConversions()`: guard with `if (! str_starts_with($media?->mime_type ?? '', 'image/')) { return; }` before registering the `webp` conversion (ADR-4 — protects both `addMedia` and `copy()` paths from PDF rasterization).
- [x] 2.4 `backend/Modules/Chat/app/Services/ChatService.php::postMessage()`: rename `array $images = []` param to `array $attachments = []` (same shape `{file, title}`), re-validate each file's extension/size/count via `MediaAttachmentPolicy` before `addMedia()` (double-layer validation, spec requirement) — reject with the same `InvalidArgumentException` pattern used elsewhere in this service. Added private `assertAttachmentsAllowed()`.
- [x] 2.5 `backend/Modules/Chat/app/Services/ChatService.php::postSystemMessage()`: add 5th param `array $mediaToCopy = []` (additive/default-safe per ADR-5); after `createMessageRecord()`, iterate `$mediaToCopy` and call `$media->copy($chatMessage, 'attachments')`, no validation re-check needed here (already-attached, trusted announcement media).
- [x] 2.6 `backend/Modules/Chat/app/Http/Controllers/ChatMessageController.php`: renamed the `$images` local variable to `$attachments`.
- [x] 2.7 RED tests extending `backend/tests/Feature/Chat/ChatRichMessageTest.php`: whitelist matrix (mixed valid files → all persisted; `.zip` → validation error, zero media persisted; 11MB PDF → validation error; 6 files → whole request rejected, no partial persist); double-layer validation (service call bypassing FormRequest still rejects a non-whitelisted mime before `addMedia()`); conversion scoping (PDF attached → no `webp` conversion generated; JPG attached → `webp` conversion still generated, non-regression).
- [x] 2.8 GREEN: run 2.7 until passing. (9 passed; full `tests/Feature/Chat/` suite 58 passed, no regression)

## Phase 3: Announcement Cross-Post Attachment Carry-Over

- [x] 3.1 `backend/Modules/Announcements/app/Services/AnnouncementService.php::postToOfficialChatChannel()`: pass `$announcement->getMedia('attachments')->all()` as the 5th arg to `$chatService->postSystemMessage(...)` — only this call site edited (per ADR-5, sole caller).
- [x] 3.2 Confirmed the existing `try`/`catch (\Throwable $e)` around `postToOfficialChatChannel()` already swallows and logs copy failures — no new try/catch added; targeted assertion added in 3.3 instead.
- [x] 3.3 RED tests extending `backend/tests/Feature/Announcements/AnnouncementChatCrossPostTest.php`: announcement with 1 PDF attachment published+cross-posted → resulting `ChatMessage` has 1 copied media item with owner `ChatMessage` (not `Announcement`); text-only announcement → 0 media items, no error; simulated copy failure (deleted underlying stored file so `Media::copy()` throws) → announcement still publishes, cross-post text message still created, no exception propagates; re-firing publish for an already-cross-posted announcement → no duplicate message/attachment (non-regression on existing `$wasDraft` idempotency).
- [x] 3.4 GREEN: run 3.3 until passing. (9 passed — 5 pre-existing + 4 new)

## Phase 4: Frontend

- [x] 4.1 `frontend/src/modules/chat/helpers/imageCompressor.ts`: added `isAllowedAttachment(file: File): boolean` (whitelist by extension, mirrors `MediaAttachmentPolicy`) and `isValidAttachmentSize(file: File, maxMB = 10): boolean` as new exported helpers.
- [x] 4.2 `frontend/src/modules/chat/components/MessageComposer.tsx`: widened `accept` attribute and paste-filter (paste stays image-only per design) to the full whitelist; extended the attachment item type to `FileAttachment { file: File; title: string; kind: 'image' | 'file'; previewUrl?: string }`; `compressImageToWebP()` only runs when `kind === 'image'`; renders a named file chip (icon + filename) for non-image kinds instead of a thumbnail. `MAX_IMAGES`→`MAX_FILES` (5), `MAX_MB` 5→10.
- [x] 4.3 Created `frontend/src/shared/components/molecules/MediaAttachmentCard.tsx`: props `{ media: IMedia }`, renders filename/icon, `onClick` triggers `downloadMediaWithSignedUrl` (reused existing helper, no new download logic).
- [x] 4.4 `frontend/src/modules/chat/components/ChatMessageBubble.tsx`: kept the existing `AttachedImages` thumbnail grid unchanged (non-regression); added an `AttachedFiles` section that renders one `MediaAttachmentCard` per non-image attachment.
- [x] 4.5 `frontend/src/modules/announcement/AnnouncementViewPage.tsx`: refactored its existing attachment-download rendering to consume the new shared `MediaAttachmentCard` molecule instead of its local implementation (DRY per design ADR-6) — identical visual output (same classes moved into the molecule).
- [x] 4.6 Vitest coverage exists for `imageCompressor` — added cases for `isAllowedAttachment`/`isValidAttachmentSize` (36 tests total, all passing). No existing test file for `MessageComposer` — skipped net-new scaffolding per task guidance.

## Phase 5: Full Verification

- [x] 5.1 Ran Pint on all touched PHP files: `vendor/bin/pint --dirty --format agent` (host, not sail — matches project constraint). Fixed formatting in `ChatRichMessageTest.php` and `ChatService.php` (import ordering, brace style); re-ran affected tests after, still green.
- [x] 5.2 Ran the targeted backend suites ONE PROCESS AT A TIME on host `php`: `MediaAttachmentPolicyTest` (19 passed), `MediaAuthorizationServiceTest` (4 passed), full `tests/Feature/Chat/` (58 passed, includes `ChatRichMessageTest`), `AnnouncementChatCrossPostTest` (9 passed). All sequential, no parallel runs.
- [x] 5.3 Ran `pnpm lint` (scoped to touched files: 0 errors/warnings; project-wide run showed pre-existing unrelated errors/warnings in uncommitted WIP files like `signup-form.tsx`, not introduced by this change) and `pnpm typecheck` (`tsc -p tsconfig.app.json --noEmit`; only the same 2 pre-existing `signup-form.tsx` errors, unrelated to this change's files) in `frontend/`.
- [ ] 5.4 Manual smoke (not performed — no live dev server/browser session in this apply batch): attach a PDF in chat composer → send → verify `MediaAttachmentCard` renders and downloads; publish an announcement with an attachment → verify cross-posted chat message shows the same file downloadable. Recommend for `sdd-verify` or manual QA.
- [x] 5.5 Cross-checked whitelist literals now live in ONE place (`MediaAttachmentPolicy`) — confirmed `AnnouncementService.php` (`attachFile`, line ~326) and `AnnouncementController.php` (`uploadMedia`, line ~235) are left as pre-existing duplication (out of scope per design; not refactored in this change).
