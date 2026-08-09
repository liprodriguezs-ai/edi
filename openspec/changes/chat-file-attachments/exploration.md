# Exploration: chat-file-attachments

> Image-only chat attachments → generic file attachments, plus the announcement→chat
> cross-post carrying the announcement's file attachments (report PDFs reach the
> "Avisos oficiales" channel).
>
> Source: Engram topic `sdd/chat-file-attachments/explore` (obs #1644), materialized here
> because the exploration sub-agent lacked write tooling.

## Current State

### Backend — Chat attachments (image-only today)
- `Modules/Chat/app/Http/Requests/ChatMessageCreateRequest.php:19-23` — `attachments.*` rule is
  `['image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120']` (5MB), max 5 items, parallel `attachment_titles`.
- `Modules/Chat/app/Models/ChatMessage.php:83-95` — `registerMediaCollections()` on the `attachments`
  collection `acceptsMimeTypes` only the 4 image types; `registerMediaConversions()` adds a `webp`
  conversion (nonQueued, quality 80). This only runs for mimes with a matching Spatie image generator
  (Image/Webp/Avif/Pdf/Svg/Video registered in `config/media-library.php`), so a future PDF attachment
  would still get a rasterized-then-webp preview conversion "for free"; docs/xls skip conversion safely.
- `ChatService::postMessage()` (`Modules/Chat/app/Services/ChatService.php:220-269`) loops `$images`
  (`['file' => UploadedFile, 'title' => ?string]`), calls
  `$message->addMedia($file)->usingName(...)->withCustomProperties(['title'=>...])->toMediaCollection('attachments')`.
- `ChatService::postSystemMessage()` (line 160-166) — used ONLY by the announcement cross-post
  (`AnnouncementService::postToOfficialChatChannel`, `Modules/Announcements/app/Services/AnnouncementService.php:427-463`,
  itself uncommitted). It takes NO `$images`/attachments parameter — the cross-post currently posts
  text/HTML only, the announcement's PDF is never carried over.

### Backend — Announcement attachments (already generic, the model to mirror)
- `Modules/Announcements/app/Models/Announcement.php:90-102` — `attachments` collection already
  `acceptsMimeTypes` image + `application/pdf` + Word (missing xls/mp4/mp3 mimes in the collection
  declaration, but the SERVICE layer's extension whitelist is broader — a latent inconsistency).
- `AnnouncementService::attachFile()` (`AnnouncementService.php:324-348`) — extension whitelist
  `['pdf','jpg','jpeg','png','webp','gif','mp4','mp3','doc','docx','xls','xlsx']`, hard 10MB cap
  (`10*1024*1024`), max 5 files (`self::MAX_ATTACHMENTS = 5`), 6th file REJECTED (never silently rotated).
  `detachFile()` scopes deletion to `collection_name='attachments'` + announcement ownership (404 on mismatch).
- `AnnouncementController::uploadMedia()` (`AnnouncementController.php:230-248`) — the reusable
  double-layer validation convention: HTTP-boundary
  `mimes:pdf,jpg,jpeg,png,webp,gif,mp4,mp3,doc,docx,xls,xlsx','max:10240` THEN service re-validates
  (extension + byte-size + count) before persisting.
- `ReportBoardPublisher::publish()` (`Modules/Reports/app/Services/ReportBoardPublisher.php`) wraps the
  generated PDF bytes in a test-mode `UploadedFile` and routes it through `AnnouncementService::attachFile`
  — the "report → announcement PDF" leg already built; the missing leg is announcement → chat cross-post
  NOT carrying that PDF's media.

### Media pipeline / storage / URL generation (shared by both modules)
- `config/media-library.php:35` — `disk_name` = `env('MEDIA_DISK', 'do_spaces')` (DigitalOcean Spaces,
  S3-compatible), disk `visibility: 'private'` (`config/filesystems.php:73`) — NOT publicly readable.
- `config/media-library.php:130` — `url_generator` = `App\Services\TenantAwareUrlGenerator`, which
  overrides `getUrl()` to ALWAYS return `url('/api/media/'.$media->id.'/download')` — every
  `original_url`/`preview_url` the frontend receives is this app-controlled endpoint, never a raw S3 URL.
- Routes (`routes/api.php:144-151`):
  - `GET media/{media}/download-signed` (name `media.download_signed`) — `signed` middleware ONLY; safe
    because the signed URL is only ever minted by `downloadUrl()` AFTER authorization already passed.
  - `GET media/{media}/download` and `GET media/{media}/download-url` — behind
    `auth:sanctum, rejectRefreshToken, authActive, initializeTenancy`; both call
    `MediaAuthorizationService::authorizeDownload($mediaItem)` first.
- `MediaDownloadUrlService::resolveDownloadMeta()` — tries `$disk->temporaryUrl()` first (real S3 presigned
  URL, direct-to-storage, zero app-server bandwidth) and falls back to a Laravel
  `temporarySignedRoute('media.download_signed', ...)` (30 min expiry) when the driver can't presign.
- `MediaStreamDownloadService::streamDownload()` — the non-presigned fallback: reads the disk stream
  server-side and `response()->streamDownload()`s it (bounded by the same auth gate).

## CRITICAL FINDING — `MediaAuthorizationService` has NO rule for `ChatMessage`-owned media

`app/Services/MediaAuthorizationService.php:34-49` — the `MEDIA_READ_ABILITIES` map covers
`User, Company, Payment, Expense, Announcement, Visitor, BoardPeriod, CommunityProject, Collaborator,
UserProfile`. **`Modules\Chat\Models\ChatMessage` is absent.** Tracing `authorizeDownload()` for a
`ChatMessage`-owned `Media` item:
1. `$user->tokenCan('*')` — false for real users (login/refresh mints REAL Spatie permission names, never `['*']`; `['*']` only in test helpers).
2. `ownerVisibleToUser()` returns `true` for any non-`User` owner — passes tenant-visibility layer.
3. `$ability = MEDIA_READ_ABILITIES[ChatMessage::class] ?? null` → `null` → ability branch never fires.
4. `ownsMediaSubject()` — none of its branches match a `ChatMessage` owner → returns `false`.
5. → `authorizeDownload()` throws `AuthorizationException` for EVERY real user, regardless of channel membership.

No test exercises this path. The EXISTING image-attachment feature likely already 403s on
`GET /api/media/{id}/download(-url)` for chat images for any normal token — a pre-existing gap this change
must fix. Recommended fix (owner-agnostic, minimal): add a `ChatMessage` branch to `ownsMediaSubject()`
delegating to `ChatService::canAccessChannel($chatMessage->channel, $user->id)` (reuse the existing
per-user channel-access resolver rather than inventing a new Spatie ability — chat access is
target-based/per-channel, not a role ability).

## Frontend — rendering
- `frontend/src/modules/chat/components/ChatMessageBubble.tsx:147-181` — `AttachedImages` hard-filters
  `media.filter(m => m.mime_type.startsWith('image/'))`. Any non-image media is silently dropped from render.
- `frontend/src/modules/chat/components/MessageComposer.tsx` — `accept="image/jpeg,image/png,image/webp,image/gif"`
  (:405), paste handler filters `item.type.startsWith('image/')` (:136), client-side
  `isValidImageFile`/`isValidImageSize` (5MB), and unconditionally runs `compressImageToWebP()` on every
  attached file (:167) — this compression step is image-specific and must be branched for non-image files.
- `frontend/src/modules/chat/hooks/useSendMessage.ts` — `SendMessagePayload.attachments?: File[]` already
  generic; builds `FormData` with `attachments[]` + parallel `attachment_titles[]` (mime-agnostic here).
- `frontend/src/modules/chat/schema.ts` — `IChatMessage` inherits `media` from `IEntity`.
  `IMedia` (`Entity.ts:45-60`) already carries `mime_type`, url fields, `file_name`, `custom_properties`
  — fully generic, no schema change needed for the file-card UI.

## Reusable generic "file download card" — ALREADY EXISTS in Announcements, not yet in Chat
`frontend/src/modules/announcement/AnnouncementViewPage.tsx:111-130` — the pattern to extract:
a `<button onClick={() => downloadMediaWithSignedUrl({ mediaId, fileName })}>` with a Download icon +
truncated name. `frontend/src/shared/services/downloadMediaWithSignedUrl.ts` — the shared, mime-agnostic
download helper (tries `/api/media/{id}/download-url` → `downloadFromSignedUrl()`, falls back to streamed
blob on non-401/403 error). No dedicated `FileDownloadCard`/`AttachmentCard` atom exists yet — extracting a
shared `shared/components/molecules/MediaAttachmentCard.tsx` consumed by both modules removes duplication.

## Affected Areas
- `backend/Modules/Chat/app/Http/Requests/ChatMessageCreateRequest.php` — extend `attachments.*`
  validation from image-only to the announcement-style generic mime/extension list + size cap.
- `backend/Modules/Chat/app/Models/ChatMessage.php` — `acceptsMimeTypes` needs the doc mimes; webp
  conversion should stay image-scoped (explicit mime guard for clarity).
- `backend/Modules/Chat/app/Services/ChatService.php` — generalize `postMessage()`'s `$images` loop;
  `postSystemMessage()` needs a NEW parameter to carry pre-existing `Media` objects into the cross-post.
- `backend/Modules/Announcements/app/Services/AnnouncementService.php:427-463` (`postToOfficialChatChannel`,
  uncommitted) — must pass the announcement's attachments through to `postSystemMessage()`.
- `backend/app/Services/MediaAuthorizationService.php` — **must** add a `ChatMessage` ownership rule.
- `frontend/src/modules/chat/components/MessageComposer.tsx` — generalize `accept`, paste filter, and the
  compression step (skip `compressImageToWebP` for non-images); add a non-image file preview chip.
- `frontend/src/modules/chat/components/ChatMessageBubble.tsx` — dual-render: images as thumbnails,
  everything else as a download card (reuse `downloadMediaWithSignedUrl` + shared molecule).
- `frontend/src/modules/chat/helpers/imageCompressor.ts` — generic siblings / branch so non-images skip WebP.

## Approaches
1. **Mirror the Announcements attachment contract exactly** (mime whitelist, 10MB, extension list) and add
   a `ChatMessage` case to `MediaAuthorizationService`. Pros: single consistent contract across the two
   modules that already share the Media pipeline; closes the pre-existing auth gap; DRY via shared card;
   reuses `downloadMediaWithSignedUrl` as-is. Cons: touches 7+ files across two modules; requires a
   size-cap decision (chat 5MB vs announcements 10MB) and a call on whether chat allows video/audio.
   Effort: Medium.
2. **Copy media (not reference) when cross-posting** — in `postToOfficialChatChannel`, iterate the
   announcement's attachments and `$media->copy($chatMessage, 'attachments')` (Spatie's `HasMedia::copy()`
   duplicates the file + DB row under the new owner). Pros: a Media row cannot belong to two owners
   simultaneously so this is the only structurally valid mechanism; keeps the owner-based gate correct
   per-message (copied media's owner is the `ChatMessage`, same fix from Approach 1 covers it). Cons:
   duplicates storage bytes (acceptable for small, infrequent report PDFs). Effort: Low, contingent on
   Approach 1 (ChatMessage collection must accept `application/pdf` first).
3. **Reference the announcement's attachments from the chat message** (store `announcement_id`, resolve
   live). Pros: zero storage duplication, single source of truth. Cons: breaks the "chat message is
   self-contained" invariant; new resource joins; muddies the owner-based authorization model.
   Effort: Medium-High, not recommended.

## Recommendation
Approach 1 (generalize the Chat attachment contract to match Announcements' proven pattern, fixing the
`MediaAuthorizationService` gap as a hard prerequisite) combined with Approach 2 (`Media::copy()`) for the
cross-post leg. Keeps the "media belongs to exactly one owner" invariant Spatie assumes and that
`MediaAuthorizationService` is built around, reuses proven Announcements validation, and reuses the
frontend's `downloadMediaWithSignedUrl` + card pattern. Approach 3 not recommended — the storage cost of
Approach 2 is low and buys much simpler authorization/rendering code.

## Risks
- **(Must-fix prerequisite)** `MediaAuthorizationService` has no `ChatMessage` rule — chat media downloads
  403 for every real user today; must be fixed here or the feature ships broken.
- **Size/count divergence**: chat today 5 files × 5MB; announcements 5 files × 10MB. Needs an explicit decision.
- **WebP conversion scope**: the unconditional `webp` conversion would rasterize PDFs into thumbnails if the
  collection accepts `application/pdf`. Decide explicitly (guard by mime).
- **Compression pipeline is image-specific**: `MessageComposer`'s composer unconditionally calls
  `compressImageToWebP()` — must be branched so non-images bypass it.
- **`postSystemMessage` signature change** collides with the in-flight uncommitted cross-post work —
  coordinate the signature edit.
- **Announcement's own collection declaration is narrower than its service whitelist** — a pre-existing
  minor drift in the module being mirrored; don't copy the drift into the new Chat collection.

## Ready for Proposal
Yes. Two decisions surfaced (both resolved as orchestrator defaults in the proposal): final size/count
caps, and whether chat accepts mp4/mp3. The `MediaAuthorizationService` fix is scoped as an explicit
requirement, not an incidental side-fix.
