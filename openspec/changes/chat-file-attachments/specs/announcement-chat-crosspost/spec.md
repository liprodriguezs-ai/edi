# Announcement-to-Chat Cross-Post Attachment Carry-Over Specification

## Purpose

Extends the announcement→chat cross-post
(`AnnouncementService::postToOfficialChatChannel` → `ChatService::postSystemMessage`)
so that an announcement's file attachments are copied onto the cross-posted
"Avisos oficiales" chat message, not dropped.

## Requirements

### Requirement: Cross-post carries attachments via Media::copy

When an announcement with one or more attachments is published and cross-posted
to the "Avisos oficiales" channel, `postSystemMessage()` MUST accept the
announcement's `Media` collection and copy each item onto the resulting
`ChatMessage` using Spatie's `Media::copy($chatMessage, 'attachments')`, so the
copied media's owner becomes the chat message.

#### Scenario: Publishing an announcement with a PDF carries it to chat

- GIVEN an announcement has one PDF attachment
- WHEN it is published and cross-posted to "Avisos oficiales"
- THEN the resulting chat message has one media item in its `attachments` collection, a copy of the announcement's PDF
- AND the copied media's owner is the `ChatMessage`, authorized via the standard chat channel-access rule

### Requirement: No attachments produces a message without media

When an announcement has no attachments, the cross-post message MUST be created
without any media — the copy step MUST be skipped, not attempted with an empty
set producing an error.

#### Scenario: Text-only announcement cross-posts cleanly

- GIVEN an announcement has no attachments
- WHEN it is published and cross-posted
- THEN the chat message is created with the text/HTML content and zero media items

### Requirement: Attachment copy failure must not break publish

If copying one or more attachments to the cross-post message fails (e.g. a
storage error), the announcement publish operation MUST still succeed — the
failure MUST be logged, not thrown back to block or roll back the publish
transaction.

#### Scenario: A copy failure is isolated from publish

- GIVEN copying an announcement's attachment to the chat message raises a storage exception
- WHEN the announcement is published
- THEN the announcement is still published successfully and the cross-post text message is still created
- AND the failure is logged for follow-up

### Requirement: Cross-post remains idempotent and non-duplicating (non-regression)

Attachment carry-over MUST NOT alter the existing cross-post idempotency
guarantee: republishing or re-triggering the same announcement publish event MUST
NOT create a second cross-post message or duplicate attachments, and MUST NOT
trigger a duplicate notification.

#### Scenario: Re-triggering publish does not duplicate the cross-post

- GIVEN an announcement with an attachment has already been cross-posted once
- WHEN the publish event fires again for the same announcement (e.g. a retry)
- THEN no second chat message or duplicate attachment copy is created, and no duplicate notification is sent
