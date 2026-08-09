# Plan 066: Keep Meta/GA conversion secrets out of request URLs (log capture risk)

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done. NEVER paste a real
> token value into any file, commit, or log.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- Modules/Tracking/app/Channels/FacebookConversionsChannel.php Modules/Tracking/app/Channels/GoogleAnalyticsChannel.php`

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security (secret hygiene)
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

The Tracking channels put credentials in the outbound request URL query string: the Meta
CAPI access token (`FacebookConversionsChannel`) and the GA4 Measurement Protocol
`api_secret` (`GoogleAnalyticsChannel`). Any place that records outgoing request URIs —
Laravel Telescope's HTTP-client watcher (Telescope is installed), reverse-proxy access
logs, APM — then persists the full URL including the secret. A store/log dump exposes live
tokens, which then require ROTATION, not just removal. Meta CAPI accepts the token in the
POST body (so it can move out of the URL entirely); GA4 requires it in the query (so GA
needs watcher/log redaction instead).

## Current state

- `backend/Modules/Tracking/app/Channels/FacebookConversionsChannel.php` (line 56):
  ```php
  $url = "https://graph.facebook.com/{$apiVersion}/{$pixelId}/events?access_token=".urlencode((string) $accessToken);
  // ...
  Http::asJson()->throw()->post($url, $payload);
  ```
  The token is in the query string of the POST URL. Meta accepts `access_token` in the JSON
  body instead.
- `backend/Modules/Tracking/app/Channels/GoogleAnalyticsChannel.php` (line 74): puts
  `api_secret` (and `measurement_id`) in the GA4 MP URL query — GA4 REQUIRES these as query
  params, so this one can't move to the body.
- `laravel/telescope` is installed; its HTTP-client watcher records outgoing request URLs;
  `telescope:prune` is scheduled (`routes/console.php`).
- These run inside `DeliverTrackingEventJob` (queued), errors propagate for retry — keep
  that behavior.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Syntax | `cd backend && php -l Modules/Tracking/app/Channels/FacebookConversionsChannel.php` | clean |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=Tracking` (or `--filter=FacebookConversions`) | all pass |

## Scope

**In scope**:
- `FacebookConversionsChannel.php` — move `access_token` from the URL query into the JSON
  body (Meta supports `access_token` as a top-level body field).
- Telescope HTTP-watcher redaction (or a global outgoing-request redaction) for the GA4
  host's `api_secret` query param (and, defensively, `access_token`), since GA4 needs it in
  the URL. Config the redaction in `config/telescope.php` (`ignore`/`hideRequestParameters`
  or a watcher option) — read the installed Telescope version's redaction API first.

**Out of scope**:
- Changing GA4 to body params (the API doesn't support it).
- The PII hashing (already correct: em/ph/external_id are SHA-256'd).
- Rotating the current tokens — recommend it operationally (the values may already be in
  logs), but rotation is an ops action, not a code change; note it in the PR.

## Steps

### Step 1: Move the Meta token to the request body

```php
$url = "https://graph.facebook.com/{$apiVersion}/{$pixelId}/events";
$body = $payload;                 // existing { data: [...], test_event_code? }
$body['access_token'] = (string) $accessToken;

Http::asJson()->throw()->post($url, $body);
```
Confirm against Meta CAPI docs (via Context7 or Boost `search-docs`) that `access_token` is
accepted as a body field for the `/events` endpoint (it is, as of Graph API current).

**Verify**: `php -l` clean; `php artisan test --filter=Tracking` still green (the Http fake
assertion may need updating to check the body instead of the URL — update it to assert the
token is in the body and NOT in the URL).

### Step 2: Redact GA4 (and defensively Meta) secrets from Telescope/logs

In `config/telescope.php`, add redaction for the outgoing-request secrets so the watcher
does not persist `api_secret`/`access_token`. Read the installed Telescope version's option
for hiding request parameters/headers; if there's no per-param redaction for outgoing HTTP,
disable the HTTP watcher for these hosts or filter via the watcher's `ignore` hook.

**Verify**: with Telescope enabled locally (if feasible), fire a tracking event and confirm
the stored entry's URL has the secret redacted. If Telescope can't be exercised in the
executor env, at minimum confirm the config change is syntactically valid
(`php artisan config:show telescope` or `php -l`).

### Step 3: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] Meta CAPI `access_token` is sent in the POST body, not the URL (test asserts it)
- [ ] GA4 `api_secret` (and Meta token) are redacted from the Telescope HTTP watcher / logs
- [ ] `php artisan test --filter=Tracking` green
- [ ] `php -l` clean, `vendor/bin/pint --dirty` exit 0
- [ ] PR notes that the current tokens should be rotated (they may already be in logs)
- [ ] `plans/README.md` row updated

## STOP conditions

- The channel code differs from the excerpt (drift).
- Meta rejects the token in the body for this endpoint/version (verify via docs first) —
  if so, keep it in the URL and rely on redaction (Step 2) for Meta too; report.
- The installed Telescope version has no outgoing-request redaction hook — disable the HTTP
  watcher for these two hosts and report the coarser mitigation.

## Maintenance notes

- Any new outbound integration that authenticates via a query param must be added to the
  redaction list.
- Reviewer: confirm the Http fake test asserts the secret is absent from the recorded URL.
- Operational follow-up (not code): rotate the Meta CAPI token and GA4 api_secret, since
  they may already be present in historical Telescope entries / proxy logs.
