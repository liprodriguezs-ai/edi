# Chat Media Authorization Specification

## Purpose

Fixes the missing `ChatMessage` ownership rule in `MediaAuthorizationService`,
which today causes every real user's chat-media download to 403, and defines the
tenant-isolation and channel-membership contract for this authorization.

## Requirements

### Requirement: ChatMessage media authorization delegates to channel access

`MediaAuthorizationService::ownsMediaSubject()` MUST add a branch for
`Modules\Chat\Models\ChatMessage`-owned media that authorizes the download IFF the
requesting user can access the message's channel, delegating to
`ChatService::canAccessChannel($chatMessage->channel, $user->id)`. This MUST NOT
introduce a new Spatie ability — chat access is target-based (per-channel
membership), not role-based.

#### Scenario: A channel member can download chat media

- GIVEN a user is a member of a chat channel
- AND a message in that channel carries a media attachment
- WHEN the user requests `GET /api/media/{id}/download-url`
- THEN the request succeeds and a download URL/stream is returned

#### Scenario: A non-member is denied

- GIVEN a user is NOT a member of the chat channel a message belongs to
- WHEN the user requests `GET /api/media/{id}/download-url` for that message's attachment
- THEN the request is rejected with a 403 Authorization exception

#### Scenario: Existing image attachments now succeed for real users (regression fix)

- GIVEN a channel member requests an existing image attachment posted before this change
- WHEN the download endpoint is called with a real (non-wildcard) user token
- THEN the request succeeds — this is the fix for the pre-existing 403 defect

### Requirement: Tenant isolation on chat media downloads

Chat media authorization MUST NOT bypass tenant scoping: a user from tenant A
MUST NOT be able to authorize a download of media owned by a `ChatMessage`
belonging to tenant B's channel, regardless of the channel-access check's outcome.

#### Scenario: Cross-tenant download is denied

- GIVEN a user belongs only to tenant A
- AND a chat message with an attachment belongs to a channel in tenant B
- WHEN the user requests the download
- THEN the request is rejected — tenant scoping denies visibility independent of channel-access logic
