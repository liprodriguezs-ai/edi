# Proposal: chat-file-attachments

## Intent

**Problem.** Chat attachments are image-only today, and the attachment-download
authorization path for chat media is silently broken — `MediaAuthorizationService`
has no `ChatMessage` rule, so `GET /api/media/{id}/download(-url)` returns 403 for
every real (non-superadmin) user token. On top of that, the recently added
announcement→chat cross-post (`ChatService::postSystemMessage` +
`AnnouncementService::postToOfficialChatChannel`, currently uncommitted) copies only
the announcement's text/HTML — it drops the announcement's file attachments, so
report PDFs never reach the "Avisos oficiales" channel.

**Why now.** The cross-post work is in flight and uncommitted; the missing file leg
must land with it or it ships incomplete. The chat media 403 is a live defect
affecting the existing image feature, not a new risk introduced by this change.
Generalizing chat attachments to arbitrary files is also a reusable platform
capability future features (documents in community projects, treasury receipts in
chat, etc.) can build on.

**Success looks like.**
- A resident/admin can attach a PDF, office document, image, or short media file to a
  chat message and any channel member can download it (no 403).
- An official announcement that carries a report PDF cross-posts that PDF into the
  "Avisos oficiales" chat channel as a downloadable file.
- Chat and Announcements share one platform-wide file convention (limits, whitelist,
  double-layer validation) and one shared attachment-card UI.

## Scope

### In scope
- **Fix `MediaAuthorizationService` for `ChatMessage` media (HARD PREREQUISITE).** Add
  an ownership rule that delegates to the existing per-user channel-access resolver
  (`ChatService::canAccessChannel($chatMessage->channel, $user->id)`) rather than a new
  Spatie ability. Includes tests covering: channel member can download, non-member 403,
  and (regression) existing image downloads now succeed for real users.
- **Generalize chat attachment validation** to mirror the Announcements contract
  exactly: 10MB per file, max 5 files, whitelist `pdf,jpg,jpeg,png,webp,gif,mp4,mp3,doc,docx,xls,xlsx`.
  Double-layer validation (FormRequest at the HTTP boundary + service-side whitelist +
  byte-size + count re-check), matching `AnnouncementController::uploadMedia()` /
  `AnnouncementService::attachFile()`.
- **`ChatMessage` media collection**: extend `acceptsMimeTypes` to the whitelisted mimes;
  scope the `webp` media conversion to image mimes only so PDFs are NOT rasterized into
  thumbnails.
- **Generalize `ChatService::postMessage()`** from an image-specific loop to a generic
  attachment loop (rename `$images` semantics; skip conversions for non-image mimes).
- **Extend `ChatService::postSystemMessage()`** with a new parameter to carry
  pre-existing `Media` into the cross-post message, and update
  `AnnouncementService::postToOfficialChatChannel()` to pass the announcement's
  attachments through. Cross-post uses **`Media::copy($chatMessage, 'attachments')`**
  (each Media row belongs to exactly one owner; the copy's owner becomes the
  `ChatMessage`, so the same authorization fix covers it uniformly).
- **Frontend — composer**: generalize `MessageComposer` (`accept` list, paste filter),
  branch client-side compression so `compressImageToWebP()` runs on images only,
  non-image files pass through untouched; add a non-image file preview chip.
- **Frontend — rendering**: `ChatMessageBubble` dual-renders — images as thumbnails
  (existing behavior), non-image media as a download card.
- **Frontend — Active DRY**: extract a shared `MediaAttachmentCard` molecule reused by
  both `ChatMessageBubble` and the Announcements detail view; reuse the existing
  `downloadMediaWithSignedUrl` helper unchanged.

### Out of scope
- Chat attachment **delete/detach** endpoint (Announcements' `detachFile` pattern noted
  for a future change; not required for this capability).
- Rich PDF/thumbnail preview inline in the chat bubble (download card only).
- Any change to the Announcements attachment contract itself, or reconciling the
  pre-existing drift between the Announcement collection's `acceptsMimeTypes` and its
  service whitelist (flagged, not fixed here — the new Chat collection simply must not
  copy that drift).
- Changing chat's realtime/broadcast payloads beyond what carrying media naturally
  requires.
- Any new storage/dedup strategy for cross-posted media (duplication via `Media::copy()`
  is accepted for small, infrequent report PDFs).

## Approach

Adopt exploration **Approach 1 + Approach 2** (the recommendation):

1. **One platform-wide file convention.** Mirror the proven Announcements contract
   (10MB, 5 files, shared whitelist, double-layer validation) into Chat rather than
   inventing a divergent one. This intentionally raises chat's existing per-file cap
   from 5MB→10MB to keep a single convention across the two modules that already share
   the same Spatie media pipeline, disk, URL generator, and download endpoints.

2. **Fix authorization by delegating to channel access, not a new ability.** Chat access
   is target-based (per-channel membership), not a role ability, so the `ChatMessage`
   branch in `MediaAuthorizationService::ownsMediaSubject()` reuses
   `ChatService::canAccessChannel(...)`. This keeps the owner-based gate uniform and
   also fixes the copied cross-post media automatically (its owner is a `ChatMessage`).

3. **Cross-post via `Media::copy()`.** A Spatie Media row has a single morph owner, so
   copying (duplicating file + row under the `ChatMessage`) is the only structurally
   valid mechanism — Approach 3 (live reference) is rejected because it breaks the
   "chat message is self-contained" invariant and complicates authorization for a
   marginal storage saving.

4. **Frontend reuse over reinvention.** The Announcements detail already has the exact
   download-card pattern and the mime-agnostic `downloadMediaWithSignedUrl` helper;
   extract the card into a shared molecule (Active DRY) instead of copy-pasting inline
   JSX into the chat bubble.

**Rationale.** Every design choice favors reusing an already-proven, already-wired
convention (Announcements) over building parallel chat-specific plumbing — smaller
surface, one contract to reason about, and the required authorization fix lands once and
covers both direct chat uploads and cross-posted announcement media.

## Impact

- **Backend files:** `Modules/Chat/.../ChatMessageCreateRequest.php`,
  `Modules/Chat/.../ChatMessage.php`, `Modules/Chat/.../ChatService.php`,
  `Modules/Announcements/.../AnnouncementService.php` (coordinate with uncommitted
  cross-post code), `app/Services/MediaAuthorizationService.php` + its tests.
- **Frontend files:** `chat/components/MessageComposer.tsx`,
  `chat/components/ChatMessageBubble.tsx`, `chat/helpers/imageCompressor.ts`, a new
  `shared/components/molecules/MediaAttachmentCard.tsx`, and the Announcements detail
  view refactored to consume it.
- **Behavior change (intended):** chat per-file cap 5MB→10MB; chat now accepts documents
  and short media, not just images.
- **Reviewers to run after apply:** `@laravel-architecture-reviewer`,
  `@react-frontend-reviewer`, `@integration-reviewer` (back+front contract seam changes
  together), `@tenancy-reviewer` (media authorization touches tenant-scoped access).
- **Coordination:** the `postSystemMessage` signature change and
  `postToOfficialChatChannel` edit touch uncommitted in-flight code — must not regress it.

## Risks / Open questions

- **(Resolved assumption) Size/count cap** — aligned to Announcements' 10MB × 5 files.
  This is a deliberate raise of chat's existing 5MB image cap; confirm no product reason
  to keep chat lower.
- **(Resolved assumption) mp4/mp3 in chat** — included, to keep ONE platform-wide
  whitelist. The exploration flagged the prompt's narrower "PDF/office docs" wording;
  the orchestrator default chose the single shared convention. Confirm chat should accept
  short audio/video.
- **WebP conversion must be image-scoped** — if the mime guard is missed, PDFs get
  rasterized into thumbnails via Spatie's PDF generator (surprise async work). Enforced
  in scope; verify in spec/design.
- **`postSystemMessage` signature collision** with uncommitted cross-post code —
  coordinate the parameter addition so the in-flight work does not regress.
- **Announcement collection vs service whitelist drift** — pre-existing; the new Chat
  collection must declare the full mime set to avoid inheriting the same inconsistency.
- **Streaming fallback bandwidth** — non-presigned downloads stream through the app
  server; bounded by the same auth gate, acceptable for small report PDFs (presigned S3
  is the production common path).

## Proposal assumptions needing user review

These orchestrator defaults were pre-agreed under Automatic mode; noted for a correction
pass if desired:
1. Chat per-file cap raised 5MB → 10MB (align with Announcements).
2. Chat accepts the full shared whitelist including `mp4`/`mp3` (not document-only).
3. Cross-post duplicates storage via `Media::copy()` (accepted for small report PDFs).
4. Chat attachment delete/detach endpoint is out of scope for this change.

## Next

`sdd-spec` and `sdd-design` can run in parallel from this proposal.
