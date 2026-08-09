# Design: chat-file-attachments

> Architectural HOW for generalizing chat's image-only attachments into platform-wide
> generic file attachments, fixing the broken `ChatMessage` media-download authorization,
> and carrying announcement attachments through the announcement→chat cross-post.
>
> Companion to `proposal.md` / `exploration.md`. Skill authority: laravel-backend-architecture, react-frontend-architecture,
> stancl-tenancy. Tasks (the WHAT-to-do steps) are produced by `sdd-tasks`, not here.

## Architecture Overview

The change spans one horizontal seam already shared by Chat and Announcements: the Spatie
Media pipeline (private `do_spaces` disk → `TenantAwareUrlGenerator` → `/api/media/{id}/download*`
→ `MediaAuthorizationService`). Nothing new is introduced into that pipeline; the work is
(1) widening one owner model's collection + validation to the existing Announcements
contract, (2) closing an authorization gap for one owner type, and (3) threading pre-existing
`Media` into a system message via Spatie's own `copy()`.

Layering is unchanged and follows the project contract:

- **HTTP boundary** — `ChatMessageCreateRequest` (FormRequest, double-layer layer 1).
- **Domain** — `ChatService` owns all attachment business rules (layer 2 re-validation,
  WebP branch, cross-post copy). Controllers only orchestrate (`performStore`).
- **Model** — `ChatMessage` declares the media collection + mime-guarded conversion.
- **Cross-cutting** — `MediaAuthorizationService` (app-level) gates downloads; it delegates
  the chat rule back into `ChatService::canAccessChannel` (the existing per-channel resolver).
- **Shared source of truth** — a new `App\Support\MediaAttachmentPolicy` holds the single
  whitelist/caps definition so Chat does not hardcode a second copy of the Announcements
  literals.

## Component & Data Flow

### Direct chat upload (generalized)
```
MessageComposer (client validate: ext + size≤10MB + count≤5; compress images only)
  → useSendMessage (FormData: attachments[] + attachment_titles[])   [unchanged]
  → POST /chat_message
  → ChatMessageController::performStore  (validates ChatMessageCreateRequest — layer 1)
  → ChatService::postMessage($channel,$userId,$body,$reply,$html,$attachments)
       ├─ assertChannelAccess / assertCanWriteToChannel / rate limit  [unchanged]
       ├─ createMessageRecord                                          [unchanged]
       └─ foreach attachment: assertAttachmentAllowed (layer 2) → addMedia → 'attachments'
              (image → WebP conversion fires; non-image → conversion skipped by mime guard)
  → notifyChannelMessage                                              [unchanged]
```

### Announcement → chat cross-post (carry attachments)
```
AnnouncementService::publish (draft→active, $wasDraft guard)          [unchanged trigger]
  → postToOfficialChatChannel($announcement)
       └─ ChatService::postSystemMessage($channel,$authorId,$body,$html, $announcement->getMedia('attachments')->all())
              ├─ addMember + createMessageRecord (no write-guard/rate-limit/notify)  [unchanged]
              └─ foreach $media: $media->copy($chatMessage, 'attachments')
                     (new Media row owned by the ChatMessage; PDF NOT rasterized)
```

### Download authorization (the live-defect fix)
```
GET /api/media/{id}/download(-url)  → MediaAuthorizationService::authorizeDownload
  owner = ChatMessage
    → not in MEDIA_READ_ABILITIES map (correct — chat is not a role ability)
    → ownsMediaSubject(): NEW branch
         return app(ChatService::class)->canAccessChannel($owner->channel, $user->id)
```
Because cross-posted media's owner is the *ChatMessage* (via `copy()`), this one branch
authorizes both direct uploads and copied announcement PDFs uniformly.

## ADR-style Decisions

### ADR-1 — Single source of truth for the whitelist/caps: `App\Support\MediaAttachmentPolicy`
**Decision.** Create `backend/app/Support/MediaAttachmentPolicy.php` (a `final` class, no state)
holding the canonical contract:
- `EXTENSIONS` = `['pdf','jpg','jpeg','png','webp','gif','mp4','mp3','doc','docx','xls','xlsx']`
- `MIME_TYPES` = the fully-mapped set for every extension above (image/jpeg, image/png,
  image/gif, image/webp, application/pdf, video/mp4, audio/mpeg, application/msword,
  `…wordprocessingml.document`, application/vnd.ms-excel, `…spreadsheetml.sheet`)
- `MAX_FILES = 5`, `MAX_FILE_BYTES = 10*1024*1024`, `MAX_FILE_KILOBYTES = 10240`
- helpers: `mimesRule(): string` (`'mimes:'.implode(',', EXTENSIONS)`), `isAllowedExtension(string)`,
  `withinSizeLimit(int $bytes)`.

Chat's new code references this class exclusively. `ChatMessage` collection uses
`MediaAttachmentPolicy::MIME_TYPES`; `ChatMessageCreateRequest` uses `mimesRule()` +
`MAX_FILE_KILOBYTES` + `MAX_FILES`; `ChatService` layer-2 re-check uses `isAllowedExtension`
+ `withinSizeLimit`.

**Why.** The literals are currently duplicated in `AnnouncementService::attachFile` and
`AnnouncementController::uploadMedia`. Adding a third copy in Chat would triple the drift
surface. A shared class is the smallest DRY seam that respects the "business rules live in
one place" contract.

**Critical — do NOT inherit the Announcements drift.** `Announcement::registerMediaCollections`
declares a NARROWER mime set (image + pdf + msword + docx; missing xls/xlsx/mp4/mp3) than its
own service whitelist. If Chat's collection were narrower than the FormRequest whitelist,
Spatie would throw at `addMedia()` for a file the request already accepted. Therefore
`ChatMessage`'s collection declares the FULL `MediaAttachmentPolicy::MIME_TYPES` (exact
1:1 with the extension whitelist).

**Rejected alternatives.**
- *Config file `config/media.php`.* Rejected: arrays-in-config invite `env()`/runtime drift
  and are harder to reference as typed constants; a `Support` class is the established pattern
  for pure constants/helpers.
- *Inline duplication in Chat (mirror the literals).* Rejected: it is exactly the drift the
  proposal calls out; a fourth literal copy is a maintenance trap.
- *Refactor Announcements to consume the new class now.* Out of scope (proposal excludes
  fixing the Announcements drift). The class is DESIGNED so Announcements CAN adopt it later
  in a follow-up to kill its drift, but this change does not touch Announcements' collection.

### ADR-2 — Authorize chat media by delegating to channel access, not a role ability
**Decision.** Add a `ChatMessage` branch to `MediaAuthorizationService::ownsMediaSubject()`:
```php
if ($owner instanceof ChatMessage) {
    $channel = $owner->channel;
    return $channel !== null
        && app(ChatService::class)->canAccessChannel($channel, (string) $user->getKey());
}
```
`ChatMessage` is deliberately NOT added to the `MEDIA_READ_ABILITIES` map.

**Why.** Chat authorization is **target-based** (per-channel membership resolved from
`target_type`/`target_selector`), not a Spatie role ability. A map entry keyed to some
`read.chat_message`-style ability would grant *any* holder of that permission access to
*every* channel's media, breaking the per-channel isolation the whole Chat module is built
on. `canAccessChannel` already encapsulates the exact rule (target match OR moderator) and
is reused by every other chat interaction, so the media gate stays consistent with reads.
It also covers cross-posted media automatically because the copy's owner is a `ChatMessage`.

**Seam / no-cycle note.** `MediaAuthorizationService` (app) → `ChatService` (module) is a
one-way dependency; `ChatService` does not reference `MediaAuthorizationService`, so no
circular dependency. `ChatService` is resolved via `app(ChatService::class)` (its ctor deps
`TargetResolver` + `ChatHtmlSanitizer` are container-bound). The lookup runs inside an
`initializeTenancy` request, so `$owner->channel` resolves tenant-scoped correctly. Guard the
null channel (soft-deleted/edge) to fail closed.

**Tenant-visibility layer.** `ownerVisibleToUser()` already returns `true` for non-`User`
owners (TenantScope filters foreign-tenant media to a null owner upstream), so no change
there; the new branch is purely the ownership layer.

**Rejected alternative.** *Add `ChatMessage => 'update.chat_message'` to the abilities map.*
Rejected: moderators already pass via `canAccessChannel` (`userCanModerate`), and mapping a
non-moderator read ability would over-grant across channels.

### ADR-3 — Cross-post copies media (`Media::copy`), does not reference
**Decision.** `postSystemMessage` gains a 5th optional param carrying pre-existing `Media`
models; each is duplicated onto the new `ChatMessage` via Spatie `$media->copy($chatMessage,
'attachments')`.

**Why.** A Spatie Media row has exactly ONE morph owner. Copying is the only structurally
valid way to make the chat message self-contained and keep the owner-based gate (ADR-2)
correct — the copy's owner becomes the `ChatMessage`, so the same authorization rule applies
with zero special-casing. Storage duplication is accepted (small, infrequent report PDFs;
explicitly in-scope decision from the proposal).

**Rejected alternative.** *Reference the announcement's media live (store `announcement_id`).*
Rejected (exploration Approach 3): breaks the self-contained invariant, forces cross-owner
joins, and muddies authorization for a marginal storage saving.

### ADR-4 — WebP conversion scoped to image mimes (guard at conversion registration)
**Decision.** In `ChatMessage::registerMediaConversions(?Media $media)`, early-return for
non-image media before registering the `webp` conversion:
```php
public function registerMediaConversions(?Media $media = null): void
{
    if ($media !== null && ! str_starts_with((string) $media->mime_type, 'image/')) {
        return;
    }
    $this->addMediaConversion('webp')->format('webp')->quality(80)->nonQueued();
}
```
**Why.** Once the collection accepts `application/pdf`, Spatie's PDF generator would otherwise
rasterize a PDF into a WebP "thumbnail" on `addMedia` AND on `copy()` (the cross-post path).
The mime guard on the passed `$media` is the explicit, self-documenting fix — it prevents
surprise conversion work for PDFs/office docs while keeping the existing image WebP backstop.
This directly protects the ADR-3 copy path (copying a PDF must not trigger rasterization).

**Rejected alternative.** *Rely on "no image generator ⇒ no conversion" implicit behavior.*
Rejected: correct today for docs, but fragile — a PDF DOES have a generator, so the implicit
behavior would rasterize it. Explicit guard is required.

### ADR-5 — `postMessage` `$images` → generic `$attachments`, backward-compatible
**Decision.** Rename the `$images` parameter to `$attachments` (same array shape
`array{file, title?}`) and generalize the loop: attach every file to `'attachments'`; the
WebP branch is now handled at the model conversion layer (ADR-4), so the loop itself is
mime-agnostic. The controller (`performStore`) keeps building the same `['file'=>…,'title'=>…]`
list — only the local variable name/intent changes; no call-signature break for the sole
HTTP caller.

`postSystemMessage` signature becomes:
```php
postSystemMessage(ChatChannel $channel, string $userId, string $body,
                  ?string $bodyHtml = null, array $mediaToCopy = []): ChatMessage
```
The new param defaults to `[]`, so the in-flight uncommitted cross-post call keeps compiling;
`postToOfficialChatChannel` is the only caller and is edited in the same change to pass
`$announcement->getMedia('attachments')->all()`.

**Coordination with uncommitted cross-post (do not regress).** The `$wasDraft` idempotency
guard (cross-post fires only on the draft→active transition), the best-effort `try/catch`
around `postToOfficialChatChannel`, and the deliberate no-notification behavior of
`postSystemMessage` are ALL preserved. The only edit inside `postToOfficialChatChannel` is
adding the media argument; the `copy()` loop lives inside `postSystemMessage` so any copy
failure is swallowed by the existing outer `try/catch` and can never break publish.

### ADR-6 — Frontend: generalize composer, dual-render bubble, extract shared card
**Decision.**
- **`MessageComposer`** — `ImageAttachment` → `FileAttachment { file, title, kind:'image'|'file',
  previewUrl?: string }`. `MAX_MB 5→10`, `MAX_IMAGES→MAX_FILES`. `accept` = full whitelist
  (map `MediaAttachmentPolicy` extensions to accept tokens). `addImage` → `addAttachment`:
  validate extension + size(≤10MB) + count; images run `compressImageToWebP`, non-images pass
  through untouched (`kind:'file'`, no previewUrl). The paste handler stays image-only
  (clipboard file items are images in practice). Preview strip: images render the existing
  thumbnail; non-images render a chip (file icon + truncated name + remove).
- **`imageCompressor.ts`** — add generic siblings `isAllowedAttachment(file)` (extension-based)
  and `isValidAttachmentSize(file, maxMB=10)`; keep `compressImageToWebP` + `isValidImageFile`
  for the image branch. (No new file needed; the module already owns this helper.)
- **`ChatMessageBubble`** — keep `AttachedImages` (image grid) filtering
  `mime_type.startsWith('image/')`; add `AttachedFiles` for the complement, rendering the
  shared `MediaAttachmentCard`.
- **Shared molecule** — `frontend/src/shared/components/molecules/MediaAttachmentCard.tsx`,
  props `{ media: IMedia }`, renders the download button (Download icon + `media.name ||
  media.file_name`) wired to the existing `downloadMediaWithSignedUrl` helper (unchanged).
- **`AnnouncementViewPage`** — refactor the inline attachment `<button>` (lines ~116-128) to
  render `MediaAttachmentCard` (Active DRY; identical behavior).
- **`useSendMessage`** — FormData already generic (`attachments[]` + `attachment_titles[]`,
  mime-agnostic) → **unchanged**.
- **`schema.ts`** — `IChatMessage` inherits generic `media: IMedia[]` from `IEntity`;
  `IMedia` already carries `mime_type`/url fields/`custom_properties` → **no schema change**.

**Why.** The download-card + `downloadMediaWithSignedUrl` pattern already exists in
Announcements; extracting one molecule removes duplication instead of copy-pasting inline JSX
into the bubble. Branching compression at the composer keeps the WebP savings for images
without corrupting binary docs.

**Rejected alternative.** *Compress/convert non-images client-side.* Rejected: meaningless for
PDFs/docs and would corrupt them; pass-through is correct.

## Integration Points

| Seam | Contract | Change |
|---|---|---|
| `POST /chat_message` (multipart) | `attachments[]` + `attachment_titles[]` | Widen accepted mimes; caps 5×10MB. Payload shape unchanged. |
| `GET /api/media/{id}/download(-url)` | `MediaAuthorizationService::authorizeDownload` | New `ChatMessage` ownership branch. |
| `postSystemMessage` (in-flight cross-post caller) | 5th `array $mediaToCopy = []` param | Additive, default-safe; sole caller updated in-change. |
| Spatie `attachments` collection on `ChatMessage` | `acceptsMimeTypes` + mime-guarded `webp` conversion | Full mime set; PDF not rasterized. |
| Shared FE molecule | `MediaAttachmentCard({ media })` | New; consumed by chat bubble + announcement view. |

## Risks & Assumptions Requiring Validation

- **R1 — collection/whitelist parity.** `ChatMessage` collection MUST declare the full
  `MediaAttachmentPolicy::MIME_TYPES`; any narrower set reproduces the Announcements drift as a
  runtime `addMedia`/`copy` exception. Covered by a conversion/collection test (below).
- **R2 — `copy()` triggers conversions.** Spatie regenerates conversions on `copy()`; ADR-4's
  mime guard is what stops a copied PDF from rasterizing. If the guard regresses, cross-post
  does surprise async work. Test asserts no `webp` conversion on a copied PDF.
- **R3 — `postSystemMessage` signature collision.** The param is additive/default-safe, but the
  cross-post code is uncommitted — the edit must land atomically with it and preserve
  `$wasDraft` / try-catch / no-notify. Assumption: no other caller of `postSystemMessage` exists
  (verified: only `postToOfficialChatChannel`).
- **R4 — behavior change (intended).** Chat per-file cap 5→10MB and now accepts docs/mp4/mp3.
  Product sign-off assumed per proposal (orchestrator default). Front + back caps must move
  together or the FE rejects files the BE would accept (mismatch UX).
- **R5 — streamed fallback bandwidth.** Non-presigned downloads stream through the app server,
  bounded by the same auth gate; acceptable for small report PDFs (presigned S3 is the common
  path).
- **A1 — moderator coverage.** `canAccessChannel` already grants admins/moderators
  (`userCanModerate`), so ADR-2 gives them chat-media download without a separate rule.

## Test Strategy (backend feature tests; steps enumerated in sdd-tasks)

1. **`MediaAuthorizationService` (HARD prerequisite).** New feature test:
   - channel member downloads a `ChatMessage`-owned media → authorized;
   - non-member (no target match, not moderator) → 403;
   - moderator/admin → authorized;
   - **regression**: an existing image attachment now downloads for a real (non-`*`) token;
   - cross-posted (copied) media owned by a `ChatMessage` → authorized via channel access.
2. **`ChatMessageCreateRequest` validation matrix** (extend the controller store test):
   - accepts pdf/docx/xlsx/mp4/mp3/image; rejects disallowed extension; rejects >10MB;
     rejects a 6th file; layer-2 service re-check rejects a spoofed file that slips layer 1.
3. **`ChatMessage` conversion scoping** (new model/service test): attach a PDF → NO `webp`
   conversion generated and mime accepted; attach an image → `webp` conversion generated.
4. **Cross-post copy** (extend the AnnouncementService cross-post test): publishing an
   announcement with an `attachments` PDF creates a chat message in "Avisos oficiales" whose
   copied media is owned by the `ChatMessage` and is downloadable by a channel member; assert
   (a) `$wasDraft` still gates a single cross-post, (b) failure is swallowed, (c) NO extra
   notification is dispatched by the cross-post.
5. **Existing tests to extend, not duplicate:** ChatService `postMessage` attachment tests
   (rename `$images`→`$attachments`, add a doc case), `ChatMessageController` store test (mime
   widening), AnnouncementService publish/cross-post test (media leg).

Frontend: rely on existing chat page test harness (ResizeObserver polyfill already in
`src/test/setup.ts`); a light render test that a non-image `IMedia` renders `MediaAttachmentCard`
and an image renders the thumbnail grid is sufficient (no store/logic change to cover).

## Out of Scope (restated)
Chat attachment delete/detach endpoint; inline PDF preview; fixing Announcements' own
collection-vs-service drift (the shared policy class merely enables a future fix); storage
dedup for cross-posted media.
