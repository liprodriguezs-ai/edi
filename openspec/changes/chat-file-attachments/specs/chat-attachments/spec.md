# Chat Attachments Specification

## Purpose

Generalizes chat message attachments from images-only to the platform-wide generic
file convention shared with Announcements, and defines the corresponding
composer/bubble frontend contract.

## Requirements

### Requirement: Shared attachment whitelist and limits

Chat message attachments MUST accept the platform-wide extension whitelist
`pdf, jpg, jpeg, png, webp, gif, mp4, mp3, doc, docx, xls, xlsx`, MUST reject files
over 10MB, and MUST reject a message with more than 5 attachments — mirroring the
Announcements contract exactly (`AnnouncementService::attachFile()` conventions).

#### Scenario: Whitelisted files within limits are accepted

- GIVEN a user sends a chat message with 3 attachments: a 2MB PDF, a 4MB docx, and a 1MB jpg
- WHEN the message is submitted
- THEN all 3 attachments are persisted to the `attachments` media collection

#### Scenario: Disallowed mime is rejected

- GIVEN a user attaches a `.zip` file
- WHEN the message is submitted
- THEN the request is rejected with a validation error and no media is persisted

#### Scenario: Oversize file is rejected

- GIVEN a user attaches an 11MB PDF
- WHEN the message is submitted
- THEN the request is rejected with a validation error and no media is persisted

#### Scenario: Too many attachments is rejected

- GIVEN a user attaches 6 whitelisted files to one message
- WHEN the message is submitted
- THEN the request is rejected — the 6th file is never silently dropped or rotated

### Requirement: Double-layer validation

Attachment validation MUST be enforced at two layers: the HTTP boundary
(`ChatMessageCreateRequest` mime/size/count rules) AND the service layer
(`ChatService::postMessage()` re-checks extension, byte-size, and count before
persisting), matching the Announcements double-layer pattern.

#### Scenario: Service layer re-validates independent of the FormRequest

- GIVEN a caller bypasses the FormRequest (e.g. a direct service call in a test)
- WHEN `ChatService::postMessage()` receives a file outside the whitelist
- THEN the service rejects it before calling `addMedia()`

### Requirement: WebP conversion is image-scoped

The `webp` media conversion on the `ChatMessage` `attachments` collection MUST run
only for image mimes (`jpeg, jpg, png, webp, gif`). PDFs, Office documents, and
audio/video files MUST be stored as-is, with no rasterized or converted derivative
generated.

#### Scenario: A PDF attachment produces no webp conversion

- GIVEN a user attaches a PDF to a chat message
- WHEN the media conversion pipeline runs
- THEN no `webp` conversion exists for that media item, and the original PDF is retrievable unchanged

#### Scenario: An image attachment still gets its webp conversion (non-regression)

- GIVEN a user attaches a JPG to a chat message
- WHEN the media conversion pipeline runs
- THEN a `webp` conversion is generated, matching current image-attachment behavior

### Requirement: Composer accepts the whitelist and compresses only images

The chat message composer MUST accept files matching the whitelist (via its
file-picker `accept` attribute and paste-handler filter). It MUST run client-side
compression (`compressImageToWebP()`) only on image files; non-image files MUST
pass through untouched. Non-image files MUST render as a named file chip (not an
image thumbnail) before the message is sent.

#### Scenario: Attaching a PDF shows a file chip, not a thumbnail

- GIVEN a user selects a PDF in the composer
- WHEN the file is added to the pending-attachments list
- THEN the composer shows a file-name chip and does not attempt image compression

#### Scenario: Attaching an image is compressed as before (non-regression)

- GIVEN a user selects a JPG in the composer
- WHEN the file is added to the pending-attachments list
- THEN the composer runs `compressImageToWebP()` on it, unchanged from current behavior

### Requirement: Bubble renders images and non-image files distinctly

`ChatMessageBubble` MUST render image-mime media as thumbnails (current behavior,
non-regressed) and MUST render every other whitelisted mime as a download card
using a shared `MediaAttachmentCard` molecule (also reused by the Announcements
detail view), which triggers `downloadMediaWithSignedUrl` on click.

#### Scenario: A non-image attachment renders as a download card

- GIVEN a chat message carries a `.docx` attachment
- WHEN the bubble renders
- THEN a `MediaAttachmentCard` is shown with the file name, and clicking it invokes `downloadMediaWithSignedUrl`

#### Scenario: Existing image rendering is unchanged (non-regression)

- GIVEN a chat message carries a JPG attachment
- WHEN the bubble renders
- THEN the image renders as a thumbnail exactly as before this change
